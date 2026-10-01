<?php
/**
 * PaymentRequest: what a gateway is given to authorize an intent
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
 * An authorization request: the intent, its frozen amount and the customer's payment token.
 *
 * Owns one fact: what an authorization sends a provider. The intent's uuid is also the
 * idempotency key the provider receives, stable across retries of the one attempt. The token
 * is what the provider's own script in the browser produced; no field here holds card data.
 *
 * @since 0.1.0
 */
final readonly class PaymentRequest {

	/**
	 * Records the request.
	 *
	 * @since 0.1.0
	 *
	 * @param string $intentUuid   The intent's uuid, and the provider's idempotency key.
	 * @param Money  $amount       The intent's frozen amount.
	 * @param string $paymentToken The provider's token for the customer's payment method.
	 */
	public function __construct(
		public string $intentUuid,
		public Money $amount,
		public string $paymentToken
	) {
	}
}
