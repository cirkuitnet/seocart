<?php
/**
 * GatewayRefund: what a gateway is given to give money back
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Payment\Domain\Gateway;

use SEOCart\Support\Money;

defined( 'ABSPATH' ) || exit;

/**
 * A refund request: the intent, the provider's reference to it, the amount to give back, and the refund's own identifier.
 *
 * Owns one fact: what a refund sends a provider. The amount is in the intent's currency and was
 * allocated from the order's stored figures before the call. The refund's uuid is the
 * idempotency key the provider receives, and what the refund is found by when the gateway is
 * asked what became of it (PaymentGateway::queryRefund()), which is asked with the same request.
 *
 * @since 0.1.0
 */
final readonly class GatewayRefund {

	/**
	 * Records the request.
	 *
	 * @since 0.1.0
	 *
	 * @param string      $intentUuid       The intent's uuid.
	 * @param string|null $providerIntentId The provider's reference to the intent, when it gave one.
	 * @param Money       $amount           The amount to give back.
	 * @param string      $refundUuid       The refund's uuid: its idempotency key.
	 */
	public function __construct(
		public string $intentUuid,
		public ?string $providerIntentId,
		public Money $amount,
		public string $refundUuid
	) {
	}
}
