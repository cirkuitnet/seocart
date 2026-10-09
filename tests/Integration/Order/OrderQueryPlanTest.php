<?php
/**
 * Tests the query plans of the order module's reads
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Order;

use SEOCart\Order\Domain\LockedOrder;
use SEOCart\Order\Infrastructure\MysqlConversionContexts;
use SEOCart\Order\Infrastructure\MysqlOrderRepository;
use SEOCart\Order\Infrastructure\OrderStatements;
use SEOCart\Order\Infrastructure\OrderTables;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Platform\Database\Database;
use SEOCart\Tests\Support\Doubles\SequentialIdGenerator;
use SEOCart\Tests\Support\Order\NewOrders;
use SEOCart\Tests\Support\Order\OrderTestCase;
use SEOCart\Tests\Support\QueryPlan\AllowList;
use SEOCart\Tests\Support\QueryPlan\PlanRecorder;
use SEOCart\Tests\Support\QueryPlan\QueryPlan;
use SEOCart\Tests\Support\QueryPlan\ReadInventory;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- The run counts the rows of the tables it explains, directly and unrecorded.

/**
 * Every SELECT the order module's source writes is sent, explained and judged by the query-plan rule.
 *
 * The order module's reads run over a PlanRecorder: the reads back of placing an order, the
 * order's lock, the order's read for showing it and for its access check, the paged reads doctor's
 * payment check sends, reconciliation's read of the orders with nothing due, and a conversion
 * context's read. Each plugin SELECT is explained, printed and judged as the catalog's and the
 * inventory's are; the reference dataset has no orders, so the tables stay under the size at
 * which the rule gates and the run records the plans. And every SELECT the module's source writes
 * must have been sent (ReadInventory), so no read goes unexplained. Reconciliation's read, which
 * runs every five minutes on a live store, is judged again on a table of planted orders large
 * enough for the rule to gate.
 *
 * It runs only when SEOCART_QUERY_PLANS is 1, as `composer test:query-plans` sets it.
 *
 * The event a refund appends is an INSERT of a SELECT: its SELECT, cut from the statement's own text,
 * is sent on its own, so its plan is judged as every read's is.
 *
 * Planted violations, each shown red and removed: leave out the access check's read in exercise():
 * the run names MysqlOrderRepository's FIND_FOR_ACCESS as a read it did not send; leave out the read
 * inside a refund's event: the run names APPEND_AUDIT's SELECT.
 *
 * @group performance
 *
 * @since 0.1.0
 */
final class OrderQueryPlanTest extends OrderTestCase {

	/**
	 * Skips the test unless the run is switched on.
	 *
	 * @since 0.1.0
	 */
	public function set_up(): void {
		parent::set_up();

		if ( '1' !== getenv( 'SEOCART_QUERY_PLANS' ) ) {
			$this->markTestSkipped( 'The query-plan run is `composer test:query-plans`.' );
		}
	}

	/**
	 * Tests that every read of the order module is sent and keeps the query-plan rule, or is allowed with its reason.
	 *
	 * @since 0.1.0
	 */
	public function test_every_order_read_is_sent_and_keeps_the_rule(): void {
		global $wpdb;

		$allowed  = AllowList::load( dirname( __DIR__, 3 ) . '/' . AllowList::FILE );
		$recorder = PlanRecorder::open();

		try {
			$this->exercise( new Database( $recorder, true, $this->reporter() ) );
		} finally {
			$recorder->close();
		}

		$report   = array();
		$breaking = array();

		foreach ( $recorder->statements() as $statement ) {
			$plan    = QueryPlan::explain( $wpdb, $statement, static fn( string $table ): int => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) ) );
			$verdict = array() === $plan->breaches ? 'ok' : ( isset( $allowed[ $statement->id() ] ) ? 'allowed' : 'BREAKS THE RULE' );

			if ( 'BREAKS THE RULE' === $verdict ) {
				$breaking = array_merge( $breaking, $plan->lines( $verdict ) );
			}

			$report = array_merge( $report, $plan->lines( $verdict ) );
		}

		fwrite( STDOUT, sprintf( "\nThe plans of the order module's %d SELECTs:\n%s\n", count( $recorder->statements() ), implode( "\n", $report ) ) );

		$heads  = ReadInventory::of( dirname( __DIR__, 3 ) . '/src/Order' );
		$tables = array_map( fn( string $name ): string => $this->table( $name ), OrderTables::names() );

		$this->assertSame( array(), ReadInventory::unsent( $heads, $recorder->allSent() ), 'A read the order module\'s source writes was not sent; send it in exercise(), so its plan is judged.' );
		$this->assertSame( array(), ReadInventory::unknown( $heads, $recorder->allSent(), $tables ), 'A read of the order tables came from outside src/Order.' );
		$this->assertSame( array(), $breaking, sprintf( "These order SELECTs break the query-plan rule:\n%s\n", implode( "\n", $breaking ) ) );
	}

	/**
	 * Tests that reconciliation's read of the orders with nothing due keeps the rule on an orders table large enough to be judged, by the `status_created` key, and finds what it looks for.
	 *
	 * The reference dataset has no orders, so the run plants them: a store's worth, most of them
	 * long accepted, one in fifty still pending payment and half of those with nothing to pay, all
	 * placed an hour ago by the database clock. The table is analysed before the read is explained.
	 *
	 * Planted violation: in MysqlOrderRepository::NOTHING_DUE_IN_STATUS, compare `CAST( status AS
	 * CHAR )`: no index serves the read, and the rule names its scan of the orders.
	 *
	 * @since 0.1.0
	 */
	public function test_the_read_of_orders_with_nothing_due_keeps_the_rule_on_a_large_table(): void {
		global $wpdb;

		$ids       = new SequentialIdGenerator( 700000 );
		$template  = $this->db->transaction( fn() => $this->ordersOver( $this->db, $ids )->insert( NewOrders::forTwoLines( 'EUR', 'USD' ), Actor::user( 0 ) ) );
		$planted   = QueryPlan::LARGE_TABLE + 2000;
		$nothingIn = $this->plantOrders( $template->id, $planted );
		$recorder  = PlanRecorder::open();

		$wpdb->query( $wpdb->prepare( 'ANALYZE TABLE %i', $this->table( OrderTables::ORDERS ) ) );

		try {
			$found = ( new MysqlOrderRepository( new OrderStatements( new Database( $recorder, true, $this->reporter() ) ), $ids ) )->pendingNothingDue( 600, 0, 50 );
		} finally {
			$recorder->close();
		}

		$this->assertCount( 1, $recorder->statements() );

		$plan = QueryPlan::explain( $wpdb, $recorder->statements()[0], static fn( string $table ): int => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) ) );

		fwrite( STDOUT, sprintf( "\nThe plan of the read of orders with nothing due, over %d orders:\n%s\n", $planted + 1, implode( "\n", $plan->lines( array() === $plan->breaches ? 'ok' : 'BREAKS THE RULE' ) ) ) );

		$this->assertSame( array(), $plan->breaches, implode( "\n", $plan->breaches ) );
		$this->assertSame( 'status_created', $plan->accesses[0]['key'] ?? null, 'The read is served by the status_created key.' );
		$this->assertGreaterThanOrEqual( QueryPlan::LARGE_TABLE, $plan->accesses[0]['table_rows'], 'The table is large enough for the rule to judge the read.' );
		$this->assertSame( array_slice( $nothingIn, 0, 50 ), $found, 'The first page of the orders still pending payment with nothing to pay, in id order.' );
	}

	/**
	 * Plants orders copied from one, each under its own uuid and number, all placed an hour ago: one in fifty pending payment, and one in a hundred pending with a grand total of zero; the rest accepted.
	 *
	 * @since 0.1.0
	 *
	 * @param int $templateId The order every planted one is copied from.
	 * @param int $count      How many to plant.
	 * @return list<int> The ids of the planted orders pending payment with nothing to pay, in id order.
	 */
	private function plantOrders( int $templateId, int $count ): array {
		$orders   = $this->table( OrderTables::ORDERS );
		$override = array(
			'uuid'              => "CONCAT( '0192f000-0000-7000-8000-', LPAD( n.i, 12, '0' ) )",
			'order_number'      => "CONCAT( 'QP', n.i )",
			'status'            => "IF( MOD( n.i, 50 ) = 0, 'pending_payment', 'processing' )",
			'grand_total_minor' => 'IF( MOD( n.i, 100 ) = 0, 0, o.grand_total_minor )',
			'due_minor'         => 'IF( MOD( n.i, 100 ) = 0, 0, o.due_minor )',
			'created_at'        => 'UTC_TIMESTAMP(6) - INTERVAL 1 HOUR',
		);
		$columns  = array();
		$values   = array();

		foreach ( $this->db->fetchAll( "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME <> 'id' ORDER BY ORDINAL_POSITION", $orders ) as $column ) {
			$name      = (string) $column['COLUMN_NAME'];
			$columns[] = '`' . $name . '`';
			$values[]  = $override[ $name ] ?? 'o.`' . $name . '`';
		}

		$this->db->execute( 'SET SESSION cte_max_recursion_depth = %d', $count + 1 );
		$this->db->execute(
			'INSERT INTO %i ( ' . implode( ', ', $columns ) . ' ) WITH RECURSIVE n ( i ) AS ( SELECT 1 UNION ALL SELECT i + 1 FROM n WHERE i < %d ) SELECT ' . implode( ', ', $values ) . ' FROM n JOIN %i o ON o.id = %d',
			$orders,
			$count,
			$orders,
			$templateId
		);

		return array_map( 'intval', array_column( $this->db->fetchAll( "SELECT id FROM %i WHERE status = 'pending_payment' AND grand_total_minor = 0 ORDER BY id", $orders ), 'id' ) );
	}

	/**
	 * Runs the order module's reads through the Database under test.
	 *
	 * @since 0.1.0
	 *
	 * @param Database $db The Database over the recorder.
	 */
	private function exercise( Database $db ): void {
		$ids        = new SequentialIdGenerator( 600000 );
		$orders     = $this->ordersOver( $db, $ids );
		$repository = new MysqlOrderRepository( new OrderStatements( $db ), $ids );
		$contexts   = new MysqlConversionContexts( new OrderStatements( $db ), $ids );

		// Placing an order reads back the ids of its lines and adjustments.
		$inserted = $db->transaction( static fn() => $orders->insert( NewOrders::forTwoLines( 'EUR', 'USD' ), Actor::user( 0 ) ) );

		// The order's lock, by a transition and by a payment.
		$orders->accept( $inserted->id, Actor::system( 'payment', 3 ) );
		$db->transaction( static fn(): LockedOrder => $orders->lockForPayment( $inserted->id ) );

		// The order's reads by uuid: for showing it, and for its access check.
		$orders->findByUuid( $inserted->uuid );
		$repository->findForAccess( $inserted->uuid );

		// The reads of a placement's settlement and of a refusal that names the order: its lines' stock, its status by id.
		$orders->stockLines( $inserted->id );
		$orders->statusOf( $inserted->id );

		// Reconciliation's read of the orders with nothing due still pending payment.
		$orders->pendingNothingDue( 600, 0, 50 );

		// The order's flag and clearance by its uuid, which a clearance reads back and a settlement answers with; and the
		// order's lock by its uuid, which a clearance takes before it reads the money it clears.
		$repository->reconciliation( $inserted->uuid );
		$db->transaction( static fn(): ?array => $repository->lockReconciliation( $inserted->uuid ) );

		// The reads of doctor's payment check: the payment amounts and the lines' refunded quantities, a page at a time, the flagged orders and the totals drift.
		$repository->paymentAmounts( 0, 500 );
		$repository->refundedQuantities( 0, 500 );
		$repository->unreconciled( 20 );
		$repository->currentTotalsDrift( 20 );

		// A refund's reads of the order: the order with its current totals version, a line, the shipping added up, and their components. The line's uuid is the test's read.
		$repository->findRefundable( $inserted->uuid, array( (string) $this->db->fetchValue( 'SELECT line_uuid FROM %i WHERE order_id = %d ORDER BY sort_order LIMIT 1', $this->table( OrderTables::LINES ), $inserted->id ) ), true );

		// The read inside the event a refund appends, the order's payment status by its primary key: the SELECT of
		// MysqlOrderRepository::APPEND_AUDIT, cut from the statement and sent alone, as the INSERT reads it.
		$audit                    = MysqlOrderRepository::APPEND_AUDIT;
		list( $read, $arguments ) = OrderStatements::expand( substr( $audit, (int) strpos( $audit, 'SELECT ' ) ), array( 'payment', 'refund_recorded', '0199a0b1-c2d3-7e4f-8a5b-6c7d8e9f0a1b', 'user', 1, '', $inserted->id ), fn( string $name ): string => $this->table( $name ) );

		$db->fetchRow( $read, ...$arguments );

		// The order's conversion context, read back; its id is looked up unrecorded, being the test's read, not the module's.
		$contexts->find( (int) $this->db->fetchValue( 'SELECT conversion_context_id FROM %i WHERE id = %d', $this->table( OrderTables::ORDERS ), $inserted->id ) );
	}
}
