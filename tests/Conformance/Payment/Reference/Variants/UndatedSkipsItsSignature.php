<?php
/**
 * UndatedSkipsItsSignature: a gateway of undated deliveries that reads a delivery of the signature's shape without comparing the signature
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Conformance\Payment\Reference\Variants;

use SEOCart\Contracts\Payment\WebhookEnvelope;
use SEOCart\Contracts\Payment\WebhookReading;

/**
 * Takes a delivery whose signature header has the provider's shape, `sha512=` and 128 upper-case hexadecimal digits, without comparing it with the HMAC of the body.
 *
 * Under the test's declaration that deliveries carry no signed time, rule 10 drops its two cases of
 * a signed time and keeps its case of a bad signature: it must fail this gateway on that case.
 *
 * @since 0.2.0
 */
final class UndatedSkipsItsSignature extends UndatedDeliveries {

	/**
	 * Reads a delivery of the signature's shape, unverified.
	 *
	 * @since 0.2.0
	 *
	 * @param WebhookEnvelope $envelope The delivery.
	 * @return WebhookReading What it says, or why it was rejected.
	 */
	public function readWebhook( WebhookEnvelope $envelope ): WebhookReading {
		$headers = array_change_key_case( $envelope->headers );

		if ( 1 !== preg_match( '/^sha512=[0-9A-F]{128}$/D', (string) ( $headers[ self::SIGNATURE_HEADER ] ?? '' ) ) ) {
			return WebhookReading::rejected( WebhookReading::BAD_SIGNATURE );
		}

		return $this->readEvent( $this->decoded( $envelope->rawBody ) );
	}
}
