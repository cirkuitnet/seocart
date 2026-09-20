<?php
/**
 * RuntimePackages: lists the dependencies that ship inside the plugin
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tools\WpOrg;

/**
 * Reads the runtime packages, and their declared licences, out of the two lockfiles.
 *
 * "Runtime" means what reaches a merchant's site: Composer's non-dev `packages`, and the
 * npm packages that are not strictly part of the `devDependencies` tree. Build tools
 * never ship, so their licences are not the directory's concern. One convention follows
 * from this: an npm package whose code is bundled into build/ must be declared under
 * `dependencies`, not `devDependencies`, or this gate does not see it.
 *
 * @since 0.1.0
 */
final class RuntimePackages {

	/**
	 * Lists the non-dev packages of a decoded composer.lock.
	 *
	 * Composer's `license` field is a list in which several entries are alternatives, so
	 * they are joined with `OR`.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $lock The decoded composer.lock.
	 * @return list<array{name: string, version: string, license: string|null}> The runtime packages;
	 *                                                                           a null licence means none is declared.
	 */
	public static function fromComposerLock( array $lock ): array {
		$packages = array();

		foreach ( (array) ( $lock['packages'] ?? array() ) as $package ) {
			$licenses = array_values( array_filter( (array) ( $package['license'] ?? array() ), 'is_string' ) );
			$license  = $licenses[0] ?? null;

			if ( count( $licenses ) > 1 ) {
				$license = '(' . implode( ') OR (', $licenses ) . ')';
			}

			$packages[] = array(
				'name'    => (string) ( $package['name'] ?? '(unnamed)' ),
				'version' => (string) ( $package['version'] ?? '' ),
				'license' => $license,
			);
		}

		return $packages;
	}

	/**
	 * Lists the production packages of a decoded package-lock.json (lockfile version 2 or 3).
	 *
	 * A package marked `dev` is reachable only through `devDependencies`. Everything else —
	 * including `optional`, `devOptional` and `peer` packages — can be installed by
	 * `npm ci --omit=dev` and is treated as runtime. The root project and workspace links
	 * are not dependencies.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $lock The decoded package-lock.json.
	 * @return list<array{name: string, version: string, license: string|null}> The runtime packages;
	 *                                                                           a null licence means none is declared.
	 */
	public static function fromPackageLock( array $lock ): array {
		$packages = array();

		foreach ( (array) ( $lock['packages'] ?? array() ) as $path => $package ) {
			if ( '' === $path || true === ( $package['dev'] ?? false ) || true === ( $package['link'] ?? false ) ) {
				continue;
			}

			$license = $package['license'] ?? null;

			// Packages published before npm 2 declare `"license": { "type": "MIT" }`.
			if ( is_array( $license ) ) {
				$license = $license['type'] ?? null;
			}

			$segments = explode( 'node_modules/', (string) $path );

			$packages[] = array(
				'name'    => (string) ( $package['name'] ?? end( $segments ) ),
				'version' => (string) ( $package['version'] ?? '' ),
				'license' => is_string( $license ) && '' !== trim( $license ) ? $license : null,
			);
		}

		return $packages;
	}
}
