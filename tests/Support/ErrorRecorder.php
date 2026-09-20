<?php
/**
 * ErrorRecorder: remembers the PHP errors raised while the plugin loads, so a test can fail on them
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support;

/**
 * Records notices, warnings and deprecations that are raised outside any test.
 *
 * The plugin loads while WordPress boots, before PHPUnit starts its first test, so PHPUnit's
 * own conversion of errors into failures never sees what the plugin raises then. The
 * integration bootstrap starts this recorder just before it includes the plugin and stops it
 * once WordPress has finished loading; a test then asserts that nothing was recorded.
 *
 * Recording does not replace PHP's own handling: the error is still displayed or logged as the
 * configuration says. An error silenced with the `@` operator is not recorded, because its
 * author has already decided it is expected.
 *
 * The state is static because the recording and the assertion happen in different places of
 * one PHP process, with no object that both could be handed.
 *
 * @since 0.1.0
 */
final class ErrorRecorder {

	/**
	 * The errors recorded so far.
	 *
	 * @since 0.1.0
	 *
	 * @var list<array{severity: int, message: string, file: string, line: int}>
	 */
	private static array $records = array();

	/**
	 * Starts recording every PHP error.
	 *
	 * @since 0.1.0
	 */
	public static function start(): void {
		set_error_handler( array( self::class, 'record' ) );
	}

	/**
	 * Stops recording and hands error handling back to whoever had it before.
	 *
	 * @since 0.1.0
	 */
	public static function stop(): void {
		restore_error_handler();
	}

	/**
	 * Records one error. This is the error handler start() installs.
	 *
	 * @since 0.1.0
	 *
	 * @param int    $severity The level of the error, one of the E_* constants.
	 * @param string $message  The error message.
	 * @param string $file     Optional. The file the error was raised in. Default empty.
	 * @param int    $line     Optional. The line the error was raised on. Default 0.
	 * @return bool Always false, so that PHP goes on to handle the error as it normally would.
	 */
	public static function record( int $severity, string $message, string $file = '', int $line = 0 ): bool {
		// Inside an expression silenced with `@`, error_reporting() no longer includes the level.
		if ( 0 !== ( error_reporting() & $severity ) ) {
			self::$records[] = array(
				'severity' => $severity,
				'message'  => $message,
				'file'     => $file,
				'line'     => $line,
			);
		}

		return false;
	}

	/**
	 * Returns the errors recorded so far.
	 *
	 * @since 0.1.0
	 *
	 * @return list<array{severity: int, message: string, file: string, line: int}> The errors, in the order they were raised.
	 */
	public static function records(): array {
		return self::$records;
	}

	/**
	 * Forgets every recorded error.
	 *
	 * @since 0.1.0
	 */
	public static function reset(): void {
		self::$records = array();
	}

	/**
	 * Prints recorded errors, for a failure message.
	 *
	 * @since 0.1.0
	 *
	 * @param list<array{severity: int, message: string, file: string, line: int}> $records The errors to print.
	 * @return string One line per error: level, message, file and line.
	 */
	public static function describe( array $records ): string {
		$lines = array();

		foreach ( $records as $record ) {
			$lines[] = sprintf( '  %s: %s in %s:%d', self::levelName( $record['severity'] ), $record['message'], $record['file'], $record['line'] );
		}

		return implode( "\n", $lines );
	}

	/**
	 * Names an error level.
	 *
	 * @since 0.1.0
	 *
	 * @param int $severity One of the E_* constants.
	 * @return string The name developers know the level by.
	 */
	private static function levelName( int $severity ): string {
		switch ( $severity ) {
			case E_WARNING:
			case E_USER_WARNING:
				return 'Warning';
			case E_NOTICE:
			case E_USER_NOTICE:
				return 'Notice';
			case E_DEPRECATED:
			case E_USER_DEPRECATED:
				return 'Deprecated';
			default:
				return 'Error (level ' . $severity . ')';
		}
	}
}
