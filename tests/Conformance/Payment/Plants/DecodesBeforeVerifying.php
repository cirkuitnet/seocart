<?php
/**
 * DecodesBeforeVerifying: a gateway that reads a delivery before it verifies it
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Conformance\Payment\Plants;

use SEOCart\Contracts\Payment\WebhookEnvelope;
use SEOCart\Contracts\Payment\WebhookReading;
use SEOCart\Tests\Conformance\Payment\Reference\ReferenceGateway;

/**
 * Decodes a delivery's body, throwing on one that is not JSON, before it checks the signature.
 *
 * The plant of rule 10: the conformance suite must fail on it with that rule's message.
 *
 * @since 0.2.0
 */
final class DecodesBeforeVerifying extends ReferenceGateway {

	/**
	 * What the suite's failure on this plant shows, beside its rule's message: the reason the plant plants.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const FAILS_WITH = 'threw JsonException';

	/**
	 * Decodes first, then reads as the reference gateway does.
	 *
	 * @since 0.2.0
	 *
	 * @throws \JsonException When the body is not JSON.
	 *
	 * @param WebhookEnvelope $envelope The delivery.
	 * @return WebhookReading What it says.
	 */
	public function readWebhook( WebhookEnvelope $envelope ): WebhookReading {
		json_decode( $envelope->rawBody, true, 512, JSON_THROW_ON_ERROR );

		return parent::readWebhook( $envelope );
	}
}
