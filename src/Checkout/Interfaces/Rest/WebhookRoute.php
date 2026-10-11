<?php
/**
 * WebhookRoute: the REST route a payment provider delivers its webhooks to, one address per gateway and mode
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Checkout\Interfaces\Rest;

use SEOCart\Application\Operations\CompiledOperation;
use SEOCart\Checkout\Application\ReceiveWebhook;
use SEOCart\Contracts\Payment\GatewayDescriptor;
use SEOCart\Contracts\Payment\Mode;
use SEOCart\Contracts\Payment\WebhookEnvelope;
use SEOCart\Interfaces\Operations\ErrorTranslator;
use SEOCart\Payment\Application\PaymentError;
use SEOCart\Payment\Application\WebhookAddress;
use SEOCart\Platform\Authorization\PermissionCallback;
use SEOCart\Platform\Logging\Reporter;
use SEOCart\Platform\Rest\CachePolicy;
use SEOCart\Support\Clock;
use SEOCart\Support\Error\CodedException;
use SEOCart\Support\Schema\FieldSpec;
use SEOCart\Support\Schema\FieldType;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * `POST /seocart/v1/webhooks/{gateway_id}/{mode}`: the address a provider delivers to, which the merchant registers once per mode.
 *
 * Owns one fact: how a delivery reaches the receiver. It is the one plugin route that is no
 * operation's: its input is a raw body and the provider's headers, not declared fields, and its
 * only answer is `{ received: true }`, so it has no Ability, no command and no form, and the
 * operation surfaces list it as a signed route with that reason. Its guard is the signed kind
 * (PermissionCallback::signed()), whose policy checks the method and the body's size only; the
 * receiver verifies the signature, once, before anything of the body is read.
 *
 * What WordPress does first is stated plainly: it decodes a JSON body while it checks the
 * request's parameters, before the permission check, and answers a body that is not JSON with
 * its own 400. Nothing of the plugin reads what it decoded: the envelope carries the raw body.
 * The gateway and the mode are read from the URL only, since get_param() would let a body's
 * `mode` win, and only in their exact spelling, since WordPress matches routes ignoring case. The
 * headers are handed over with their lower-case hyphenated names, which WordPress turned into
 * underscores.
 *
 * Every answer, the policy's refusals and WordPress's own included, is finished by finish(): the
 * documented error shape, and `Cache-Control: no-store, private`. The route registers on the
 * kernel's `rest_api_init` callback and builds the receiver only when a delivery arrives.
 *
 * @since 0.2.0
 */
final class WebhookRoute {

	/**
	 * The key of the route's endpoint that marks it as this route's, for finish().
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const ENDPOINT_KEY = 'seocart_signed_route';

	/**
	 * Creates the route. Builds nothing.
	 *
	 * @since 0.2.0
	 *
	 * @param \Closure        $receiver   Returns the receiver, built on the first delivery.
	 * @param ErrorTranslator $translator Builds and shapes the errors the route answers with.
	 * @param Clock           $clock      Says when a delivery arrived.
	 * @param Reporter        $report     Reports a failure nothing else caught.
	 *
	 * @phpstan-param \Closure(): ReceiveWebhook $receiver
	 */
	public function __construct(
		private \Closure $receiver,
		private ErrorTranslator $translator,
		private Clock $clock,
		private Reporter $report
	) {
	}

	/**
	 * Registers the route and the filter that finishes its answers. Called on `rest_api_init` by the kernel.
	 *
	 * @since 0.2.0
	 */
	public function register(): void {
		register_rest_route(
			WebhookAddress::NAMESPACE,
			WebhookAddress::pattern(),
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'receive' ),
					'permission_callback' => PermissionCallback::signed( new WebhookRequestPolicy( $this->translator ) ),
					self::ENDPOINT_KEY    => true,
				),
				'schema' => array( $this, 'schema' ),
			)
		);

		add_filter( 'rest_request_after_callbacks', array( $this, 'finish' ), PHP_INT_MAX, 3 );
	}

	/**
	 * Hands a delivery to the receiver and answers it: 200 for every decision, or the receiver's refusal.
	 *
	 * Public only because WordPress calls it as the route's callback.
	 *
	 * @since 0.2.0
	 *
	 * @param WP_REST_Request $request The delivery.
	 * @return WP_REST_Response|WP_Error `{ received: true }`, or the refusal.
	 */
	public function receive( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$address   = $request->get_url_params();
		$gatewayId = (string) ( $address['gateway_id'] ?? '' );
		$mode      = Mode::tryFrom( (string) ( $address['mode'] ?? '' ) );

		// WordPress matched the route ignoring case: `Stripe` or `TEST` is no address of the store's.
		if ( null === $mode || 1 !== preg_match( GatewayDescriptor::ID_PATTERN, $gatewayId ) ) {
			return $this->translator->translate( CodedException::because( PaymentError::WebhookGatewayUnknown ) );
		}

		try {
			( $this->receiver )()->receive( new WebhookEnvelope( $gatewayId, $mode, self::headersOf( $request ), $request->get_body(), $this->clock->now() ) );
		} catch ( CodedException $refused ) {
			return $this->translator->translate( $refused );
		} catch ( \Throwable $failure ) {
			$this->report->error(
				'payment.webhook_faulted',
				array(
					'gateway_id' => $gatewayId,
					'mode'       => $mode->value,
					'exception'  => $failure,
				)
			);

			return $this->translator->unexpected();
		}

		return new WP_REST_Response( array( 'received' => true ), 200 );
	}

	/**
	 * Returns the schema of the route's answer, compiled where every route's is.
	 *
	 * Public only because WordPress calls it as the route's schema callback.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, mixed> The schema of `{ received: true }`.
	 */
	public function schema(): array {
		return CompiledOperation::wordPressSchema(
			'WebhookAck',
			array(
				new FieldSpec(
					name: 'received',
					type: FieldType::Boolean,
					description: 'True: the delivery was received and is settled, or was settled before.',
					label: static fn(): string => __( 'Received', 'seocart' ),
					example: true,
					required: true
				),
			)
		);
	}

	/**
	 * Finishes every answer of the route: the documented error shape and the caching headers. Hooked to `rest_request_after_callbacks`.
	 *
	 * WordPress passes every outcome of a matched endpoint through that filter: the error of a
	 * request that failed its parameters or its permission check, and the callback's answer. Any
	 * other endpoint is left alone.
	 *
	 * Public only because WordPress calls it as a filter.
	 *
	 * @since 0.2.0
	 *
	 * @param mixed           $response The outcome: a WP_Error, a response, or the callback's data.
	 * @param array           $handler  The endpoint that matched the request.
	 * @param WP_REST_Request $request  The request.
	 * @return mixed The outcome of another endpoint unchanged; for this route's, the finished WP_REST_Response.
	 *
	 * @phpstan-param array<string, mixed> $handler
	 */
	public function finish( $response, array $handler, WP_REST_Request $request ) {
		unset( $request );

		if ( ! isset( $handler[ self::ENDPOINT_KEY ] ) ) {
			return $response;
		}

		if ( $response instanceof WP_Error ) {
			return CachePolicy::apply( rest_convert_error_to_response( $this->translator->conform( $response ) ) );
		}

		return CachePolicy::apply( rest_ensure_response( $response ) );
	}

	/**
	 * Returns a request's headers by their lower-case hyphenated names, each repeated header joined as HTTP joins it.
	 *
	 * @since 0.2.0
	 *
	 * @param WP_REST_Request $request The request.
	 * @return array<string, string> The headers.
	 */
	private static function headersOf( WP_REST_Request $request ): array {
		$headers = array();

		foreach ( $request->get_headers() as $name => $values ) {
			$headers[ str_replace( '_', '-', (string) $name ) ] = implode( ', ', (array) $values );
		}

		return $headers;
	}
}
