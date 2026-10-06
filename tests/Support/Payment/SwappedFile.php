<?php
/**
 * SwappedFile: a stream wrapper whose files change between their check and their reading, as another process could change them
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support\Payment;

/**
 * Stands in for the filesystem under the scheme `seocart-swapped://`: what a path's check sees is one local file, and what opening it gives may be another, or the same file grown once it is open.
 *
 * Owns one fact: a change between a check and a reading, timed. One process cannot interleave
 * another's rename with its own calls, so each path here plays a change at a fixed point: lstat()
 * and stat() answer for the file checked; opening hands over the file put in its place (a
 * symbolic link is followed, as fopen() follows it); and a file that grows does so right after
 * fstat() answered for it, before it is read. Everything else is the real file's.
 *
 * @since 0.2.0
 */
final class SwappedFile {

	/**
	 * The wrapper's scheme.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const SCHEME = 'seocart-swapped';

	/**
	 * The stream context PHP assigns to a wrapper instance.
	 *
	 * @since 0.2.0
	 *
	 * @var resource|null
	 */
	public $context;

	/**
	 * The paths handed out, by name: the file checked, the file opened, and how many bytes the file opened grows by once its status is read.
	 *
	 * @since 0.2.0
	 *
	 * @var array<string, array{checked: string, opened: string, grows: int}>
	 */
	private static array $paths = array();

	/**
	 * The file this instance opened.
	 *
	 * @since 0.2.0
	 *
	 * @var resource
	 */
	private $handle;

	/**
	 * The path this instance opened, as its name.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private string $name = '';

	/**
	 * Registers the scheme.
	 *
	 * @since 0.2.0
	 */
	public static function register(): void {
		if ( ! in_array( self::SCHEME, stream_get_wrappers(), true ) ) {
			stream_wrapper_register( self::SCHEME, self::class );
		}
	}

	/**
	 * Unregisters the scheme and forgets the paths.
	 *
	 * @since 0.2.0
	 */
	public static function unregister(): void {
		if ( in_array( self::SCHEME, stream_get_wrappers(), true ) ) {
			stream_wrapper_unregister( self::SCHEME );
		}

		self::$paths = array();
	}

	/**
	 * Returns a path checked as one file and opened as another, as when the other is put in its place in between.
	 *
	 * @since 0.2.0
	 *
	 * @param string $checked The local file a check of the path sees.
	 * @param string $opened  The local file, or link, opening the path gives.
	 * @return string The path.
	 */
	public static function replaced( string $checked, string $opened ): string {
		return self::path( $checked, $opened, 0 );
	}

	/**
	 * Returns a path to a file that grows by some bytes once it is open and its status read, before it is read.
	 *
	 * @since 0.2.0
	 *
	 * @param string $file  The local file.
	 * @param int    $bytes How many bytes it grows by: spaces, which leave a JSON text as it was.
	 * @return string The path.
	 */
	public static function growing( string $file, int $bytes ): string {
		return self::path( $file, $file, $bytes );
	}

	/**
	 * Answers a path's status, for lstat(), stat(), file_exists() and the like: the file checked's.
	 *
	 * @since 0.2.0
	 *
	 * @param string $path  The path.
	 * @param int    $flags STREAM_URL_STAT_* flags.
	 * @return array<int|string, int>|false The status; false for a path not handed out.
	 */
	public function url_stat( string $path, int $flags ): array|false {
		$file = self::$paths[ self::nameOf( $path ) ] ?? null;

		if ( null === $file ) {
			return false;
		}

		return ( $flags & STREAM_URL_STAT_LINK ) ? lstat( $file['checked'] ) : stat( $file['checked'] );
	}

	/**
	 * Opens the file put in the path's place.
	 *
	 * @since 0.2.0
	 *
	 * @param string      $path        The path.
	 * @param string      $mode        The fopen() mode.
	 * @param int         $options     Stream options.
	 * @param string|null $opened_path Set to the path actually opened.
	 * @return bool Whether it was opened.
	 */
	public function stream_open( string $path, string $mode, int $options, ?string &$opened_path ): bool {
		unset( $options );

		$this->name = self::nameOf( $path );
		$file       = self::$paths[ $this->name ] ?? null;
		$handle     = null === $file ? false : fopen( $file['opened'], $mode );

		if ( ! is_resource( $handle ) ) {
			return false;
		}

		$this->handle = $handle;
		$opened_path  = $path;

		return true;
	}

	/**
	 * Answers the open file's status, then lets the file grow, if it does.
	 *
	 * @since 0.2.0
	 *
	 * @return array<int|string, int>|false The status, as it was before the file grew.
	 */
	public function stream_stat(): array|false {
		$status = fstat( $this->handle );
		$file   = self::$paths[ $this->name ];

		if ( $file['grows'] > 0 ) {
			file_put_contents( $file['opened'], str_repeat( ' ', $file['grows'] ), FILE_APPEND );
			clearstatcache( true, $file['opened'] );
		}

		return $status;
	}

	/**
	 * Reads from the open file.
	 *
	 * @since 0.2.0
	 *
	 * @param int $count How many bytes to read.
	 * @return string|false The bytes read.
	 */
	public function stream_read( int $count ): string|false {
		return fread( $this->handle, max( 1, $count ) );
	}

	/**
	 * Tells whether the end of the open file was reached.
	 *
	 * @since 0.2.0
	 *
	 * @return bool True at the end.
	 */
	public function stream_eof(): bool {
		return feof( $this->handle );
	}

	/**
	 * Accepts no stream option.
	 *
	 * @since 0.2.0
	 *
	 * @param int      $option The option.
	 * @param int      $arg1   Its first argument.
	 * @param int|null $arg2   Its second argument.
	 * @return bool Always false.
	 */
	public function stream_set_option( int $option, int $arg1, ?int $arg2 ): bool {
		unset( $option, $arg1, $arg2 );

		return false;
	}

	/**
	 * Closes the open file.
	 *
	 * @since 0.2.0
	 */
	public function stream_close(): void {
		fclose( $this->handle );
	}

	/**
	 * Hands a path out.
	 *
	 * @since 0.2.0
	 *
	 * @param string $checked The file a check sees.
	 * @param string $opened  The file opening gives.
	 * @param int    $grows   How many bytes the file opened grows by once its status is read.
	 * @return string The path.
	 */
	private static function path( string $checked, string $opened, int $grows ): string {
		$name = 'file-' . count( self::$paths );

		self::$paths[ $name ] = array(
			'checked' => $checked,
			'opened'  => $opened,
			'grows'   => $grows,
		);

		return self::SCHEME . '://' . $name;
	}

	/**
	 * Returns the name of a path of the scheme.
	 *
	 * @since 0.2.0
	 *
	 * @param string $path The path.
	 * @return string The name.
	 */
	private static function nameOf( string $path ): string {
		return substr( $path, strlen( self::SCHEME ) + 3 );
	}
}
