<?php
/**
 * JobsCommand: `wp seocart jobs`, which runs the plugin's due jobs and reports on them from the command line
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Jobs\Cli;

use SEOCart\Platform\Database\Exception\DatabaseException;
use SEOCart\Platform\Jobs\JobQueue;
use SEOCart\Platform\Jobs\JobsReport;
use SEOCart\Platform\Jobs\RunnerTriggers;
use SEOCart\Support\Error\ErrorDefinition;

defined( 'ABSPATH' ) || exit;

/**
 * Runs background work on demand, for a system cron, and prints the state of the plugin's jobs.
 *
 * Owns one fact: the command's contract with an operator. `run` drains the outbox and runs the
 * plugin's due jobs within a time budget and exits 0, or 2 when jobs are paused and nothing
 * ran, or 3 when Action Scheduler keeps its actions in a store another plugin chose, so no job
 * could run here (WP-Cron runs them). `status` prints the due, waiting, running and failed
 * counts, how long the oldest due job has waited, the runner's last check-in, the handlers
 * that fail most with their last error, and which copy and version of Action Scheduler is in
 * control, and warns of such a store; it exits 0. A database failure exits 1 with its code and
 * message.
 *
 * On a site where WP-Cron does not fire reliably, a system cron runs it every minute:
 *
 *     * * * * * wp --path=/path/to/wordpress seocart jobs run --quiet
 *
 * It is a maintenance command, not an application operation: it has no REST or Ability twin,
 * so it resolves to no operation definition by design.
 *
 * Output goes through a callable and run() returns the exit code, so tests call run() without
 * WP-CLI. Only __invoke(), which WP-CLI calls, touches the WP_CLI class. The kernel registers
 * the command.
 *
 * @since 0.1.0
 */
final class JobsCommand {

	/**
	 * Exit code: done.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const EXIT_OK = 0;

	/**
	 * Exit code: a database failure, or the command was used wrongly.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const EXIT_FAILED = 1;

	/**
	 * Exit code: jobs are paused; nothing ran.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const EXIT_PAUSED = 2;

	/**
	 * Exit code: Action Scheduler keeps its actions in a store another plugin chose, so no job ran here.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const EXIT_CUSTOM_STORE = 3;

	/**
	 * The triggers the command runs.
	 *
	 * @since 0.1.0
	 *
	 * @var RunnerTriggers
	 */
	private RunnerTriggers $triggers;

	/**
	 * The queue the command reports on.
	 *
	 * @since 0.1.0
	 *
	 * @var JobQueue
	 */
	private JobQueue $queue;

	/**
	 * Prints one line for the operator.
	 *
	 * @since 0.1.0
	 *
	 * @var callable(string): void
	 */
	private $output;

	/**
	 * Creates the command.
	 *
	 * @since 0.1.0
	 *
	 * @param RunnerTriggers         $triggers The triggers.
	 * @param JobQueue               $queue    The queue.
	 * @param callable(string): void $output   Prints one line, for example WP_CLI::log().
	 */
	public function __construct( RunnerTriggers $triggers, JobQueue $queue, callable $output ) {
		$this->triggers = $triggers;
		$this->queue    = $queue;
		$this->output   = $output;
	}

	/**
	 * Runs the plugin's due jobs, or reports on them.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : What to do. `run` drains the event outbox and runs the due jobs; `status` prints the
	 * state of the jobs and of the runner.
	 * ---
	 * options:
	 *   - run
	 *   - status
	 * ---
	 *
	 * [--budget=<seconds>]
	 * : With run, how long to keep starting jobs.
	 * ---
	 * default: 60
	 * ---
	 *
	 * ## EXIT STATUS
	 *
	 * 0 when done, 1 on a database failure, 2 when jobs are paused and nothing ran, 3 when
	 * Action Scheduler uses a store another plugin chose and no job could run here.
	 *
	 * ## EXAMPLES
	 *
	 *     wp seocart jobs run
	 *     wp seocart jobs run --budget=300
	 *     wp seocart jobs status
	 *
	 * @since 0.1.0
	 *
	 * @param string[]                   $args      The action.
	 * @param array<string, string|bool> $assocArgs The options.
	 */
	public function __invoke( array $args, array $assocArgs ): void {
		\WP_CLI::halt( $this->run( $args, $assocArgs ) );
	}

	/**
	 * Runs the command and returns its exit code.
	 *
	 * @since 0.1.0
	 *
	 * @param string[]                   $args      The action: run or status.
	 * @param array<string, string|bool> $assocArgs The options: budget.
	 * @return int One of the EXIT_* constants.
	 */
	public function run( array $args, array $assocArgs ): int {
		try {
			switch ( $args[0] ?? '' ) {
				case 'run':
					return $this->runJobs( self::intOption( $assocArgs, 'budget', RunnerTriggers::COMMAND_BUDGET_SECONDS ) );

				case 'status':
					return $this->status();

				default:
					$this->say( 'Usage: wp seocart jobs <run|status> [--budget=<seconds>]' );

					return self::EXIT_FAILED;
			}
		} catch ( DatabaseException $failure ) {
			$this->say( (string) $failure->errorCode()->value . ': ' . ErrorDefinition::of( $failure->errorCode() )->render( $failure->context() ) );

			return self::EXIT_FAILED;
		}
	}

	/**
	 * Drains the outbox and runs the due jobs.
	 *
	 * @since 0.1.0
	 *
	 * @param int $budgetSeconds How long to keep starting jobs.
	 * @return int One of the EXIT_* constants.
	 */
	private function runJobs( int $budgetSeconds ): int {
		$result = $this->triggers->command( max( 1, $budgetSeconds ) );

		if ( $result['paused'] ) {
			$this->say( 'Background jobs are paused, so nothing ran.' );

			return self::EXIT_PAUSED;
		}

		$drain = $result['drain'];

		if ( null !== $drain ) {
			$this->say(
				null === $drain->skipped
					? sprintf( 'Outbox: dispatched %d, retried %d, parked as failed %d.', $drain->dispatched, $drain->retried, $drain->failed )
					: sprintf( 'Outbox: not drained (%s).', $drain->skipped )
			);
		}

		if ( null !== $result['custom_store'] ) {
			$this->say( 'No job ran: ' . self::customStoreLine( $result['custom_store'] ) );

			return self::EXIT_CUSTOM_STORE;
		}

		$this->say( sprintf( 'Jobs run: %d.', $result['ran'] ) );

		if ( $result['exhausted'] ) {
			$this->say( 'Stopped when the time budget ran out; run the command again to continue.' );
		}

		return self::EXIT_OK;
	}

	/**
	 * Prints the state of the jobs and of the runner.
	 *
	 * @since 0.1.0
	 *
	 * @return int EXIT_OK.
	 */
	private function status(): int {
		$report = $this->queue->report();

		$this->say( self::runtimeLine( $report ) );

		if ( ! $report->runtimeSupported ) {
			$this->say( sprintf( 'Warning: SEOCart needs Action Scheduler %s or newer.', JobsReport::MINIMUM_VERSION ) );
		}

		if ( null !== $report->customStore ) {
			$this->say( 'Warning: ' . self::customStoreLine( $report->customStore ) . ' `wp seocart jobs run` and the admin tick run none, and the counts below come from Action Scheduler\'s own tables, not from that store.' );
		}

		$this->say( sprintf( 'Due: %d%s', $report->due, null === $report->oldestDueSeconds ? '' : sprintf( ' (the oldest has waited %d seconds)', $report->oldestDueSeconds ) ) );
		$this->say( sprintf( 'Pending: %d', $report->pending ) );
		$this->say( sprintf( 'In progress: %d', $report->inProgress ) );
		$this->say( sprintf( 'Failed: %d', $report->failed ) );
		$this->say( null === $report->secondsSinceCheckIn ? 'Last runner check-in: never' : sprintf( 'Last runner check-in: %d seconds ago%s', $report->secondsSinceCheckIn, $report->runnerStale() ? ' (stale)' : '' ) );

		if ( array() === $report->failingHandlers ) {
			$this->say( 'Failing handlers: none' );

			return self::EXIT_OK;
		}

		$this->say( 'Failing handlers:' );

		foreach ( $report->failingHandlers as $handler ) {
			$this->say( sprintf( '  %s: %d failed, last error: %s', $handler['handler'], $handler['failures'], '' === $handler['last_error'] ? '(none recorded)' : $handler['last_error'] ) );
		}

		return self::EXIT_OK;
	}

	/**
	 * Describes the Action Scheduler copy in control, and the other registered versions.
	 *
	 * @since 0.1.0
	 *
	 * @param JobsReport $report The report.
	 * @return string One line.
	 */
	private static function runtimeLine( JobsReport $report ): string {
		if ( '' === $report->runtimeVersion ) {
			return 'Action Scheduler in control: none loaded';
		}

		return sprintf(
			'Action Scheduler in control: %s from %s (registered: %s)',
			$report->runtimeVersion,
			$report->runtimeSource,
			array() === $report->registeredVersions ? 'none' : implode( ', ', $report->registeredVersions )
		);
	}

	/**
	 * Names the store another plugin chose, and who runs the plugin's jobs there.
	 *
	 * @since 0.1.0
	 *
	 * @param string $store The store's class.
	 * @return string Two sentences.
	 */
	private static function customStoreLine( string $store ): string {
		return sprintf( 'Action Scheduler keeps its actions in a store another plugin chose (%s). WP-Cron runs SEOCart\'s jobs through Action Scheduler.', $store );
	}

	/**
	 * Prints one line.
	 *
	 * @since 0.1.0
	 *
	 * @param string $line The line.
	 */
	private function say( string $line ): void {
		( $this->output )( $line );
	}

	/**
	 * Reads a whole-number option.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, string|bool> $assocArgs The options.
	 * @param string                     $name      The option name.
	 * @param int                        $fallback  The value when the option is absent or not a whole number.
	 * @return int The value.
	 */
	private static function intOption( array $assocArgs, string $name, int $fallback ): int {
		$value = $assocArgs[ $name ] ?? null;

		return is_string( $value ) && 1 === preg_match( '/^\d+$/', $value ) ? (int) $value : $fallback;
	}
}
