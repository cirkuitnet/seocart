<?php
/**
 * ScopedVendorNormalizer: makes vendor-scoped/ the same bytes on every machine
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tools\Packaging;

use RuntimeException;

/**
 * Removes what differs between two honest installs from the autoloader Strauss generates.
 *
 * The vendor-scoped/ directory ships in the release zip, and a release must be reproducible:
 * whoever rebuilds a tag has to arrive at the published checksum. Composer's generator puts two
 * kinds of machine state into the files it writes there. The name of the loader class ends
 * in a suffix that is random for every run, and composer/installed.php records the branch
 * and the commit the checkout happened to be on. Neither is read by anything the plugin
 * ships, so both are replaced by values that depend on composer.lock alone.
 *
 * The working tree is normalized, not the zip: development and the release load libraries
 * from the same files (tools/scope-vendor.php calls this right after Strauss).
 *
 * @since 0.1.0
 */
final class ScopedVendorNormalizer {

	/**
	 * The files that spell out the loader class name, relative to vendor-scoped/.
	 *
	 * @since 0.1.0
	 *
	 * @var list<string>
	 */
	private const AUTOLOADER_FILES = array( 'autoload.php', 'composer/autoload_real.php', 'composer/autoload_static.php' );

	/**
	 * The file that describes the root package, relative to vendor-scoped/.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const INSTALLED_FILE = 'composer/installed.php';

	/**
	 * What Composer itself writes for a root package whose version it cannot detect.
	 *
	 * The field name maps to the PHP literal that replaces the detected value.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, string>
	 */
	private const ROOT_FIELDS = array(
		'pretty_version' => "'1.0.0+no-version-set'",
		'version'        => "'1.0.0.0'",
		'reference'      => 'NULL',
	);

	/**
	 * Returns the loader class suffix for a lock file.
	 *
	 * It must not equal the suffix of vendor/autoload.php, which is the content hash itself:
	 * the test suites load both autoloaders into one process.
	 *
	 * @since 0.1.0
	 *
	 * @param string $content_hash The `content-hash` of composer.lock.
	 * @return string 32 hexadecimal characters.
	 */
	public static function suffixFor( string $content_hash ): string {
		return md5( 'seocart/vendor-scoped:' . $content_hash );
	}

	/**
	 * Normalizes a vendor-scoped/ directory in place. Running it again changes nothing.
	 *
	 * @since 0.1.0
	 *
	 * @param string $directory    Absolute path of vendor-scoped/.
	 * @param string $content_hash The `content-hash` of composer.lock.
	 *
	 * @throws RuntimeException When a file is missing or does not have the expected shape, so
	 *                          that a change in what Strauss generates is noticed, not shipped.
	 */
	public function normalize( string $directory, string $content_hash ): void {
		if ( 1 !== preg_match( '/^[0-9a-f]{32}$/D', $content_hash ) ) {
			throw new RuntimeException( 'The content hash of composer.lock is not 32 hexadecimal characters.' );
		}

		$directory = rtrim( $directory, '/' );
		$entry     = $this->read( $directory . '/' . self::AUTOLOADER_FILES[0] );

		if ( 1 !== preg_match( '/ComposerAutoloaderInit([0-9a-f]{32})\b/', $entry, $matches ) ) {
			throw new RuntimeException( self::AUTOLOADER_FILES[0] . ' does not name a loader class with a 32-character suffix.' );
		}

		$suffix = self::suffixFor( $content_hash );

		foreach ( self::AUTOLOADER_FILES as $file ) {
			$source = $this->read( $directory . '/' . $file );

			if ( ! str_contains( $source, $matches[1] ) ) {
				throw new RuntimeException( $file . ' does not mention the loader class suffix.' );
			}

			$this->write( $directory . '/' . $file, $source, str_replace( $matches[1], $suffix, $source ) );
		}

		$source = $this->read( $directory . '/' . self::INSTALLED_FILE );

		$this->write( $directory . '/' . self::INSTALLED_FILE, $source, $this->withoutRootState( $source ) );
	}

	/**
	 * Replaces the detected version, branch and commit of the root package.
	 *
	 * Only the `root` block is touched. The `reference` of a dependency comes from
	 * composer.lock and is the same everywhere.
	 *
	 * @since 0.1.0
	 *
	 * @param string $source Contents of composer/installed.php.
	 * @return string The contents with a neutral root package.
	 *
	 * @throws RuntimeException When the root block is not where it is expected.
	 */
	private function withoutRootState( string $source ): string {
		$end = strpos( $source, "'versions' =>" );

		if ( false === $end || ! str_contains( substr( $source, 0, $end ), "'root' =>" ) ) {
			throw new RuntimeException( self::INSTALLED_FILE . ' has no root block before its versions block.' );
		}

		$root = substr( $source, 0, $end );

		foreach ( self::ROOT_FIELDS as $field => $literal ) {
			$root = preg_replace( "/('" . $field . "' => )(?:'[^']*'|NULL)/", '$1' . $literal, $root, -1, $count );

			if ( 1 !== $count || null === $root ) {
				throw new RuntimeException( self::INSTALLED_FILE . " does not state '" . $field . "' exactly once for the root package." );
			}
		}

		return $root . substr( $source, $end );
	}

	/**
	 * Reads a file.
	 *
	 * @since 0.1.0
	 *
	 * @param string $path Absolute path.
	 * @return string The contents.
	 *
	 * @throws RuntimeException When the file cannot be read.
	 */
	private function read( string $path ): string {
		$contents = is_file( $path ) ? file_get_contents( $path ) : false;

		if ( false === $contents ) {
			throw new RuntimeException( $path . ' is missing. Strauss did not generate an autoloader.' );
		}

		return $contents;
	}

	/**
	 * Writes a file only when its contents change, so a second run leaves timestamps alone.
	 *
	 * @since 0.1.0
	 *
	 * @param string $path   Absolute path.
	 * @param string $before The contents on disk.
	 * @param string $after  The contents wanted.
	 *
	 * @throws RuntimeException When the file cannot be written.
	 */
	private function write( string $path, string $before, string $after ): void {
		if ( $before !== $after && false === file_put_contents( $path, $after ) ) {
			throw new RuntimeException( $path . ' could not be written.' );
		}
	}
}
