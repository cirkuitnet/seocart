<?php
/**
 * Regenerates, or drift-checks, every generated document that is committed
 *
 * Usage: php bin/generate-docs.php [--check]
 *
 * Behind `composer docs:generate` and `composer docs:check`. Without an argument every
 * generated document is rewritten from its source. With `--check` nothing is written,
 * and the exit code is non-zero when a committed document has drifted from its source
 * or when a generator skipped a source item it does not declare it skips. WordPress is
 * never loaded.
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

use SEOCart\Platform\Http\OutboundEndpoints;
use SEOCart\Tools\Docs\DocsRunner;
use SEOCart\Tools\Docs\ExternalServicesSection;

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

$seocart_root = dirname( __DIR__ );

if ( ! is_file( $seocart_root . '/vendor/autoload.php' ) ) {
	fwrite( STDERR, "generate-docs: vendor/autoload.php is missing. Run `composer install` first.\n" );
	exit( 2 );
}

/*
 * The generators read declarations under src/. Every file there carries the direct-access
 * guard the WordPress.org directory expects, so ABSPATH is defined as a placeholder, exactly
 * as tests/bootstrap-unit.php does: the path does not exist, which makes any accidental
 * attempt to include WordPress fail loudly.
 */
if ( ! defined( 'ABSPATH' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- ABSPATH is WordPress's own constant, defined here only as a placeholder.
	define( 'ABSPATH', $seocart_root . '/__wordpress-is-not-loaded-by-command-line-tools__/' );
}

require $seocart_root . '/vendor/autoload.php';

/*
 * Every generator, in the order it runs. Later generators (docs/openapi.json and the
 * reference documents under docs/reference/) are added to this list and to nothing else.
 */
$seocart_generators = array(
	new ExternalServicesSection( OutboundEndpoints::all() ),
);

$seocart_runner = new DocsRunner(
	$seocart_generators,
	$seocart_root,
	static function ( string $line ): void {
		fwrite( STDOUT, $line . "\n" );
	}
);

exit( $seocart_runner->main( array_slice( $argv, 1 ) ) );
