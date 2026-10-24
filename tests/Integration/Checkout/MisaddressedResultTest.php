<?php
/**
 * Tests that a placement's authorization and the reconciliation run's query are settled only on the payment they asked about, and a delivery only on the payment both its ids name
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Checkout;

use SEOCart\Checkout\Application\PlaceOrder;
use SEOCart\Checkout\Domain\CheckoutError;
use SEOCart\Checkout\Infrastructure\Jobs\ReconcileStalePlacements;
use SEOCart\Contracts\Payment\GatewayUnavailable;
use SEOCart\Contracts\Payment\Operation;
use SEOCart\Contracts\Payment\Outcome;
use SEOCart\Contracts\Payment\PaymentGateway;
use SEOCart\Order\Infrastructure\OrderTables;
use SEOCart\Payment\Domain\Webhook\ReceiptDecision;
use SEOCart\Payment\Domain\Webhook\ReceiptResult;
use SEOCart\Payment\Infrastructure\Gateway\StubGateway;
use SEOCart\Payment\Infrastructure\PaymentTables;
use SEOCart\Platform\Kernel\Container;
use SEOCart\Platform\Logging\LogsTable;
use SEOCart\Support\Error\CodedException;
use SEOCart\Tests\Support\Checkout\PlacementKernel;
use SEOCart\Tests\Support\Checkout\WebhookTestCase;
use SEOCart\Tests\Support\Doubles\MisaddressingGateway;
use SEOCart\Tests\Support\Payment\StubWebhooks;

/**
 * A gateway's answer about one payment, A, addressed to another payment of the same gateway, B, that waits as A does, settles neither: the placement of A waits as one the gateway did not answer, and the reconciliation run defers A. A delivery whose uuid names one payment and whose provider reference names the other moves neither.
 *
 * Planted violations, each shown red and removed, one at a time:
 * - in CanonicalGateway::authorize() and query(), return the gateway's answer through
 *   DeliveredValues::canonical() alone, without answerTo(): B is then authorized, and its order
 *   accepted, on the answer about A;
 * - in ReceiveWebhook::intentOf(), return the intent of the uuid without comparing the reference
 *   it holds with the delivery's: the capture is then applied to the payment of the uuid;
 * - in ReceiveWebhook::intentOf(), return the intent of the uuid that holds no reference without
 *   looking up whether another intent holds the delivery's: B, which waits for its gateway's
 *   answer, is then voided and its order cancelled on a delivery naming A's reference;
 * - in ReceiveWebhook::intentOf(), return the intent of the uuid that holds no reference only
 *   when no intent holds the delivery's, so that the lookup finding the intent itself refuses
 *   it (`null === $holder ? $intent : false`): the delivery B's own reference came with is then
 *   ignored, though B took that reference between its read and the lookup.
 *
 * @since 0.2.0
 */
final class MisaddressedResultTest extends WebhookTestCase {

	/**
	 * The gateway of the requests the test makes through `$misaddressed`: the recorded stand-in, its answers addressed as the test sets.
	 *
	 * @since 0.2.0
	 *
	 * @var MisaddressingGateway
	 */
	private MisaddressingGateway $misaddressing;

	/**
	 * The production wiring of a request whose gateway is the misaddressing one.
	 *
	 * @since 0.2.0
	 *
	 * @var Container
	 */
	private Container $misaddressed;

	/**
	 * Builds the wiring of a request over the misaddressing gateway.
	 *
	 * @since 0.2.0
	 */
	public function set_up(): void {
		parent::set_up();

		$misaddressing       = new MisaddressingGateway( $this->gateway );
		$this->misaddressing = $misaddressing;
		$this->misaddressed  = PlacementKernel::over( $this->db, $this->tokens, $this->identities, $this->wake, $this->reporter(), array( PaymentGateway::class => static fn(): PaymentGateway => $misaddressing ) );
	}

	/**
	 * Tests that an authorization of A answered as an approval of B, which waits for the gateway's answer, settles neither placement.
	 *
	 * @since 0.2.0
	 */
	public function test_an_authorization_answered_for_another_intent_settles_neither_placement(): void {
		$b      = $this->placed( StubGateway::THROW, 'attempt-b' );
		$before = $this->standing( $b['intent_uuid'] );

		$this->misaddressing->answerFor( $b['intent_uuid'] );
		$this->readyCart( array( $this->sellable() => 1 ) );

		$refused = null;

		try {
			$this->misaddressed->get( PlaceOrder::class )->place( $this->placeInput( 'attempt-a' ), self::guest() );
		} catch ( CodedException $unanswered ) {
			$refused = $unanswered;
		}

		$a = $this->latestPlaced();

		$this->assertNotSame( $b['intent_uuid'], $a['intent_uuid'] );
		$this->assertSame( $before, $this->standing( $b['intent_uuid'] ), 'B is as it was: its intent, its order, its events and its ledger.' );
		$this->assertSame( array( 'created', 'pending_payment', 'unpaid' ), self::statuses( $this->standing( $a['intent_uuid'] ) ), 'A waits as placed.' );
		$this->assertSame( array(), $this->standing( $a['intent_uuid'] )['ledger'] );
		$this->assertInstanceOf( CodedException::class, $refused, 'The placement was answered.' );
		$this->assertSame( CheckoutError::GatewayUnavailable, $refused->errorCode(), 'The placement is answered as one the gateway did not answer.' );

		$cause = $refused->getPrevious();

		$this->assertInstanceOf( GatewayUnavailable::class, $cause );
		$this->assertStringContainsString( 'a call to authorize for another intent', $cause->getMessage() );
		$this->assertStringNotContainsString( $b['intent_uuid'], $cause->getMessage() );
		$this->assertStringNotContainsString( $a['intent_uuid'], $cause->getMessage() );
	}

	/**
	 * Tests that the reconciliation run's query of A answered as an approval of B, both waiting for their shopper, settles neither and defers A.
	 *
	 * @since 0.2.0
	 */
	public function test_a_query_answered_for_another_intent_settles_neither_placement(): void {
		$b = $this->placed( StubGateway::REQUIRES_ACTION, 'attempt-b' );
		$a = $this->placed( StubGateway::REQUIRES_ACTION, 'attempt-a' );

		// Only A has waited long enough for the run to ask the gateway about it.
		$this->db->execute( 'UPDATE %i SET updated_at = UTC_TIMESTAMP(6) - INTERVAL 11 MINUTE WHERE uuid = %s', $this->table( PaymentTables::INTENTS ), $a['intent_uuid'] );

		$before = array( $this->standing( $a['intent_uuid'] ), $this->standing( $b['intent_uuid'] ) );

		$this->misaddressing->answerFor( $b['intent_uuid'] );
		$this->gateway->calls = array();

		$this->assertNull( $this->misaddressed->get( ReconcileStalePlacements::class )->handle( array() ) );

		$this->assertSame( array( 'query' ), array_column( $this->gateway->calls, 'method' ), 'The gateway was asked about A.' );
		$this->assertSame( $before, array( $this->standing( $a['intent_uuid'] ), $this->standing( $b['intent_uuid'] ) ), 'Neither payment moved.' );
		$this->assertSame( array( array( $a['intent_uuid'], 'gateway_unavailable' ) ), array_map( static fn( array $deferred ): array => array( $deferred['intent_uuid'] ?? null, $deferred['reason'] ?? null ), $this->logged( ReconcileStalePlacements::DEFERRED ) ), 'A is deferred, as when the gateway gives no answer.' );
	}

	/**
	 * Tests that a delivery naming one payment by its uuid and the other by the provider's reference is ignored as naming no payment of the store's, crossed either way, its receipt written and its line saying the ids disagree without quoting either; named by both of its own ids, the same capture is applied.
	 *
	 * @since 0.2.0
	 */
	public function test_a_delivery_whose_two_ids_name_two_payments_is_ignored(): void {
		$a = $this->placed( StubGateway::APPROVE, 'attempt-a' );
		$b = $this->placed( StubGateway::APPROVE, 'attempt-b' );

		$this->assertNotNull( $a['provider_intent_id'] );
		$this->assertNotNull( $b['provider_intent_id'] );

		$this->assertCrossedIgnored(
			array(
				'B\'s uuid, A\'s reference' => StubWebhooks::of( $this->resultAbout( $b, Operation::Capture, Outcome::Approved, 'stub-cap-' . $b['intent_uuid'], null, $a['provider_intent_id'] ) ),
				'A\'s uuid, B\'s reference' => StubWebhooks::of( $this->resultAbout( $a, Operation::Capture, Outcome::Approved, 'stub-cap-' . $a['intent_uuid'], null, $b['provider_intent_id'] ) ),
			),
			$a['intent_uuid'],
			$b['intent_uuid']
		);

		$this->assertSame( ReceiptResult::Applied, $this->deliver( StubWebhooks::of( $this->captureOf( $a ) ) ) );
		$this->assertSame( array( 'captured', 'authorized' ), array( $this->intentRow( $a['intent_uuid'] )['status'], $this->intentRow( $b['intent_uuid'] )['status'] ) );
	}

	/**
	 * Tests that a delivery naming, by its uuid, a payment that holds no provider reference yet, and another payment's reference, is ignored as naming no payment of the store's, a void and a decline alike; under a reference no payment holds, the provider's first word about the payment, by webhook before its answer, is applied to it.
	 *
	 * @since 0.2.0
	 */
	public function test_a_delivery_naming_a_payment_without_a_reference_and_another_payments_reference_is_ignored(): void {
		$a = $this->placed( StubGateway::APPROVE, 'attempt-a' );
		$b = $this->placed( StubGateway::THROW, 'attempt-b' );

		$this->assertNotNull( $a['provider_intent_id'] );
		$this->assertNull( $b['provider_intent_id'], 'B holds no reference: the gateway did not answer its authorization.' );

		$this->assertCrossedIgnored(
			array(
				'a void'    => StubWebhooks::of( $this->resultAbout( $b, Operation::Void, Outcome::Approved, 'stub-void-' . $b['intent_uuid'], null, $a['provider_intent_id'] ) ),
				'a decline' => StubWebhooks::of( $this->resultAbout( $b, Operation::Authorize, Outcome::Declined, 'stub-ch-' . $b['intent_uuid'], StubGateway::CARD_DECLINED, $a['provider_intent_id'] ) ),
			),
			$a['intent_uuid'],
			$b['intent_uuid']
		);

		$standingOfA = $this->standing( $a['intent_uuid'] );
		$firstWord   = StubWebhooks::of( $this->approvalOf( $b ) );

		$this->assertSame( ReceiptResult::Applied, $this->deliver( $firstWord ) );
		$this->assertSame( array( 'authorized', 'stub-pi-approve-' . $b['intent_uuid'] ), array( $this->intentRow( $b['intent_uuid'] )['status'], $this->intentRow( $b['intent_uuid'] )['provider_intent_id'] ), 'B takes the reference of its first word.' );
		$this->assertSame( $standingOfA, $this->standing( $a['intent_uuid'] ), 'A did not move.' );
	}

	/**
	 * Tests that a delivery naming, by its uuid, a payment that held no provider reference when it was read, and a reference the payment itself takes before the lookup of the reference runs, is applied to that payment, once: its receipt says applied, and a redelivery is answered from the receipt.
	 *
	 * The reference lookup finds the payment of the uuid holding the reference: the same payment, so not another's.
	 *
	 * @since 0.2.0
	 */
	public function test_a_delivery_whose_reference_the_payment_takes_before_the_lookup_is_applied_once(): void {
		$b = $this->placed( StubGateway::THROW, 'attempt-b' );

		$this->assertNull( $b['provider_intent_id'], 'B holds no reference when the delivery reads it.' );

		$approval  = StubWebhooks::of( $this->approvalOf( $b ) );
		$reference = 'stub-pi-approve-' . $b['intent_uuid'];
		$takes     = function ( $query ) use ( $b, $reference, &$takes ) {
			// One shot, on the lookup of the reference: B takes it between the read of B and the lookup.
			if ( is_string( $query ) && str_contains( $query, 'provider_intent_id = \'' . $reference . '\'' ) ) {
				remove_filter( 'query', $takes );

				$this->db->execute( 'UPDATE %i SET provider_intent_id = %s WHERE uuid = %s', $this->table( PaymentTables::INTENTS ), $reference, $b['intent_uuid'] );
			}

			return $query;
		};

		add_filter( 'query', $takes );

		try {
			$decided = $this->deliver( $approval );
		} finally {
			remove_filter( 'query', $takes );
		}

		$this->assertSame( ReceiptResult::Applied, $decided );
		$this->assertSame( array( 'authorized', $reference ), array( $this->intentRow( $b['intent_uuid'] )['status'], $this->intentRow( $b['intent_uuid'] )['provider_intent_id'] ), 'B is authorized under its own reference.' );
		$this->assertCount( 1, $this->ledgerOf( $b['order_id'] ), 'The ledger holds the one result.' );
		$this->assertSame( array( 'applied', $b['intent_uuid'] ), array( $this->receiptOf( $approval->eventId() )['result'] ?? null, $this->receiptOf( $approval->eventId() )['intent_uuid'] ?? null ), 'The receipt says applied, for B.' );

		$standing = $this->standing( $b['intent_uuid'] );

		$this->assertSame( ReceiptResult::Applied, $this->deliver( $approval ), 'A redelivery is answered from its receipt.' );
		$this->assertSame( $standing, $this->standing( $b['intent_uuid'] ), 'Nothing moved.' );
		$this->assertSame( 1, $this->receiptCount() );
	}

	/**
	 * Delivers results whose two ids name two payments, and asserts that neither payment moved, that each was ignored as naming no payment of the store's, its receipt written, and that each delivery's line says the ids disagree without quoting either.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, StubWebhooks> $crossed  The deliveries, by what they cross.
	 * @param string                      ...$uuids The payments' intents.
	 */
	private function assertCrossedIgnored( array $crossed, string ...$uuids ): void {
		$before  = array_map( fn( string $uuid ): array => $this->standing( $uuid ), $uuids );
		$decided = array_map( fn( StubWebhooks $delivery ): string => $this->deliver( $delivery )->value, $crossed );

		$this->assertSame( $before, array_map( fn( string $uuid ): array => $this->standing( $uuid ), $uuids ), 'Neither payment moved.' );
		$this->assertSame( array_fill_keys( array_keys( $crossed ), ReceiptResult::Ignored->value ), $decided );

		foreach ( $crossed as $case => $delivery ) {
			$receipt = $this->receiptOf( $delivery->eventId() );

			$this->assertNotNull( $receipt, "The receipt is written: {$case}." );
			$this->assertSame( array( 'ignored', ReceiptDecision::UNKNOWN_INTENT, null ), array( $receipt['result'], $receipt['result_code'], $receipt['intent_uuid'] ), $case );
		}

		$lines = $this->db->fetchAll( 'SELECT message, context_json FROM %i WHERE machine_code = %s ORDER BY id', $this->table( LogsTable::NAME ), 'payment.webhook_ignored' );

		$this->assertCount( count( $crossed ), $lines );

		foreach ( $lines as $line ) {
			$this->assertStringContainsString( 'the two ids disagree', (string) $line['message'] );
			$this->assertSame( 'ids_disagree', json_decode( (string) $line['context_json'], true )['reason'] ?? null );

			// Every reference the stand-in makes carries its intent's uuid.
			foreach ( $uuids as $id ) {
				$this->assertStringNotContainsString( $id, $line['message'] . $line['context_json'], 'The line quotes neither id.' );
			}
		}
	}

	/**
	 * Reads where a payment stands: its intent's row, its order's row, the order's events and its ledger.
	 *
	 * @since 0.2.0
	 *
	 * @param string $intentUuid The intent.
	 * @return array{intent: array<string, mixed>, order: array<string, mixed>, events: list<array<string, mixed>>, ledger: list<array<string, mixed>>} The rows.
	 */
	private function standing( string $intentUuid ): array {
		$intent  = (array) $this->db->fetchRow( 'SELECT * FROM %i WHERE uuid = %s', $this->table( PaymentTables::INTENTS ), $intentUuid );
		$orderId = (int) $intent['order_id'];

		return array(
			'intent' => $intent,
			'order'  => (array) $this->db->fetchRow( 'SELECT * FROM %i WHERE id = %d', $this->table( OrderTables::ORDERS ), $orderId ),
			'events' => $this->db->fetchAll( 'SELECT * FROM %i WHERE order_id = %d ORDER BY id', $this->table( OrderTables::EVENTS ), $orderId ),
			'ledger' => $this->db->fetchAll( 'SELECT * FROM %i WHERE order_id = %d ORDER BY id', $this->table( PaymentTables::TRANSACTIONS ), $orderId ),
		);
	}

	/**
	 * Returns a payment's intent status, order status and payment status, from where it stands.
	 *
	 * @since 0.2.0
	 *
	 * @param array{intent: array<string, mixed>, order: array<string, mixed>} $standing Where it stands.
	 * @return list<mixed> The three statuses.
	 */
	private static function statuses( array $standing ): array {
		return array( $standing['intent']['status'], $standing['order']['status'], $standing['order']['payment_status'] );
	}
}
