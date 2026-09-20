<?php
/**
 * Runs Strauss after `composer install` / `composer update`
 *
 * Strauss copies runtime dependencies into vendor-scoped/, which is the only copy the
 * plugin ever loads: development and the release zip resolve libraries from the same
 * path. Strauss is a development dependency, so after `composer install --no-dev` it is
 * legitimately absent and this wrapper skips. In every other case a missing Strauss is
 * an error, because skipping quietly would produce a checkout — or a release — without
 * vendor-scoped/.
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

$seocart_root    = dirname( __DIR__ );
$seocart_strauss = $seocart_root . '/vendor/bin/strauss';

if ( ! is_file( $seocart_strauss ) ) {
	// Composer exports COMPOSER_DEV_MODE=0 to scripts when it runs with --no-dev.
	if ( '0' === getenv( 'COMPOSER_DEV_MODE' ) ) {
		fwrite( STDOUT, "scope-vendor: --no-dev install, Strauss is not available; skipping.\n" );
		exit( 0 );
	}

	fwrite( STDERR, "scope-vendor: vendor/bin/strauss is missing. Run `composer install` first.\n" );
	exit( 1 );
}

chdir( $seocart_root );
passthru( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $seocart_strauss ), $seocart_exit_code );
exit( $seocart_exit_code );
