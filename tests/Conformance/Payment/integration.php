<?php
/**
 * Runs the integration half of the payment gateway conformance suite
 *
 * The second step of `composer test:conformance:payment`: SEOCart's own conformance tests, or,
 * when SEOCART_EXTENSION_PATH names an extension (bin/ci/extension.sh conformance sets it), that
 * extension's (ConformanceRun).
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

use SEOCart\Tests\Conformance\Payment\ConformanceRun;

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

require dirname( __DIR__, 3 ) . '/vendor/autoload.php';

exit( ConformanceRun::main( dirname( __DIR__, 3 ), (string) getenv( 'SEOCART_EXTENSION_PATH' ), 'true' === getenv( 'GITHUB_ACTIONS' ) ) );
