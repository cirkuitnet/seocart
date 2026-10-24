<?php
/**
 * Tests that the reconciliation run reports a payment or an order whose settlement throws, and goes on to the next
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Checkout;

use SEOCart\Checkout\Infrastructure\Jobs\ReconcileStalePlacements;
use SEOCart\Contracts\Payment\GatewayResult;
use SEOCart\Contracts\Payment\PaymentQuery;
use SEOCart\Order\Infrastructure\OrderTables;
use SEOCart\Payment\Infrastructure\Gateway\StubGateway;
use SEOCart\Payment\Infrastructure\PaymentTables;
use SEOCart\Platform\Events\OutboxTable;
use SEOCart\Platform\Logging\LogsTable;
use SEOCart\Promotion\Infrastructure\PromotionTables;
use SEOCart\Tests\Support\Checkout\PlacementSnapshots;
use SEOCart\Tests\Support\Checkout\WebhookTestCase;

/**
 * One payment's failure is that payment's alone: whatever its settlement throws, the reconciliation run reports it deferred, with the class of what was thrown and never its message, and goes on to the next payment, and to the orders with nothing due.
 *
 * The failures are a gateway adapter that throws where it should answer, and a statement of the
 * settlement's transaction that throws, which rolls the transaction back: the payment or the order
 * is left as it was, for the next run.
 *
 * Planted violations, each shown red and removed, one at a time:
 * - in ReconcileStalePlacements::settleAnswered(), catch only the gateway's missing answer and a
 *   coded refusal: the first failure stops the run, and the payments after it are never asked;
 * - in ReconcileStalePlacements::settleNothingDue(), catch only a coded refusal: the first order's
 *   failure stops the run, and the order after it waits;
 * - in ReconcileStalePlacements::reportUnexpected(), report the message instead of the class: the
 *   line quotes what was thrown.
 *
 * @since 0.2.0
 */
final class ReconcileFailureTest extends WebhookTestCase {

	use PlacementSnapshots;

	/**
	 * What the messages of the exceptions the test throws carry, which no line may quote.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const MARKER = 'pi_never_logged_0001';

	/**
	 * Tests that a payment whose gateway adapter throws, and one whose settlement throws, are each reported and left as they were, and that the payment after them is asked and settled in the same pass.
	 *
	 * @since 0.2.0
	 */
	public function test_a_payment_whose_settlement_throws_is_reported_and_the_run_goes_on(): void {
		$broken  = $this->placed( StubGateway::REQUIRES_ACTION, 'attempt-broken' );
		$faulty  = $this->placed( StubGateway::REQUIRES_ACTION, 'attempt-faulty' );
		$healthy = $this->placed( StubGateway::REQUIRES_ACTION, 'attempt-healthy' );
		$asked   = array();

		$this->db->execute( 'UPDATE %i SET updated_at = UTC_TIMESTAMP(6) - INTERVAL 11 MINUTE', $this->table( PaymentTables::INTENTS ) );

		$this->gateway->calls   = array();
		$this->gateway->queries = static function ( PaymentQuery $query ) use ( $broken, &$asked ): ?GatewayResult {
			$asked[] = $query->intentUuid;

			if ( $query->intentUuid === $broken['intent_uuid'] ) {
				throw new \UnexpectedValueException( 'A PaymentIntent ' . self::MARKER . ' in a state the adapter does not know.' );
			}

			return ( new StubGateway() )->query( $query );
		};

		// The lock of the faulty payment's intent, the first statement of its settlement's transaction that names it.
		$this->beforeStatement(
			'/WHERE uuid = \'' . preg_quote( $faulty['intent_uuid'], '/' ) . '\' FOR UPDATE$/',
			static function (): never {
				throw new \RuntimeException( 'The statement about ' . self::MARKER . ' failed.' );
			}
		);

		$before = $this->takeSnapshot();

		$this->assertNull( $this->kernel->get( ReconcileStalePlacements::class )->handle( array() ) );

		$this->assertSame( array( $broken['intent_uuid'], $faulty['intent_uuid'], $healthy['intent_uuid'] ), $asked, 'Every payment was asked about, in one pass.' );
		$this->assertSame( array( 'query', 'query', 'query' ), array_column( $this->gateway->calls, 'method' ) );
		$this->assertSame(
			array(
				array(
					'intent_uuid' => $broken['intent_uuid'],
					'gateway_id'  => StubGateway::ID,
					'reason'      => ReconcileStalePlacements::UNEXPECTED,
					'exception'   => \UnexpectedValueException::class,
				),
				array(
					'intent_uuid' => $faulty['intent_uuid'],
					'gateway_id'  => StubGateway::ID,
					'reason'      => ReconcileStalePlacements::UNEXPECTED,
					'exception'   => \RuntimeException::class,
				),
			),
			$this->logged( ReconcileStalePlacements::DEFERRED ),
			'Each failure is reported with its payment and the class of what was thrown.'
		);
		$this->assertChanges(
			self::merged(
				array( PaymentTables::INTENTS => self::change( array( 'healthy requires_action' ), array( 'healthy authorized' ) ) ),
				self::authorized( 'healthy' )
			),
			$before,
			array(
				'broken'  => $broken,
				'faulty'  => $faulty,
				'healthy' => $healthy,
			)
		);
		$this->assertNothingQuoted();
	}

	/**
	 * Tests that an order with nothing due whose settlement throws is reported and left as it was, and that the order after it is settled in the same pass.
	 *
	 * @since 0.2.0
	 */
	public function test_an_order_with_nothing_due_whose_settlement_throws_is_reported_and_the_run_goes_on(): void {
		$faulty  = $this->stoppedWithNothingDue( 'faulty' );
		$healthy = $this->stoppedWithNothingDue( 'healthy' );

		$this->db->execute( 'UPDATE %i SET created_at = UTC_TIMESTAMP(6) - INTERVAL 11 MINUTE', $this->table( OrderTables::ORDERS ) );
		$this->beforeStatement(
			'/WHERE id = ' . $faulty['order_id'] . ' FOR UPDATE$/',
			static function (): never {
				throw new \RuntimeException( 'The statement about ' . self::MARKER . ' failed.' );
			}
		);

		$before = $this->takeSnapshot();

		$this->kernel->get( ReconcileStalePlacements::class )->handle( array() );

		$this->assertSame(
			array(
				array(
					'order_id'  => $faulty['order_id'],
					'reason'    => ReconcileStalePlacements::UNEXPECTED,
					'exception' => \RuntimeException::class,
				),
			),
			$this->logged( ReconcileStalePlacements::DEFERRED )
		);
		$this->assertChanges(
			array(
				OrderTables::ORDERS    => self::change( array( 'healthy pending_payment/unpaid/0' ), array( 'healthy processing/paid/0' ) ),
				OrderTables::EVENTS    => self::change( array(), array( 'healthy order:pending_payment>processing:payment_approved', 'healthy payment:unpaid>paid:nothing_due' ) ),
				OutboxTable::NAME      => self::change( array(), array( 'healthy order_placed', 'healthy order_status_changed', 'healthy payment_status_changed', 'healthy stock_allocated' ) ),
				PromotionTables::USAGE => self::change( array( 'healthy reserved', 'healthy reserved' ), array( 'healthy committed', 'healthy committed' ) ),
			) + self::allocated( 'healthy' ),
			$before,
			array(
				'faulty'  => $faulty,
				'healthy' => $healthy,
			)
		);
		$this->assertNothingQuoted();
	}

	/**
	 * Places an order with nothing to pay, one unit of a new variant with its price and its shipping taken off, and stops it between its two units of work: its second throws at its first statement, the order's lock.
	 *
	 * @since 0.2.0
	 *
	 * @param string $round What sets the order's promotion codes apart.
	 * @return array{order_id: int, variant: int} The order and its variant.
	 */
	private function stoppedWithNothingDue( string $round ): array {
		$variant = $this->sellable();
		$codes   = array( 'FULL-' . strtoupper( $round ), 'SHIP-' . strtoupper( $round ) );

		$this->plantPromotion(
			$codes[0],
			array(
				'effect_kind'                 => 'percent',
				'effect_percent_micropercent' => 100000000,
			)
		);
		$this->plantPromotion(
			$codes[1],
			array(
				'effect_kind'                 => 'free_shipping',
				'effect_percent_micropercent' => null,
			)
		);
		$this->readyCart( array( $variant => 1 ), $codes );
		$this->beforeStatement(
			'/^SELECT id, uuid, order_number, .* FOR UPDATE$/s',
			static function (): never {
				throw new \RuntimeException( 'The placement stops between its two units of work.' );
			}
		);

		try {
			$this->placement->place( $this->placeInput( 'nothing-due-' . $round ), self::guest() );
			$this->fail( 'The placement was not stopped.' );
		} catch ( \RuntimeException $stopped ) {
			$this->assertSame( 'The placement stops between its two units of work.', $stopped->getMessage() );
		}

		$order = (array) $this->db->fetchRow( 'SELECT id, status, payment_status, grand_total_minor FROM %i ORDER BY id DESC LIMIT 1', $this->table( OrderTables::ORDERS ) );

		$this->assertSame( array( 'pending_payment', 'unpaid', '0' ), array( $order['status'], $order['payment_status'], $order['grand_total_minor'] ), 'Only the first unit committed.' );

		return array(
			'order_id' => (int) $order['id'],
			'variant'  => $variant,
		);
	}

	/**
	 * Asserts that no line of the log quotes the message of what the test threw.
	 *
	 * @since 0.2.0
	 */
	private function assertNothingQuoted(): void {
		foreach ( $this->db->fetchAll( 'SELECT message, context_json FROM %i', $this->table( LogsTable::NAME ) ) as $line ) {
			$this->assertStringNotContainsString( self::MARKER, $line['message'] . $line['context_json'], 'A line quotes what was thrown.' );
		}
	}
}
