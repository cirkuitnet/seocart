<?php
/**
 * ZipBuilder: cuts the release zip from a prepared working tree
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tools\Packaging;

use InvalidArgumentException;
use RuntimeException;
use ZipArchive;

/**
 * Builds `seocart-<version>.zip` from everything .distignore does not exclude.
 *
 * Written on PHP's ZipArchive so that there is one implementation for every platform;
 * the FreeBSD development server has no `zip` binary.
 *
 * The output is reproducible. Entries are added in byte order of their paths, every
 * entry carries the same timestamp and the same permissions, and the time zone is
 * pinned while the archive is written, so the modification times, modes and order of
 * the files on disk never reach the zip. Two builds of the same tree with the same
 * PHP, libzip and zlib are byte-identical.
 *
 * Symbolic links are never followed and never stored. A link under an excluded path is
 * not even looked at; a link anywhere else stops the build, because a release is made
 * of regular files and a link may point outside the tree.
 *
 * @since 0.1.0
 */
final class ZipBuilder {

	/**
	 * Directories that are built, not committed, and must exist before a zip is cut.
	 *
	 * They are git-ignored and they ship; a zip without them would install and then fail
	 * at runtime. Each maps to the way it is produced, which the refusal quotes.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, string>
	 */
	public const BUILT_DIRECTORIES = array(
		'vendor-scoped' => 'Run `composer install`: Strauss writes it, and Strauss is a development dependency, so `--no-dev` leaves it out.',
		'build'         => 'Run `npm ci && npm run build`.',
	);

	/**
	 * The earliest time a zip entry can carry: 1980-01-01T00:00:00Z.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private const EARLIEST_TIMESTAMP = 315532800;

	/**
	 * The Unix mode stored for every entry: a regular file, rw-r--r--.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private const ENTRY_MODE = 0100644;

	/**
	 * Absolute path of the plugin root, without a trailing slash.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private string $root;

	/**
	 * Decides which paths are left out.
	 *
	 * @since 0.1.0
	 *
	 * @var DistIgnore
	 */
	private DistIgnore $ignore;

	/**
	 * The modification time stored for every entry, as a Unix timestamp.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private int $timestamp;

	/**
	 * Prepares a build of one working tree.
	 *
	 * @since 0.1.0
	 *
	 * @param string     $root      Path of the plugin root.
	 * @param DistIgnore $ignore    Decides which paths are left out.
	 * @param int        $timestamp Modification time for every entry. Times before 1980 cannot
	 *                              be stored in a zip and are raised to 1980-01-01.
	 *
	 * @throws InvalidArgumentException When the root is not a directory.
	 */
	public function __construct( string $root, DistIgnore $ignore, int $timestamp ) {
		$resolved = realpath( $root );

		if ( false === $resolved || ! is_dir( $resolved ) ) {
			throw new InvalidArgumentException( "{$root} is not a directory." );
		}

		$this->root      = rtrim( str_replace( '\\', '/', $resolved ), '/' );
		$this->ignore    = $ignore;
		$this->timestamp = max( $timestamp, self::EARLIEST_TIMESTAMP );
	}

	/**
	 * Chooses the timestamp for a build: SOURCE_DATE_EPOCH, or else the time of the HEAD commit.
	 *
	 * There is deliberately no fallback to the current time. A build that cannot be
	 * reproduced must say so instead of looking like one that can.
	 *
	 * @since 0.1.0
	 *
	 * @param string $root Path of the plugin root.
	 * @return int A Unix timestamp.
	 *
	 * @throws RuntimeException When neither source yields a timestamp.
	 */
	public static function resolveTimestamp( string $root ): int {
		$epoch = getenv( 'SOURCE_DATE_EPOCH' );

		if ( false !== $epoch && '' !== $epoch ) {
			if ( ! ctype_digit( $epoch ) ) {
				throw new RuntimeException( "SOURCE_DATE_EPOCH must be a Unix timestamp in whole seconds; it is \"{$epoch}\"." );
			}

			return (int) $epoch;
		}

		$process = proc_open(
			array( 'git', '-C', $root, 'log', '-1', '--format=%ct', 'HEAD' ),
			array(
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			),
			$pipes
		);

		if ( is_resource( $process ) ) {
			$commit_time = trim( (string) stream_get_contents( $pipes[1] ) );

			fclose( $pipes[1] );
			fclose( $pipes[2] );

			if ( 0 === proc_close( $process ) && ctype_digit( $commit_time ) ) {
				return (int) $commit_time;
			}
		}

		throw new RuntimeException(
			'no timestamp for the zip entries: SOURCE_DATE_EPOCH is not set and the time of the HEAD commit could not be read from git. '
			. 'Outside a git checkout, set SOURCE_DATE_EPOCH to a Unix timestamp.'
		);
	}

	/**
	 * Lists the files that ship, relative to the plugin root, in the order they are stored.
	 *
	 * @since 0.1.0
	 *
	 * @return list<string> Paths with forward slashes, sorted by byte value.
	 *
	 * @throws RuntimeException When a path that would ship is a symbolic link or is not a regular file.
	 */
	private function files(): array {
		$files = $this->filesBeneath( '' );

		// Byte order, so that the locale of the machine never decides the order of the entries.
		sort( $files, SORT_STRING );

		return $files;
	}

	/**
	 * Builds the zip and the checksum file.
	 *
	 * The zip of this version and the checksum file left by an earlier build are removed
	 * first, so a build that is refused leaves nothing for bin/check-zip.php to pass.
	 *
	 * @since 0.1.0
	 *
	 * @param string $output_directory Directory to write to. Created when it does not exist.
	 * @return array{path: string, files: int, bytes: int, sha256: string} What was written.
	 *
	 * @throws RuntimeException When the tree is not ready to be released or the zip cannot be written.
	 */
	public function build( string $output_directory ): array {
		$main_file = $this->root . '/' . PluginPackage::MAIN_FILE;

		if ( ! is_file( $main_file ) ) {
			throw new RuntimeException( PluginPackage::MAIN_FILE . " is missing, so {$this->root} is not the plugin root." );
		}

		$version = PluginPackage::header( (string) file_get_contents( $main_file ), 'Version' );

		if ( null === $version ) {
			throw new RuntimeException( PluginPackage::MAIN_FILE . ' has no Version header, so the zip cannot be named.' );
		}

		$output_directory = rtrim( $output_directory, '/' );
		$path             = $output_directory . '/' . PluginPackage::zipFileName( $version );
		$checksums        = $output_directory . '/SHA256SUMS';

		// Before anything can refuse the build: a refused or failed build must not leave an earlier zip for check-zip to pass.
		foreach ( array( $path, $checksums ) as $earlier ) {
			if ( is_file( $earlier ) && ! unlink( $earlier ) ) {
				throw new RuntimeException( "{$earlier} is left from an earlier build and could not be removed." );
			}
		}

		foreach ( self::BUILT_DIRECTORIES as $directory => $remedy ) {
			if ( ! is_dir( $this->root . '/' . $directory ) ) {
				throw new RuntimeException(
					"{$directory}/ is missing. It is not committed and it ships, so a zip cut now would be broken. {$remedy}"
				);
			}
		}

		$files = $this->files();

		if ( ! is_dir( $output_directory ) && ! mkdir( $output_directory, 0755, true ) ) {
			throw new RuntimeException( "{$output_directory} could not be created." );
		}

		$this->write( $path, $files );

		$sha256 = (string) hash_file( 'sha256', $path );

		// The format `sha256sum --check` and `shasum -a 256 --check` read: hash, two spaces, name.
		if ( false === file_put_contents( $checksums, $sha256 . '  ' . basename( $path ) . "\n" ) ) {
			throw new RuntimeException( 'SHA256SUMS could not be written.' );
		}

		return array(
			'path'   => $path,
			'files'  => count( $files ),
			'bytes'  => (int) filesize( $path ),
			'sha256' => $sha256,
		);
	}

	/**
	 * Runs the builder for bin/build-zip.php.
	 *
	 * @since 0.1.0
	 *
	 * @param string   $root      Path of the plugin root.
	 * @param string[] $arguments The command line, script name included.
	 * @return int The process exit code.
	 */
	public static function main( string $root, array $arguments ): int {
		$usage = "Usage: php bin/build-zip.php\n\n"
			. 'Builds dist/' . PluginPackage::zipFileName( '<version>' ) . " and dist/SHA256SUMS from everything .distignore does not exclude.\n"
			. "Entry timestamps come from SOURCE_DATE_EPOCH, or else from the HEAD commit.\n";

		if ( array( '--help' ) === array_slice( $arguments, 1 ) ) {
			fwrite( STDOUT, $usage );
			return 0;
		}

		if ( count( $arguments ) > 1 ) {
			fwrite( STDERR, 'build-zip: unrecognized argument "' . $arguments[1] . "\".\n\n" . $usage );
			return 2;
		}

		try {
			$builder = new self( $root, DistIgnore::fromFile( $root . '/.distignore' ), self::resolveTimestamp( $root ) );
			$result  = $builder->build( $root . '/dist' );
		} catch ( InvalidArgumentException | RuntimeException $error ) {
			fwrite( STDERR, 'build-zip: ' . $error->getMessage() . "\n" );
			return 1;
		}

		fwrite(
			STDOUT,
			sprintf(
				"build-zip: wrote %s\n           %d files, %d bytes (%.2f MiB)\n           sha256 %s\n",
				$result['path'],
				$result['files'],
				$result['bytes'],
				$result['bytes'] / 1048576,
				$result['sha256']
			)
		);

		return 0;
	}

	/**
	 * Collects the files that ship beneath one directory.
	 *
	 * An excluded directory is pruned without being read, so nothing beneath vendor/ or
	 * node_modules/ is ever examined.
	 *
	 * @since 0.1.0
	 *
	 * @param string $directory Directory relative to the plugin root; an empty string for the root itself.
	 * @return list<string> Relative paths of the files beneath it, in no particular order.
	 *
	 * @throws RuntimeException When a path that would ship is a symbolic link or is not a regular file.
	 */
	private function filesBeneath( string $directory ): array {
		$absolute = '' === $directory ? $this->root : $this->root . '/' . $directory;
		$names    = scandir( $absolute, SCANDIR_SORT_NONE );

		if ( false === $names ) {
			throw new RuntimeException( "{$absolute} could not be read." );
		}

		$files = array();

		foreach ( $names as $name ) {
			if ( '.' === $name || '..' === $name ) {
				continue;
			}

			$relative = '' === $directory ? $name : $directory . '/' . $name;

			if ( $this->ignore->excludes( $relative ) ) {
				continue;
			}

			$path = $this->root . '/' . $relative;

			if ( is_link( $path ) ) {
				throw new RuntimeException(
					"{$relative} is a symbolic link. Links are never followed or stored: replace it with the real file, or exclude it in .distignore."
				);
			}

			if ( is_dir( $path ) ) {
				$files = array_merge( $files, $this->filesBeneath( $relative ) );
			} elseif ( is_file( $path ) ) {
				$files[] = $relative;
			} else {
				throw new RuntimeException( "{$relative} is neither a regular file nor a directory." );
			}
		}

		return $files;
	}

	/**
	 * Writes the archive.
	 *
	 * @since 0.1.0
	 *
	 * @param string   $path  Where to write the zip. An existing file is replaced.
	 * @param string[] $files Relative paths of the files to store, in order.
	 *
	 * @throws RuntimeException When the zip cannot be written.
	 */
	private function write( string $path, array $files ): void {
		$zip    = new ZipArchive();
		$opened = $zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE );

		if ( true !== $opened ) {
			throw new RuntimeException( "{$path} could not be opened for writing (ZipArchive error {$opened})." );
		}

		/*
		 * libzip converts the entry time to the zip format's local-time fields with the C
		 * library, which reads the TZ environment variable. Pinning it for the duration of
		 * the write keeps the machine's time zone out of the archive; the conversion happens
		 * in close(), so the variable is restored only after that.
		 */
		$time_zone = getenv( 'TZ' );
		putenv( 'TZ=UTC' );

		try {
			foreach ( $files as $relative ) {
				$entry = PluginPackage::SLUG . '/' . $relative;

				if (
					! $zip->addFile( $this->root . '/' . $relative, $entry )
					|| ! $zip->setCompressionName( $entry, ZipArchive::CM_DEFLATE, 9 )
					|| ! $zip->setMtimeName( $entry, $this->timestamp )
					|| ! $zip->setExternalAttributesName( $entry, ZipArchive::OPSYS_UNIX, self::ENTRY_MODE << 16 )
				) {
					$status = $zip->getStatusString();

					// Drop what was queued, so that destroying the object does not write a partial archive.
					$zip->unchangeAll();

					throw new RuntimeException( "{$relative} could not be added to the zip: {$status}" );
				}
			}

			if ( ! $zip->close() ) {
				throw new RuntimeException( "{$path} could not be written: " . $zip->getStatusString() );
			}
		} finally {
			putenv( false === $time_zone ? 'TZ' : 'TZ=' . $time_zone );
		}
	}
}
