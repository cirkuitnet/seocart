<?php
/**
 * Tests that a provider's references are kept as the provider gave them on both of the ways they arrive, and that no provider value takes an identity the store derives
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Checkout;

use SEOCart\Contracts\Payment\GatewayResult;
use SEOCart\Contracts\Payment\Mode;
use SEOCart\Contracts\Payment\Operation;
use SEOCart\Contracts\Payment\Outcome;
use SEOCart\Contracts\Payment\PaymentGateway;
use SEOCart\Order\Infrastructure\OrderTables;
use SEOCart\Payment\Application\DeliveredValues;
use SEOCart\Payment\Application\PaymentService;
use SEOCart\Payment\Application\RefundService;
use SEOCart\Payment\Domain\Refund\RefundLineRequest;
use SEOCart\Payment\Domain\Refund\RefundRequest;
use SEOCart\Payment\Domain\Webhook\ReceiptResult;
use SEOCart\Payment\Infrastructure\Doctor\PaymentLedgerCheck;
use SEOCart\Payment\Infrastructure\Gateway\StubGateway;
use SEOCart\Platform\Logging\CardNumbers;
use SEOCart\Platform\Logging\LogsTable;
use SEOCart\Tests\Support\Checkout\WebhookTestCase;
use SEOCart\Tests\Support\Doubles\NumberingGateway;
use SEOCart\Tests\Support\Doubles\RecordingGateway;
use SEOCart\Tests\Support\Payment\StubWebhooks;

/**
 * The stand-in names every capture and refund by NUMBER, digits alone that pass for a card number, as a provider numbering its transactions may.
 *
 * Planted violations, each named on its test.
 *
 * @since 0.2.0
 */
final class WebhookIdentifierTest extends WebhookTestCase {

	/**
	 * The number the stand-in names every refund by: a published test card number.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const NUMBER = '4242424242424242';

	/**
	 * A reference that is not an identifier: a slash and a space.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const ODD = 'cap/1 2';

	/**
	 * The stand-in naming every capture and refund, behind the kernel's recording gateway.
	 *
	 * @since 0.2.0
	 *
	 * @var NumberingGateway|null
	 */
	private ?NumberingGateway $numbering = null;

	/**
	 * Binds the gateway of the kernel to the stand-in naming every capture and refund by NUMBER.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, callable> The bindings.
	 */
	protected function overrides(): array {
		return array( PaymentGateway::class => fn(): PaymentGateway => new RecordingGateway( $this->numbering ??= new NumberingGateway( new StubGateway(), self::NUMBER ), $this->db ) ) + parent::overrides();
	}

	/**
	 * Tests that a reference of digits that pass for a card number, which the refund's own answer recorded, is met by the provider's delivery of the same refund: one row, a repeat; and that the receiver logs the event without printing the number.
	 *
	 * The refund is asked through the kernel's own gateways, whose answers pass through the same
	 * canonicalisation a delivery passes through in the receiver.
	 *
	 * Planted violations, each shown red and removed:
	 * - in DeliveredValues::identifier(), refuse what CardNumbers::contains() reads as a card number:
	 *   both the answer and the delivery then keep the refund under a stand-in of the number, and the
	 *   ledger no longer holds the provider's reference;
	 * - in ReceiveWebhook::context(), log the event's id as it is kept: the logger is then handed the
	 *   number to remove.
	 *
	 * @since 0.2.0
	 */
	public function test_a_card_shaped_reference_the_refund_recorded_meets_its_delivery(): void {
		$placed = $this->placed( StubGateway::APPROVE );

		$this->kernel->get( PaymentService::class )->capture( $placed['intent_uuid'], $this->capturer() );

		$line     = (string) $this->db->fetchValue( 'SELECT line_uuid FROM %i WHERE order_id = %d ORDER BY sort_order LIMIT 1', $this->table( OrderTables::LINES ), $placed['order_id'] );
		$refund   = $this->kernel->get( RefundService::class )->refund( new RefundRequest( $placed['order_uuid'], array( new RefundLineRequest( $line, 1 ) ), false, 'customer_return' ), $this->userGranted( RefundService::CAPABILITY ) );
		$currency = (string) $this->intentRow( $placed['intent_uuid'] )['currency'];
		$delivery = StubWebhooks::of( self::stubResult( $placed['intent_uuid'], Operation::Refund, Outcome::Approved, $refund->total->minorUnits(), $currency, self::NUMBER ), $refund->uuid )->withEventId( 'evt_' . self::NUMBER );

		$this->assertSame( array( 'refund:approved:1:' . self::NUMBER ), $this->refundRows( $placed['order_id'] ), 'The refund\'s own answer kept the reference as the provider gave it.' );
		$this->assertSame( ReceiptResult::Duplicate, $this->deliver( $delivery ), 'The delivery met the row the answer wrote.' );
		$this->assertSame( array( 'refund:approved:1:' . self::NUMBER ), $this->refundRows( $placed['order_id'] ), 'One row.' );
		$this->assertNotNull( $this->receiptOf( 'evt_' . self::NUMBER ), 'The event is kept by its id as the provider gave it.' );

		$received = $this->logged( 'payment.webhook_received' );
		$logged   = (string) wp_json_encode( $this->db->fetchAll( 'SELECT machine_code, message, context_json FROM %i', $this->table( LogsTable::NAME ) ) );
		$findings = (string) wp_json_encode( $this->kernel->get( PaymentLedgerCheck::class )->run()->findings );

		$this->assertSame( 'evt_' . CardNumbers::MARKER, end( $received )['event_id'] ?? null, 'The receiver logs the event with the marker in place of the number.' );
		$this->assertStringNotContainsString( self::NUMBER, $logged, 'No log line prints the number.' );
		$this->assertSame( array(), $this->logged( 'logging.card_number_removed' ), 'Every line printed the number through DeliveredValues::printable(), never leaving it to the logger to remove.' );
		$this->assertStringNotContainsString( self::NUMBER, $findings, 'Doctor prints no event id.' );
	}

	/**
	 * Tests that a capture of digits that pass for a card number, which the capture operation recorded, is met by the provider's delivery of the same capture: one row, a repeat, and the order neither flagged nor parked.
	 *
	 * @since 0.2.0
	 */
	public function test_a_card_shaped_capture_the_operation_recorded_meets_its_delivery(): void {
		$placed = $this->placed( StubGateway::APPROVE );

		$this->kernel->get( PaymentService::class )->capture( $placed['intent_uuid'], $this->capturer() );

		$intent   = $this->intentRow( $placed['intent_uuid'] );
		$delivery = StubWebhooks::of( self::stubResult( $placed['intent_uuid'], Operation::Capture, Outcome::Approved, (int) $intent['amount_minor'], (string) $intent['currency'], self::NUMBER ) );

		$this->assertSame( ReceiptResult::Duplicate, $this->deliver( $delivery ), 'The delivery met the row the capture wrote.' );
		$this->assertSame( array( 'capture:approved:1:' . self::NUMBER ), array_values( (array) preg_grep( '/^capture:/', $this->ledgerOf( $placed['order_id'] ) ) ), 'One row, under the provider\'s reference.' );
		$this->assertSame( '0', $this->orderState( $placed['order_id'] )['has_unreconciled_money'], 'Nothing kept for a person.' );
	}

	/**
	 * Tests that a capture whose reference is not an identifier, which the capture operation recorded through the registry's wrapper, is met by the provider's delivery of the same capture: both keep the same stand-in, the delivery is a repeat, and nothing is held for a person.
	 *
	 * Planted violation: in CanonicalGateway, return each money method's answer as the gateway gave
	 * it (bypass DeliveredValues::canonical() in all six): the ledger then holds `cap/1 2` as given,
	 * the delivery keeps its stand-in, meets nothing, and adds a kept row that flags the order.
	 *
	 * @since 0.2.0
	 */
	public function test_a_capture_reference_that_is_no_identifier_meets_its_delivery_through_the_wrapper(): void {
		$this->assertNotNull( $this->numbering, 'The kernel serves the numbering stand-in.' );
		$this->numbering->nameBy( self::ODD );

		$placed = $this->placed( StubGateway::APPROVE );

		$this->kernel->get( PaymentService::class )->capture( $placed['intent_uuid'], $this->capturer() );

		$intent   = $this->intentRow( $placed['intent_uuid'] );
		$delivery = StubWebhooks::of( self::stubResult( $placed['intent_uuid'], Operation::Capture, Outcome::Approved, (int) $intent['amount_minor'], (string) $intent['currency'], self::ODD ) );
		$captures = fn(): array => array_values( (array) preg_grep( '/^capture:/', $this->ledgerOf( $placed['order_id'] ) ) );

		$this->assertSame( array( 'capture:approved:1:' . DeliveredValues::reference( self::ODD ) ), $captures(), 'The capture kept the stand-in a delivery keeps.' );
		$this->assertSame( ReceiptResult::Duplicate, $this->deliver( $delivery ), 'The delivery met the row the capture wrote.' );
		$this->assertSame( array( 'capture:approved:1:' . DeliveredValues::reference( self::ODD ) ), $captures(), 'One row.' );
		$this->assertSame( '0', $this->orderState( $placed['order_id'] )['has_unreconciled_money'], 'Nothing held for a person.' );
	}

	/**
	 * Tests that a signed event whose id is shaped like another event's stand-in is an event of its own: decided on its own, never answered from the other's receipt.
	 *
	 * Planted violation: build the stand-in without the synthetic mark (`DeliveredValues::STAND_IN =
	 * 'sha256:'`): the second event's id is then an identifier equal to the first's stand-in, and it
	 * is answered from the first's receipt.
	 *
	 * @since 0.2.0
	 */
	public function test_an_event_id_shaped_like_a_stand_in_is_an_event_of_its_own(): void {
		$placed = $this->paid();
		$first  = StubWebhooks::of( $this->external( $placed, 500, 'external-re-a' ) )->withEventId( 'evt a' );
		$forged = StubWebhooks::dispute( $placed['intent_uuid'] )->withEventId( DeliveredValues::eventId( 'evt a' ) );

		$this->assertSame( ReceiptResult::Unapplied, $this->deliver( $first ) );
		$this->assertSame( ReceiptResult::Ignored, $this->deliver( $forged ), 'Decided on its own, not answered from the first event\'s receipt.' );
		$this->assertSame( 2, $this->receiptCount() );
	}

	/**
	 * Tests that a provider object shaped like an event's ledger identity, with the synthetic mark or without it, is a reference of its own: its money kept on a row of its own, never met as the event's.
	 *
	 * Planted violations, each shown red and removed:
	 * - build the identity without the synthetic mark (`DeliveredValues::EVENT_PREFIX = 'event:'`):
	 *   the provider's object `event:test:evt_c` then meets the event's row, and its money is lost;
	 * - in DeliveredValues::identifier(), let the synthetic mark through: the provider's object
	 *   `~event:test:evt_c` then meets the event's row.
	 *
	 * @since 0.2.0
	 */
	public function test_a_provider_object_shaped_like_an_event_identity_is_its_own(): void {
		$placed     = $this->paid();
		$deliveries = array(
			StubWebhooks::of( $this->external( $placed, 300, null ) )->withEventId( 'evt_c' ),
			StubWebhooks::of( $this->external( $placed, 300, DeliveredValues::identity( Mode::Test, 'evt_c' ) ) )->withEventId( 'evt_d' ),
			StubWebhooks::of( $this->external( $placed, 300, 'event:test:evt_c' ) )->withEventId( 'evt_e' ),
		);

		foreach ( $deliveries as $delivery ) {
			$this->assertSame( ReceiptResult::Unapplied, $this->deliver( $delivery ), $delivery->eventId() . ' is money kept for a person, never a duplicate.' );
		}

		$this->assertCount( 3, (array) preg_grep( '/^refund:approved:0:/', $this->ledgerOf( $placed['order_id'] ) ), 'Three references, three rows.' );
	}

	/**
	 * Places an order the stand-in approves, and captures it.
	 *
	 * @since 0.2.0
	 *
	 * @return array{order_id: int, order_uuid: string, intent_uuid: string} What placed() returned.
	 */
	private function paid(): array {
		$placed = $this->placed( StubGateway::APPROVE );

		$this->kernel->get( PaymentService::class )->capture( $placed['intent_uuid'], $this->capturer() );

		return $placed;
	}

	/**
	 * Builds a refund the provider made outside the store, of an order's intent.
	 *
	 * @since 0.2.0
	 *
	 * @param array{intent_uuid: string} $placed What placed() returned.
	 * @param int                        $minor  What it gave back, in minor units.
	 * @param string|null                $refundObject The provider's refund object, or null for none.
	 * @return GatewayResult The refund.
	 */
	private function external( array $placed, int $minor, ?string $refundObject ): GatewayResult {
		return self::stubResult( $placed['intent_uuid'], Operation::Refund, Outcome::Approved, $minor, (string) $this->intentRow( $placed['intent_uuid'] )['currency'], $refundObject );
	}

	/**
	 * Lists an order's refund rows, as ledgerOf() names them.
	 *
	 * @since 0.2.0
	 *
	 * @param int $orderId The order.
	 * @return list<string> The rows.
	 */
	private function refundRows( int $orderId ): array {
		return array_values( (array) preg_grep( '/^refund:/', $this->ledgerOf( $orderId ) ) );
	}
}
