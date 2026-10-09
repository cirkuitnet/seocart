<?php
/**
 * Refunds an order in a process of its own, through the kernel's wiring, and dies once the gateway has given the money back
 *
 * Usage: php tests/Support/Payment/refund-probe.php <result-file> <request>
 *
 * The refund recovery test starts this script through ChildProcessProbe::start(), so that a
 * refund can die the way a request does: after the gateway gave the money back and before the
 * transaction that would record it. It boots WordPress against the installed test site without
 * loading the plugin, and builds the kernel's container over a Database whose guards throw, as the
 * tests' do, with the stub gateway wrapped in a CrashingRefundGateway: once the stub made the
 * refund, the refund is logged to the call log and the process kills itself with SIGKILL, so
 * nothing after the gateway's answer runs, not even PHP's shutdown.
 *
 * The request is base64 of a JSON object: `order_uuid`; `units`, the units of each line by the
 * line's uuid; `shipping`; `user_id`, the user who refunds; `call_log`, the file each refund is
 * logged to; optionally `crash`, false for a refund that is not killed, with the plain stub
 * gateway, as the refund race test runs a second refund while its own holds a lock; and optionally
 * `idempotency_key`, with which the refund is asked through the refund operation's service, as a
 * client's request is. A probe that was not killed reports how the refund ended: `refund_uuid`,
 * the refusal (`refused`, `context`), or any other failure.
 *
 * With `action` `settle`, it settles the claim of `refund_uuid` instead, as `user_id`, with the
 * person's `statement` (`refunded` or `not_refunded`), `note`, and `provider_refund_id` and
 * `amount_minor` when given, through the plain stub gateway, and reports `settled` (how the claim
 * ended) and `decided_by`. With `action` `reconcile`, it clears the unreconciled money of
 * `order_uuid` as `user_id`, with `note`, and reports `reconciled_at`. With `action` `land`, it
 * records a refund of `intent_uuid` the provider made that no claim asked for, `amount_minor` of
 * `currency` as the provider's object `object`, as money a person must reconcile, and reports
 * `kept`. With `die_before`, a
 * statement's first words such as `START TRANSACTION`, the process kills itself with SIGKILL just
 * before it would send the first such statement of the action: a crash at that point.
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

use SEOCart\Contracts\Payment\GatewayResult;
use SEOCart\Contracts\Payment\Operation;
use SEOCart\Contracts\Payment\Outcome;
use SEOCart\Contracts\Payment\PaymentGateway;
use SEOCart\Order\Application\Orders;
use SEOCart\Payment\Application\ClaimStatement;
use SEOCart\Payment\Application\PaymentService;
use SEOCart\Payment\Application\RefundService;
use SEOCart\Payment\Domain\Refund\RefundLineRequest;
use SEOCart\Payment\Domain\Refund\RefundRequest;
use SEOCart\Payment\Infrastructure\Gateway\StubGateway;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Platform\Database\Database;
use SEOCart\Platform\Database\TransactionManager;
use SEOCart\Platform\Events\EventCatalog;
use SEOCart\Platform\Events\EventPublisher;
use SEOCart\Platform\Events\HookBridge;
use SEOCart\Platform\Events\Outbox;
use SEOCart\Platform\Events\Publisher;
use SEOCart\Platform\Kernel\Container;
use SEOCart\Platform\Kernel\Modules;
use SEOCart\Platform\Logging\CorrelationId;
use SEOCart\Support\Currency;
use SEOCart\Support\Error\CodedException;
use SEOCart\Support\Money;
use SEOCart\Tests\Support\Doubles\CrashingRefundGateway;
use SEOCart\Tests\Support\KernelContainer;

// phpcs:disable WordPress.WP.AlternativeFunctions -- The probe writes its report to the file its parent reads.

if ( 'cli' !== PHP_SAPI || ! isset( $argv[1], $argv[2] ) ) {
	fwrite( STDERR, 'Usage: php tests/Support/Payment/refund-probe.php <result-file> <request>' . PHP_EOL );

	exit( 2 );
}

putenv( 'SEOCART_TESTS_LOAD_PLUGIN=0' );

require dirname( __DIR__, 2 ) . '/bootstrap-integration.php';

global $wpdb;

$seocart_probe_request = (array) json_decode( (string) base64_decode( $argv[2], true ), true );
$seocart_probe_db      = new Database( $wpdb, true, static function (): void {} );
$seocart_probe_kernel  = KernelContainer::build(
	$seocart_probe_db,
	static function (): void {},
	array(
		TransactionManager::class => static fn(): TransactionManager => $seocart_probe_db,
		PaymentGateway::class     => static fn(): PaymentGateway => false === ( $seocart_probe_request['crash'] ?? true ) ? new StubGateway() : new CrashingRefundGateway(
			new StubGateway(),
			(string) ( $seocart_probe_request['call_log'] ?? '' ),
			static function (): void {
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- No posix extension on every host: the shell kills the process.
				exec( 'kill -9 ' . getmypid() );
			}
		),
		EventPublisher::class     => static fn( Container $c ): EventPublisher => new Publisher( $seocart_probe_db, new Outbox( $seocart_probe_db ), $c->get( HookBridge::class ), new EventCatalog( Modules::EVENT_CLASSES ), $c->get( CorrelationId::class ), static function (): void {} ),
	)
);

$seocart_probe_lines = array();

foreach ( (array) ( $seocart_probe_request['units'] ?? array() ) as $seocart_probe_line => $seocart_probe_quantity ) {
	$seocart_probe_lines[] = new RefundLineRequest( (string) $seocart_probe_line, (int) $seocart_probe_quantity );
}

try {
	$seocart_probe_service = $seocart_probe_kernel->get( RefundService::class );
	$seocart_probe_actor   = Actor::user( (int) ( $seocart_probe_request['user_id'] ?? 0 ) );
	$seocart_probe_request = $seocart_probe_request + array(
		'idempotency_key' => '',
		'action'          => 'refund',
		'die_before'      => '',
	);

	if ( '' !== $seocart_probe_request['die_before'] ) {
		$seocart_probe_prefix = (string) $seocart_probe_request['die_before'];

		add_filter(
			'query',
			static function ( string $query ) use ( $seocart_probe_prefix ): string {
				if ( str_starts_with( $query, $seocart_probe_prefix ) ) {
					// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- No posix extension on every host: the shell kills the process.
					exec( 'kill -9 ' . getmypid() );
				}

				return $query;
			}
		);
	}

	if ( 'settle' === $seocart_probe_request['action'] ) {
		$seocart_probe_settled = $seocart_probe_service->settleClaim(
			(string) $seocart_probe_request['refund_uuid'],
			new ClaimStatement(
				ClaimStatement::REFUNDED === $seocart_probe_request['statement'],
				(string) $seocart_probe_request['note'],
				isset( $seocart_probe_request['provider_refund_id'] ) ? (string) $seocart_probe_request['provider_refund_id'] : null,
				isset( $seocart_probe_request['amount_minor'] ) ? (int) $seocart_probe_request['amount_minor'] : null
			),
			$seocart_probe_actor
		);
		$seocart_probe_outcome = array(
			'settled'    => $seocart_probe_settled->state->value,
			'decided_by' => $seocart_probe_settled->decidedBy,
		);
	} elseif ( 'land' === $seocart_probe_request['action'] ) {
		$seocart_probe_payments = $seocart_probe_kernel->get( PaymentService::class );
		$seocart_probe_landed   = new GatewayResult(
			StubGateway::ID,
			Operation::Refund,
			Outcome::Approved,
			(string) $seocart_probe_request['intent_uuid'],
			Money::of( (int) $seocart_probe_request['amount_minor'], Currency::of( (string) $seocart_probe_request['currency'] ) ),
			(string) $seocart_probe_request['object']
		);

		$seocart_probe_db->transaction( static fn() => $seocart_probe_payments->recordUnapplied( $seocart_probe_landed, Actor::system( 'payment', 3 ) ) );

		$seocart_probe_outcome = array( 'kept' => true );
	} elseif ( 'reconcile' === $seocart_probe_request['action'] ) {
		$seocart_probe_outcome = array(
			'reconciled_at' => $seocart_probe_kernel->get( Orders::class )->clearUnreconciledMoney(
				array(
					'order_uuid' => (string) $seocart_probe_request['order_uuid'],
					'note'       => (string) $seocart_probe_request['note'],
				),
				$seocart_probe_actor
			)['money_reconciled_at'],
		);
	} elseif ( '' !== $seocart_probe_request['idempotency_key'] ) {
		$seocart_probe_outcome = array(
			'refund_uuid' => $seocart_probe_service->refundOrder(
				array(
					'order_uuid'      => (string) $seocart_probe_request['order_uuid'],
					'lines'           => array_map(
						static fn( RefundLineRequest $line ): array => array(
							'line_uuid' => $line->lineUuid,
							'quantity'  => $line->quantity,
						),
						$seocart_probe_lines
					),
					'shipping'        => (bool) ( $seocart_probe_request['shipping'] ?? false ),
					'reason_code'     => 'customer_return',
					'idempotency_key' => (string) $seocart_probe_request['idempotency_key'],
				),
				$seocart_probe_actor
			)['refund_uuid'],
		);
	} else {
		$seocart_probe_refund  = $seocart_probe_service->refund(
			new RefundRequest( (string) ( $seocart_probe_request['order_uuid'] ?? '' ), $seocart_probe_lines, (bool) ( $seocart_probe_request['shipping'] ?? false ), 'customer_return' ),
			$seocart_probe_actor
		);
		$seocart_probe_outcome = array( 'refund_uuid' => $seocart_probe_refund->uuid );
	}
} catch ( CodedException $seocart_probe_refusal ) {
	$seocart_probe_outcome = array(
		'refused' => $seocart_probe_refusal->errorCode()->value,
		'context' => $seocart_probe_refusal->context(),
	);
} catch ( Throwable $seocart_probe_failure ) {
	$seocart_probe_outcome = array( 'failure' => get_class( $seocart_probe_failure ) . ': ' . $seocart_probe_failure->getMessage() );
}

file_put_contents( $argv[1], (string) wp_json_encode( $seocart_probe_outcome ) );
