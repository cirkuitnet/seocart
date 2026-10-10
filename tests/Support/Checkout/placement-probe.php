<?php
/**
 * Places an order, or settles one, in a process of its own, through the kernel's wiring, and reports how it ended
 *
 * Usage: php tests/Support/Checkout/placement-probe.php <result-file> place <request>
 *        php tests/Support/Checkout/placement-probe.php <result-file> settle <order-uuid>
 *        php tests/Support/Checkout/placement-probe.php <result-file> <window-end|capture|void|capture-and-die> <request>
 *
 * The placement concurrency and recovery tests start this script through
 * ChildProcessProbe::start(), so that a placement or a settlement runs on a connection of its own
 * while the test's own placement holds locks, or so that a placement can die the way a request
 * does. It boots WordPress against the installed test site without loading the plugin, and builds
 * the container with PlacementKernel over a Database whose guards throw, as the tests' do; its
 * sleeper never pauses and counts the retries.
 *
 * - `place`: the request is base64 of a JSON object: `token`, the cart token the client presents;
 *   `input`, the placement's input; and optionally `die_after_commit`, a count of COMMITs after
 *   which the publisher's wake kills the process with SIGKILL, as a request killed right after
 *   its unit of work committed: nothing after it runs, not even PHP's shutdown.
 * - `settle`: the stub gateway's answer for the order's intent, as a webhook delivers it, is
 *   applied through SettlePlacement.
 * - `window-end`: the end of the shopper's time to act, as reconciliation runs it for the order
 *   named by the request's `order_uuid`: its payment voided at the gateway on no user's authority,
 *   then the void settled through SettlePlacement.
 * - `capture` and `void`: the capture or the void operation's service, for the request's
 *   `intent_uuid`, as `wp seocart payment capture|void` runs it for the request's `user_id`, who is
 *   granted both capabilities; a void gives the request's `reason`.
 * - `capture-and-die`: a capture whose process the gateway kills with SIGKILL once the provider
 *   made the capture and remembered it, before anything is recorded.
 *   For these three, a request's `provider_file` makes the gateway a FileProviderGateway that
 *   remembers in that file, which the test's own process shares.
 *
 * The report names the answer (`answer_json`, as encoded), or the refusal (`refused`, `context`,
 * `details`), or any other failure, with the retries.
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

use SEOCart\Cart\Domain\CartToken;
use SEOCart\Checkout\Application\PlaceOrder;
use SEOCart\Checkout\Application\SettlePlacement;
use SEOCart\Contracts\Payment\Mode;
use SEOCart\Contracts\Payment\PaymentGateway;
use SEOCart\Contracts\Payment\PaymentQuery;
use SEOCart\Order\Infrastructure\OrderTables;
use SEOCart\Payment\Application\PaymentService;
use SEOCart\Payment\Domain\VoidReason;
use SEOCart\Payment\Infrastructure\Gateway\StubGateway;
use SEOCart\Payment\Infrastructure\PaymentTables;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Platform\Database\Database;
use SEOCart\Support\Currency;
use SEOCart\Support\Error\CodedException;
use SEOCart\Support\Money;
use SEOCart\Tests\Support\Checkout\PlacementKernel;
use SEOCart\Tests\Support\Doubles\FakeCartTokens;
use SEOCart\Tests\Support\Doubles\FileProviderGateway;
use SEOCart\Tests\Support\Doubles\RecordingGateway;

// phpcs:disable WordPress.WP.AlternativeFunctions -- The probe writes its report to the file its parent reads.
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- The probe reads the order's intent to ask the stub about it.

if ( 'cli' !== PHP_SAPI || ! isset( $argv[1], $argv[2], $argv[3] ) || ! in_array( $argv[2], array( 'place', 'settle', 'window-end', 'capture', 'void', 'capture-and-die' ), true ) ) {
	fwrite( STDERR, 'Usage: php tests/Support/Checkout/placement-probe.php <result-file> <place|settle|window-end|capture|void|capture-and-die> <request|order-uuid>' . PHP_EOL );

	exit( 2 );
}

putenv( 'SEOCART_TESTS_LOAD_PLUGIN=0' );

/*
 * The WordPress test bootstrap deletes every post when it loads, and the products this probe
 * sells are the calling test's. Deletions are refused until WordPress has loaded. Hooks added
 * before WordPress loads are kept in this array shape until plugin.php builds them.
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
$seocart_probe_commits = 0;
$seocart_probe_request = 'settle' !== $argv[2] ? (array) json_decode( (string) base64_decode( $argv[3], true ), true ) : array();
$seocart_probe_db      = new Database(
	$wpdb,
	true,
	static function (): void {},
	5,
	static function () use ( &$seocart_probe_retries ): void {
		++$seocart_probe_retries;
	}
);

add_filter(
	'query',
	static function ( string $query ) use ( &$seocart_probe_commits ): string {
		if ( 'COMMIT' === $query ) {
			++$seocart_probe_commits;
		}

		return $query;
	}
);

$seocart_probe_tokens            = new FakeCartTokens();
$seocart_probe_tokens->presented = CartToken::fromString( (string) ( $seocart_probe_request['token'] ?? '' ) );
$seocart_probe_die_after         = (int) ( $seocart_probe_request['die_after_commit'] ?? 0 );
$seocart_probe_kernel            = PlacementKernel::over(
	$seocart_probe_db,
	$seocart_probe_tokens,
	PlacementKernel::identities(),
	static function () use ( &$seocart_probe_commits, $seocart_probe_die_after ): void {
		if ( 0 < $seocart_probe_die_after && $seocart_probe_commits >= $seocart_probe_die_after ) {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- No posix extension on every host: the shell kills the process.
			exec( 'kill -9 ' . getmypid() );
		}
	},
	static function (): void {},
	'' === (string) ( $seocart_probe_request['provider_file'] ?? '' ) ? array() : array(
		PaymentGateway::class => static fn(): PaymentGateway => new RecordingGateway(
			new FileProviderGateway(
				new StubGateway(),
				(string) $seocart_probe_request['provider_file'],
				'capture-and-die' !== $argv[2] ? null : static function (): void {
					// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- No posix extension on every host: the shell kills the process.
					exec( 'kill -9 ' . getmypid() );
				}
			),
			$seocart_probe_db
		),
	)
);

// The user a capture or a void acts for holds both capabilities, as the test's own user does.
$seocart_probe_user = (int) ( $seocart_probe_request['user_id'] ?? 0 );

add_filter(
	'user_has_cap',
	static function ( $caps, $cap, $args ) use ( $seocart_probe_user ) {
		if ( 0 < $seocart_probe_user && (int) ( $args[1] ?? 0 ) === $seocart_probe_user ) {
			$caps[ PaymentService::CAPTURE_CAPABILITY ] = true;
			$caps[ PaymentService::VOID_CAPABILITY ]    = true;
		}

		return $caps;
	},
	10,
	3
);

try {
	if ( 'place' === $argv[2] ) {
		$seocart_probe_outcome = array( 'answer_json' => (string) wp_json_encode( $seocart_probe_kernel->get( PlaceOrder::class )->place( (array) ( $seocart_probe_request['input'] ?? array() ), Actor::user( 0 ) ) ) );
	} elseif ( 'window-end' === $argv[2] ) {
		$seocart_probe_store  = Actor::system( 'reconciliation', 0 );
		$seocart_probe_intent = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT i.uuid FROM %i i JOIN %i o ON o.id = i.order_id WHERE o.uuid = %s', $seocart_probe_db->table( PaymentTables::INTENTS ), $seocart_probe_db->table( OrderTables::ORDERS ), (string) ( $seocart_probe_request['order_uuid'] ?? '' ) ) );
		$seocart_probe_void   = $seocart_probe_kernel->get( PaymentService::class )->askVoid( $seocart_probe_intent, $seocart_probe_store, VoidReason::ActionWindowEnded );

		$seocart_probe_outcome = array( 'outcome' => $seocart_probe_kernel->get( SettlePlacement::class )->apply( $seocart_probe_void, $seocart_probe_store, VoidReason::ActionWindowEnded )->outcome->value );
	} elseif ( 'void' === $argv[2] ) {
		$seocart_probe_input   = array(
			'intent_uuid' => (string) ( $seocart_probe_request['intent_uuid'] ?? '' ),
			'reason'      => (string) ( $seocart_probe_request['reason'] ?? '' ),
		);
		$seocart_probe_outcome = array( 'answer_json' => (string) wp_json_encode( $seocart_probe_kernel->get( PaymentService::class )->voidPayment( $seocart_probe_input, Actor::system( 'cli', $seocart_probe_user ) ) ) );
	} elseif ( 'settle' !== $argv[2] ) {
		$seocart_probe_input   = array( 'intent_uuid' => (string) ( $seocart_probe_request['intent_uuid'] ?? '' ) );
		$seocart_probe_outcome = array( 'answer_json' => (string) wp_json_encode( $seocart_probe_kernel->get( PaymentService::class )->capturePayment( $seocart_probe_input, Actor::system( 'cli', $seocart_probe_user ) ) ) );
	} else {
		$seocart_probe_intent = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT i.uuid, i.provider_intent_id, i.amount_minor, i.currency FROM %i i JOIN %i o ON o.id = i.order_id WHERE o.uuid = %s',
				$seocart_probe_db->table( PaymentTables::INTENTS ),
				$seocart_probe_db->table( OrderTables::ORDERS ),
				$argv[3]
			),
			ARRAY_A
		);
		$seocart_probe_answer = null === $seocart_probe_intent ? null : ( new StubGateway() )->query( new PaymentQuery( (string) $seocart_probe_intent['uuid'], (string) $seocart_probe_intent['provider_intent_id'], Money::of( (int) $seocart_probe_intent['amount_minor'], Currency::of( (string) $seocart_probe_intent['currency'] ) ), Mode::Test ) );

		if ( null === $seocart_probe_answer ) {
			throw new RuntimeException( 'The stub has no answer for the order\'s intent.' );
		}

		$seocart_probe_outcome = array( 'outcome' => $seocart_probe_kernel->get( SettlePlacement::class )->apply( $seocart_probe_answer, Actor::user( 0 ) )->outcome->value );
	}
} catch ( CodedException $seocart_probe_refusal ) {
	$seocart_probe_outcome = array(
		'refused' => $seocart_probe_refusal->errorCode()->value,
		'context' => $seocart_probe_refusal->context(),
		'details' => $seocart_probe_refusal->details(),
	);
} catch ( Throwable $seocart_probe_failure ) {
	$seocart_probe_outcome = array( 'failure' => get_class( $seocart_probe_failure ) . ': ' . $seocart_probe_failure->getMessage() );
}

file_put_contents( $argv[1], (string) wp_json_encode( $seocart_probe_outcome + array( 'retries' => $seocart_probe_retries ) ) );
