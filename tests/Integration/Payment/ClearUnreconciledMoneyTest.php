<?php
/**
 * Tests clearing an order's unreconciled money: the payment's refunds are freed of what landed before, and held back again by what lands after
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Payment;

use SEOCart\Order\Application\OrderError;
use SEOCart\Order\Infrastructure\MysqlOrderRepository;
use SEOCart\Order\Infrastructure\OrderTables;
use SEOCart\Payment\Application\PaymentError;
use SEOCart\Payment\Infrastructure\PaymentTables;
use SEOCart\Platform\Authorization\AuthorizationError;
use SEOCart\Support\Error\CodedException;
use SEOCart\Tests\Support\Payment\RefundOrders;
use SEOCart\Tests\Support\Payment\RefundTestCase;
use SEOCart\Tests\Support\RunningProbe;

/**
 * A payment result the ledger applied to nothing holds the payment's refunds back until a person clears the order's flag; the clearance frees them of every such result written before it, and one written after it holds them back again.
 *
 * The result here is a refund the provider made that no claim asked for, kept on the ledger with
 * `applied = 0` and the order flagged and parked, as money that lands unexpected is kept. The
 * clearance is asked of the order service, as the clearance operation asks it.
 *
 * Two real connections, never a pause: the clearance racing such money is a probe process whose
 * lock of the order waits while connection B, holding the order, writes the result; and money
 * landing between a refund's plain reads and its claim is written by a probe process that a
 * barrier runs to its end just before connection A's claim locks the intent.
 *
 * Planted violations, each shown red and removed:
 *
 * - in RefundService::plan(), read the intent with no clearance (null): the refund after the
 *   clearance is refused `payment.unreconciled` by the plain reads;
 * - in RefundService::claim(), lock the intent with no clearance (null): the same refund is refused
 *   under the lock;
 * - in RefundService::claim(), drop the refusal of money landed since the reads: the refund racing
 *   money that lands is claimed and made;
 * - in MysqlOrderRepository::CLEARANCE, leave out the newest money's time, as the statement was
 *   before: a clearance of money dated after its flag, as a database clock that stepped back
 *   leaves it, lowers the flag while the money still holds the refunds back, for good;
 * - in MysqlPaymentRepository::INSERT_TRANSACTION, date the row by `UTC_TIMESTAMP(6)` alone: money
 *   landing after a clearance dated ahead of the clock, as a clock that stepped back since leaves
 *   it, is dated before the clearance, and the payment refunds with the flag up.
 *
 * The clearance racing money that lands no longer has a plant of one edit: the clearance takes the
 * order's lock before it reads the clock, the row's time or the money's, so each of the three
 * alone dates it after money that landed while it waited. Dated `UTC_TIMESTAMP(6)` alone, as the
 * statement first was, it now stays green.
 *
 * @since 0.2.0
 *
 * @group concurrency
 */
final class ClearUnreconciledMoneyTest extends RefundTestCase {

	/**
	 * The note every clearance of the test writes.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const NOTE = 'The second refund was made in the provider\'s dashboard by mistake; the customer returned it.';

	/**
	 * The clearance running in a process of its own, once the barrier started it.
	 *
	 * @since 0.2.0
	 *
	 * @var RunningProbe|null
	 */
	private ?RunningProbe $probe = null;

	/**
	 * Tests that a clearance whose update waited for the order while money landed for it clears that money too, so the flag and the refunds agree: the flag down, and the payment refunding again.
	 *
	 * Connection B records money that lands unexpected: it locks the intent and the order and,
	 * just before it writes the ledger row, the barrier starts the clearance in a process of its
	 * own and returns once the server shows the clearance's lock of the order waiting. B then
	 * writes the row, flags the order and commits, and the clearance lowers the flag. Had the
	 * clearance been dated before B's row, the row would hold the payment's refunds back with the
	 * flag down, and no clearance could free them: a second one finds no flag to clear.
	 *
	 * @since 0.2.0
	 */
	public function test_a_clearance_waiting_for_money_that_lands_clears_it_too(): void {
		list( $order, $intent ) = $this->placePaid( self::order() );
		list( $tee )            = $this->lineUuids( $order->id );
		list( , $connectionB )  = $this->secondOrders();
		$manager                = $this->manager();
		$lock                   = $this->raw( MysqlOrderRepository::LOCK_RECONCILIATION, $order->uuid );
		$this->probe            = null;

		$this->keepUnappliedRefund( $intent, 500, 'EUR', 'external-re-1' );

		// The barrier: B holds the order; the clearance waits for it; then B writes the money.
		$this->beforeStatement(
			'/^INSERT INTO \S*payment_transactions\b/',
			function () use ( $order, $manager, $lock ): void {
				$this->probe = $this->startReconcileProbe( $order->uuid, self::NOTE, $manager );

				$this->awaitProbeWaiting( $this->probe, $lock, 'statistics' );
			}
		);

		$this->keepUnappliedRefund( $intent, 300, 'EUR', 'external-re-2', $connectionB );

		$this->assertNotNull( $this->probe, 'The clearance never raced the money.' );

		$report  = $this->probe->finish();
		$landed  = (string) $this->db->fetchValue( "SELECT created_at FROM %i WHERE provider_object_id = 'external-re-2'", $this->table( PaymentTables::TRANSACTIONS ) );
		$cleared = (string) $this->db->fetchValue( 'SELECT money_reconciled_at FROM %i WHERE id = %d', $this->table( OrderTables::ORDERS ), $order->id );

		$this->assertArrayHasKey( 'reconciled_at', $report, 'The clearance was refused: ' . wp_json_encode( $report ) );
		$this->assertSame( '0', (string) $this->orderRow( $order->id )['has_unreconciled_money'], 'The clearance ran after the money landed, and lowered the flag.' );

		try {
			$this->refund( $order->uuid, array( $tee => 1 ) );
		} catch ( CodedException $refused ) {
			$this->fail( sprintf( 'With the flag down, the payment refused a refund, %1$s (the money landed at %2$s, the clearance is dated %3$s), and no clearance can free it, as a second one finds no flag.', $refused->errorCode()->value, $landed, $cleared ) );
		}

		$this->assertGreaterThan( $landed, $cleared, 'The clearance is dated after the money it cleared.' );
		$this->assertCount( 1, $this->refundRows( $order->id ), 'With the flag down, the payment took a refund.' );
	}

	/**
	 * Tests that a clearance is dated after the newest money it clears, even money dated after the order's flag, as a database clock that stepped back between the money and its flag leaves it: the flag goes down and the payment refunds again, together.
	 *
	 * The money's ledger row is dated an hour after the order's row last changed, when the flag was
	 * raised for it. Dated by the order's row and the clock alone, the clearance would come before
	 * the money: the flag would go down while the money still held the payment's refunds back, for
	 * good, with nothing flagged for a person to clear and nothing for doctor to report.
	 *
	 * @since 0.2.0
	 */
	public function test_a_clearance_is_dated_after_money_dated_after_its_flag(): void {
		list( $order, $intent ) = $this->placePaid( self::order() );
		list( $tee )            = $this->lineUuids( $order->id );

		$this->keepUnappliedRefund( $intent, 500, 'EUR', 'external-re-1' );

		// The money, dated an hour after its flag.
		$this->db->execute(
			"UPDATE %i transactions JOIN %i orders ON orders.id = transactions.order_id SET transactions.created_at = orders.updated_at + INTERVAL 1 HOUR WHERE transactions.provider_object_id = 'external-re-1'",
			$this->table( PaymentTables::TRANSACTIONS ),
			$this->table( OrderTables::ORDERS )
		);

		$this->clear( $order->uuid );

		$landed  = (string) $this->db->fetchValue( "SELECT created_at FROM %i WHERE provider_object_id = 'external-re-1'", $this->table( PaymentTables::TRANSACTIONS ) );
		$cleared = (array) $this->db->fetchRow( 'SELECT has_unreconciled_money, money_reconciled_at, updated_at FROM %i WHERE id = %d', $this->table( OrderTables::ORDERS ), $order->id );

		$this->assertSame( '0', (string) $cleared['has_unreconciled_money'], 'The flag is down.' );
		$this->assertNull( $this->refusalOf( fn() => $this->refund( $order->uuid, array( $tee => 1 ) ) ), sprintf( 'With the flag down, the payment refunds again: the money is dated %1$s, the clearance %2$s.', $landed, (string) $cleared['money_reconciled_at'] ) );
		$this->assertGreaterThan( $landed, (string) $cleared['money_reconciled_at'], 'The clearance is dated after the money it cleared.' );
		$this->assertSame( (string) $cleared['money_reconciled_at'], (string) $cleared['updated_at'], 'The order\'s row is dated by its clearance.' );
	}

	/**
	 * Tests that money landing after a clearance is dated after it, even when the database clock reads earlier than the clearance, as one that stepped back since the clearance does: the flag goes up and the payment's refunds are held back, together.
	 *
	 * The clearance is moved an hour ahead of the clock, with the order's row, as a clock that
	 * stepped back after the clearance leaves them. Dated by the clock alone, the money landing then
	 * would be dated before the clearance: the order flagged while its payment refunds on, over money
	 * no person has reconciled.
	 *
	 * @since 0.2.0
	 */
	public function test_money_landing_after_a_clearance_dated_ahead_of_the_clock_is_dated_after_it(): void {
		list( $order, $intent ) = $this->placePaid( self::order() );
		list( $tee )            = $this->lineUuids( $order->id );

		$this->keepUnappliedRefund( $intent, 500, 'EUR', 'external-re-1' );
		$this->clear( $order->uuid );

		// The clearance, and the order's row with it, an hour ahead of the clock.
		$this->db->execute( 'UPDATE %i SET money_reconciled_at = money_reconciled_at + INTERVAL 1 HOUR, updated_at = updated_at + INTERVAL 1 HOUR WHERE id = %d', $this->table( OrderTables::ORDERS ), $order->id );

		$this->keepUnappliedRefund( $intent, 300, 'EUR', 'external-re-2' );

		$landed  = (string) $this->db->fetchValue( "SELECT created_at FROM %i WHERE provider_object_id = 'external-re-2'", $this->table( PaymentTables::TRANSACTIONS ) );
		$cleared = (string) $this->db->fetchValue( 'SELECT money_reconciled_at FROM %i WHERE id = %d', $this->table( OrderTables::ORDERS ), $order->id );

		$this->assertSame( '1', (string) $this->orderRow( $order->id )['has_unreconciled_money'], 'The money flags the order again.' );
		$this->assertSame( PaymentError::Unreconciled->value, $this->refusalOf( fn() => $this->refund( $order->uuid, array( $tee => 1 ) ) ), sprintf( 'With the flag up, the money holds the payment\'s refunds back: it is dated %1$s, the clearance %2$s.', $landed, $cleared ) );
		$this->assertGreaterThan( $cleared, $landed, 'The money is dated after the clearance.' );
	}

	/**
	 * Tests that money landed unexpected holds the payment's refunds back, that a clearance frees them and keeps when and why with an event of the order, and that money landing after it holds them back again.
	 *
	 * @since 0.2.0
	 */
	public function test_a_clearance_frees_the_refunds_of_what_landed_before_it_and_not_of_what_lands_after(): void {
		list( $order, $intent ) = $this->placePaid( self::order() );
		list( $tee )            = $this->lineUuids( $order->id );

		$this->keepUnappliedRefund( $intent, 500, 'EUR', 'external-re-1' );

		$this->assertSame( PaymentError::Unreconciled->value, $this->refusalOf( fn() => $this->refund( $order->uuid, array( $tee => 1 ) ) ), 'Money that landed unexpected holds the refunds back.' );
		$this->assertSame( '1', (string) $this->orderRow( $order->id )['has_unreconciled_money'] );

		$answer = $this->clear( $order->uuid );
		$row    = (array) $this->db->fetchRow( 'SELECT has_unreconciled_money, money_reconciled_at, money_reconciliation_note, status FROM %i WHERE id = %d', $this->table( OrderTables::ORDERS ), $order->id );

		$this->assertSame(
			array(
				'order_uuid'             => $order->uuid,
				'has_unreconciled_money' => false,
				'money_reconciled_at'    => str_replace( ' ', 'T', (string) $row['money_reconciled_at'] ) . 'Z',
			),
			$answer,
			'The clearance answers when, as the database clock dated it.'
		);
		$this->assertSame( array( '0', self::NOTE, 'on_hold' ), array( (string) $row['has_unreconciled_money'], $row['money_reconciliation_note'], $row['status'] ), 'The flag is down, the note kept; the order stays where it was parked, for a person to move.' );
		$this->assertSame(
			array( 'payment', 'money_reconciled', $order->uuid, (string) $this->manager()->userId() ),
			array_values( (array) $this->db->fetchRow( 'SELECT machine, reason, reference, actor_id FROM %i WHERE order_id = %d ORDER BY id DESC LIMIT 1', $this->table( OrderTables::EVENTS ), $order->id ) ),
			'The clearance is the order\'s last event, naming the order, with who cleared it.'
		);

		$this->refund( $order->uuid, array( $tee => 1 ) );

		$this->assertCount( 1, $this->refundRows( $order->id ), 'The clearance freed the payment\'s refunds.' );

		$this->keepUnappliedRefund( $intent, 300, 'EUR', 'external-re-2' );

		$this->assertSame( '1', (string) $this->orderRow( $order->id )['has_unreconciled_money'], 'Money landing after the clearance flags the order again.' );
		$this->assertSame( PaymentError::Unreconciled->value, $this->refusalOf( fn() => $this->refund( $order->uuid, array( $tee => 1 ) ) ), 'Money landing after the clearance holds the refunds back again.' );
	}

	/**
	 * Tests that a result dated the same microsecond as the clearance holds the refunds back: a clearance is dated after every result it cleared, so such a result was written after it.
	 *
	 * @since 0.2.0
	 */
	public function test_money_dated_the_same_as_the_clearance_holds_the_refunds_back(): void {
		list( $order, $intent ) = $this->placePaid( self::order() );
		list( $tee )            = $this->lineUuids( $order->id );

		$this->keepUnappliedRefund( $intent, 500, 'EUR', 'external-re-1' );
		$this->clear( $order->uuid );
		$this->keepUnappliedRefund( $intent, 300, 'EUR', 'external-re-2' );

		// The second result, dated the very microsecond of the clearance.
		$this->db->execute(
			"UPDATE %i transactions JOIN %i orders ON orders.id = transactions.order_id SET transactions.created_at = orders.money_reconciled_at WHERE transactions.provider_object_id = 'external-re-2'",
			$this->table( PaymentTables::TRANSACTIONS ),
			$this->table( OrderTables::ORDERS )
		);

		$this->assertSame( PaymentError::Unreconciled->value, $this->refusalOf( fn() => $this->refund( $order->uuid, array( $tee => 1 ) ) ) );
	}

	/**
	 * Tests that money landing between a refund's plain reads and its claim refuses the refund under the intent's lock, after a clearance as before one: it is newer than any clearance the reads saw.
	 *
	 * The barrier runs just before connection A's claim locks the intent: a process of its own
	 * records the money, whole, on its own connection. A's lock then reads it, and A is refused
	 * with nothing claimed or asked.
	 *
	 * @since 0.2.0
	 */
	public function test_money_landing_between_the_reads_and_the_claim_is_refused_under_the_lock(): void {
		list( $order, $intent ) = $this->placePaid( self::order() );
		list( $tee )            = $this->lineUuids( $order->id );

		$this->keepUnappliedRefund( $intent, 500, 'EUR', 'external-re-1' );
		$this->clear( $order->uuid );

		$barrier = $this->beforeStatement(
			'/^SELECT intent\.refunded_minor, intent\.base_refunded_minor, /',
			function () use ( $intent ): void {
				$landing = $this->startLandingProbe( $intent, 300, 'EUR', 'external-re-2' );

				$this->awaitProbeEnd( $landing );
				$this->assertSame( array( 'kept' => true ), $landing->finish(), 'The money landed on connection B.' );
			}
		);

		$this->assertSame( PaymentError::Unreconciled->value, $this->refusalOf( fn() => $this->refund( $order->uuid, array( $tee => 1 ) ) ), 'The money that landed is refused under the lock.' );
		$this->assertTrue( $barrier->fired, 'The money never landed between the reads and the claim.' );
		$this->assertSame( array(), $this->claimRows( $order->id ), 'Nothing was claimed.' );
		$this->assertSame( 0, $this->refundCalls(), 'Nothing was asked of the gateway.' );
	}

	/**
	 * Tests that a second clearance is refused, and so is the clearance of an order not flagged or not there, each with nothing written.
	 *
	 * @since 0.2.0
	 */
	public function test_a_second_clearance_and_one_of_an_order_not_flagged_are_refused(): void {
		list( $order, $intent ) = $this->placePaid( self::order() );
		list( $other )          = $this->placePaid( self::order() );

		$this->keepUnappliedRefund( $intent, 500, 'EUR', 'external-re-1' );
		$this->clear( $order->uuid );

		$events = count( $this->eventsOf( $order->id ) );

		$this->assertSame( OrderError::NotUnreconciled->value, $this->refusalOf( fn() => $this->clear( $order->uuid ) ), 'A second clearance finds no flag.' );
		$this->assertSame( OrderError::NotUnreconciled->value, $this->refusalOf( fn() => $this->clear( $other->uuid ) ), 'An order never flagged has nothing to clear.' );
		$this->assertSame( OrderError::NotFound->value, $this->refusalOf( fn() => $this->clear( '0192a4b3-7c5d-7e8f-9a0b-1c2d3e4f5a6b' ) ) );
		$this->assertCount( $events, $this->eventsOf( $order->id ), 'The second clearance wrote no event.' );
		$this->assertSame( array(), array_values( array_filter( $this->eventsOf( $other->id ), static fn( string $event ): bool => str_ends_with( $event, ':money_reconciled' ) ) ), 'The order never flagged has no clearance event.' );
	}

	/**
	 * Tests that two clearances of one order at once, on two connections, end with one clearance and one refusal: the second waits for the first's row, and then finds no flag.
	 *
	 * The barrier runs just before connection A's clearance writes its event, while it holds the
	 * order's row: it starts B's clearance in a process of its own and returns once the server
	 * shows B's lock of the order waiting.
	 *
	 * @since 0.2.0
	 */
	public function test_two_clearances_at_once_clear_once(): void {
		list( $order, $intent ) = $this->placePaid( self::order() );
		$manager                = $this->manager();
		$lock                   = $this->raw( MysqlOrderRepository::LOCK_RECONCILIATION, $order->uuid );
		$this->probe            = null;

		$this->keepUnappliedRefund( $intent, 500, 'EUR', 'external-re-1' );

		$this->beforeStatement(
			'/^INSERT INTO \S*order_events\b/',
			function () use ( $order, $manager, $lock ): void {
				$this->probe = $this->startReconcileProbe( $order->uuid, 'B', $manager );

				$this->awaitProbeWaiting( $this->probe, $lock, 'statistics' );
			}
		);

		$this->clear( $order->uuid );

		$this->assertNotNull( $this->probe, 'B never raced A.' );
		$this->assertSame( OrderError::NotUnreconciled->value, $this->probe->finish()['refused'] ?? null, 'B found no flag once A committed.' );
		$this->assertSame( self::NOTE, $this->db->fetchValue( 'SELECT money_reconciliation_note FROM %i WHERE id = %d', $this->table( OrderTables::ORDERS ), $order->id ), 'A\'s clearance is the one kept.' );
		$this->assertCount( 1, array_filter( $this->eventsOf( $order->id ), static fn( string $event ): bool => str_ends_with( $event, ':money_reconciled' ) ), 'One clearance event.' );
	}

	/**
	 * Tests that the service refuses, before any statement, a user who may not override what the plugin knows of the money, and a note that says nothing, is too long or holds a card number.
	 *
	 * @since 0.2.0
	 */
	public function test_the_clearance_refuses_before_any_statement(): void {
		list( $order, $intent ) = $this->placePaid( self::order() );

		$this->keepUnappliedRefund( $intent, 500, 'EUR', 'external-re-1' );

		$cases = array(
			'an order agent'            => array( $this->agent(), self::NOTE, AuthorizationError::Denied->value ),
			'a note of spaces'          => array( $this->manager(), '   ', OrderError::ReconciliationNoteRejected->value ),
			'a note with a card number' => array( $this->manager(), 'Refunded to 4111-1111-1111-1111 by hand.', OrderError::ReconciliationNoteRejected->value ),
			'a note of 501 characters'  => array( $this->manager(), str_repeat( 'x', 501 ), OrderError::ReconciliationNoteRejected->value ),
		);

		foreach ( $cases as $case => list( $actor, $note, $code ) ) {
			$sent = $this->captureQueries( fn() => $this->assertSame( $code, $this->refusalOf( fn() => $this->clear( $order->uuid, $actor, $note ) ), $case ) )->matching( self::STATEMENTS );

			$this->assertQueryCount( 0, $sent, $case );
		}

		$this->assertSame( '1', (string) $this->orderRow( $order->id )['has_unreconciled_money'], 'The flag is still up.' );
		$this->assertNull( $this->db->fetchValue( 'SELECT money_reconciliation_note FROM %i WHERE id = %d', $this->table( OrderTables::ORDERS ), $order->id ), 'No note was kept.' );
	}

	/**
	 * Tests that doctor reports money landed unexpected, with its flagged order, until a person clears the order, and then no more, while the ledger keeps the row.
	 *
	 * @since 0.2.0
	 */
	public function test_doctor_reports_unreconciled_money_until_it_is_cleared(): void {
		list( $order, $intent ) = $this->placePaid( self::order() );

		$this->keepUnappliedRefund( $intent, 500, 'EUR', 'external-re-1' );

		$before = $this->ledgerCheck()->run()->findings;

		$this->assertCount( 2, $before, implode( "\n", $before ) );
		$this->assertStringStartsWith( 'Warning: the refund result ', $before[0] );
		$this->assertSame( "Warning: order {$order->uuid} holds money a person must reconcile.", $before[1] );

		$this->clear( $order->uuid );

		$this->assertSame( array(), $this->ledgerCheck()->run()->findings, 'A cleared order is reported no more.' );
		$this->assertCount( 1, array_filter( $this->refundLedgerRows( $order->id ), static fn( array $row ): bool => '0' === (string) $row['applied'] ), 'The ledger keeps the row.' );
	}

	/**
	 * Clears an order's unreconciled money through the order service, as the clearance operation asks.
	 *
	 * @since 0.2.0
	 *
	 * @param string                                     $orderUuid The order.
	 * @param \SEOCart\Platform\Authorization\Actor|null $actor     Optional. Who clears it. Default the manager.
	 * @param string                                     $note      Optional. Why. Default NOTE.
	 * @return array<string, mixed> The answer.
	 */
	private function clear( string $orderUuid, ?\SEOCart\Platform\Authorization\Actor $actor = null, string $note = self::NOTE ): array {
		return $this->ordersOver( $this->db, $this->ids )->clearUnreconciledMoney(
			array(
				'order_uuid' => $orderUuid,
				'note'       => $note,
			),
			$actor ?? $this->manager()
		);
	}

	/**
	 * Runs something and says what refused it.
	 *
	 * @since 0.2.0
	 *
	 * @param callable $run What runs.
	 * @return string|null The refusal's code, or null when nothing refused it.
	 */
	private function refusalOf( callable $run ): ?string {
		try {
			$run();

			return null;
		} catch ( CodedException $refused ) {
			return $refused->errorCode()->value;
		}
	}

	/**
	 * Builds the fixture order: three tees, taxed, shipped.
	 *
	 * @since 0.2.0
	 *
	 * @return \SEOCart\Order\Domain\NewOrder The document.
	 */
	private static function order(): \SEOCart\Order\Domain\NewOrder {
		return RefundOrders::priced( array( RefundOrders::line( 'tee', '12.34', 3, 'standard' ) ) );
	}
}
