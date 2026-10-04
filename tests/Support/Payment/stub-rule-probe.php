<?php
/**
 * Reports, in a process of its own, whether the kernel's gateway registry offers the stand-in gateway
 *
 * Usage: php tests/Support/Payment/stub-rule-probe.php <result-file>
 *
 * The test of the stand-in's production rule starts this script through ChildProcessProbe::run():
 * a constant, once defined, cannot be undefined, so a site without SEOCART_STUB_GATEWAY needs a
 * process of its own. The parent sets SEOCART_TESTS_WITHOUT_STUB_GATEWAY for the child when the
 * integration bootstrap must leave the constant undefined. The test site's environment type is
 * WordPress's default, `production`. The report says the environment type, whether the constant
 * is defined, and the mode the registry offers the stand-in in, or null when it does not offer it.
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

use SEOCart\Payment\Application\Gateways;
use SEOCart\Payment\Infrastructure\Gateway\StubGateway;
use SEOCart\Platform\Database\Database;
use SEOCart\Tests\Support\KernelContainer;

// phpcs:disable WordPress.WP.AlternativeFunctions -- The probe writes its report to the file its parent reads.

if ( 'cli' !== PHP_SAPI || ! isset( $argv[1] ) ) {
	fwrite( STDERR, 'Usage: php tests/Support/Payment/stub-rule-probe.php <result-file>' . PHP_EOL );

	exit( 2 );
}

putenv( 'SEOCART_TESTS_LOAD_PLUGIN=0' );

require dirname( __DIR__, 2 ) . '/bootstrap-integration.php';

global $wpdb;

$seocart_probe_kernel = KernelContainer::build( new Database( $wpdb, true, static function (): void {} ), static function (): void {} );
$seocart_probe_mode   = $seocart_probe_kernel->get( Gateways::class )->configuredMode( StubGateway::ID );

file_put_contents(
	$argv[1],
	(string) wp_json_encode(
		array(
			'environment_type' => wp_get_environment_type(),
			'constant_defined' => defined( 'SEOCART_STUB_GATEWAY' ),
			'stub_mode'        => $seocart_probe_mode?->value,
		)
	)
);
