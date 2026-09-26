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
 * order's lock, the order's read for showing it and for its access check, and a conversion
 * context's read. Each plugin SELECT is explained, printed and judged as the catalog's and the
 * inventory's are; the reference dataset has no orders, so the tables stay under the size at
 * which the rule gates and the run records the plans. And every SELECT the module's source writes
 * must have been sent (ReadInventory), so no read goes unexplained.
 *
 * It runs only when SEOCART_QUERY_PLANS is 1, as `composer test:query-plans` sets it.
 *
 * Planted violation, shown red and removed: leave out the access check's read in exercise(): the
 * run names MysqlOrderRepository's FIND_FOR_ACCESS as a read it did not send.
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

		// The order's conversion context, read back; its id is looked up unrecorded, being the test's read, not the module's.
		$contexts->find( (int) $this->db->fetchValue( 'SELECT conversion_context_id FROM %i WHERE id = %d', $this->table( OrderTables::ORDERS ), $inserted->id ) );
	}
}
