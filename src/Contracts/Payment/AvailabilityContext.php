<?php
/**
 * AvailabilityContext: the payment a gateway is asked whether it can take
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Contracts\Payment;

use SEOCart\Support\Currency;
use SEOCart\Support\Money;

defined( 'ABSPATH' ) || exit;

/**
 * What the plugin knows about a new payment when it asks whether a gateway can take it.
 *
 * Owns one fact: the inputs of availability. The plugin builds it at placement, before anything
 * is written, from the order's priced total, its billing country and its channel, the mode the
 * payment would be created in, and the gateway's non-secret settings of that mode, already read.
 *
 * @since 0.2.0
 *
 * @api
 */
final readonly class AvailabilityContext {

	/**
	 * Records the context.
	 *
	 * @since 0.2.0
	 *
	 * @param Currency                       $currency       The payment's currency.
	 * @param Money                          $amount         The amount to authorize.
	 * @param string|null                    $billingCountry The billing address's country, ISO 3166-1 alpha-2, or null when none was given.
	 * @param string                         $channel        Where the order comes from, for example `storefront`.
	 * @param Mode                           $mode           The mode the payment would be created in.
	 * @param array<string, int|string|null> $account        The gateway's non-secret settings of that mode, by the names it declared them with.
	 */
	public function __construct(
		public Currency $currency,
		public Money $amount,
		public ?string $billingCountry,
		public string $channel,
		public Mode $mode,
		public array $account
	) {
	}
}
