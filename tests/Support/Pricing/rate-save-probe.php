<?php
/**
 * Saves a rate set of one rate in a process of its own, through MysqlExchangeRates, and reports the version it took
 *
 * Usage: php tests/Support/Pricing/rate-save-probe.php <result-file> <currency> <rate>
 *
 * The concurrent-save test starts one of these while its own save is between taking the next
 * version and inserting it, so that two saves meet on a connection each. It boots WordPress
 * against the installed test site without loading the plugin, so that the save is the plugin's own
 * code over a real Database, under the rates lock as the kernel takes it on a host whose locks are
 * the server's, in a store whose base currency is USD.
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

use SEOCart\Platform\Authorization\Actor;
use SEOCart\Platform\Database\Database;
use SEOCart\Platform\Database\LockMode;
use SEOCart\Platform\Database\LockService;
use SEOCart\Pricing\Application\ManualRate;
use SEOCart\Pricing\Infrastructure\MysqlExchangeRates;
use SEOCart\Support\Currency;
use SEOCart\Support\Decimal;

// phpcs:disable WordPress.WP.AlternativeFunctions -- The probe writes its report to the file its parent reads.

if ( 'cli' !== PHP_SAPI || ! isset( $argv[1], $argv[2], $argv[3] ) ) {
	fwrite( STDERR, 'Usage: php tests/Support/Pricing/rate-save-probe.php <result-file> <currency> <rate>' . PHP_EOL );

	exit( 2 );
}

putenv( 'SEOCART_TESTS_LOAD_PLUGIN=0' );

require dirname( __DIR__, 2 ) . '/bootstrap-integration.php';

global $wpdb;

$seocart_probe_db    = new Database( $wpdb, false, static function (): void {} );
$seocart_probe_rates = new MysqlExchangeRates(
	$seocart_probe_db,
	$seocart_probe_db,
	array( new LockService( $seocart_probe_db, LockMode::GetLock ), 'withLock' ),
	static fn(): Currency => Currency::of( 'USD' ),
	static function (): void {}
);

try {
	$seocart_probe_outcome = array( 'version' => $seocart_probe_rates->saveVersion( array( new ManualRate( Currency::of( 'USD' ), Currency::of( $argv[2] ), Decimal::of( $argv[3] ) ) ), Actor::user( 0 ) ) );
} catch ( Throwable $seocart_probe_failure ) {
	$seocart_probe_outcome = array( 'failure' => get_class( $seocart_probe_failure ) . ': ' . $seocart_probe_failure->getMessage() );
}

file_put_contents( $argv[1], (string) wp_json_encode( $seocart_probe_outcome ) );
