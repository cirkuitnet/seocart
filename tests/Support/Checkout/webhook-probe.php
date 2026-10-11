<?php
/**
 * Delivers a webhook to the receiver in a process of its own, through the kernel's wiring, and reports how it ended
 *
 * Usage: php tests/Support/Checkout/webhook-probe.php <result-file> deliver <request>
 *
 * The webhook concurrency and crash tests start this script through ChildProcessProbe::start(),
 * so that a delivery runs on a connection of its own while the test's own process holds locks, or
 * so that a delivery can die the way a request does. It boots WordPress against the installed test
 * site without loading the plugin, and builds the container with PlacementKernel over a Database
 * whose guards throw, as the tests' do; its sleeper never pauses and counts the retries.
 *
 * The request is base64 of a JSON object: `gateway_id` and `mode`, the address; `headers` and
 * `body`, the delivery as signed; and optionally `die_before`, a regular expression: the process
 * kills itself with SIGKILL just before it sends the first statement that matches, as a request
 * killed mid-way does, so the server rolls back what it had open and nothing after it runs.
 *
 * The report names what was decided (`result`), or the refusal (`refused`, `context`), or any
 * other failure, with the retries.
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

use SEOCart\Cart\Domain\CartToken;
use SEOCart\Checkout\Application\ReceiveWebhook;
use SEOCart\Contracts\Payment\Mode;
use SEOCart\Contracts\Payment\WebhookEnvelope;
use SEOCart\Platform\Database\Database;
use SEOCart\Support\Error\CodedException;
use SEOCart\Tests\Support\Checkout\PlacementKernel;
use SEOCart\Tests\Support\Doubles\FakeCartTokens;

// phpcs:disable WordPress.WP.AlternativeFunctions -- The probe writes its report to the file its parent reads.

if ( 'cli' !== PHP_SAPI || ! isset( $argv[1], $argv[2], $argv[3] ) || 'deliver' !== $argv[2] ) {
	fwrite( STDERR, 'Usage: php tests/Support/Checkout/webhook-probe.php <result-file> deliver <request>' . PHP_EOL );

	exit( 2 );
}

putenv( 'SEOCART_TESTS_LOAD_PLUGIN=0' );

/*
 * The WordPress test bootstrap deletes every post when it loads, and the products the calling
 * test sells are its own. Deletions are refused until WordPress has loaded.
 */
// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- WordPress is not loaded yet; this is how a hook is added before it is.
$GLOBALS['wp_filter']['pre_delete_post'][ PHP_INT_MIN ][] = array(
	'function'      => static fn(): bool => false,
	'accepted_args' => 1,
);

require dirname( __DIR__, 2 ) . '/bootstrap-integration.php';

remove_all_filters( 'pre_delete_post', PHP_INT_MIN );

global $wpdb;

$seocart_probe_retries = 0;
$seocart_probe_request = (array) json_decode( (string) base64_decode( $argv[3], true ), true );
$seocart_probe_die     = (string) ( $seocart_probe_request['die_before'] ?? '' );
$seocart_probe_db      = new Database(
	$wpdb,
	true,
	static function (): void {},
	5,
	static function () use ( &$seocart_probe_retries ): void {
		++$seocart_probe_retries;
	}
);

if ( '' !== $seocart_probe_die ) {
	add_filter(
		'query',
		static function ( string $query ) use ( $seocart_probe_die ): string {
			if ( 1 === preg_match( $seocart_probe_die, $query ) ) {
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- No posix extension on every host: the shell kills the process.
				exec( 'kill -9 ' . getmypid() );
			}

			return $query;
		}
	);
}

$seocart_probe_tokens            = new FakeCartTokens();
$seocart_probe_tokens->presented = CartToken::fromString( '' );
$seocart_probe_kernel            = PlacementKernel::over( $seocart_probe_db, $seocart_probe_tokens, PlacementKernel::identities(), static function (): void {}, static function (): void {} );

try {
	$seocart_probe_envelope = new WebhookEnvelope(
		(string) ( $seocart_probe_request['gateway_id'] ?? '' ),
		Mode::from( (string) ( $seocart_probe_request['mode'] ?? '' ) ),
		array_map( 'strval', (array) ( $seocart_probe_request['headers'] ?? array() ) ),
		(string) ( $seocart_probe_request['body'] ?? '' ),
		new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) )
	);
	$seocart_probe_outcome  = array( 'result' => $seocart_probe_kernel->get( ReceiveWebhook::class )->receive( $seocart_probe_envelope )->value );
} catch ( CodedException $seocart_probe_refusal ) {
	$seocart_probe_outcome = array(
		'refused' => $seocart_probe_refusal->errorCode()->value,
		'context' => $seocart_probe_refusal->context(),
	);
} catch ( Throwable $seocart_probe_failure ) {
	$seocart_probe_outcome = array( 'failure' => get_class( $seocart_probe_failure ) . ': ' . $seocart_probe_failure->getMessage() );
}

file_put_contents( $argv[1], (string) wp_json_encode( $seocart_probe_outcome + array( 'retries' => $seocart_probe_retries ) ) );
