<?php
/**
 * Tests the plugin's own triggers on a site where Action Scheduler uses a store another plugin chose
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Jobs;

use SEOCart\Platform\Jobs\Cli\JobsCommand;
use SEOCart\Platform\Jobs\Job;
use SEOCart\Tests\Support\Jobs\CustomStoreStub;
use SEOCart\Tests\Support\Jobs\JobsTestCase;
use SEOCart\Tests\Support\Jobs\RecordingJob;

/**
 * On a custom store, `wp seocart jobs run`, the admin tick and runDue() run no job, and say why.
 *
 * The plugin's runner claims ids in the library's tables and hands them to the library's
 * runner, which fetches them from the active store; on another store an id could name another
 * plugin's action. The test swaps the library's store for CustomStoreStub, then puts the
 * original back.
 *
 * Planted violations: in JobRunner::runDue(), drop the check of customStore(); the due job
 * runs. In RunnerTriggers::tick(), drop `null !== $this->queue->customStore() ||`; the tick
 * reaches runDue(), which reports the store on every admin request. In
 * ActionSchedulerQueue::customStore(), accept every hybrid store; one whose destination
 * another plugin chose is accepted too.
 *
 * @since 0.1.0
 */
final class CustomStoreTest extends JobsTestCase {

	/**
	 * The library's store before the test swapped it.
	 *
	 * @since 0.1.0
	 *
	 * @var \ActionScheduler_Store|null
	 */
	private ?\ActionScheduler_Store $original = null;

	/**
	 * Puts the library's own store back.
	 *
	 * @since 0.1.0
	 */
	public function tear_down(): void {
		if ( null !== $this->original ) {
			( new \ReflectionProperty( \ActionScheduler_Store::class, 'store' ) )->setValue( null, $this->original );

			$this->original = null;
		}

		parent::tear_down();
	}

	/**
	 * Tests that the library's own store is not a custom one.
	 *
	 * @since 0.1.0
	 */
	public function test_the_librarys_own_store_is_not_a_custom_store(): void {
		$this->assertNull( $this->queue->customStore() );
		$this->assertNull( $this->queue->report()->customStore );
	}

	/**
	 * Tests that on a custom store no trigger of the plugin's runs a job, and the command and the report say why.
	 *
	 * @since 0.1.0
	 */
	public function test_on_a_custom_store_the_plugins_own_triggers_run_no_job_and_say_why(): void {
		$this->queue->enqueue( new Job( RecordingJob::NAME ) );
		$this->useStore( new CustomStoreStub() );

		$this->assertSame( CustomStoreStub::class, $this->queue->customStore() );

		$result = $this->runner->runDue( 30, 'test' );

		$this->assertSame( 0, $result['ran'] );
		$this->assertSame( CustomStoreStub::class, $result['custom_store'] );
		$this->assertSame( array(), RecordingJob::$runs, 'No job runs from an id the custom store may not share.' );
		$this->assertSame( array( 'pending' ), array_column( $this->actions(), 'status' ) );
		$this->assertSame( array( 'jobs.custom_store' ), $this->reportedCodes() );
		$this->assertSame( array( 'store' => CustomStoreStub::class ), $this->reports[0]['context'] );

		$this->reports = array();

		$this->triggers->tick();

		$this->assertSame( array(), RecordingJob::$runs );
		$this->assertSame( array(), $this->reports, 'The tick runs on every admin request, so it stays silent.' );

		$lines   = array();
		$command = new JobsCommand(
			$this->triggers,
			$this->queue,
			static function ( string $line ) use ( &$lines ): void {
				$lines[] = $line;
			}
		);

		$this->assertSame( JobsCommand::EXIT_CUSTOM_STORE, $command->run( array( 'run' ), array() ) );
		$this->assertSame(
			array(
				'Outbox: dispatched 0, retried 0, parked as failed 0.',
				'No job ran: Action Scheduler keeps its actions in a store another plugin chose (' . CustomStoreStub::class . '). WP-Cron runs SEOCart\'s jobs through Action Scheduler.',
			),
			$lines
		);
		$this->assertSame( array(), RecordingJob::$runs );

		$lines = array();

		$this->assertSame( JobsCommand::EXIT_OK, $command->run( array( 'status' ), array() ) );
		$this->assertContains( 'Warning: Action Scheduler keeps its actions in a store another plugin chose (' . CustomStoreStub::class . '). WP-Cron runs SEOCart\'s jobs through Action Scheduler. `wp seocart jobs run` and the admin tick run none, and the counts below come from Action Scheduler\'s own tables, not from that store.', $lines );
		$this->assertSame( CustomStoreStub::class, $this->queue->report()->customStore );
	}

	/**
	 * Tests that a hybrid store is the library's own only while new actions go to the library's table store.
	 *
	 * Another plugin can give the hybrid store a destination of its own through the library's
	 * `action_scheduler/migration_config` filter; ids from the tables could then name its actions.
	 *
	 * @since 0.1.0
	 */
	public function test_a_hybrid_store_whose_destination_another_plugin_chose_is_a_custom_store(): void {
		$this->queue->enqueue( new Job( RecordingJob::NAME ) );
		$this->useStore( new \ActionScheduler_HybridStore() );

		$this->assertNull( $this->queue->customStore(), 'The library builds its hybrid store with its own destination.' );

		add_filter(
			'action_scheduler/migration_config',
			static function (): \Action_Scheduler\Migration\Config {
				$config = new \Action_Scheduler\Migration\Config();

				$config->set_destination_store( new CustomStoreStub() );

				return $config;
			}
		);

		$this->assertSame( CustomStoreStub::class, $this->queue->customStore() );

		$result = $this->runner->runDue( 30, 'test' );

		$this->assertSame( CustomStoreStub::class, $result['custom_store'] );
		$this->assertSame( array(), RecordingJob::$runs );
		$this->assertSame( array( 'pending' ), array_column( $this->actions(), 'status' ) );

		add_filter( 'action_scheduler/migration_config', static fn(): string => 'not a config', 20 );

		$this->assertSame( 'ActionScheduler_HybridStore', $this->queue->customStore(), 'A config that cannot be read proves nothing.' );
	}

	/**
	 * Swaps the library's store, as another plugin's `action_scheduler_store_class` filter would.
	 *
	 * @since 0.1.0
	 *
	 * @param \ActionScheduler_Store $store The store the library uses from now on.
	 */
	private function useStore( \ActionScheduler_Store $store ): void {
		$property       = new \ReflectionProperty( \ActionScheduler_Store::class, 'store' );
		$this->original = $this->original ?? $property->getValue();

		$property->setValue( null, $store );
	}
}
