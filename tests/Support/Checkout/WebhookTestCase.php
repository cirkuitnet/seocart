<?php
/**
 * WebhookTestCase: the base of the webhook tests, with the receiver, its route and the stand-in's deliveries
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support\Checkout;

use SEOCart\Checkout\Application\ReceiveWebhook;
use SEOCart\Checkout\Interfaces\Rest\WebhookRoute;
use SEOCart\Contracts\Payment\GatewayResult;
use SEOCart\Contracts\Payment\Mode;
use SEOCart\Contracts\Payment\Operation;
use SEOCart\Contracts\Payment\Outcome;
use SEOCart\Order\Infrastructure\OrderTables;
use SEOCart\Payment\Domain\Webhook\ReceiptResult;
use SEOCart\Payment\Infrastructure\Gateway\StubGateway;
use SEOCart\Payment\Infrastructure\PaymentTables;
use SEOCart\Payment\Infrastructure\WebhookReceiptTables;
use SEOCart\Platform\Kernel\Container;
use SEOCart\Support\Currency;
use SEOCart\Support\Error\CodedException;
use SEOCart\Support\Money;
use SEOCart\Tests\Support\ChildProcessProbe;
use SEOCart\Tests\Support\KernelHooks;
use SEOCart\Tests\Support\Payment\StubWebhooks;
use SEOCart\Tests\Support\RunningProbe;
use SEOCart\Tests\Support\ServesRequests;
use WP_REST_Request;
use WP_REST_Response;

/**
 * A GatewayPlacementTestCase with the receiver as the kernel wires it, its route on request, and the stand-in gateway's deliveries.
 *
 * Owns one fact: how a webhook test places an order, delivers the stand-in's event about its
 * payment, and reads back the receipt, the ledger and the order.
 *
 * @since 0.2.0
 */
abstract class WebhookTestCase extends GatewayPlacementTestCase {

	use ServesRequests;

	/**
	 * Whether the test serves the webhook route, and so has request globals and a REST server to put back.
	 *
	 * @since 0.2.0
	 *
	 * @var bool
	 */
	private bool $servesRoute = false;

	/**
	 * The receiver over `$this->db`.
	 *
	 * @since 0.2.0
	 *
	 * @var ReceiveWebhook
	 */
	protected ReceiveWebhook $receiver;

	/**
	 * Builds the receiver.
	 *
	 * @since 0.2.0
	 */
	public function set_up(): void {
		parent::set_up();

		$this->receiver = $this->kernel->get( ReceiveWebhook::class );
	}

	/**
	 * Puts back the request globals and discards the REST server, when the test served the route.
	 *
	 * @since 0.2.0
	 */
	public function tear_down(): void {
		if ( $this->servesRoute ) {
			$this->restoreRequestGlobals();
			self::discardRestServer();
		}

		parent::tear_down();
	}

	/**
	 * Registers the webhook route on `rest_api_init` from the wiring `$wiring` returns at registration, as the kernel registers it from its own, in place of the kernel's routes.
	 *
	 * @since 0.2.0
	 *
	 * @param \Closure $wiring Returns the container the route is built from.
	 *
	 * @phpstan-param \Closure(): Container $wiring
	 */
	protected function serveRouteFrom( \Closure $wiring ): void {
		$this->saveRequestGlobals();

		$this->servesRoute = true;

		KernelHooks::detach( 'rest_api_init' );
		add_action( 'rest_api_init', static fn() => $wiring()->get( WebhookRoute::class )->register() );
		self::discardRestServer();
	}

	/**
	 * Sends a delivery to the route, as WordPress dispatches it, signed now.
	 *
	 * @since 0.2.0
	 *
	 * @param StubWebhooks $delivery  The delivery.
	 * @param string       $address   Optional. The gateway and the mode, as the URL spells them. Default `stub/test`.
	 * @param string       $arrivedAs Optional. The method the request arrived with; it is routed as POST. Default POST.
	 * @return WP_REST_Response The answer.
	 */
	protected function post( StubWebhooks $delivery, string $address = 'stub/test', string $arrivedAs = 'POST' ): WP_REST_Response {
		$_SERVER['REQUEST_METHOD'] = $arrivedAs;

		$request = new WP_REST_Request( 'POST', '/seocart/v1/webhooks/' . $address );

		$request->set_body( $delivery->body() );

		foreach ( $delivery->headers( time() ) as $name => $value ) {
			$request->set_header( $name, $value );
		}

		return rest_do_request( $request );
	}

	/**
	 * Discards the REST server, so the next dispatch builds one with the route registered afresh.
	 *
	 * @since 0.2.0
	 */
	protected static function discardRestServer(): void {
		global $wp_rest_server;

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- resets core's REST server global: the next dispatch builds a new server and fires rest_api_init again.
		$wp_rest_server = null;
	}

	/**
	 * Delivers an event to the receiver, arriving now.
	 *
	 * @since 0.2.0
	 *
	 * @param StubWebhooks $delivery  The delivery.
	 * @param Mode         $mode      Optional. The mode of the address. Default test.
	 * @param string       $gatewayId Optional. The gateway of the address. Default the stand-in.
	 * @return ReceiptResult What was decided.
	 */
	protected function deliver( StubWebhooks $delivery, Mode $mode = Mode::Test, string $gatewayId = StubGateway::ID ): ReceiptResult {
		return $this->receiver->receive( $delivery->envelope( new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) ), $mode, $gatewayId ) );
	}

	/**
	 * Starts a delivery in a process of its own, on a connection of its own, signed now.
	 *
	 * @since 0.2.0
	 *
	 * @param StubWebhooks $delivery  The delivery.
	 * @param string       $dieBefore Optional. A regular expression: the process kills itself just before it sends the
	 *                                first statement that matches. Default '', never.
	 * @param Mode         $mode      Optional. The mode of the address. Default test.
	 * @return RunningProbe The running delivery; its report names what was decided, or its refusal.
	 */
	protected function startDelivery( StubWebhooks $delivery, string $dieBefore = '', Mode $mode = Mode::Test ): RunningProbe {
		$request = array(
			'gateway_id' => StubGateway::ID,
			'mode'       => $mode->value,
			'headers'    => $delivery->headers( time() ),
			'body'       => $delivery->body(),
			'die_before' => $dieBefore,
		);

		return ChildProcessProbe::start( __DIR__ . '/webhook-probe.php', array( 'deliver', base64_encode( (string) wp_json_encode( $request ) ) ) );
	}

	/**
	 * Waits for a probe to end, watching its output, never pausing; fails the test at the deadline.
	 *
	 * @since 0.2.0
	 *
	 * @param RunningProbe $probe The probe.
	 */
	protected function awaitEnd( RunningProbe $probe ): void {
		$deadline = hrtime( true ) + 60 * 1000000000;

		while ( ! $probe->watch( 50 ) ) {
			if ( hrtime( true ) >= $deadline ) {
				$this->fail( "The probe did not end.\n" . $probe->output() );
			}
		}
	}

	/**
	 * Delivers an event, and returns the code the receiver refused it with, or null when it took it.
	 *
	 * @since 0.2.0
	 *
	 * @param StubWebhooks $delivery  The delivery.
	 * @param Mode         $mode      Optional. The mode of the address. Default test.
	 * @param string       $gatewayId Optional. The gateway of the address. Default the stand-in.
	 * @return string|null The refusal's code, or null.
	 */
	protected function refusalOf( StubWebhooks $delivery, Mode $mode = Mode::Test, string $gatewayId = StubGateway::ID ): ?string {
		try {
			$this->deliver( $delivery, $mode, $gatewayId );
		} catch ( CodedException $refused ) {
			return (string) $refused->errorCode()->value;
		}

		return null;
	}

	/**
	 * Places an order for one unit of a new variant with a payment token, and returns its order and intent; a gateway that did not answer leaves them waiting.
	 *
	 * @since 0.2.0
	 *
	 * @param string $token The payment token.
	 * @param string $key   Optional. The idempotency key. Default `attempt-1`.
	 * @return array{order_uuid: string, order_id: int, intent_uuid: string, status: string, provider_intent_id: string|null, variant: int} The order and its intent.
	 */
	protected function placed( string $token, string $key = 'attempt-1' ): array {
		$variant = $this->sellable();

		$this->readyCart( array( $variant => 1 ) );

		try {
			$this->placement->place( $this->placeInput( $key, $token ), self::guest() );
		} catch ( CodedException $unanswered ) {
			// The gateway did not answer: the order waits as placed, which a delivery settles.
			unset( $unanswered );
		}

		return $this->latestPlaced() + array( 'variant' => $variant );
	}

	/**
	 * Reads the order placed last and its intent, as committed so far.
	 *
	 * @since 0.2.0
	 *
	 * @return array{order_uuid: string, order_id: int, intent_uuid: string, status: string, provider_intent_id: string|null} The order and its intent.
	 */
	protected function latestPlaced(): array {
		$row = (array) $this->db->fetchRow(
			'SELECT o.uuid AS order_uuid, o.id AS order_id, i.uuid AS intent_uuid, i.status, i.provider_intent_id FROM %i i JOIN %i o ON o.id = i.order_id ORDER BY i.id DESC LIMIT 1',
			$this->table( PaymentTables::INTENTS ),
			$this->table( OrderTables::ORDERS )
		);

		return array(
			'order_uuid'         => (string) $row['order_uuid'],
			'order_id'           => (int) $row['order_id'],
			'intent_uuid'        => (string) $row['intent_uuid'],
			'status'             => (string) $row['status'],
			'provider_intent_id' => null === $row['provider_intent_id'] ? null : (string) $row['provider_intent_id'],
		);
	}

	/**
	 * Builds a result of the stand-in about an intent, as a delivery reports it.
	 *
	 * @since 0.2.0
	 *
	 * @param string      $intentUuid       The intent, or '' for one named by the provider's reference only.
	 * @param Operation   $operation        The operation.
	 * @param Outcome     $outcome          The outcome.
	 * @param int         $minor            The amount, in minor units.
	 * @param string      $currency         The currency.
	 * @param string|null $objectId         The provider's object; null for an answer that moves no money.
	 * @param string|null $providerIntentId Optional. The provider's reference to the intent. Default null.
	 * @param string|null $errorCode        Optional. A decline's code. Default null.
	 * @return GatewayResult The result.
	 */
	protected static function stubResult( string $intentUuid, Operation $operation, Outcome $outcome, int $minor, string $currency, ?string $objectId, ?string $providerIntentId = null, ?string $errorCode = null ): GatewayResult {
		return new GatewayResult( StubGateway::ID, $operation, $outcome, $intentUuid, Money::of( $minor, Currency::of( $currency ) ), $objectId, $providerIntentId, $errorCode );
	}

	/**
	 * Builds the stand-in's approval of an order's authorization, for its whole amount, named as the stand-in names it.
	 *
	 * @since 0.2.0
	 *
	 * @param array{intent_uuid: string} $placed What placed() returned.
	 * @return GatewayResult The approval.
	 */
	protected function approvalOf( array $placed ): GatewayResult {
		$intent = $this->intentRow( $placed['intent_uuid'] );

		return self::stubResult( $placed['intent_uuid'], Operation::Authorize, Outcome::Approved, (int) $intent['amount_minor'], (string) $intent['currency'], 'stub-ch-' . $placed['intent_uuid'], 'stub-pi-approve-' . $placed['intent_uuid'] );
	}

	/**
	 * Builds the stand-in's capture of an authorized order's whole amount, named as the stand-in names it.
	 *
	 * @since 0.2.0
	 *
	 * @param array{intent_uuid: string} $placed What placed() returned.
	 * @return GatewayResult The capture.
	 */
	protected function captureOf( array $placed ): GatewayResult {
		return $this->resultAbout( $placed, Operation::Capture, Outcome::Approved, 'stub-cap-' . $placed['intent_uuid'] );
	}

	/**
	 * Builds the stand-in's void of an authorized order, named as the stand-in names it.
	 *
	 * @since 0.2.0
	 *
	 * @param array{intent_uuid: string} $placed What placed() returned.
	 * @return GatewayResult The void.
	 */
	protected function voidOf( array $placed ): GatewayResult {
		return $this->resultAbout( $placed, Operation::Void, Outcome::Approved, 'stub-void-' . $placed['intent_uuid'] );
	}

	/**
	 * Builds the stand-in's result about an order's intent, for the intent's whole amount in its currency.
	 *
	 * @since 0.2.0
	 *
	 * @param array{intent_uuid: string} $placed           What placed() returned.
	 * @param Operation                  $operation        The operation.
	 * @param Outcome                    $outcome          The outcome.
	 * @param string|null                $objectId         The provider's object; null for an answer that moves no money.
	 * @param string|null                $errorCode        Optional. A decline's code. Default null.
	 * @param string|null                $providerIntentId Optional. The provider's reference to the intent. Default the one the intent holds.
	 * @return GatewayResult The result.
	 */
	protected function resultAbout( array $placed, Operation $operation, Outcome $outcome, ?string $objectId, ?string $errorCode = null, ?string $providerIntentId = null ): GatewayResult {
		$intent = $this->intentRow( $placed['intent_uuid'] );

		return self::stubResult( $placed['intent_uuid'], $operation, $outcome, (int) $intent['amount_minor'], (string) $intent['currency'], $objectId, $providerIntentId ?? ( null === $intent['provider_intent_id'] ? null : (string) $intent['provider_intent_id'] ), $errorCode );
	}

	/**
	 * Reads an intent's row.
	 *
	 * @since 0.2.0
	 *
	 * @param string $intentUuid The intent.
	 * @return array<string, mixed> The row.
	 */
	protected function intentRow( string $intentUuid ): array {
		return (array) $this->db->fetchRow( 'SELECT * FROM %i WHERE uuid = %s', $this->table( PaymentTables::INTENTS ), $intentUuid );
	}

	/**
	 * Reads an order's status, payment status and flag.
	 *
	 * @since 0.2.0
	 *
	 * @param int $orderId The order.
	 * @return array{status: string, payment_status: string, has_unreconciled_money: string} The three.
	 */
	protected function orderState( int $orderId ): array {
		$row = (array) $this->db->fetchRow( 'SELECT status, payment_status, has_unreconciled_money FROM %i WHERE id = %d', $this->table( OrderTables::ORDERS ), $orderId );

		return array(
			'status'                 => (string) $row['status'],
			'payment_status'         => (string) $row['payment_status'],
			'has_unreconciled_money' => (string) $row['has_unreconciled_money'],
		);
	}

	/**
	 * Lists an order's ledger rows: the operation, the result, whether it was applied, and the provider's object.
	 *
	 * @since 0.2.0
	 *
	 * @param int $orderId The order.
	 * @return list<string> One `operation:result:applied:object` per row, in order.
	 */
	protected function ledgerOf( int $orderId ): array {
		return array_map(
			static fn( array $row ): string => implode( ':', array( $row['operation'], $row['result'], $row['applied'], (string) $row['provider_object_id'] ) ),
			$this->db->fetchAll( 'SELECT operation, result, applied, provider_object_id FROM %i WHERE order_id = %d ORDER BY id', $this->table( PaymentTables::TRANSACTIONS ), $orderId )
		);
	}

	/**
	 * Reads an order's events, oldest first.
	 *
	 * @since 0.2.0
	 *
	 * @param int $orderId The order.
	 * @return list<string> Each event as `machine:from>to:reason`.
	 */
	protected function eventsOf( int $orderId ): array {
		return array_map(
			static fn( array $row ): string => $row['machine'] . ':' . $row['from_status'] . '>' . $row['to_status'] . ':' . $row['reason'],
			$this->db->fetchAll( 'SELECT machine, from_status, to_status, reason FROM %i WHERE order_id = %d ORDER BY id', $this->table( OrderTables::EVENTS ), $orderId )
		);
	}

	/**
	 * Reads the receipt of an event of the stand-in's test address.
	 *
	 * @since 0.2.0
	 *
	 * @param string $eventId The event.
	 * @return array<string, mixed>|null The receipt's row, or null when there is none.
	 */
	protected function receiptOf( string $eventId ): ?array {
		return $this->db->fetchRow( 'SELECT * FROM %i WHERE event_id = %s', $this->table( WebhookReceiptTables::RECEIPTS ), $eventId );
	}

	/**
	 * Counts the receipts.
	 *
	 * @since 0.2.0
	 *
	 * @return int The count.
	 */
	protected function receiptCount(): int {
		return (int) $this->db->fetchValue( 'SELECT COUNT(*) FROM %i', $this->table( WebhookReceiptTables::RECEIPTS ) );
	}
}
