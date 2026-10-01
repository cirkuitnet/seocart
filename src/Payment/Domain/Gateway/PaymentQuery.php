<?php
/**
 * PaymentQuery: what a gateway is given to say where an intent stands
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
 * A status query: the intent, the provider's reference to it, and the amount it was for.
 *
 * Owns one fact: what reconciliation sends a provider about an intent whose result never
 * arrived. The provider looks the intent up by its reference, or by the intent's uuid, which it
 * received as the idempotency key.
 *
 * @since 0.1.0
 */
final readonly class PaymentQuery {

	/**
	 * Records the query.
	 *
	 * @since 0.1.0
	 *
	 * @param string      $intentUuid       The intent's uuid.
	 * @param string|null $providerIntentId The provider's reference to the intent, when it gave one.
	 * @param Money       $amount           The intent's frozen amount.
	 */
	public function __construct(
		public string $intentUuid,
		public ?string $providerIntentId,
		public Money $amount
	) {
	}
}
