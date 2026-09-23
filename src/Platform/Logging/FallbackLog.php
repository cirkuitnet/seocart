<?php
/**
 * FallbackLog: where lost log lines are accounted for when the log itself cannot be written
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Logging;

defined( 'ABSPATH' ) || exit;

/**
 * Accounts for every log line or report that could not be written, in PHP's error log, without flooding it.
 *
 * Owns one fact: how much of a loss reaches the error log. The first loss for each reason
 * writes one line, which names the reason and never the lost line's message or context. Every
 * later loss for a reason already written is only counted. At the first loss of all, one
 * final line is registered for the end of the process: it gives the count of every loss, and
 * is written only if something was lost. The logger and the reporter share one instance, so a
 * process that loses lines through both writes one count.
 *
 * Every line is scanned for card numbers before it is written. Nothing here throws.
 * Constructing it sends nothing and registers nothing.
 *
 * @since 0.1.0
 */
final class FallbackLog {

	/**
	 * Writes one line to the error log.
	 *
	 * @since 0.1.0
	 *
	 * @var \Closure(string): void
	 */
	private \Closure $write;

	/**
	 * Registers work to run when the process ends.
	 *
	 * @since 0.1.0
	 *
	 * @var \Closure(callable): void
	 */
	private \Closure $atShutdown;

	/**
	 * The reasons a line was already written for, in this process.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, true>
	 */
	private array $reasons = array();

	/**
	 * How many losses there were since the count was last written.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private int $lost = 0;

	/**
	 * Whether the end-of-process count is registered.
	 *
	 * @since 0.1.0
	 *
	 * @var bool
	 */
	private bool $reportScheduled = false;

	/**
	 * Creates the fallback. Sends nothing and registers nothing.
	 *
	 * @since 0.1.0
	 *
	 * @param callable|null $write      Optional. Writes one line. Default null, which uses PHP's error log.
	 * @param callable|null $atShutdown Optional. Registers a callable to run when the process ends;
	 *                                  called once, at the first loss. Default null, which uses
	 *                                  register_shutdown_function().
	 *
	 * @phpstan-param (callable(string): void)|null   $write
	 * @phpstan-param (callable(callable): void)|null $atShutdown
	 */
	public function __construct( ?callable $write = null, ?callable $atShutdown = null ) {
		$this->write      = null === $write ? \Closure::fromCallable( array( self::class, 'errorLog' ) ) : \Closure::fromCallable( $write );
		$this->atShutdown = null === $atShutdown ? static function ( callable $work ): void {
			register_shutdown_function( $work );
		} : \Closure::fromCallable( $atShutdown );
	}

	/**
	 * Accounts for one loss: its line is written if its reason is new, and it is counted either way.
	 *
	 * @since 0.1.0
	 *
	 * @param string $reason Why it was lost, the same text for the same cause.
	 * @param string $line   What to write the first time: the loss and its reason, never its content.
	 */
	public function lost( string $reason, string $line ): void {
		++$this->lost;

		if ( ! $this->reportScheduled ) {
			$this->reportScheduled = true;

			try {
				( $this->atShutdown )( array( $this, 'reportLost' ) );
			} catch ( \Throwable $failure ) {
				unset( $failure );
			}
		}

		if ( isset( $this->reasons[ $reason ] ) ) {
			return;
		}

		$this->reasons[ $reason ] = true;

		$this->write( $line . ' Later losses for the same reason are only counted, and the count is written when the process ends.' );
	}

	/**
	 * Writes how many losses there were, if there were any. Called when the process ends.
	 *
	 * It writes one line with the count and starts the count again, so calling it twice writes
	 * nothing more.
	 *
	 * @since 0.1.0
	 */
	public function reportLost(): void {
		if ( 0 === $this->lost ) {
			return;
		}

		$lost       = $this->lost;
		$this->lost = 0;

		$this->write( sprintf( 'SEOCart: %1$d log %2$s could not be written in this process; the first of each reason was reported on its own line.', $lost, 1 === $lost ? 'line' : 'lines' ) );
	}

	/**
	 * Writes one line to PHP's error log: the default writer.
	 *
	 * @since 0.1.0
	 *
	 * @param string $line The line.
	 */
	public static function errorLog( string $line ): void {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- The fallback when the log table cannot be written; the line holds no message and no context.
		error_log( $line );
	}

	/**
	 * Writes one line, with any card number removed. Never throws.
	 *
	 * @since 0.1.0
	 *
	 * @param string $line The line.
	 */
	private function write( string $line ): void {
		try {
			( $this->write )( CardNumbers::scrub( $line ) );
		} catch ( \Throwable $failed ) {
			// Nowhere is left to report to; the caller must still go on.
			unset( $failed );
		}
	}
}
