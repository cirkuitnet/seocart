<?php
/**
 * Tests that the jobs module costs nothing until a job is queued or run
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Jobs;

use SEOCart\Platform\Jobs\ActionSchedulerQueue;
use SEOCart\Platform\Jobs\Cli\JobsCommand;
use SEOCart\Platform\Jobs\JobHandler;
use SEOCart\Platform\Jobs\JobHandlers;
use SEOCart\Platform\Jobs\JobRunner;
use SEOCart\Platform\Jobs\RunnerTriggers;
use SEOCart\Platform\Logging\CorrelationId;
use SEOCart\Tests\Support\Doubles\SequentialIdGenerator;
use SEOCart\Tests\Support\Jobs\JobsTestCase;

/**
 * Building the whole module sends no query, adds no hook and builds no handler.
 *
 * The kernel binds these classes lazily and adds one hook for the runner; whatever the module
 * did when built would be paid by every request that touches it. The idle request itself is
 * measured, library included, by the idle-budget test.
 *
 * Planted violations: `add_action( 'shutdown', static function (): void {} );` in JobRunner::__construct()
 * (one more hook); `$this->hasWork();` in ActionSchedulerQueue::__construct() (one query);
 * `$this->handler( $name );` at the end of the loop in JobHandlers::__construct() (a handler built).
 *
 * @since 0.1.0
 *
 * @group performance
 */
final class IdleBudgetJobsTest extends JobsTestCase {

	/**
	 * Tests that constructing the production registry, the queue, the runner, the triggers and the command sends nothing, registers nothing and builds nothing.
	 *
	 * @since 0.1.0
	 */
	public function test_building_the_module_sends_nothing_registers_nothing_and_builds_no_handler(): void {
		$hooks = self::callbackCount();
		$built = array();
		$made  = array();

		$log = $this->captureQueries(
			function () use ( &$built, &$made ): void {
				$correlation = new CorrelationId( new SequentialIdGenerator() );
				$handlers    = new JobHandlers(
					JobHandlers::PRODUCTION,
					static function ( string $handlerClass ) use ( &$built ): JobHandler {
						$built[] = $handlerClass;

						throw new \LogicException( 'No handler may be built while the module is built.' );
					}
				);
				$queue       = new ActionSchedulerQueue( $this->db, $this->locks(), $handlers, $correlation, $this->reporter() );
				$runner      = new JobRunner( $handlers, $queue, $correlation, new SequentialIdGenerator(), $this->reporter() );
				$triggers    = new RunnerTriggers( $runner, $queue, $this->drainer(), $this->locks(), $this->reporter() );
				$command     = new JobsCommand( $triggers, $queue, static function ( string $line ): void {} );

				$made = array( $handlers, $queue, $runner, $triggers, $command );
			}
		);

		$this->assertCount( 5, $made );
		$this->assertQueryCount( 0, $log, 'Building the jobs module' );
		$this->assertSame( $hooks, self::callbackCount(), 'Building the jobs module added a hook callback.' );
		$this->assertSame( array(), $built, 'Building the jobs module built a handler.' );
	}

	/**
	 * Counts every callback in the hook table.
	 *
	 * @since 0.1.0
	 *
	 * @return int The count.
	 */
	private static function callbackCount(): int {
		global $wp_filter;

		$count = 0;

		foreach ( (array) $wp_filter as $hook ) {
			foreach ( (array) ( $hook->callbacks ?? array() ) as $callbacks ) {
				$count += count( (array) $callbacks );
			}
		}

		return $count;
	}
}
