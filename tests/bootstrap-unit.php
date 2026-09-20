<?php
/**
 * Bootstrap for the unit and tools test suites
 *
 * WordPress is never loaded here. Every file under src/ carries the direct-access guard
 * the WordPress.org directory expects, so ABSPATH is defined as a placeholder to let those
 * files load; it points at a directory that does not exist, which makes any accidental
 * attempt to include WordPress from a unit test fail loudly instead of silently working.
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- ABSPATH is WordPress's own constant, defined here only as a placeholder.
	define( 'ABSPATH', __DIR__ . '/__wordpress-is-not-loaded-in-unit-tests__/' );
}

require dirname( __DIR__ ) . '/vendor/autoload.php';
