<?php
/**
 * SafeModeCommand: `wp seocart safe-mode`, which switches Safe Mode on or off and says why it is on
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Kernel\Cli;

use SEOCart\Platform\Kernel\SafeMode;
use SEOCart\Platform\Kernel\SafeModeStatus;

defined( 'ABSPATH' ) || exit;

/**
 * Lets an operator or a deployment script control Safe Mode without the admin screens.
 *
 * Owns one fact: the command's contract with an operator. `on` records Manual; `off` confirms
 * that this site is the store, which records its current address and clears every recorded
 * reason but a canary failure; `status` prints the status, the recorded address and the current
 * one. Every action ends by printing the status, so a script sees what is now true, and exits 0;
 * an unknown action exits 1.
 *
 * It is a maintenance command, not an application operation: it has no REST or Ability twin.
 * Output goes through a callable and run() returns the exit code, so tests call run() without
 * WP-CLI. Only __invoke(), which WP-CLI calls, touches the WP_CLI class.
 *
 * @since 0.1.0
 */
final class SafeModeCommand {

	/**
	 * Exit code: done.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const EXIT_OK = 0;

	/**
	 * Exit code: the command was used wrongly.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const EXIT_USAGE = 1;

	/**
	 * Safe Mode.
	 *
	 * @since 0.1.0
	 *
	 * @var SafeMode
	 */
	private SafeMode $safeMode;

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
	 * @param SafeMode               $safeMode Safe Mode.
	 * @param callable(string): void $output   Prints one line, for example WP_CLI::log().
	 */
	public function __construct( SafeMode $safeMode, callable $output ) {
		$this->safeMode = $safeMode;
		$this->output   = $output;
	}

	/**
	 * Switches Safe Mode on or off, or shows whether it is on and why.
	 *
	 * In Safe Mode no live payment, outbound mail or background job runs; the storefront and the
	 * admin still work. It turns on by itself when the site's address changes, because the site
	 * may be a copy of another store.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : What to do.
	 * ---
	 * options:
	 *   - on
	 *   - off
	 *   - status
	 * ---
	 *
	 * ## EXIT STATUS
	 *
	 * 0 when the action was done, 1 when the command was used wrongly. `off` exits 0 even when
	 * Safe Mode stays on for a reason it cannot clear; the status it prints says which.
	 *
	 * ## EXAMPLES
	 *
	 *     wp seocart safe-mode status
	 *     wp seocart safe-mode on
	 *     wp seocart safe-mode off
	 *
	 * @since 0.1.0
	 *
	 * @param string[] $args Positional arguments: the action.
	 */
	public function __invoke( array $args ): void {
		// WP-CLI passes the options as a second argument; the command takes none, so it is not declared.
		\WP_CLI::halt( $this->run( $args ) );
	}

	/**
	 * Runs the command and returns its exit code.
	 *
	 * @since 0.1.0
	 *
	 * @param string[] $args Positional arguments: `on`, `off` or `status`.
	 * @return int One of the EXIT_* constants.
	 */
	public function run( array $args ): int {
		$action = $args[0] ?? 'status';

		if ( 'on' === $action ) {
			$this->safeMode->enter( SafeModeStatus::Manual );
		} elseif ( 'off' === $action ) {
			$this->safeMode->adopt();
		} elseif ( 'status' !== $action ) {
			$this->say( sprintf( 'Unknown action "%s". Use on, off or status.', $action ) );

			return self::EXIT_USAGE;
		}

		$this->printStatus();

		return self::EXIT_OK;
	}

	/**
	 * Prints the status, the recorded address and the current one.
	 *
	 * @since 0.1.0
	 */
	private function printStatus(): void {
		$status = $this->safeMode->status();

		$this->say( SafeModeStatus::Off === $status ? 'Safe Mode: off' : sprintf( 'Safe Mode: on (%s)', $status->value ) );
		$this->say( sprintf( 'Recorded address: %s', $this->safeMode->recordedUrl() ?? '(none: SEOCart is not installed on this site)' ) );
		$this->say( sprintf( 'Current address: %s', home_url() ) );
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
}
