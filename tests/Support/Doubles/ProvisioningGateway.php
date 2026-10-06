<?php
/**
 * ProvisioningGateway: a gateway that sets up its provider's webhook endpoints through its own HTTP client, as an adapter does
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support\Doubles;

use SEOCart\Contracts\ExtensionContext;
use SEOCart\Contracts\OutboundRequest;
use SEOCart\Contracts\Payment\CaptureRequest;
use SEOCart\Contracts\Payment\GatewayDescriptor;
use SEOCart\Contracts\Payment\GatewayRefund;
use SEOCart\Contracts\Payment\GatewayResult;
use SEOCart\Contracts\Payment\GatewayUnavailable;
use SEOCart\Contracts\Payment\PaymentGateway;
use SEOCart\Contracts\Payment\PaymentQuery;
use SEOCart\Contracts\Payment\PaymentRequest;
use SEOCart\Contracts\Payment\ProvisionsWebhooks;
use SEOCart\Contracts\Payment\WebhookProvisioning;
use SEOCart\Contracts\Payment\WebhookTarget;

/**
 * A gateway whose provider keeps webhook endpoints, set up the way the contract asks: listed, then reused, replaced or created, by this site's tag and URL only.
 *
 * Owns one fact: an adapter's side of ProvisionsWebhooks, for the tests of the plugin's side. It
 * lists the provider's endpoints at `GET /v1/webhook_endpoints` through its own HTTP client,
 * keeps those whose metadata carries the target's install uuid and mode, counts those of them at
 * another URL and leaves them alone, reuses the one at the target's URL when it is the endpoint
 * whose secret the plugin holds (WebhookTarget::$endpointId), and otherwise deletes it and creates
 * one, whose answer carries the signing secret. A provider error is a GatewayUnavailable with the
 * provider's answer in its message. Every payment call is the stub's, through DeclaredGateway.
 *
 * @since 0.2.0
 */
final class ProvisioningGateway implements PaymentGateway, ProvisionsWebhooks {

	use DecoratesGateway;

	/**
	 * The provider's API, on the host the descriptor declares as `provider`.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const API = 'https://api.provider.example/v1/webhook_endpoints';

	/**
	 * The event types its endpoints subscribe to.
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	public const EVENTS = array( 'payment_intent.succeeded', 'charge.refunded' );

	/**
	 * The gateway whose payment calls this one gives.
	 *
	 * @since 0.2.0
	 *
	 * @var DeclaredGateway
	 */
	private DeclaredGateway $inner;

	/**
	 * Whether it reuses this site's one endpoint whenever the plugin holds a signing secret, whichever endpoint the secret is for, as a gateway that ignores WebhookTarget::$endpointId would.
	 *
	 * @since 0.2.0
	 *
	 * @var bool
	 */
	public bool $ignoresEndpointId = false;

	/**
	 * Builds the gateway.
	 *
	 * @since 0.2.0
	 *
	 * @param GatewayDescriptor     $descriptor Its declaration, with the `provider` host and the webhook_secret credential.
	 * @param ExtensionContext|null $context    Optional. Its context, whose HTTP client it calls the provider through. Default none, for a gateway that is only registered.
	 */
	public function __construct( GatewayDescriptor $descriptor, private ?ExtensionContext $context = null ) {
		$this->inner = new DeclaredGateway( $descriptor, $context );
	}

	/**
	 * Returns the event types its endpoints subscribe to.
	 *
	 * @since 0.2.0
	 *
	 * @return list<string> EVENTS.
	 */
	public function webhookEvents(): array {
		return self::EVENTS;
	}

	/**
	 * Sets up this site's endpoint for the target's mode.
	 *
	 * @since 0.2.0
	 *
	 * @throws GatewayUnavailable When the provider answers an error.
	 *
	 * @param WebhookTarget $target The endpoint the site needs.
	 * @return WebhookProvisioning What was done.
	 */
	public function provisionWebhooks( WebhookTarget $target ): WebhookProvisioning {
		$ours      = array();
		$elsewhere = 0;

		foreach ( $this->call( 'GET', self::API )['data'] ?? array() as $endpoint ) {
			$metadata = (array) ( $endpoint['metadata'] ?? array() );

			// Another installation's, or this one's for the other mode: never touched.
			if ( ( $metadata['seocart_install'] ?? null ) !== $target->installUuid || ( $metadata['seocart_mode'] ?? null ) !== $target->mode->value ) {
				continue;
			}

			// This installation's at another address: a copy of the site, or the site before it moved.
			if ( ( $endpoint['url'] ?? null ) !== $target->url ) {
				++$elsewhere;

				continue;
			}

			$ours[] = (string) $endpoint['id'];
		}

		if ( 1 === count( $ours ) && $target->secretHeld && ( $this->ignoresEndpointId || $ours[0] === $target->endpointId ) ) {
			return new WebhookProvisioning( WebhookProvisioning::REUSED, $ours[0], null, array(), $elsewhere );
		}

		foreach ( $ours as $id ) {
			$this->call( 'DELETE', self::API . '/' . $id );
		}

		$created = $this->call(
			'POST',
			self::API,
			array(
				'url'            => $target->url,
				'enabled_events' => $target->events,
				'metadata'       => array(
					'seocart_install' => $target->installUuid,
					'seocart_mode'    => $target->mode->value,
				),
			)
		);

		return new WebhookProvisioning( array() === $ours ? WebhookProvisioning::CREATED : WebhookProvisioning::REPLACED, (string) $created['id'], (string) $created['secret'], $ours, $elsewhere );
	}

	/**
	 * Authorizes as the stub does.
	 *
	 * @since 0.2.0
	 *
	 * @param PaymentRequest $request The request.
	 * @return GatewayResult The answer.
	 */
	public function authorize( PaymentRequest $request ): GatewayResult {
		return $this->inner->authorize( $request );
	}

	/**
	 * Captures as the stub does.
	 *
	 * @since 0.2.0
	 *
	 * @param CaptureRequest $request The request.
	 * @return GatewayResult The answer.
	 */
	public function capture( CaptureRequest $request ): GatewayResult {
		return $this->inner->capture( $request );
	}

	/**
	 * Refunds as the stub does.
	 *
	 * @since 0.2.0
	 *
	 * @param GatewayRefund $request The request.
	 * @return GatewayResult The answer.
	 */
	public function refund( GatewayRefund $request ): GatewayResult {
		return $this->inner->refund( $request );
	}

	/**
	 * Says what became of a refund as the stub does.
	 *
	 * @since 0.2.0
	 *
	 * @param GatewayRefund $request The refund.
	 * @return GatewayResult|null The answer.
	 */
	public function queryRefund( GatewayRefund $request ): ?GatewayResult {
		return $this->inner->queryRefund( $request );
	}

	/**
	 * Answers a status query as the stub does.
	 *
	 * @since 0.2.0
	 *
	 * @param PaymentQuery $query The query.
	 * @return GatewayResult|null The answer.
	 */
	public function query( PaymentQuery $query ): ?GatewayResult {
		return $this->inner->query( $query );
	}

	/**
	 * Calls the provider through the gateway's own client.
	 *
	 * @since 0.2.0
	 *
	 * @throws \LogicException When the gateway was built without a context.
	 * @throws GatewayUnavailable When the provider answers an error.
	 *
	 * @param string                    $method The method.
	 * @param string                    $url    The URL.
	 * @param array<string, mixed>|null $body   Optional. The JSON body. Default none.
	 * @return array<string, mixed> The answer, decoded.
	 */
	private function call( string $method, string $url, ?array $body = null ): array {
		if ( null === $this->context ) {
			throw new \LogicException( 'This gateway was built without a context, so it calls no provider.' );
		}

		$response = $this->context->http()->send( new OutboundRequest( 'provider', $method, $url, array( 'Content-Type' => 'application/json' ), null === $body ? null : (string) wp_json_encode( $body ) ) );

		if ( $response->status >= 400 ) {
			throw new GatewayUnavailable( sprintf( 'The provider answered %1$d: %2$s', $response->status, $response->body ) );
		}

		return $response->json();
	}
}
