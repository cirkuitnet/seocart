<?php
/**
 * DoctorCommand: `wp seocart doctor`, which checks the store's state and exits non-zero when something is wrong
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Cli;

use SEOCart\Platform\Cli\Doctor\Check;
use SEOCart\Platform\Cli\Doctor\CheckResult;
use SEOCart\Platform\Cli\Doctor\Doctor;
use SEOCart\Platform\Cli\Doctor\Repairable;
use SEOCart\Platform\Cli\Doctor\RepairResult;
use SEOCart\Platform\Logging\CardNumbers;
use SEOCart\Support\Error\CodedException;
use SEOCart\Support\Error\ErrorDefinition;

defined( 'ABSPATH' ) || exit;

/**
 * Runs the doctor's checks for the current site and prints what each found.
 *
 * Owns one fact: the command's contract with an operator. It prints one line per check,
 * `[ok]` or `[FAIL]`, with a line per finding under a failed one, then a verdict; it exits 0
 * when every check passed and 1 when any failed. `--residue` runs the residue check instead,
 * for a site whose store data was deleted; it is read-only and repairs nothing.
 *
 * `--repair` runs every check, calls repair() on each Repairable check that failed, prints what
 * each changed, then runs every check again and prints that second pass: the exit code is 0 only
 * when the second pass is clean and no repair itself threw (one may have changed some rows before
 * it did, which the second pass alone cannot tell from a repair that changed nothing). Without
 * `--repair`, output and exit codes are exactly what they were before it existed. `--repair`
 * together with `--residue` is a usage error: the residue checks are read-only by nature and have
 * nothing to repair.
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
final class DoctorCommand {

	/**
	 * Exit code: every check passed.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const EXIT_OK = 0;

	/**
	 * Exit code: at least one check failed (or, with --repair, the second pass still has one).
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const EXIT_FAILED = 1;

	/**
	 * Exit code: --repair was given together with --residue.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const EXIT_USAGE = 2;

	/**
	 * The checks.
	 *
	 * @since 0.1.0
	 *
	 * @var Doctor
	 */
	private Doctor $doctor;

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
	 * @param Doctor                 $doctor The checks.
	 * @param callable(string): void $output Prints one line, for example WP_CLI::log().
	 */
	public function __construct( Doctor $doctor, callable $output ) {
		$this->doctor = $doctor;
		$this->output = $output;
	}

	/**
	 * Checks the store's schema, migrations, locks and outbox, or what remains of a deleted store.
	 *
	 * ## OPTIONS
	 *
	 * [--residue]
	 * : Check instead that nothing the plugin owned remains: run it after the store's data was deleted.
	 *
	 * [--repair]
	 * : Fix what is structural and fully recoverable, then check again. Never a price, a variant, a
	 * ledger row or a source binding. Not with --residue.
	 *
	 * ## EXIT STATUS
	 *
	 * 0 when every check passed (with --repair: when the second pass is clean and no repair threw), 1 when any failed.
	 *
	 * ## EXAMPLES
	 *
	 *     wp seocart doctor
	 *     wp seocart doctor --repair
	 *     wp seocart doctor --residue
	 *
	 * @since 0.1.0
	 *
	 * @param string[]                   $args      No positional arguments.
	 * @param array<string, string|bool> $assocArgs The options.
	 */
	public function __invoke( array $args, array $assocArgs ): void {
		\WP_CLI::halt( $this->run( $args, $assocArgs ) );
	}

	/**
	 * Runs the checks and returns the exit code.
	 *
	 * @since 0.1.0
	 *
	 * @param string[]                   $args      No positional arguments.
	 * @param array<string, string|bool> $assocArgs The options: residue, repair.
	 * @return int EXIT_OK, EXIT_FAILED, or EXIT_USAGE for --repair with --residue.
	 */
	public function run( array $args, array $assocArgs ): int {
		unset( $args );

		$residue = ! empty( $assocArgs['residue'] );
		$repair  = ! empty( $assocArgs['repair'] );

		if ( $repair && $residue ) {
			$this->say( 'error: --repair and --residue cannot be used together: the residue checks are read-only by nature.' );

			return self::EXIT_USAGE;
		}

		$checks = $residue ? $this->doctor->residueChecks() : $this->doctor->checks();

		if ( ! $repair ) {
			return $this->report( $this->doctor->runList( $checks ) );
		}

		$first = $this->doctor->runList( $checks );

		$this->say( 'First pass:' );
		$this->printResults( $first );

		$anyRepairFailed = false;
		$repairs         = $this->repairFailed( $checks, $first, $anyRepairFailed );

		$this->say( '' );
		$this->say( 0 === count( $repairs ) ? 'Nothing to repair.' : 'Repairs:' );

		foreach ( $repairs as $repaired ) {
			if ( array() === $repaired->changes ) {
				$this->say( sprintf( '%s: nothing left to change.', $repaired->check ) );

				continue;
			}

			$this->say( sprintf( '%s:', $repaired->check ) );

			foreach ( $repaired->changes as $change ) {
				$this->say( '  - ' . $change );
			}
		}

		$this->say( '' );
		$this->say( 'Second pass:' );

		$exitCode = $this->report( $this->doctor->runList( $checks ) );

		return $anyRepairFailed ? self::EXIT_FAILED : $exitCode;
	}

	/**
	 * Calls repair() on each check that is Repairable and failed the first pass.
	 *
	 * A repair that throws is reported as a change of nothing, never left to escape: the second
	 * pass still runs, and is still printed, but a repair that threw may have changed some rows
	 * before it did, which the second pass alone cannot tell from a repair that changed nothing —
	 * so $anyRepairFailed forces the exit code to EXIT_FAILED regardless of what the second pass
	 * finds.
	 *
	 * @since 0.1.0
	 *
	 * @param Check[]       $checks          The checks the first pass ran, in order.
	 * @param CheckResult[] $first           The first pass's results, the same length and order.
	 * @param bool          $anyRepairFailed Set to true when a repair threw.
	 * @return list<RepairResult> One entry per Repairable check the first pass failed.
	 *
	 * @phpstan-param list<Check>        $checks
	 * @phpstan-param list<CheckResult>  $first
	 */
	private function repairFailed( array $checks, array $first, bool &$anyRepairFailed ): array {
		$repairs         = array();
		$anyRepairFailed = false;

		foreach ( $checks as $index => $check ) {
			if ( ! $check instanceof Repairable || ( $first[ $index ]->passed ?? true ) ) {
				continue;
			}

			try {
				$repairs[] = $check->repair();
			} catch ( \Throwable $failure ) {
				$anyRepairFailed = true;
				$repairs[]       = new RepairResult( $check->name(), array( 'the repair could not run: ' . self::describe( $failure ) ) );
			}
		}

		return $repairs;
	}

	/**
	 * Prints a pass's results and returns the exit code it decides.
	 *
	 * @since 0.1.0
	 *
	 * @param CheckResult[] $results The pass's results.
	 * @return int EXIT_OK or EXIT_FAILED.
	 *
	 * @phpstan-param list<CheckResult> $results
	 */
	private function report( array $results ): int {
		$this->printResults( $results );

		$failed = count( array_filter( $results, static fn( CheckResult $result ): bool => ! $result->passed ) );

		if ( 0 === $failed ) {
			$this->say( sprintf( 'All %d %s passed.', count( $results ), 1 === count( $results ) ? 'check' : 'checks' ) );

			return self::EXIT_OK;
		}

		$this->say( sprintf( '%d of %d %s failed.', $failed, count( $results ), 1 === count( $results ) ? 'check' : 'checks' ) );

		return self::EXIT_FAILED;
	}

	/**
	 * Prints one line per result, with a line per finding under a failed one.
	 *
	 * @since 0.1.0
	 *
	 * @param CheckResult[] $results The results.
	 *
	 * @phpstan-param list<CheckResult> $results
	 */
	private function printResults( array $results ): void {
		foreach ( $results as $result ) {
			$this->say( sprintf( '%s %s: %s', $result->passed ? '[ok]  ' : '[FAIL]', $result->check, $result->summary ) );

			foreach ( $result->findings as $finding ) {
				$this->say( '         - ' . $finding );
			}
		}
	}

	/**
	 * Describes why a repair could not run, without any value the failure carries.
	 *
	 * @since 0.1.0
	 *
	 * @param \Throwable $failure The failure.
	 * @return string For a coded failure its code and its message; otherwise the class.
	 */
	private static function describe( \Throwable $failure ): string {
		if ( $failure instanceof CodedException ) {
			return (string) $failure->errorCode()->value . ': ' . ErrorDefinition::of( $failure->errorCode() )->render( $failure->context() );
		}

		return get_class( $failure ) . '.';
	}

	/**
	 * Prints one line, with any card-shaped number removed.
	 *
	 * @since 0.1.0
	 *
	 * @param string $line The line.
	 */
	private function say( string $line ): void {
		( $this->output )( CardNumbers::scrub( $line ) );
	}
}
