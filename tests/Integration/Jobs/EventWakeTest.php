<?php
/**
 * Tests the publisher's wake: the response ends before delivery, or delivery goes to the job runner
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Jobs;

use SEOCart\Platform\Events\EventCatalog;
use SEOCart\Platform\Events\EventEnvelope;
use SEOCart\Platform\Events\HookBridge;
use SEOCart\Platform\Events\Publisher;
use SEOCart\Platform\Jobs\EventWake;
use SEOCart\Platform\Jobs\Handlers\OutboxCatchUp;
use SEOCart\Platform\Jobs\JobHandler;
use SEOCart\Platform\Jobs\RunnerTriggers;
use SEOCart\Tests\Support\Doubles\SequentialIdGenerator;
use SEOCart\Tests\Support\Events\ThingHappened;
use SEOCart\Tests\Support\Events\ThingNoticed;
use SEOCart\Tests\Support\Jobs\JobsTestCase;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- The tests age and read outbox rows directly.

/**
 * EventWake over the real outbox, queue and runner.
 *
 * A test plays one request: it publishes, then calls atShutdown() as WordPress's `shutdown`
 * action would, with a stand-in for ending the response that records when it ran. Each test
 * names its planted violation, in EventWake unless it says otherwise.
 *
 * @since 0.1.0
 */
final class EventWakeTest extends JobsTestCase {

	/**
	 * What happened, in order: the response ending, and each delivery to the listener.
	 *
	 * @since 0.1.0
	 *
	 * @var list<string>
	 */
	private array $order = array();

	/**
	 * Wires the catch-up job and a listener that records each delivery.
	 *
	 * @since 0.1.0
	 */
	public function set_up(): void {
		parent::set_up();

		$this->order = array();

		$this->wire( array( OutboxCatchUp::class ), fn(): JobHandler => new OutboxCatchUp( $this->drainer() ) );

		add_action(
			EventEnvelope::hookFor( ThingHappened::eventName() ),
			function ( ThingHappened $event ): void {
				$this->order[] = 'delivered ' . $event->aggregateId();
			}
		);
	}

	/**
	 * Tests that a wake costs no query, and adds one callback to `shutdown`, after core's flush of the output buffers.
	 *
	 * Planted violation: add atShutdown() at priority 0; it would end the response before core
	 * flushed the output buffers.
	 *
	 * @since 0.1.0
	 */
	public function test_a_wake_costs_no_query_and_runs_after_core_flushes_the_output(): void {
		$wake = $this->wake( static fn(): bool => true );

		$this->assertQueryCount( 0, $this->captureQueries( static fn() => $wake() ), 'The wake' );
		$this->assertQueryCount( 0, $this->captureQueries( static fn() => $wake() ), 'A second wake' );

		$this->assertSame( PHP_INT_MAX, has_action( 'shutdown', array( $wake, 'atShutdown' ) ) );
		$this->assertLessThan( PHP_INT_MAX, has_action( 'shutdown', 'wp_ob_end_flush_all' ), 'Core flushes the output buffers first.' );
	}

	/**
	 * Tests that where the response can end early, it ends before the first listener runs, and the events are delivered.
	 *
	 * Planted violation: in atShutdown(), drain before ending the response.
	 *
	 * @since 0.1.0
	 */
	public function test_where_the_response_can_end_early_it_ends_before_any_listener_runs(): void {
		$wake = $this->wake(
			function (): bool {
				$this->order[] = 'response ended';

				return true;
			}
		);

		$this->publish( $wake, 1 );
		$this->publish( $wake, 2 );

		$wake->atShutdown();

		$this->assertSame( array( 'response ended', 'delivered 1', 'delivered 2' ), $this->order );
		$this->assertSame( array( 'dispatched', 'dispatched' ), $this->outboxStates() );
		$this->assertSame( array(), $this->actions(), 'A request that drains at its end queues no job.' );
	}

	/**
	 * Tests that the end-of-request drain does not prune: a dispatched row past retention stays for the retention job.
	 *
	 * Planted violation: in DrainOptions::shutdown(), prune again.
	 *
	 * @since 0.1.0
	 */
	public function test_the_end_of_request_drain_leaves_pruning_to_the_retention_job(): void {
		$wake = $this->wake( static fn(): bool => true );

		$this->publish( $wake, 1, 2 );

		global $wpdb;

		$wpdb->query( $wpdb->prepare( "UPDATE %i SET state = 'dispatched', dispatched_at = UTC_TIMESTAMP(6) - INTERVAL 30 DAY WHERE aggregate_id = 1", $this->db->table( 'outbox' ) ) );

		$wake->atShutdown();

		$this->assertSame( array( 'delivered 2' ), $this->order );
		$this->assertSame( array( 'dispatched', 'dispatched' ), $this->outboxStates(), 'The dispatched row past retention is still there.' );
	}

	/**
	 * Tests that where the response cannot end early, a request queues one catch-up job, delivers nothing itself, and the runner delivers.
	 *
	 * Planted violations: in atShutdown(), drain instead of queueing the job; the listener runs
	 * in the request. In __invoke(), queue the job at every wake; two commits queue two jobs.
	 *
	 * @since 0.1.0
	 */
	public function test_where_the_response_cannot_end_early_the_request_queues_one_job_and_the_runner_delivers(): void {
		$wake = $this->wake( static fn(): bool => false );

		$this->publish( $wake, 1 );
		$this->publish( $wake, 2 );

		$wake->atShutdown();

		$this->assertSame( array(), $this->order, 'The request delivers nothing itself.' );
		$this->assertSame( array( 'pending', 'pending' ), $this->outboxStates() );

		$actions = $this->actions();

		$this->assertCount( 1, $actions, 'Two commits in one request queue one job.' );
		$this->assertSame( 'pending', $actions[0]['status'] );
		$this->assertLessThanOrEqual( 0, $actions[0]['due_in'], 'The job is due at once.' );
		$this->assertSame( OutboxCatchUp::name(), self::envelopeOf( $actions[0] )->job->handler );
		$this->assertSame( EventWake::KEY_PREFIX . SequentialIdGenerator::nth( 5000 ), self::envelopeOf( $actions[0] )->job->uniqueKey );

		$wake->atShutdown();

		$this->assertCount( 1, $this->actions(), 'A second end of request with nothing published queues nothing.' );

		$this->runner->runDue( 30, 'test' );

		$this->assertSame( array( 'delivered 1', 'delivered 2' ), $this->order, 'The runner delivers.' );
		$this->assertSame( array( 'dispatched', 'dispatched' ), $this->outboxStates() );
		$this->assertSame( array( 'complete' ), array_column( $this->actions(), 'status' ) );

		// The next request that publishes queues its own job.
		$this->publish( $wake, 3 );

		$wake->atShutdown();

		$this->assertSame( array( 'complete', 'pending' ), array_column( $this->actions(), 'status' ) );
		$this->assertSame( EventWake::KEY_PREFIX . SequentialIdGenerator::nth( 5001 ), self::envelopeOf( $this->actions()[1] )->job->uniqueKey );
	}

	/**
	 * Tests that a site can turn ending the response early off: the request then hands its delivery to the runner.
	 *
	 * Planted violation: in atShutdown(), ignore the `seocart_end_response_early` filter; the
	 * response is ended and the listener runs in the request.
	 *
	 * @since 0.1.0
	 */
	public function test_the_end_response_early_filter_sends_delivery_to_the_runner(): void {
		$ended = 0;
		$wake  = $this->wake(
			static function () use ( &$ended ): bool {
				++$ended;

				return true;
			}
		);

		add_filter( 'seocart_end_response_early', '__return_false' );

		$this->publish( $wake, 1 );

		$wake->atShutdown();

		$this->assertSame( 0, $ended, 'The response is not ended early.' );
		$this->assertSame( array(), $this->order, 'Nothing is delivered in the request.' );
		$this->assertTrue( $wake->handedOff() );
		$this->assertCount( 1, $this->actions() );
		$this->assertSame( OutboxCatchUp::name(), self::envelopeOf( $this->actions()[0] )->job->handler );
	}

	/**
	 * Tests that the admin tick runs no job in a request whose events the wake handed to the runner.
	 *
	 * On a server that cannot end a response early, the tick runs after the wake and before the
	 * response has ended; running the catch-up job there would deliver inline what the wake
	 * handed off. A later request's tick runs it.
	 *
	 * Planted violation: in RunnerTriggers::tick(), drop the check of handedOff(); the listener
	 * runs in this request.
	 *
	 * @since 0.1.0
	 */
	public function test_the_admin_tick_leaves_a_handed_off_delivery_to_a_later_request(): void {
		$wake = $this->wake( static fn(): bool => false );

		$this->publish( $wake, 1 );

		$wake->atShutdown();

		$this->assertTrue( $wake->handedOff() );

		( new RunnerTriggers( $this->runner, $this->queue, $this->drainer(), $this->locks(), $this->reporter(), null, array( $wake, 'handedOff' ) ) )->tick();

		$this->assertSame( array(), $this->order, 'The tick of the request that handed off delivers nothing.' );
		$this->assertSame( array( 'pending' ), $this->outboxStates() );

		$later = $this->wake( static fn(): bool => false );

		$this->assertFalse( $later->handedOff(), 'A request that published nothing handed nothing off.' );

		( new RunnerTriggers( $this->runner, $this->queue, $this->drainer(), $this->locks(), $this->reporter(), null, array( $later, 'handedOff' ) ) )->tick();

		$this->assertSame( array( 'delivered 1' ), $this->order, 'A later request\'s tick delivers.' );
	}

	/**
	 * Tests that after a fatal error the wake neither ends the response, nor drains, nor queues.
	 *
	 * Planted violation: in atShutdown(), drop the check for a fatal error.
	 *
	 * @since 0.1.0
	 */
	public function test_after_a_fatal_error_the_wake_does_nothing(): void {
		$ended = 0;
		$wake  = $this->wake(
			static function () use ( &$ended ): bool {
				++$ended;

				return true;
			}
		);

		$this->publish( $wake, 1 );

		$wake->atShutdown(
			array(
				'type'    => E_ERROR,
				'message' => 'Allowed memory size exhausted',
				'file'    => __FILE__,
				'line'    => __LINE__,
			)
		);

		$this->assertSame( 0, $ended );
		$this->assertSame( array(), $this->order );
		$this->assertSame( array(), $this->actions() );
	}

	/**
	 * Tests that the wake never throws at shutdown: a failure to end the response queues the job, a failure to queue it is reported.
	 *
	 * Planted violation: in queueCatchUp(), let the queue's exception through.
	 *
	 * @since 0.1.0
	 */
	public function test_a_failing_wake_reports_and_never_throws(): void {
		$wake = $this->wake(
			static function (): bool {
				throw new \RuntimeException( 'An output buffer callback failed.' );
			}
		);

		$this->publish( $wake, 1 );

		$wake->atShutdown();

		$this->assertSame( array( 'jobs.wake_failed' ), $this->reportedCodes() );
		$this->assertSame( array(), $this->order, 'A response that did not end is not drained.' );
		$this->assertCount( 1, $this->actions(), 'The runner delivers instead.' );

		$this->reports = array();

		$this->publish( $wake, 2 );

		// Queueing inside a transaction is refused; at shutdown that is reported, not thrown.
		$this->db->transaction( static fn() => $wake->atShutdown() );

		$this->assertSame( array( 'jobs.wake_failed', 'jobs.wake_failed' ), $this->reportedCodes() );
		$this->assertSame( 'LogicException', $this->reports[1]['context']['exception'] );
		$this->assertCount( 1, $this->actions() );
	}

	/**
	 * Tests that on the command line the response cannot end early, so the default wake queues the job.
	 *
	 * Planted violation: in endResponse(), return true when neither function exists.
	 *
	 * @since 0.1.0
	 */
	public function test_on_the_command_line_the_response_cannot_end_early(): void {
		if ( function_exists( 'fastcgi_finish_request' ) || function_exists( 'litespeed_finish_request' ) ) {
			$this->markTestSkipped( 'The tests run on the command line.' );
		}

		$this->assertFalse( EventWake::endResponse() );
	}

	/**
	 * Builds a wake over the test's outbox and queue, minting key ids from 5000.
	 *
	 * @since 0.1.0
	 *
	 * @param callable(): bool $endResponse Ends the response and says whether it could.
	 * @return EventWake The wake.
	 */
	private function wake( callable $endResponse ): EventWake {
		return new EventWake( $this->drainer(), $this->queue, new SequentialIdGenerator( 5000 ), $this->reporter(), $endResponse );
	}

	/**
	 * Publishes one ThingHappened per id, in one committed unit of work, waking the wake after the commit.
	 *
	 * @since 0.1.0
	 *
	 * @param EventWake $wake   The wake.
	 * @param int       ...$ids The aggregate ids.
	 */
	private function publish( EventWake $wake, int ...$ids ): void {
		$publisher = new Publisher( $this->db, $this->outbox, new HookBridge( $this->reporter() ), new EventCatalog( array( ThingHappened::class, ThingNoticed::class ) ), $this->correlation, $wake );

		$this->db->transaction(
			static function () use ( $publisher, $ids ): void {
				foreach ( $ids as $id ) {
					$publisher->publish( new ThingHappened( $id ) );
				}
			}
		);
	}

	/**
	 * Lists the outbox's states, in aggregate order.
	 *
	 * @since 0.1.0
	 *
	 * @return list<string> The states.
	 */
	private function outboxStates(): array {
		global $wpdb;

		return array_map( 'strval', $wpdb->get_col( $wpdb->prepare( 'SELECT state FROM %i ORDER BY aggregate_id', $this->db->table( 'outbox' ) ) ) );
	}
}
