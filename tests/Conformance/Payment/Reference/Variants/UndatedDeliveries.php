<?php
/**
 * UndatedDeliveries: the reference gateway of a provider that signs a delivery's body alone, with no time
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Conformance\Payment\Reference\Variants;

use SEOCart\Contracts\Payment\GatewayDescriptor;
use SEOCart\Contracts\Payment\WebhookEnvelope;
use SEOCart\Contracts\Payment\WebhookReading;
use SEOCart\Tests\Conformance\Payment\Reference\ReferenceGateway;

/**
 * Verifies a delivery by `sha512=` and the HMAC-SHA512 of its raw body in upper-case hexadecimal, and refuses none for its age.
 *
 * A correct gateway of a provider shaped otherwise than the reference's: the provider signs no
 * time and sends a delivery again for days, so the store's receipt of the event refuses a replay
 * and the gateway keeps no window. The suite passes it when its test declares
 * deliveriesCarryNoSignedTime(), and rule 10 fails it when the test does not: a delivery an hour
 * old is not rejected.
 *
 * @since 0.2.0
 */
class UndatedDeliveries extends ReferenceGateway {

	/**
	 * Reads a delivery: its signature over the raw body first, then its content.
	 *
	 * @since 0.2.0
	 *
	 * @param WebhookEnvelope $envelope The delivery.
	 * @return WebhookReading What it says, or why it was rejected.
	 */
	public function readWebhook( WebhookEnvelope $envelope ): WebhookReading {
		$headers = array_change_key_case( $envelope->headers );
		$header  = $headers[ self::SIGNATURE_HEADER ] ?? null;

		if ( ! is_string( $header ) || 1 !== preg_match( '/^sha512=([0-9A-F]{128})$/D', $header, $signature ) ) {
			return WebhookReading::rejected( WebhookReading::BAD_SIGNATURE );
		}

		if ( ! hash_equals( self::digest( $envelope->rawBody, $this->context->settings( $envelope->mode )->secret( GatewayDescriptor::WEBHOOK_SECRET ) ), $signature[1] ) ) {
			return WebhookReading::rejected( WebhookReading::BAD_SIGNATURE );
		}

		return $this->readEvent( $this->decoded( $envelope->rawBody ) );
	}

	/**
	 * Signs a delivery as the provider does: `sha512=` and the HMAC-SHA512 of the body, in upper-case hexadecimal.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, string> $headers The delivery's other headers.
	 * @param string                $body    The raw body.
	 * @param string                $secret  The signing secret.
	 * @return array<string, string> The headers, with the signature.
	 */
	public static function signedUndated( array $headers, string $body, #[\SensitiveParameter] string $secret ): array {
		$headers[ self::SIGNATURE_HEADER ] = 'sha512=' . self::digest( $body, $secret );

		return $headers;
	}

	/**
	 * Computes a delivery's signature.
	 *
	 * @since 0.2.0
	 *
	 * @param string $body   The raw body.
	 * @param string $secret The signing secret.
	 * @return string The HMAC-SHA512 of the body, in upper-case hexadecimal.
	 */
	private static function digest( string $body, #[\SensitiveParameter] string $secret ): string {
		return strtoupper( hash_hmac( 'sha512', $body, $secret ) );
	}
}
