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

use SEOCart\Platform\Cli\Doctor\CheckResult;
use SEOCart\Platform\Cli\Doctor\Doctor;
use SEOCart\Platform\Logging\CardNumbers;

defined( 'ABSPATH' ) || exit;

/**
 * Runs the doctor's checks for the current site and prints what each found.
 *
 * Owns one fact: the command's contract with an operator. It prints one line per check,
 * `[ok]` or `[FAIL]`, with a line per finding under a failed one, then a verdict; it exits 0
 * when every check passed and 1 when any failed. `--residue` runs the residue check instead,
 * for a site whose store data was deleted. It is read-only and repairs nothing. The output
 * names structure, counts and identifiers only; as a last guard, every line is scanned for card
 * numbers before it is printed.
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
	 * Exit code: at least one check failed.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const EXIT_FAILED = 1;

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
	 * ## EXIT STATUS
	 *
	 * 0 when every check passed, 1 when any failed.
	 *
	 * ## EXAMPLES
	 *
	 *     wp seocart doctor
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
	 * @param array<string, string|bool> $assocArgs The options: residue.
	 * @return int EXIT_OK or EXIT_FAILED.
	 */
	public function run( array $args, array $assocArgs ): int {
		unset( $args );

		$results = $this->doctor->run( ! empty( $assocArgs['residue'] ) );
		$failed  = count( array_filter( $results, static fn( CheckResult $result ): bool => ! $result->passed ) );

		foreach ( $results as $result ) {
			$this->say( sprintf( '%s %s: %s', $result->passed ? '[ok]  ' : '[FAIL]', $result->check, $result->summary ) );

			foreach ( $result->findings as $finding ) {
				$this->say( '         - ' . $finding );
			}
		}

		if ( 0 === $failed ) {
			$this->say( sprintf( 'All %d %s passed.', count( $results ), 1 === count( $results ) ? 'check' : 'checks' ) );

			return self::EXIT_OK;
		}

		$this->say( sprintf( '%d of %d %s failed.', $failed, count( $results ), 1 === count( $results ) ? 'check' : 'checks' ) );

		return self::EXIT_FAILED;
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
