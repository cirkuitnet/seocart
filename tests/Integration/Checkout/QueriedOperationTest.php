<?php
/**
 * Tests that a provider asked where a payment stands may answer with a capture or a refund, and the placement settles what the payment path made of it
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Checkout;

use SEOCart\Checkout\Application\ResumePayment;
use SEOCart\Checkout\Infrastructure\CheckoutTables;
use SEOCart\Checkout\Infrastructure\Jobs\ReconcileStalePlacements;
use SEOCart\Contracts\Payment\GatewayResult;
use SEOCart\Contracts\Payment\Operation;
use SEOCart\Contracts\Payment\Outcome;
use SEOCart\Contracts\Payment\PaymentQuery;
use SEOCart\Order\Infrastructure\OrderTables;
use SEOCart\Payment\Infrastructure\Gateway\StubGateway;
use SEOCart\Payment\Infrastructure\PaymentTables;
use SEOCart\Platform\Events\OutboxTable;
use SEOCart\Tests\Support\Checkout\PlacementSnapshots;
use SEOCart\Tests\Support\Checkout\WebhookTestCase;
use SEOCart\Tests\Support\Payment\StubWebhooks;

/**
 * A provider asked where a payment stands may answer with an operation the placement never asked for, such as a capture made in its dashboard while the shopper was asked to act: the payment path decides it, and the placement settles what it did, for the reconciliation run and for the shopper's resume alike.
 *
 * - A capture or a refund the payment's state cannot take is kept for a person: its ledger row
 *   unapplied, the order flagged and on hold, the intent where it was. Nothing is released: the
 *   units are allocated to the order while a person looks, as for an amount the order does not
 *   have. The run goes on to the next payment in the same pass.
 * - The capture of a payment the gateway was still deciding is a sale: the intent captured, the
 *   order accepted and paid, its units allocated and its cart converted.
 * - A declined refund of a payment that holds no capture is recorded, and moves nothing else.
 * - A capture of a payment whose authorization landed, and settled the placement, while the run
 *   was asking moves the money, and nothing of the placement a second time.
 *
 * Each test compares every row a settlement writes before and after (PlacementSnapshots).
 *
 * Planted violations, each shown red and removed, one at a time:
 * - in SettlePlacement::apply(), refuse an answer that is neither an authorization's nor a void's
 *   with an \InvalidArgumentException: the run stops at the first payment and never asks the
 *   second, and the resume fails;
 * - in SettlePlacement::settlesNothing(), settle a refund the payment path did not keep: the
 *   declined refund releases the hold of a payment that still waits, and reopens its cart;
 * - in SettlePlacement::settlesNothing(), settle a capture of a payment settled before: its units
 *   are allocated a second time, which fails, and the capture is lost.
 *
 * @since 0.2.0
 */
final class QueriedOperationTest extends WebhookTestCase {

	use PlacementSnapshots;

	/**
	 * The intents the gateway was asked about, in order.
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	private array $asked = array();

	/**
	 * Tests that two payments waiting on the shopper, the first of which the provider reports captured, are both asked about in one pass: the capture is kept for a person, and the second is settled as the stand-in answers.
	 *
	 * @since 0.2.0
	 */
	public function test_a_capture_of_a_payment_waiting_on_the_shopper_is_kept_and_the_run_goes_on(): void {
		$first  = $this->placed( StubGateway::REQUIRES_ACTION, 'attempt-first' );
		$second = $this->placed( StubGateway::REQUIRES_ACTION, 'attempt-second' );

		$this->answer( array( $first['intent_uuid'] => $this->resultAbout( $first, Operation::Capture, Outcome::Approved, 'stub-cap-' . $first['intent_uuid'] ) ) );

		$before = $this->takeSnapshot();

		$this->assertNull( $this->kernel->get( ReconcileStalePlacements::class )->handle( array() ) );

		$this->assertSame( array( $first['intent_uuid'], $second['intent_uuid'] ), $this->asked, 'Both payments were asked about, in one pass.' );
		$this->assertSame( array( 'query', 'query' ), array_column( $this->gateway->calls, 'method' ) );
		$this->assertSame( array(), $this->logged( ReconcileStalePlacements::DEFERRED ), 'Nothing was deferred.' );
		$this->assertChanges(
			self::merged(
				self::keptForAPerson( 'first', 'capture' ),
				array( PaymentTables::INTENTS => self::change( array( 'second requires_action' ), array( 'second authorized' ) ) ),
				self::authorized( 'second' )
			),
			$before,
			array(
				'first'  => $first,
				'second' => $second,
			)
		);
		$this->assertSame( array( 'amount_mismatch', 'approved' ), array( $this->kept( $first['order_id'] ), $this->kept( $second['order_id'] ) ) );
	}

	/**
	 * Tests that a payment the gateway was still deciding, which the provider reports captured for its whole amount, is settled as a sale.
	 *
	 * @since 0.2.0
	 */
	public function test_a_capture_of_a_payment_the_gateway_was_deciding_is_a_sale(): void {
		$deciding = $this->placed( StubGateway::PENDING, 'attempt-deciding' );

		$this->assertSame( 'processing', $deciding['status'] );

		$this->answer( array( $deciding['intent_uuid'] => $this->resultAbout( $deciding, Operation::Capture, Outcome::Approved, 'stub-cap-' . $deciding['intent_uuid'] ) ) );

		$before = $this->takeSnapshot();

		$this->kernel->get( ReconcileStalePlacements::class )->handle( array() );

		$this->assertSame( array(), $this->logged( ReconcileStalePlacements::DEFERRED ) );
		$this->assertChanges(
			array(
				PaymentTables::INTENTS      => self::change( array( 'deciding processing' ), array( 'deciding captured' ) ),
				PaymentTables::TRANSACTIONS => self::change( array(), array( 'deciding capture:approved:1' ) ),
				OrderTables::ORDERS         => self::change( array( 'deciding pending_payment/pending/0' ), array( 'deciding processing/paid/0' ) ),
				OrderTables::EVENTS         => self::change( array(), array( 'deciding order:pending_payment>processing:payment_approved', 'deciding payment:pending>paid:payment_captured' ) ),
				OutboxTable::NAME           => self::change( array(), array( 'deciding order_placed', 'deciding order_status_changed', 'deciding payment_captured', 'deciding payment_status_changed', 'deciding stock_allocated' ) ),
			) + self::allocated( 'deciding' ),
			$before,
			array( 'deciding' => $deciding )
		);
		$this->assertSame( 'approved', $this->kept( $deciding['order_id'] ), 'A retry of the placement is told it was accepted.' );
	}

	/**
	 * Tests that a refund the provider reports for a payment waiting on the shopper, which holds no capture, is kept for a person; and that a declined one is recorded and moves nothing else.
	 *
	 * @since 0.2.0
	 */
	public function test_a_refund_of_a_payment_waiting_on_the_shopper_is_kept_and_a_declined_one_moves_nothing(): void {
		$refunded = $this->placed( StubGateway::REQUIRES_ACTION, 'attempt-refunded' );
		$declined = $this->placed( StubGateway::REQUIRES_ACTION, 'attempt-declined' );

		$this->answer(
			array(
				$refunded['intent_uuid'] => $this->resultAbout( $refunded, Operation::Refund, Outcome::Approved, 'stub-re-' . $refunded['intent_uuid'] ),
				$declined['intent_uuid'] => $this->resultAbout( $declined, Operation::Refund, Outcome::Declined, 'stub-re-' . $declined['intent_uuid'], StubGateway::CARD_DECLINED ),
			)
		);

		$before = $this->takeSnapshot();

		$this->kernel->get( ReconcileStalePlacements::class )->handle( array() );

		$this->assertSame( array( $refunded['intent_uuid'], $declined['intent_uuid'] ), $this->asked );
		$this->assertSame( array(), $this->logged( ReconcileStalePlacements::DEFERRED ) );
		$this->assertChanges(
			self::merged(
				self::keptForAPerson( 'refunded', 'refund' ),
				array( PaymentTables::TRANSACTIONS => self::change( array(), array( 'declined refund:declined:1' ) ) )
			),
			$before,
			array(
				'refunded' => $refunded,
				'declined' => $declined,
			)
		);
		$this->assertSame( array( 'amount_mismatch', 'requires_action' ), array( $this->kept( $refunded['order_id'] ), $this->kept( $declined['order_id'] ) ), 'The declined refund leaves the placement waiting for its shopper.' );
	}

	/**
	 * Tests that the shopper's resume of a payment the provider reports captured keeps the capture for a person, and tells the shopper so.
	 *
	 * @since 0.2.0
	 */
	public function test_a_resume_of_a_payment_the_provider_reports_captured_keeps_it_for_a_person(): void {
		$resumed = $this->placed( StubGateway::REQUIRES_ACTION, 'attempt-resumed' );

		$this->answer( array( $resumed['intent_uuid'] => $this->resultAbout( $resumed, Operation::Capture, Outcome::Approved, 'stub-cap-' . $resumed['intent_uuid'] ) ) );

		$before = $this->takeSnapshot();
		$answer = $this->kernel->get( ResumePayment::class )->resume( array( 'intent_uuid' => $resumed['intent_uuid'] ), self::guest() );

		$this->assertSame(
			array(
				'intent_uuid'    => $resumed['intent_uuid'],
				'outcome'        => 'amount_mismatch',
				'status'         => 'on_hold',
				'payment_status' => 'pending',
			),
			$answer
		);
		$this->assertChanges( self::keptForAPerson( 'resumed', 'capture' ), $before, array( 'resumed' => $resumed ) );
	}

	/**
	 * Tests that a capture reported for a payment whose authorization landed while the run was asking, and settled the placement, moves the money and nothing of the placement a second time.
	 *
	 * @since 0.2.0
	 */
	public function test_a_capture_of_a_payment_its_authorization_settled_meanwhile_moves_only_the_money(): void {
		$raced = $this->placed( StubGateway::REQUIRES_ACTION, 'attempt-raced' );

		$this->answer(
			array(
				$raced['intent_uuid'] => function () use ( $raced ): GatewayResult {
					// The shopper's approval is delivered as the run asks, and the provider captured it at once.
					$this->deliver( StubWebhooks::of( $this->resultAbout( $raced, Operation::Authorize, Outcome::Approved, 'stub-ch-' . $raced['intent_uuid'] ) ) );

					return $this->resultAbout( $raced, Operation::Capture, Outcome::Approved, 'stub-cap-' . $raced['intent_uuid'] );
				},
			)
		);

		$before = $this->takeSnapshot();

		$this->kernel->get( ReconcileStalePlacements::class )->handle( array() );

		$this->assertSame( array(), $this->logged( ReconcileStalePlacements::DEFERRED ), 'Nothing was deferred.' );
		$this->assertChanges(
			array(
				PaymentTables::INTENTS      => self::change( array( 'raced requires_action' ), array( 'raced captured' ) ),
				PaymentTables::TRANSACTIONS => self::change( array(), array( 'raced authorize:approved:1', 'raced capture:approved:1' ) ),
				OrderTables::ORDERS         => self::change( array( 'raced pending_payment/pending/0' ), array( 'raced processing/paid/0' ) ),
				OrderTables::EVENTS         => self::change( array(), array( 'raced order:pending_payment>processing:payment_approved', 'raced payment:authorized>paid:payment_captured', 'raced payment:pending>authorized:payment_authorized' ) ),
				OutboxTable::NAME           => self::change( array(), array( 'raced order_placed', 'raced order_status_changed', 'raced payment_authorized', 'raced payment_captured', 'raced payment_status_changed', 'raced payment_status_changed', 'raced stock_allocated' ) ),
			) + self::allocated( 'raced' ),
			$before,
			array( 'raced' => $raced )
		);
		$this->assertSame( 'approved', $this->kept( $raced['order_id'] ) );
	}

	/**
	 * Has the gateway answer the queries about some intents as given, and every other one as the stand-in does; makes every intent old enough for the run to ask about it, and forgets the calls so far.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, GatewayResult|\Closure> $answers The answer about each intent, or what makes it.
	 *
	 * @phpstan-param array<string, GatewayResult|(\Closure(): GatewayResult)> $answers
	 */
	private function answer( array $answers ): void {
		$this->db->execute( 'UPDATE %i SET updated_at = UTC_TIMESTAMP(6) - INTERVAL 11 MINUTE', $this->table( PaymentTables::INTENTS ) );

		$this->gateway->calls   = array();
		$this->gateway->queries = function ( PaymentQuery $query ) use ( $answers ): ?GatewayResult {
			$this->asked[] = $query->intentUuid;
			$answer        = $answers[ $query->intentUuid ] ?? null;

			if ( $answer instanceof \Closure ) {
				return $answer();
			}

			return $answer ?? ( new StubGateway() )->query( $query );
		};
	}

	/**
	 * Reads the outcome the key of an order's placement keeps.
	 *
	 * @since 0.2.0
	 *
	 * @param int $orderId The order.
	 * @return string|null The outcome.
	 */
	private function kept( int $orderId ): ?string {
		$kept = json_decode( (string) $this->db->fetchValue( 'SELECT response_json FROM %i WHERE order_id = %d', $this->table( CheckoutTables::IDEMPOTENCY_KEYS ), $orderId ), true );

		return is_array( $kept ) && is_string( $kept['outcome'] ?? null ) ? $kept['outcome'] : null;
	}
}
