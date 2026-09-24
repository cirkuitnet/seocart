<?php
/**
 * Tests that the events module costs nothing until something is published
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Events;

use SEOCart\Platform\Database\LockMode;
use SEOCart\Platform\Database\LockService;
use SEOCart\Platform\Events\EventCatalog;
use SEOCart\Platform\Events\HookBridge;
use SEOCart\Platform\Events\Outbox;
use SEOCart\Platform\Events\OutboxDrainer;
use SEOCart\Platform\Events\Publisher;
use SEOCart\Platform\Logging\CorrelationId;
use SEOCart\Tests\Support\Doubles\SequentialIdGenerator;
use SEOCart\Tests\Support\Events\OutboxTestCase;
use SEOCart\Tests\Support\Events\ThingHappened;
use SEOCart\Tests\Support\Events\ThingNoticed;

/**
 * An ordinary request that publishes nothing pays no query and no hook for events.
 *
 * Nothing here is wired into the plugin's boot yet, so the idle-request budgets measured in a
 * child process are untouched; this test measures the module itself, in-process: the query
 * log and a count of every callback in `$wp_filter`.
 *
 * Each test names its planted violation, in src/Platform/Events/.
 *
 * @since 0.1.0
 *
 * @group performance
 */
final class IdleBudgetEventsTest extends OutboxTestCase {

	/**
	 * Constructing the publisher, the drainer, the bridge, the catalog and the correlation id sends nothing and registers nothing.
	 *
	 * Planted violations: `add_action( 'shutdown', '__return_null' )` in OutboxDrainer::__construct()
	 * (one more hook); `$this->outbox->report()` in Publisher::__construct() (one query).
	 *
	 * @since 0.1.0
	 */
	public function test_constructing_the_module_sends_nothing_and_registers_nothing(): void {
		$hooks = self::callbackCount();
		$built = array();

		$log = $this->captureQueries(
			function () use ( &$built ): void {
				$catalog     = new EventCatalog( array( ThingHappened::class, ThingNoticed::class ) );
				$correlation = new CorrelationId( new SequentialIdGenerator() );
				$bridge      = new HookBridge( $this->reporter() );
				$outbox      = new Outbox( $this->db );
				$drainer     = new OutboxDrainer( $this->db, $outbox, $bridge, $catalog, new LockService( $this->db, LockMode::Table ), $correlation, $this->reporter() );
				$publisher   = new Publisher( $this->db, $outbox, $bridge, $catalog, $correlation, static function (): void {} );

				$built = array( $catalog, $correlation, $bridge, $outbox, $drainer, $publisher );
			}
		);

		$this->assertCount( 6, $built );
		$this->assertQueryCount( 0, $log, 'Constructing the events module' );
		$this->assertSame( $hooks, self::callbackCount(), 'Constructing the events module added a hook callback.' );
	}

	/**
	 * A unit of work that publishes nothing costs exactly the transaction's own four statements, and wakes nobody.
	 *
	 * @since 0.1.0
	 */
	public function test_a_unit_of_work_that_publishes_nothing_costs_its_four_statements(): void {
		$log = $this->captureQueries(
			function (): void {
				$this->db->transaction(
					function (): void {
						$this->publisher->publish();
					}
				);
			}
		);

		$this->assertQueryCount( 4, $log, 'A unit of work that publishes nothing' );
		$this->assertSame( 0, $this->wake->calls() );
	}

	/**
	 * With delivery paused, the drain at the end of the request costs no query.
	 *
	 * @since 0.1.0
	 */
	public function test_a_paused_drain_costs_nothing(): void {
		$drainer = new OutboxDrainer( $this->db, $this->outbox, $this->bridge, $this->catalog, new LockService( $this->db, LockMode::Table ), $this->correlation, $this->reporter(), static fn(): bool => true );

		$this->assertQueryCount( 0, $this->captureQueries( fn() => $drainer->drainAtEndOfRequest( array( get_current_blog_id() ) ) ), 'A drain while delivery is paused' );
	}

	/**
	 * Counts every callback registered on every hook.
	 *
	 * @since 0.1.0
	 *
	 * @return int The count.
	 */
	private static function callbackCount(): int {
		global $wp_filter;

		$count = 0;

		foreach ( $wp_filter as $hook ) {
			foreach ( $hook->callbacks as $callbacks ) {
				$count += count( $callbacks );
			}
		}

		return $count;
	}
}
