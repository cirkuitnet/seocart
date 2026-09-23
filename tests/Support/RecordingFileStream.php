<?php
/**
 * RecordingFileStream: a file stream wrapper that records every file a process opens
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support;

/**
 * Stands in for PHP's `file://` wrapper, records the path of every file opened, and delegates the
 * real work to the built-in wrapper.
 *
 * Opening covers `include` and `require` as well as every read and write, so the recording shows
 * both the classes a piece of code loads and any other file it touches. Checking whether a file
 * exists is not an opening and is not recorded. The declarations probe uses it to show that
 * building the operation registry reads nothing but class files.
 *
 * @since 0.1.0
 */
final class RecordingFileStream {

	/**
	 * The stream context PHP assigns to a wrapper instance.
	 *
	 * @since 0.1.0
	 *
	 * @var resource|null
	 */
	public $context;

	/**
	 * The paths opened since start(), in order.
	 *
	 * @since 0.1.0
	 *
	 * @var list<string>
	 */
	private static array $opened = array();

	/**
	 * The real stream this instance delegates to.
	 *
	 * @since 0.1.0
	 *
	 * @var resource
	 */
	private $handle;

	/**
	 * Replaces the file wrapper and starts a new recording.
	 *
	 * @since 0.1.0
	 */
	public static function start(): void {
		self::$opened = array();

		stream_wrapper_unregister( 'file' );
		stream_wrapper_register( 'file', self::class );
	}

	/**
	 * Restores PHP's own file wrapper and returns what was recorded.
	 *
	 * @since 0.1.0
	 *
	 * @return list<string> The paths opened, in order.
	 */
	public static function stop(): array {
		stream_wrapper_restore( 'file' );

		return self::$opened;
	}

	/**
	 * Opens a file with the built-in wrapper, after recording its path.
	 *
	 * @since 0.1.0
	 *
	 * @param string      $path        The path.
	 * @param string      $mode        The fopen() mode.
	 * @param int         $options     Stream options.
	 * @param string|null $opened_path Set to the path actually opened.
	 * @return bool Whether the file was opened.
	 */
	public function stream_open( string $path, string $mode, int $options, ?string &$opened_path ): bool {
		self::$opened[] = $path;

		$handle = self::unwrapped( static fn() => fopen( $path, $mode, (bool) ( $options & STREAM_USE_PATH ) ) );

		if ( ! is_resource( $handle ) ) {
			return false;
		}

		$this->handle = $handle;
		$opened_path  = $path;

		return true;
	}

	/**
	 * Reads from the file.
	 *
	 * @since 0.1.0
	 *
	 * @param int $count How many bytes to read.
	 * @return string|false The bytes read.
	 */
	public function stream_read( int $count ): string|false {
		return fread( $this->handle, max( 1, $count ) );
	}

	/**
	 * Writes to the file.
	 *
	 * @since 0.1.0
	 *
	 * @param string $data The bytes to write.
	 * @return int How many bytes were written.
	 */
	public function stream_write( string $data ): int {
		return (int) fwrite( $this->handle, $data );
	}

	/**
	 * Tells whether the end of the file was reached.
	 *
	 * @since 0.1.0
	 *
	 * @return bool True at the end.
	 */
	public function stream_eof(): bool {
		return feof( $this->handle );
	}

	/**
	 * Moves the file position.
	 *
	 * @since 0.1.0
	 *
	 * @param int $offset The offset.
	 * @param int $whence SEEK_SET, SEEK_CUR or SEEK_END.
	 * @return bool Whether the position moved.
	 */
	public function stream_seek( int $offset, int $whence ): bool {
		return 0 === fseek( $this->handle, $offset, $whence );
	}

	/**
	 * Returns the file position.
	 *
	 * @since 0.1.0
	 *
	 * @return int The position.
	 */
	public function stream_tell(): int {
		return (int) ftell( $this->handle );
	}

	/**
	 * Returns the open file's status.
	 *
	 * @since 0.1.0
	 *
	 * @return array<int|string, int>|false The status.
	 */
	public function stream_stat(): array|false {
		return fstat( $this->handle );
	}

	/**
	 * Accepts no stream option: the defaults of the built-in wrapper apply.
	 *
	 * @since 0.1.0
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
	 * Closes the file.
	 *
	 * @since 0.1.0
	 */
	public function stream_close(): void {
		fclose( $this->handle );
	}

	/**
	 * Returns a path's status, for file_exists(), is_file() and the like. Not recorded.
	 *
	 * @since 0.1.0
	 *
	 * @param string $path  The path.
	 * @param int    $flags STREAM_URL_STAT_* flags.
	 * @return array<int|string, int>|false The status, or false when the path does not exist.
	 */
	public function url_stat( string $path, int $flags ): array|false {
		return self::unwrapped(
			static function () use ( $path, $flags ) {
				if ( ! file_exists( $path ) ) {
					return false;
				}

				return ( $flags & STREAM_URL_STAT_LINK ) ? lstat( $path ) : stat( $path );
			}
		);
	}

	/**
	 * Runs a filesystem call with PHP's own file wrapper in place.
	 *
	 * @since 0.1.0
	 *
	 * @param callable $call The call.
	 * @return mixed What the call returns.
	 */
	private static function unwrapped( callable $call ): mixed {
		stream_wrapper_restore( 'file' );

		try {
			return $call();
		} finally {
			stream_wrapper_unregister( 'file' );
			stream_wrapper_register( 'file', self::class );
		}
	}
}
