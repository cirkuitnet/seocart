<?php
/**
 * Tests a shopper's approval and the end of their time to act landing together, in either order, on two connections
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Checkout;

use SEOCart\Checkout\Application\SettlePlacement;
use SEOCart\Checkout\Domain\PlacementOutcome;
use SEOCart\Checkout\Infrastructure\CheckoutTables;
use SEOCart\Order\Infrastructure\OrderTables;
use SEOCart\Payment\Application\PaymentService;
use SEOCart\Payment\Domain\VoidReason;
use SEOCart\Payment\Infrastructure\Gateway\StubGateway;
use SEOCart\Payment\Infrastructure\MysqlPaymentRepository;
use SEOCart\Payment\Infrastructure\PaymentTables;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Tests\Support\Checkout\PlacementTestCase;
use SEOCart\Tests\Support\SecondConnection;

/**
 * The provider approves the shopper as the store voids the payment because their time to act ran out: whichever lands first under the intent's lock, the money and the order end in one known state, and nothing is released that a person may need.
 *
 * Side A runs in this process; side B is a probe, a process of its own with its own connection.
 * The barrier is A's lock of the order, after A has locked the intent: B is started there, and A
 * goes on only once the server shows B waiting for the intent's lock. Never a pause.
 *
 * - The approval first (A), the void after it (B): B asked the provider to cancel before A
 *   committed, so the provider cancelled; once A commits, B's void is applied to an authorized
 *   intent. The order is parked for a person, its units kept: one authorization row, one void row.
 * - The void first (A), the approval after it (B): the approval is older than the cancellation,
 *   which the ledger holds: it is stale, and writes nothing. The order is cancelled, everything it
 *   held given back.
 *
 * Planted violations, each shown red and removed:
 * - in PaymentService::voided(), leave an accepted order whose void nobody asked for as it is:
 *   it stays accepted with no flag, and a person is never told;
 * - in IntentTransitions::STALE_APPROVALS, drop `voided` for an authorization: the late approval
 *   is kept for a person, an unapplied row written and the cancelled order flagged.
 *
 * @group concurrency
 *
 * @since 0.2.0
 */
final class LateApprovalTest extends PlacementTestCase {

	/**
	 * A's lock of the order, which a payment takes right after the intent's.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const ORDER_LOCK = '/^SELECT id, uuid, order_number, .* FOR UPDATE$/s';

	/**
	 * The process-list state of the intent's lock while it waits for the row: the read is by a unique key, a constant row the optimizer reads while it plans, so it waits in `statistics` (MySQL 8.4, measured).
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const WAITING_READ = 'statistics';

	/**
	 * Tests the approval first, then the void: the void applied to an authorized intent, the order parked with its units.
	 *
	 * @since 0.2.0
	 */
	public function test_an_approval_before_the_void_parks_the_order_with_its_units(): void {
		list( $order, $intent, $variant ) = $this->placeActing();

		$payments = $this->kernel->get( PaymentService::class );
		$approval = $payments->queryGateway( $payments->intentRef( $intent ) ?? self::fail( 'No intent.' ) ) ?? self::fail( 'No approval.' );
		$void     = null;

		$this->beforeStatement(
			self::ORDER_LOCK,
			function () use ( $order, $intent, &$void ): void {
				$void = $this->startPaymentProbe( 'window-end', array( 'order_uuid' => $order ) );

				$this->awaitProbeSending( $void, $this->lockIntent( $intent ), self::WAITING_READ );
			}
		);

		$settled = $this->kernel->get( SettlePlacement::class )->apply( $approval, self::guest() );
		$report  = $void?->finish() ?? array();
		$b       = $this->secondConnection();

		$this->assertSame( PlacementOutcome::Approved, $settled->outcome );
		$this->assertSame( array( 'outcome' => 'voided' ), array_intersect_key( $report, array( 'outcome' => true ) ), (string) wp_json_encode( $report ) );
		$this->assertSame( 'voided', $this->committedIntent( $b, $intent ) );
		$this->assertSame( array( 'on_hold', 'voided', 1 ), array_values( array_intersect_key( $this->committedOrder( $b, $order ) ?? array(), array_flip( array( 'status', 'payment_status', 'has_unreconciled_money' ) ) ) ) );
		$this->assertSame( array( 5, 1, 0 ), $this->committedStock( $b, $variant ), 'The order keeps its units for a person.' );
		$this->assertSame( 'authorize:approved:1,void:approved:1', $this->committedLedger( $b, $order ), 'One authorization, one void.' );
		$this->assertSame( 'voided', $this->kept( $b, $order ) );
	}

	/**
	 * Tests the void first, then the approval: the approval is stale and writes nothing, and the order is cancelled with everything given back.
	 *
	 * @since 0.2.0
	 */
	public function test_an_approval_after_the_void_is_stale(): void {
		list( $order, $intent, $variant ) = $this->placeActing();

		$store    = Actor::system( 'reconciliation', 0 );
		$void     = $this->kernel->get( PaymentService::class )->askVoid( $intent, $store, VoidReason::ActionWindowEnded );
		$approval = null;

		$this->beforeStatement(
			self::ORDER_LOCK,
			function () use ( $order, $intent, &$approval ): void {
				$approval = $this->startSettlement( $order );

				$this->awaitProbeSending( $approval, $this->lockIntent( $intent ), self::WAITING_READ );
			}
		);

		$settled = $this->kernel->get( SettlePlacement::class )->apply( $void, $store, VoidReason::ActionWindowEnded );
		$report  = $approval?->finish() ?? array();
		$b       = $this->secondConnection();

		$this->assertSame( PlacementOutcome::Voided, $settled->outcome );
		$this->assertSame( array( 'outcome' => 'duplicate' ), array_intersect_key( $report, array( 'outcome' => true ) ), (string) wp_json_encode( $report ) );
		$this->assertSame( 'voided', $this->committedIntent( $b, $intent ) );
		$this->assertSame( array( 'cancelled', 'voided', 0 ), array_values( array_intersect_key( $this->committedOrder( $b, $order ) ?? array(), array_flip( array( 'status', 'payment_status', 'has_unreconciled_money' ) ) ) ) );
		$this->assertSame( array( 5, 0, 0 ), $this->committedStock( $b, $variant ), 'Everything the order held is given back.' );
		$this->assertSame( 'void:approved:1', $this->committedLedger( $b, $order ), 'The stale approval wrote nothing.' );
		$this->assertSame( 'voided', $this->kept( $b, $order ) );
	}

	/**
	 * Places an order for one unit whose payment the stub asks the shopper to act on.
	 *
	 * @since 0.2.0
	 *
	 * @return array{0: string, 1: string, 2: int} The order's uuid, the intent's uuid and the variant.
	 */
	private function placeActing(): array {
		$variant = $this->sellable();

		$this->readyCart( array( $variant => 1 ) );

		$answer = $this->placement->place( $this->placeInput( 'acting-' . $variant, StubGateway::REQUIRES_ACTION ), self::guest() );
		$intent = (string) $this->db->fetchValue( 'SELECT i.uuid FROM %i i JOIN %i o ON o.id = i.order_id WHERE o.uuid = %s', $this->table( PaymentTables::INTENTS ), $this->table( OrderTables::ORDERS ), $answer['order_uuid'] );

		$this->assertSame( 'requires_action', $answer['outcome'] );

		return array( (string) $answer['order_uuid'], $intent, $variant );
	}

	/**
	 * Returns the intent's lock as the server shows it while a probe sends it: the payment module's statement, expanded.
	 *
	 * @since 0.2.0
	 *
	 * @param string $intentUuid The intent.
	 * @return string The statement.
	 */
	private function lockIntent( string $intentUuid ): string {
		global $wpdb;

		list( $sql, $arguments ) = MysqlPaymentRepository::expand( MysqlPaymentRepository::LOCK_INTENT, array( $intentUuid ), fn( string $name ): string => $this->table( $name ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The statement is the module's constant, expanded; this is its prepare step.
		return $wpdb->remove_placeholder_escape( (string) $wpdb->prepare( $sql, ...$arguments ) );
	}

	/**
	 * Reads an intent's committed state, as connection B sees it.
	 *
	 * @since 0.2.0
	 *
	 * @param SecondConnection $b          Connection B.
	 * @param string           $intentUuid The intent.
	 * @return string|null The state.
	 */
	private function committedIntent( SecondConnection $b, string $intentUuid ): ?string {
		return $b->fetchValue( sprintf( "SELECT status FROM `%s` WHERE uuid = '%s'", $this->table( PaymentTables::INTENTS ), $intentUuid ) );
	}

	/**
	 * Reads an order's committed ledger rows, as connection B sees them: each as operation, result and whether it was applied, in order.
	 *
	 * @since 0.2.0
	 *
	 * @param SecondConnection $b         Connection B.
	 * @param string           $orderUuid The order.
	 * @return string|null The rows, comma-separated.
	 */
	private function committedLedger( SecondConnection $b, string $orderUuid ): ?string {
		return $b->fetchValue( sprintf( "SELECT GROUP_CONCAT( CONCAT_WS( ':', t.operation, t.result, t.applied ) ORDER BY t.id ) FROM `%s` t JOIN `%s` o ON o.id = t.order_id WHERE o.uuid = '%s'", $this->table( PaymentTables::TRANSACTIONS ), $this->table( OrderTables::ORDERS ), $orderUuid ) );
	}

	/**
	 * Reads the outcome the key of an order's placement keeps, as connection B sees it.
	 *
	 * @since 0.2.0
	 *
	 * @param SecondConnection $b         Connection B.
	 * @param string           $orderUuid The order.
	 * @return string|null The outcome.
	 */
	private function kept( SecondConnection $b, string $orderUuid ): ?string {
		return $b->fetchValue( sprintf( "SELECT JSON_UNQUOTE( JSON_EXTRACT( k.response_json, '$.outcome' ) ) FROM `%s` k JOIN `%s` o ON o.id = k.order_id WHERE o.uuid = '%s'", $this->table( CheckoutTables::IDEMPOTENCY_KEYS ), $this->table( OrderTables::ORDERS ), $orderUuid ) );
	}
}
