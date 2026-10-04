<?php
/**
 * DecoratesGateway: forwards to a wrapped gateway what a test double does not change
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support\Doubles;

use SEOCart\Contracts\Payment\AvailabilityContext;
use SEOCart\Contracts\Payment\GatewayDescriptor;
use SEOCart\Contracts\Payment\GatewayResult;
use SEOCart\Contracts\Payment\VoidRequest;
use SEOCart\Contracts\Payment\WebhookEnvelope;
use SEOCart\Contracts\Payment\WebhookReading;

/**
 * The members of the gateway contract a double that wraps a gateway, in its `$inner` property, passes through unchanged.
 *
 * Owns one fact: that a double changes only the calls its test is about. Each double declares
 * the calls it changes; the description, the availability, the void and the webhook reading are
 * the wrapped gateway's.
 *
 * @since 0.2.0
 */
trait DecoratesGateway {

	/**
	 * Describes the wrapped gateway.
	 *
	 * @since 0.2.0
	 *
	 * @return GatewayDescriptor Its descriptor.
	 */
	public function describe(): GatewayDescriptor {
		return $this->inner->describe();
	}

	/**
	 * Asks the wrapped gateway whether it can take a payment.
	 *
	 * @since 0.2.0
	 *
	 * @param AvailabilityContext $context The payment.
	 * @return bool Its answer.
	 */
	public function isAvailable( AvailabilityContext $context ): bool {
		return $this->inner->isAvailable( $context );
	}

	/**
	 * Voids through the wrapped gateway.
	 *
	 * @since 0.2.0
	 *
	 * @param VoidRequest $request The request.
	 * @return GatewayResult Its answer.
	 */
	public function void( VoidRequest $request ): GatewayResult {
		return $this->inner->void( $request );
	}

	/**
	 * Has the wrapped gateway read a webhook delivery.
	 *
	 * @since 0.2.0
	 *
	 * @param WebhookEnvelope $envelope The delivery.
	 * @return WebhookReading Its reading.
	 */
	public function readWebhook( WebhookEnvelope $envelope ): WebhookReading {
		return $this->inner->readWebhook( $envelope );
	}
}
