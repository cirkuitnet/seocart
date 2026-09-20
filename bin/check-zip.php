<?php
/**
 * Checks a built release zip and fails closed: php bin/check-zip.php <zip> [--budget-bytes=N] [--limit-bytes=N]
 *
 * Exits 0 when the zip may be published, 1 when it may not, 2 when the command line is
 * wrong. The rules and their reasons are in tools/Packaging/ZipChecker.php.
 *
 * The classes are required by path for the reason given in bin/build-zip.php.
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

if ( ! extension_loaded( 'zip' ) ) {
	fwrite( STDERR, "check-zip: the PHP zip extension is not loaded, and ZipArchive is what reads the archive. Install or enable ext-zip. Nothing was checked.\n" );
	exit( 1 );
}

require_once dirname( __DIR__ ) . '/tools/Packaging/PluginPackage.php';
require_once dirname( __DIR__ ) . '/tools/Packaging/ZipChecker.php';

exit( SEOCart\Tools\Packaging\ZipChecker::main( $argv, dirname( __DIR__ ) . '/composer.lock' ) );
