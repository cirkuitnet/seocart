<?php
/**
 * Tape: a gateway's recorded exchanges, deliveries and settings, on disk
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Conformance\Payment;

/**
 * The directory of a gateway's tapes: one directory per scenario holding its exchanges, the deliveries of its webhooks, and the settings it was recorded with.
 *
 * Owns one fact: the tape's layout on disk.
 *
 *     {tapes}/settings.json         the non-secret settings of the recording, and its nonce
 *     {tapes}/{scenario}/{n}.json   the n-th exchange of a scenario, from 1
 *     {tapes}/webhooks/{name}.json  one delivery
 *
 * Every file is pretty JSON with a final newline, so a recording made again shows as a diff a
 * person can review. What is written has already been scrubbed (TapeScrubber); this class only
 * reads and writes.
 *
 * @since 0.2.0
 */
final class Tape {

	/**
	 * The file holding the settings a recording used.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const SETTINGS = 'settings.json';

	/**
	 * The directory holding the recorded webhook deliveries.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const WEBHOOKS = 'webhooks';

	/**
	 * The key of the settings file that holds the nonce the recording seeded its ids with, when it had one: no setting's name holds a `-`.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const NONCE = 'recording-nonce';

	/**
	 * The tapes' directory, without a trailing slash.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private string $directory;

	/**
	 * Keeps the directory.
	 *
	 * @since 0.2.0
	 *
	 * @param string $directory The tapes' directory; it need not exist yet.
	 */
	public function __construct( string $directory ) {
		$this->directory = rtrim( $directory, '/' );
	}

	/**
	 * Returns the tapes' directory.
	 *
	 * @since 0.2.0
	 *
	 * @return string The directory, without a trailing slash.
	 */
	public function directory(): string {
		return $this->directory;
	}

	/**
	 * Returns a scenario's exchanges, in the order they were recorded.
	 *
	 * @since 0.2.0
	 *
	 * @param string $scenario The scenario's name.
	 * @return list<Exchange>|null The exchanges; null when the scenario was never recorded.
	 */
	public function exchanges( string $scenario ): ?array {
		$directory = $this->directory . '/' . $scenario;

		if ( ! is_dir( $directory ) ) {
			return null;
		}

		$exchanges = array();

		foreach ( self::numbered( $directory ) as $file ) {
			$exchanges[] = Exchange::fromTape( self::read( $file ) );
		}

		return $exchanges;
	}

	/**
	 * Writes a scenario's exchanges, replacing what was recorded before.
	 *
	 * @since 0.2.0
	 *
	 * @param string     $scenario  The scenario's name.
	 * @param Exchange[] $exchanges The scrubbed exchanges.
	 */
	public function write( string $scenario, array $exchanges ): void {
		$directory = $this->directory . '/' . $scenario;

		self::makeDirectory( $directory );

		foreach ( self::numbered( $directory ) as $old ) {
			unlink( $old );
		}

		foreach ( $exchanges as $index => $exchange ) {
			self::writeFile( $directory . '/' . ( $index + 1 ) . '.json', self::encode( $exchange->toTape() ) );
		}
	}

	/**
	 * Returns the non-secret settings the recording used.
	 *
	 * @since 0.2.0
	 *
	 * @return array<mixed> The settings by name; none when there is no file.
	 */
	public function settings(): array {
		$file = $this->directory . '/' . self::SETTINGS;

		return is_file( $file ) ? self::read( $file ) : array();
	}

	/**
	 * Writes the non-secret settings a recording used.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, int|string|null> $settings The settings by name.
	 */
	public function writeSettings( array $settings ): void {
		self::makeDirectory( $this->directory );
		ksort( $settings, SORT_STRING );
		self::writeFile( $this->directory . '/' . self::SETTINGS, self::encode( $settings ) );
	}

	/**
	 * Returns the recorded deliveries.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, array<mixed>> Each delivery by its name.
	 */
	public function deliveries(): array {
		$deliveries = array();

		foreach ( self::filesMatching( $this->directory . '/' . self::WEBHOOKS . '/*.json' ) as $file ) {
			$deliveries[ basename( $file, '.json' ) ] = self::read( $file );
		}

		ksort( $deliveries, SORT_STRING );

		return $deliveries;
	}

	/**
	 * Writes one delivery.
	 *
	 * @since 0.2.0
	 *
	 * @param string       $name     The delivery's name, such as `capture`.
	 * @param array<mixed> $delivery The scrubbed delivery: mode, signed_at, headers and body.
	 */
	public function writeDelivery( string $name, array $delivery ): void {
		self::makeDirectory( $this->directory . '/' . self::WEBHOOKS );
		self::writeFile( $this->directory . '/' . self::WEBHOOKS . '/' . $name . '.json', self::encode( $delivery ) );
	}

	/**
	 * Returns every file of the tapes, for a scan.
	 *
	 * @since 0.2.0
	 *
	 * @return list<string> The paths, sorted.
	 */
	public function files(): array {
		if ( ! is_dir( $this->directory ) ) {
			return array();
		}

		$files = array();

		foreach ( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $this->directory, \FilesystemIterator::SKIP_DOTS ) ) as $file ) {
			if ( $file instanceof \SplFileInfo && $file->isFile() ) {
				$files[] = $file->getPathname();
			}
		}

		sort( $files, SORT_STRING );

		return $files;
	}

	/**
	 * Encodes a tape file: pretty JSON, slashes and Unicode as they are, a final newline.
	 *
	 * @since 0.2.0
	 *
	 * @param array<mixed> $data The data.
	 * @return string The file's content.
	 */
	public static function encode( array $data ): string {
		$json = (string) wp_json_encode( self::sorted( $data ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		// PHP indents by four spaces; the repository's Prettier style indents by tabs. A string's
		// own line breaks are escaped, so every line begins with indentation alone.
		return (string) preg_replace_callback( '/^(?: {4})+/m', static fn( array $indent ): string => str_repeat( "\t", intdiv( strlen( $indent[0] ), 4 ) ), $json ) . "\n";
	}

	/**
	 * Sorts every object's keys, at every depth, so a recording's bytes depend on what it holds and not on the order it was built in.
	 *
	 * @since 0.2.0
	 *
	 * @param array<mixed> $data The data.
	 * @return array<mixed> The data, each object's keys sorted; a list keeps its order.
	 */
	private static function sorted( array $data ): array {
		if ( ! array_is_list( $data ) ) {
			ksort( $data, SORT_STRING );
		}

		foreach ( $data as $key => $value ) {
			if ( is_array( $value ) ) {
				$data[ $key ] = self::sorted( $value );
			}
		}

		return $data;
	}

	/**
	 * Lists a scenario directory's exchange files in their order: 1.json, 2.json, … 10.json.
	 *
	 * @since 0.2.0
	 *
	 * @param string $directory The scenario's directory.
	 * @return list<string> The paths.
	 */
	private static function numbered( string $directory ): array {
		$files = array_values( array_filter( self::filesMatching( $directory . '/*.json' ), static fn( string $file ): bool => 1 === preg_match( '/^\d+$/D', basename( $file, '.json' ) ) ) );

		usort( $files, static fn( string $one, string $other ): int => (int) basename( $one, '.json' ) <=> (int) basename( $other, '.json' ) );

		return $files;
	}

	/**
	 * Lists the files a pattern matches.
	 *
	 * @since 0.2.0
	 *
	 * @param string $pattern A glob pattern.
	 * @return list<string> The paths; none when nothing matches.
	 */
	private static function filesMatching( string $pattern ): array {
		$files = glob( $pattern );

		return false === $files ? array() : $files;
	}

	/**
	 * Reads and decodes a tape file.
	 *
	 * @since 0.2.0
	 *
	 * @throws \UnexpectedValueException When the file is not a JSON object.
	 *
	 * @param string $file The path.
	 * @return array<mixed> The decoded file.
	 */
	private static function read( string $file ): array {
		$decoded = json_decode( (string) file_get_contents( $file ), true );

		if ( ! is_array( $decoded ) ) {
			throw new \UnexpectedValueException( sprintf( 'The tape file %s is not a JSON object.', $file ) );
		}

		return $decoded;
	}

	/**
	 * Creates a directory and its parents when they do not exist.
	 *
	 * @since 0.2.0
	 *
	 * @throws \RuntimeException When it cannot be created.
	 *
	 * @param string $directory The directory.
	 */
	private static function makeDirectory( string $directory ): void {
		if ( ! is_dir( $directory ) && ! mkdir( $directory, 0775, true ) && ! is_dir( $directory ) ) {
			throw new \RuntimeException( sprintf( 'The tape directory %s could not be created.', $directory ) );
		}
	}

	/**
	 * Writes a file whole.
	 *
	 * @since 0.2.0
	 *
	 * @throws \RuntimeException When it cannot be written.
	 *
	 * @param string $file    The path.
	 * @param string $content The content.
	 */
	private static function writeFile( string $file, string $content ): void {
		if ( false === file_put_contents( $file, $content ) ) {
			throw new \RuntimeException( sprintf( 'The tape file %s could not be written.', $file ) );
		}
	}
}
