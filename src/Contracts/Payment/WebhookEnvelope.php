<?php
/**
 * WebhookEnvelope: a delivery from a provider, as the plugin received it
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Contracts\Payment;

defined( 'ABSPATH' ) || exit;

/**
 * One webhook delivery, unread: the gateway and mode it was sent to, its headers and its raw body.
 *
 * Owns one fact: what arrived. The plugin reads nothing of the body; the gateway verifies the
 * delivery's signature over these exact bytes before it parses them (PaymentGateway::readWebhook()).
 * The mode is the one in the address the delivery was sent to, never one the body claims.
 *
 * @since 0.2.0
 *
 * @api
 */
final readonly class WebhookEnvelope {

	/**
	 * Records the delivery.
	 *
	 * @since 0.2.0
	 *
	 * @param string                $gatewayId  The gateway the delivery was addressed to.
	 * @param Mode                  $mode       The mode of the address it was sent to.
	 * @param array<string, string> $headers    Its headers, keyed by lower-case name.
	 * @param string                $rawBody    Its body, byte for byte.
	 * @param \DateTimeImmutable    $receivedAt When it arrived, UTC.
	 */
	public function __construct(
		public string $gatewayId,
		public Mode $mode,
		public array $headers,
		public string $rawBody,
		public \DateTimeImmutable $receivedAt
	) {
	}
}
