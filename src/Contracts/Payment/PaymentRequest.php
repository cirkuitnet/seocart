<?php
/**
 * PaymentRequest: what a gateway is given to authorize an intent
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Contracts\Payment;

use SEOCart\Support\Money;

defined( 'ABSPATH' ) || exit;

/**
 * An authorization request: the intent, its frozen amount, the customer's payment token, the intent's mode and the order it pays for.
 *
 * Owns one fact: what an authorization sends a provider. The intent's uuid is also the
 * idempotency key the provider receives, stable across retries of the one attempt. The token
 * is what the provider's own script in the browser produced; no field here holds card data.
 *
 * @since 0.1.0
 * @since 0.2.0 Moved to the public contract. Gained the mode, the order's uuid and number, and the
 *              return address; the token may be null.
 *
 * @api
 */
final readonly class PaymentRequest {

	/**
	 * Records the request.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 The mode, the order's uuid and number, and the return address were added.
	 *
	 * @param string      $intentUuid   The intent's uuid, and the provider's idempotency key.
	 * @param Money       $amount       The intent's frozen amount.
	 * @param string|null $paymentToken The provider's token for the customer's payment method; null when the
	 *                                  customer chooses it on the provider's own page after this call.
	 * @param Mode        $mode         The intent's mode, whose credentials the call uses.
	 * @param string      $orderUuid    The order's public identifier.
	 * @param string      $orderNumber  The order's number, as people see it.
	 * @param string|null $returnUrl    Optional. Where the provider sends the customer back after asking them to
	 *                                  act; it carries the intent's uuid and nothing secret. Default null.
	 */
	public function __construct(
		public string $intentUuid,
		public Money $amount,
		public ?string $paymentToken,
		public Mode $mode,
		public string $orderUuid,
		public string $orderNumber,
		public ?string $returnUrl = null
	) {
	}

	/**
	 * Returns the key the provider is sent with the authorization.
	 *
	 * @since 0.2.0
	 *
	 * @return string The intent's uuid.
	 */
	public function idempotencyKey(): string {
		return $this->intentUuid;
	}
}
