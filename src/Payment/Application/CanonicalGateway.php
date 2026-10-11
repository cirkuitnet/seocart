<?php
/**
 * CanonicalGateway: a registered gateway, its answers kept as the store keeps a provider's values
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Payment\Application;

use SEOCart\Contracts\Payment\AvailabilityContext;
use SEOCart\Contracts\Payment\CaptureRequest;
use SEOCart\Contracts\Payment\GatewayDescriptor;
use SEOCart\Contracts\Payment\GatewayRefund;
use SEOCart\Contracts\Payment\GatewayResult;
use SEOCart\Contracts\Payment\PaymentGateway;
use SEOCart\Contracts\Payment\PaymentQuery;
use SEOCart\Contracts\Payment\PaymentRequest;
use SEOCart\Contracts\Payment\VoidRequest;
use SEOCart\Contracts\Payment\WebhookEnvelope;
use SEOCart\Contracts\Payment\WebhookReading;

defined( 'ABSPATH' ) || exit;

/**
 * Wraps a registered gateway so every answer to a call of the store's passes through DeliveredValues::canonical() once: the same canonicalisation a delivery of that answer passes through in the webhook receiver.
 *
 * Owns one fact: that a provider's reference is kept the same way whether its answer arrives in
 * the request or by webhook, so the two meet in the ledger. A delivery is read through as the
 * gateway reads it, and canonicalised once, by the receiver. It offers the gateway contract and
 * nothing more, no way to the gateway behind it, so no caller can hand a raw answer to the money
 * path.
 *
 * @since 0.2.0
 */
final class CanonicalGateway implements PaymentGateway {

	/**
	 * Wraps a gateway.
	 *
	 * @since 0.2.0
	 *
	 * @param PaymentGateway $inner The registered gateway.
	 */
	public function __construct( private PaymentGateway $inner ) {
	}

	/**
	 * Describes the gateway.
	 *
	 * @since 0.2.0
	 *
	 * @return GatewayDescriptor Its descriptor.
	 */
	public function describe(): GatewayDescriptor {
		return $this->inner->describe();
	}

	/**
	 * Tells whether the gateway takes a payment in a context.
	 *
	 * @since 0.2.0
	 *
	 * @param AvailabilityContext $context The context.
	 * @return bool Its answer.
	 */
	public function isAvailable( AvailabilityContext $context ): bool {
		return $this->inner->isAvailable( $context );
	}

	/**
	 * Authorizes, and keeps the answer canonical.
	 *
	 * @since 0.2.0
	 *
	 * @param PaymentRequest $request The request.
	 * @return GatewayResult The answer.
	 */
	public function authorize( PaymentRequest $request ): GatewayResult {
		return DeliveredValues::canonical( $this->inner->authorize( $request ) );
	}

	/**
	 * Captures, and keeps the answer canonical.
	 *
	 * @since 0.2.0
	 *
	 * @param CaptureRequest $request The request.
	 * @return GatewayResult The answer.
	 */
	public function capture( CaptureRequest $request ): GatewayResult {
		return DeliveredValues::canonical( $this->inner->capture( $request ) );
	}

	/**
	 * Voids, and keeps the answer canonical.
	 *
	 * @since 0.2.0
	 *
	 * @param VoidRequest $request The request.
	 * @return GatewayResult The answer.
	 */
	public function void( VoidRequest $request ): GatewayResult {
		return DeliveredValues::canonical( $this->inner->void( $request ) );
	}

	/**
	 * Refunds, and keeps the answer canonical.
	 *
	 * @since 0.2.0
	 *
	 * @param GatewayRefund $request The request.
	 * @return GatewayResult The answer.
	 */
	public function refund( GatewayRefund $request ): GatewayResult {
		return DeliveredValues::canonical( $this->inner->refund( $request ) );
	}

	/**
	 * Asks what became of a refund, and keeps the answer canonical.
	 *
	 * @since 0.2.0
	 *
	 * @param GatewayRefund $request The refund, as it was asked.
	 * @return GatewayResult|null The answer, or null.
	 */
	public function queryRefund( GatewayRefund $request ): ?GatewayResult {
		$answer = $this->inner->queryRefund( $request );

		return null === $answer ? null : DeliveredValues::canonical( $answer );
	}

	/**
	 * Asks where an intent stands, and keeps the answer canonical.
	 *
	 * @since 0.2.0
	 *
	 * @param PaymentQuery $query The query.
	 * @return GatewayResult|null The answer, or null.
	 */
	public function query( PaymentQuery $query ): ?GatewayResult {
		$answer = $this->inner->query( $query );

		return null === $answer ? null : DeliveredValues::canonical( $answer );
	}

	/**
	 * Reads a delivery as the gateway reads it: the receiver canonicalises it once.
	 *
	 * @since 0.2.0
	 *
	 * @param WebhookEnvelope $envelope The delivery.
	 * @return WebhookReading What the gateway read.
	 */
	public function readWebhook( WebhookEnvelope $envelope ): WebhookReading {
		return $this->inner->readWebhook( $envelope );
	}
}
