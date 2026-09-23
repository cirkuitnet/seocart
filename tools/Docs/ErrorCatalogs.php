<?php
/**
 * ErrorCatalogs: finds the error catalogs a source tree declares
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tools\Docs;

use SEOCart\Support\Error\ErrorCode;
use SEOCart\Support\Error\ErrorTable;

/**
 * Composes the one error table from every catalog under a PSR-4 source directory.
 *
 * This class owns one fact for the generators: which catalogs the documented error table is
 * composed of — every enum under `src/` that implements ErrorCode, found without a list anyone
 * has to keep. A file is loaded only when its text declares an enum implementing ErrorCode, and an
 * enum can extend nothing, so the search loads no class that needs WordPress.
 *
 * @since 0.1.0
 */
final class ErrorCatalogs {

	/**
	 * Composes the error table of a source directory.
	 *
	 * @since 0.1.0
	 *
	 * @param string $directory Absolute path of the directory the namespace prefix maps to.
	 * @param string $prefix    Optional. The namespace the directory holds. Default `SEOCart\`.
	 * @return ErrorTable The table.
	 */
	public static function table( string $directory, string $prefix = 'SEOCart\\' ): ErrorTable {
		return ErrorTable::compose( ...self::find( $directory, $prefix ) );
	}

	/**
	 * Finds the catalogs of a source directory.
	 *
	 * @since 0.1.0
	 *
	 * @throws \RuntimeException When the directory cannot be read.
	 *
	 * @param string $directory Absolute path of the directory the namespace prefix maps to.
	 * @param string $prefix    Optional. The namespace the directory holds. Default `SEOCart\`.
	 * @return list<class-string<ErrorCode>> The catalogs, in name order.
	 */
	public static function find( string $directory, string $prefix = 'SEOCart\\' ): array {
		$directory = rtrim( $directory, '/' );

		if ( ! is_dir( $directory ) ) {
			throw new \RuntimeException( 'The source directory ' . $directory . ' does not exist.' );
		}

		$catalogs = array();
		$files    = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $directory, \FilesystemIterator::SKIP_DOTS ) );

		foreach ( $files as $file ) {
			if ( ! $file instanceof \SplFileInfo || 'php' !== $file->getExtension() ) {
				continue;
			}

			$source = (string) file_get_contents( $file->getPathname() );

			if ( 1 !== preg_match( '/^\s*enum\s+\w+\s*:\s*string\s+implements\s[^{]*\bErrorCode\b/m', $source ) ) {
				continue;
			}

			$relative = substr( str_replace( '\\', '/', $file->getPathname() ), strlen( $directory ) + 1, -4 );
			$class    = $prefix . str_replace( '/', '\\', $relative );

			if ( enum_exists( $class ) && is_subclass_of( $class, ErrorCode::class ) ) {
				$catalogs[] = $class;
			}
		}

		sort( $catalogs );

		return $catalogs;
	}
}
