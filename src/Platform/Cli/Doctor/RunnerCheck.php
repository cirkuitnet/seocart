<?php
/**
 * RunnerCheck: background jobs run, on a queue runtime SEOCart supports, and none has failed
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Cli\Doctor;

use SEOCart\Platform\Jobs\JobQueue;
use SEOCart\Platform\Jobs\JobsReport;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the jobs report and decides whether background work is healthy.
 *
 * Owns one fact: when doctor calls the runner unhealthy. The jobs module's report says what
 * the state is; this check says which states need a person:
 *
 * - no runner has started one of the plugin's jobs within JobsReport::STALE_AFTER_SECONDS, or
 *   none ever did: nothing runs the jobs, so stock releases, mail and retention wait;
 * - the copy of Action Scheduler in control is older than JobsReport::MINIMUM_VERSION, or none
 *   is loaded;
 * - another plugin gave Action Scheduler a store of its own: SEOCart's own triggers then run
 *   none of its jobs, and the counts say nothing about them. Only the store and the runtime
 *   are reported then: the check-in and the failed jobs are read from the library's own
 *   tables, which that store does not use, so they would be false;
 * - a job failed for the last time: one line with the count, and one per failing handler with
 *   its count.
 *
 * Only structure and counts are shown: handler names, the version and where the copy in
 * control comes from, and the store's class, each only if it is an identifier. The last error
 * of a failed job is never shown: it is free text a handler wrote, and `wp seocart jobs
 * status` is where a person reads it.
 *
 * @since 0.1.0
 */
final class RunnerCheck implements Check {

	/**
	 * The check's name.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const NAME = 'runner';

	/**
	 * Where the copy in control comes from, when it can be shown: SEOCart, or a plugin, a must-use plugin or a theme by its directory.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const SOURCE = '/^(?:SEOCart|(?:must-use )?plugin [A-Za-z0-9_.-]{1,100}|theme [A-Za-z0-9_.-]{1,100})$/D';

	/**
	 * A PHP class name, namespaced or not.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const CLASS_NAME = '/^\\\\?[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*){0,10}$/D';

	/**
	 * The queue, which reports on the jobs.
	 *
	 * @since 0.1.0
	 *
	 * @var JobQueue
	 */
	private JobQueue $jobs;

	/**
	 * Creates the check. Sends nothing.
	 *
	 * @since 0.1.0
	 *
	 * @param JobQueue $jobs The queue.
	 */
	public function __construct( JobQueue $jobs ) {
		$this->jobs = $jobs;
	}

	/**
	 * Returns the check's name.
	 *
	 * @since 0.1.0
	 *
	 * @return string `runner`.
	 */
	public function name(): string {
		return self::NAME;
	}

	/**
	 * Reads the jobs report.
	 *
	 * @since 0.1.0
	 *
	 * @return CheckResult Passed when a runner checked in lately, the runtime is supported, the store is the library's own, and no job failed.
	 */
	public function run(): CheckResult {
		$report = $this->jobs->report();

		// On another plugin's store the check-in and the failures, read from the library's own tables, say nothing.
		$findings = null === $report->customStore ? array_merge( self::runtime( $report ), self::checkIn( $report ), self::failures( $report ) ) : self::runtime( $report );

		if ( array() === $findings ) {
			return CheckResult::pass(
				self::NAME,
				sprintf(
					'Action Scheduler %1$s from %2$s is in control; a runner started a job %3$d seconds ago; %4$d due, none failed.',
					CheckResult::identifier( $report->runtimeVersion ),
					self::source( $report->runtimeSource ),
					(int) $report->secondsSinceCheckIn,
					$report->due
				)
			);
		}

		return CheckResult::fail( self::NAME, 'Background jobs need attention.', $findings );
	}

	/**
	 * Lists what is wrong with the queue runtime in control and its store.
	 *
	 * @since 0.1.0
	 *
	 * @param JobsReport $report The report.
	 * @return list<string> The findings.
	 */
	private static function runtime( JobsReport $report ): array {
		$findings = array();

		if ( '' === $report->runtimeVersion ) {
			$findings[] = 'No copy of Action Scheduler is loaded, so no background job runs.';
		} elseif ( ! $report->runtimeSupported ) {
			$findings[] = sprintf( 'The copy of Action Scheduler in control is version %1$s, from %2$s; SEOCart needs %3$s or newer. Update the plugin or theme that ships it.', CheckResult::identifier( $report->runtimeVersion ), self::source( $report->runtimeSource ), JobsReport::MINIMUM_VERSION );
		}

		if ( null !== $report->customStore ) {
			$findings[] = sprintf( 'Action Scheduler keeps its actions in a store another plugin chose (%s): SEOCart\'s own triggers run none of its jobs, and these counts say nothing about them. Only WP-Cron runs them.', 1 === preg_match( self::CLASS_NAME, $report->customStore ) ? $report->customStore : '(a class name that is not one, withheld)' );
		}

		return $findings;
	}

	/**
	 * Lists a runner that has not checked in lately.
	 *
	 * @since 0.1.0
	 *
	 * @param JobsReport $report The report.
	 * @return list<string> The finding, or none.
	 */
	private static function checkIn( JobsReport $report ): array {
		if ( ! $report->runnerStale() ) {
			return array();
		}

		$remedy = 'Check that WP-Cron runs, or run `wp seocart jobs run` from the system cron.';

		if ( null === $report->secondsSinceCheckIn ) {
			return array( 'No runner has ever started one of SEOCart\'s jobs. ' . $remedy );
		}

		return array( sprintf( 'No runner has started one of SEOCart\'s jobs for %1$d seconds, more than %2$d minutes. %3$s', $report->secondsSinceCheckIn, intdiv( JobsReport::STALE_AFTER_SECONDS, 60 ), $remedy ) );
	}

	/**
	 * Lists the failed jobs: their count, and each failing handler with its count.
	 *
	 * @since 0.1.0
	 *
	 * @param JobsReport $report The report.
	 * @return list<string> The findings, or none.
	 */
	private static function failures( JobsReport $report ): array {
		if ( 0 === $report->failed ) {
			return array();
		}

		$findings = array( sprintf( '%1$d of SEOCart\'s jobs failed for the last time; `wp seocart jobs status` shows their last errors.', $report->failed ) );

		foreach ( $report->failingHandlers as $handler ) {
			$findings[] = sprintf( 'handler %1$s: %2$d failed', CheckResult::identifier( $handler['handler'] ), $handler['failures'] );
		}

		return $findings;
	}

	/**
	 * Returns where the copy in control comes from, fit to print only if it names SEOCart, a plugin, a must-use plugin or a theme.
	 *
	 * @since 0.1.0
	 *
	 * @param string $source As the report gives it; a path when the copy lives anywhere else.
	 * @return string The source, or a placeholder that says it was withheld.
	 */
	private static function source( string $source ): string {
		return 1 === preg_match( self::SOURCE, $source ) ? $source : '(a path, withheld)';
	}
}
