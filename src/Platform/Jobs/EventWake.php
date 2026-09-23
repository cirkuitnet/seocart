<?php
/**
 * EventWake: the end of a request that published events, without making its client wait
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Jobs;

use SEOCart\Platform\Events\OutboxDrainer;
use SEOCart\Platform\Jobs\Handlers\OutboxCatchUp;
use SEOCart\Support\IdGenerator;

defined( 'ABSPATH' ) || exit;

/**
 * The publisher's wake: ends the response, then drains; or, where the response cannot be ended early, queues the drain as a job.
 *
 * Owns one fact: that a request which publishes events never waits for their delivery.
 * Publisher calls the wake after each commit that stored events. The wake notes the site and,
 * the first time in the process, adds atShutdown() to WordPress's `shutdown` action at the
 * last priority, after core has flushed the output buffers at priority 1. No query and no I/O
 * at commit time: the rows are already committed, and any later drain delivers them.
 *
 * At shutdown, where the server can end the response while the script keeps running (PHP-FPM's
 * fastcgi_finish_request(), LiteSpeed's litespeed_finish_request()), the wake flushes what
 * output is left, ends the response, and only then drains every site that published, within
 * the shutdown bounds: a 2-second budget the sites share, and no pruning. The client has its
 * whole response before the first listener runs. Where the server cannot (mod_php, CGI, the
 * command line), the wake does not drain inline: it queues one `outbox.catch_up` job per site
 * that published, to run at once, keyed to this request's wake, and the job runner delivers
 * the events (WP-Cron, `wp seocart jobs run`, or the admin tick of a later request). The
 * admin tick of this same request runs no job (handedOff()): it runs after the wake, before
 * the response has ended, and would deliver inline what the wake handed off.
 *
 * Ending the response early comes with a rule: nothing may write output, headers or cookies
 * after WordPress's `shutdown` action has run at PHP_INT_MAX. A callback added there later, or
 * a PHP shutdown function registered later, writes to a response the client already has, and
 * what it writes is lost. A site that needs such late output returns false from the
 * `seocart_end_response_early` filter; its requests then hand their delivery to the job runner,
 * as on a server that cannot end a response early.
 *
 * After a fatal error in the request the wake does nothing; the recurring catch-up job
 * delivers the rows later. Nothing is thrown at shutdown: a failure to end the response is
 * reported (`jobs.wake_failed`) and the job is queued instead; a failure to queue it is
 * reported the same way, and the recurring catch-up job delivers the rows; a failure of the
 * drain is reported by the drainer.
 *
 * @since 0.1.0
 */
final class EventWake {

	/**
	 * How the key of a queued catch-up job starts; a fresh id per request follows.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const KEY_PREFIX = 'wake:';

	/**
	 * The functions that end the response while the script keeps running, in the order they are looked for.
	 *
	 * @since 0.1.0
	 *
	 * @var string[]
	 */
	private const FINISH_FUNCTIONS = array( 'fastcgi_finish_request', 'litespeed_finish_request' );

	/**
	 * Drains the sites that published, once the response has ended.
	 *
	 * @since 0.1.0
	 *
	 * @var OutboxDrainer
	 */
	private OutboxDrainer $drainer;

	/**
	 * Queues the catch-up job where the response cannot end early.
	 *
	 * @since 0.1.0
	 *
	 * @var JobQueue
	 */
	private JobQueue $queue;

	/**
	 * Mints the id in the queued job's key.
	 *
	 * @since 0.1.0
	 *
	 * @var IdGenerator
	 */
	private IdGenerator $ids;

	/**
	 * Receives a report code and its context.
	 *
	 * @since 0.1.0
	 *
	 * @var callable(string, array<string, mixed>): void
	 */
	private $report;

	/**
	 * Ends the response and says whether it could.
	 *
	 * @since 0.1.0
	 *
	 * @var callable(): bool
	 */
	private $endResponse;

	/**
	 * The sites whose requests published events since the wake last ran, as keys.
	 *
	 * @since 0.1.0
	 *
	 * @var array<int, true>
	 */
	private array $sites = array();

	/**
	 * Whether this request queued the catch-up job instead of delivering its events.
	 *
	 * @since 0.1.0
	 *
	 * @var bool
	 */
	private bool $handedOff = false;

	/**
	 * Whether atShutdown() is added to the `shutdown` action.
	 *
	 * @since 0.1.0
	 *
	 * @var bool
	 */
	private bool $registered = false;

	/**
	 * Creates the wake. Sends nothing and registers nothing.
	 *
	 * @since 0.1.0
	 *
	 * @param OutboxDrainer $drainer     Drains the sites that published.
	 * @param JobQueue      $queue       Queues the catch-up job.
	 * @param IdGenerator   $ids         Mints the id in the job's key.
	 * @param callable      $report      Receives a report code (string) and its context (array).
	 * @param callable|null $endResponse Optional. Ends the response and returns whether it could
	 *                                   (bool). Default null, which is endResponse().
	 */
	public function __construct( OutboxDrainer $drainer, JobQueue $queue, IdGenerator $ids, callable $report, ?callable $endResponse = null ) {
		$this->drainer     = $drainer;
		$this->queue       = $queue;
		$this->ids         = $ids;
		$this->report      = $report;
		$this->endResponse = $endResponse ?? array( self::class, 'endResponse' );
	}

	/**
	 * Wakes delivery for the end of this request. Publisher calls it after a commit that stored events.
	 *
	 * Notes the current site and, the first time in the process, adds atShutdown() to the
	 * `shutdown` action. No query, no I/O.
	 *
	 * @since 0.1.0
	 */
	public function __invoke(): void {
		$this->sites[ get_current_blog_id() ] = true;

		if ( $this->registered ) {
			return;
		}

		$this->registered = true;

		add_action( 'shutdown', array( $this, 'atShutdown' ), PHP_INT_MAX, 0 );
	}

	/**
	 * Delivers, or hands to the job runner, the events of every site that published. Added to `shutdown` by the wake.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed>|null $error Optional. The last error, as error_get_last() describes it.
	 *                                         Default null, which reads error_get_last().
	 */
	public function atShutdown( ?array $error = null ): void {
		$sites       = array_keys( $this->sites );
		$this->sites = array();

		if ( array() === $sites || OutboxDrainer::isFatal( $error ?? error_get_last() ) ) {
			return;
		}

		/**
		 * Filters whether a request that published events ends its response before delivering them.
		 *
		 * Ending it early means the client never waits for a listener, and that nothing written
		 * after WordPress's `shutdown` action has run at PHP_INT_MAX (output, headers, cookies)
		 * reaches the client. Return false on a site where something must write that late: the
		 * request then hands the delivery to the job runner instead, as on a server that cannot
		 * end a response early.
		 *
		 * @since 0.1.0
		 *
		 * @param bool $endEarly Whether to end the response before delivering. Default true.
		 */
		$endEarly = (bool) apply_filters( 'seocart_end_response_early', true );

		try {
			if ( $endEarly && true === ( $this->endResponse )() ) {
				$this->drainer->drainAtEndOfRequest( $sites );

				return;
			}
		} catch ( \Throwable $failure ) {
			// The drain reports its own failures; this is ending the response. The job runner delivers instead.
			$this->reportFailure( get_current_blog_id(), $failure );
		}

		$this->handedOff = true;

		$this->queueCatchUp( $sites );
	}

	/**
	 * Tells whether this request handed its events to the job runner instead of delivering them.
	 *
	 * True once atShutdown() has taken the path for a response that cannot end early. The admin
	 * tick asks it (RunnerTriggers) and then runs no job in this request, since it runs before
	 * the response has ended and would deliver the events inline after all.
	 *
	 * @since 0.1.0
	 *
	 * @return bool True when the catch-up job was queued in this request, or its queueing was tried.
	 */
	public function handedOff(): bool {
		return $this->handedOff;
	}

	/**
	 * Ends the response, where the server can, after flushing what output is left.
	 *
	 * Either function returns false when the response had already ended, for example in
	 * `wp-cron.php`, which ends its own; the client is not waiting then either, so any server
	 * that has one of them counts as able.
	 *
	 * @since 0.1.0
	 *
	 * @return bool True when the client no longer waits for the script; false where the server
	 *              has no way to end the response early: mod_php, CGI, the command line.
	 */
	public static function endResponse(): bool {
		foreach ( self::FINISH_FUNCTIONS as $finish ) {
			if ( function_exists( $finish ) ) {
				wp_ob_end_flush_all();
				$finish();

				return true;
			}
		}

		return false;
	}

	/**
	 * Queues one catch-up job to run at once on each site, keyed to this request's wake.
	 *
	 * @since 0.1.0
	 *
	 * @param int[] $sites The ids of the sites that published.
	 */
	private function queueCatchUp( array $sites ): void {
		$key = self::KEY_PREFIX . $this->ids->generate();

		foreach ( $sites as $site ) {
			$switched = is_multisite() && get_current_blog_id() !== $site;

			if ( $switched ) {
				switch_to_blog( $site );
			}

			try {
				$this->queue->enqueue( new Job( OutboxCatchUp::name(), array(), $key ) );
			} catch ( \Throwable $failure ) {
				$this->reportFailure( $site, $failure );
			} finally {
				if ( $switched ) {
					restore_current_blog();
				}
			}
		}
	}

	/**
	 * Reports a failure of the wake, swallowing a failure of the reporter itself: this runs at shutdown.
	 *
	 * @since 0.1.0
	 *
	 * @param int        $site    The site the wake was working for.
	 * @param \Throwable $failure What went wrong.
	 */
	private function reportFailure( int $site, \Throwable $failure ): void {
		try {
			( $this->report )(
				ReportCode::WakeFailed->value,
				array(
					'site'      => $site,
					'exception' => get_class( $failure ),
					'message'   => $failure->getMessage(),
				)
			);
		} catch ( \Throwable $ignored ) {
			// A reporter that fails at shutdown has nowhere to report to.
			return;
		}
	}
}
