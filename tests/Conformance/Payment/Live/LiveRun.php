<?php
/**
 * LiveRun: the files one run of a live workflow writes, which the live tests, the clean-up and the verdict share
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Conformance\Payment\Live;

/**
 * Names the files one run against a provider's test environment writes, and writes and reads their lines.
 *
 * The live workflow's settings step creates the files in the runner's temporary directory, and its
 * steps name them in the environment of the tests they run; the live tests append to them; the
 * clean-up and the verdict read them. The run list holds, per line, the provider's id of an
 * object the run created and the store uuid it was created for, and nothing else, so the clean-up
 * ends what the run left open. The report holds `key=value` lines of what the run's summary shows:
 * counts and the provider's words, never a body, a secret or an address with a query.
 *
 * @since 0.2.0
 */
final class LiveRun {

	/**
	 * The environment variable naming the run list.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const RUN_LIST = 'SEOCART_LIVE_RUN_LIST';

	/**
	 * The environment variable naming the report.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const REPORT = 'SEOCART_LIVE_REPORT';

	/**
	 * The environment variable naming the browser a test's script launches, for a machine Playwright downloads none for; empty for Playwright's own.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const BROWSER = 'SEOCART_LIVE_BROWSER';

	/**
	 * The shape of a provider's id on the run list, as a regular expression's part.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const ID = '[A-Za-z0-9_\-]{1,128}';

	/**
	 * The shape of a store uuid, as a regular expression's part.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const UUID = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';

	/**
	 * Returns the file an environment variable names.
	 *
	 * @since 0.2.0
	 *
	 * @throws \RuntimeException When the variable names no file that can be written.
	 *
	 * @param string $variable The variable.
	 * @return string The file.
	 */
	public static function file( string $variable ): string {
		$file = (string) getenv( $variable );

		if ( '' === $file || ! is_writable( file_exists( $file ) ? $file : dirname( $file ) ) ) {
			throw new \RuntimeException( sprintf( 'The live tests write to the file %s names, which the live workflow\'s settings step creates.', $variable ) );
		}

		return $file;
	}

	/**
	 * Appends a line to the file an environment variable names, readable by its owner alone.
	 *
	 * @since 0.2.0
	 *
	 * @param string $variable The variable.
	 * @param string $line     The line, with its newline.
	 */
	public static function append( string $variable, string $line ): void {
		$file = self::file( $variable );

		file_put_contents( $file, $line, FILE_APPEND | LOCK_EX );
		chmod( $file, 0600 );
	}

	/**
	 * Adds a fact to the run's report, its value made safe to print by the extension's guard.
	 *
	 * @since 0.2.0
	 *
	 * @param LiveDeclaration $declaration The extension's declaration.
	 * @param string          $key         The fact, such as `events`: lower-case letters, digits and `-`.
	 * @param string|int      $value       Its value, on one line.
	 */
	public static function report( LiveDeclaration $declaration, string $key, string|int $value ): void {
		self::append( self::REPORT, $declaration->guard()->say( "%s=%s\n", $key, str_replace( array( "\r", "\n" ), ' ', (string) $value ) ) );
	}

	/**
	 * Reads a report: its facts, the last value of each.
	 *
	 * @since 0.2.0
	 *
	 * @param string $text The report's text.
	 * @return array<string, string> The facts, by key.
	 */
	public static function facts( string $text ): array {
		$facts = array();

		foreach ( explode( "\n", $text ) as $line ) {
			if ( 1 === preg_match( '/^([a-z][a-z0-9\-]*)=(.*)$/D', $line, $match ) ) {
				$facts[ $match[1] ] = $match[2];
			}
		}

		return $facts;
	}

	/**
	 * Returns the run list's line for an object the run created.
	 *
	 * The run list is never printed: the clean-up reads it. So an id is written whole, even one
	 * whose digits would read as a card number; the clean-up must find every object the run made.
	 *
	 * @since 0.2.0
	 *
	 * @throws \LogicException When the id or the uuid is not of its shape, as a line of any other shape would be read as no line.
	 *
	 * @param string $providerId The provider's id: letters, digits, `_` and `-`.
	 * @param string $storeUuid  The store uuid it was created for.
	 * @return string The line, with its newline.
	 */
	public static function line( string $providerId, string $storeUuid ): string {
		if ( 1 !== preg_match( '/^' . self::ID . '$/D', $providerId ) || 1 !== preg_match( '/^' . self::UUID . '$/D', $storeUuid ) ) {
			throw new \LogicException( 'The run list holds a provider\'s id and a store uuid, and nothing else.' );
		}

		return $providerId . ' ' . $storeUuid . "\n";
	}

	/**
	 * Reads a run list: the store uuid of each object, by the provider's id.
	 *
	 * @since 0.2.0
	 *
	 * @param string $text The run list's text.
	 * @return array<string, string> The uuids, by the provider's id; a line of any other shape is left out.
	 */
	public static function runList( string $text ): array {
		$created = array();

		foreach ( explode( "\n", $text ) as $line ) {
			if ( 1 === preg_match( '/^(' . self::ID . ') (' . self::UUID . ')$/D', trim( $line ), $match ) ) {
				$created[ $match[1] ] = $match[2];
			}
		}

		return $created;
	}
}
