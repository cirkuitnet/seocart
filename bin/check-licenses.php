<?php
/**
 * Checks every runtime dependency's licence against the GPLv3-compatible allow-list
 *
 * Usage: php bin/check-licenses.php [--composer-lock=<path>] [--package-lock=<path>]
 *
 * Behind `composer licenses:check`. Only what ships is judged: the non-dev packages of
 * composer.lock and the production packages of package-lock.json. The allow-list lives in
 * tools/WpOrg/LicenseAllowList.php. WordPress is never loaded.
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

use SEOCart\Tools\WpOrg\LicenseCheckCommand;

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

$seocart_root = dirname( __DIR__ );

if ( ! is_file( $seocart_root . '/vendor/autoload.php' ) ) {
	fwrite( STDERR, "check-licenses: vendor/autoload.php is missing. Run `composer install` first.\n" );
	exit( 2 );
}

require $seocart_root . '/vendor/autoload.php';

$seocart_command = new LicenseCheckCommand(
	static function ( string $line ): void {
		fwrite( STDOUT, $line . "\n" );
	}
);

exit( $seocart_command->run( array_slice( $argv, 1 ), $seocart_root ) );
