<?php
/**
 * Allocates one order number in a process of its own, through SequenceOrderNumberGenerator, and reports it
 *
 * Usage: php tests/Support/Order/order-number-probe.php <result-file>
 *
 * The order-number soak starts many of these at once through ChildProcessProbe::start(), so that
 * many allocations meet on the counter row at the same moment, each on a connection of its own.
 * It boots WordPress against the installed test site without loading the plugin, so that the
 * allocation is the plugin's own code over a real Database, and allocates in a transaction that
 * retries on a deadlock, as a placement would.
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

use SEOCart\Order\Domain\OrderNumberGenerator;
use SEOCart\Order\Infrastructure\OrderStatements;
use SEOCart\Order\Infrastructure\SequenceOrderNumberGenerator;
use SEOCart\Platform\Database\Database;
use SEOCart\Platform\Database\RetryPolicy;

// phpcs:disable WordPress.WP.AlternativeFunctions -- The probe writes its report to the file its parent reads.

if ( 'cli' !== PHP_SAPI || ! isset( $argv[1] ) ) {
	fwrite( STDERR, 'Usage: php tests/Support/Order/order-number-probe.php <result-file>' . PHP_EOL );

	exit( 2 );
}

putenv( 'SEOCART_TESTS_LOAD_PLUGIN=0' );

require dirname( __DIR__, 2 ) . '/bootstrap-integration.php';

global $wpdb;

$seocart_probe_retries = 0;
$seocart_probe_db      = new Database(
	$wpdb,
	false,
	static function (): void {},
	5,
	static function () use ( &$seocart_probe_retries ): void {
		++$seocart_probe_retries;
	}
);

try {
	$seocart_probe_outcome = array(
		'number' => $seocart_probe_db->transaction(
			static fn(): string => ( new SequenceOrderNumberGenerator( new OrderStatements( $seocart_probe_db ) ) )->next( OrderNumberGenerator::DEFAULT_SCOPE ),
			RetryPolicy::deadlocks()
		),
	);
} catch ( Throwable $seocart_probe_failure ) {
	$seocart_probe_outcome = array( 'failure' => get_class( $seocart_probe_failure ) . ': ' . $seocart_probe_failure->getMessage() );
}

file_put_contents(
	$argv[1],
	(string) wp_json_encode(
		$seocart_probe_outcome + array(
			'retries' => $seocart_probe_retries,
			'memory'  => memory_get_peak_usage( true ),
		)
	)
);
