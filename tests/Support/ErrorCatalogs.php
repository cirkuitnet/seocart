<?php
/**
 * ErrorCatalogs: finds every error catalog declared under a directory of the repository
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support;

use SEOCart\Support\Error\ErrorCode;
use SEOCart\Tests\Unit\Support\PhpSource;

/**
 * The discovery of error catalogs that the error-table tests share.
 *
 * Owns one fact: what counts as an error catalog when the code base is searched. Every enum
 * under the directory is loaded, since enums are plain PHP, and kept when it implements
 * ErrorCode, directly or through another interface. The kernel's hand-kept list of catalogs
 * is compared with this search.
 *
 * @since 0.1.0
 */
final class ErrorCatalogs {

	/**
	 * Finds every error catalog declared under a directory.
	 *
	 * @since 0.1.0
	 *
	 * @param string $directory A directory relative to the repository root, such as `src`.
	 * @return array<class-string<ErrorCode>, string> The catalogs, sorted by name, each with the file that declares it.
	 */
	public static function under( string $directory ): array {
		$catalogs = array();

		foreach ( PhpSource::files( $directory ) as $file => $source ) {
			if ( ! str_contains( $source, 'enum ' ) ) {
				continue;
			}

			foreach ( PhpSource::declarations( $source ) as $class ) {
				if ( enum_exists( $class ) && is_subclass_of( $class, ErrorCode::class ) ) {
					$catalogs[ $class ] = $file;
				}
			}
		}

		ksort( $catalogs );

		return $catalogs;
	}
}
