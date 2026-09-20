<?php
/**
 * Builds the release zip: dist/seocart-<version>.zip and dist/SHA256SUMS
 *
 * Run it on a prepared tree: after `composer install` has written vendor-scoped/ and
 * `npm run build` has written build/. Follow it with bin/check-zip.php; a zip that has
 * not passed that check must not be published.
 *
 * The classes are required by path instead of through Composer's autoloader, which does
 * not map tools/ after `composer install --no-dev` and does not exist at all in a tree
 * that was never installed.
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
	fwrite( STDERR, "build-zip: the PHP zip extension is not loaded, and ZipArchive is what writes the archive. Install or enable ext-zip.\n" );
	exit( 1 );
}

require_once dirname( __DIR__ ) . '/tools/Packaging/DistIgnore.php';
require_once dirname( __DIR__ ) . '/tools/Packaging/PluginPackage.php';
require_once dirname( __DIR__ ) . '/tools/Packaging/ZipBuilder.php';

exit( SEOCart\Tools\Packaging\ZipBuilder::main( dirname( __DIR__ ), $argv ) );
