<?php
/**
 * ShippingRateQuoter: the port through which a calculation obtains shipping rates
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Pricing\Application;

use SEOCart\Pricing\Domain\PricingError;
use SEOCart\Pricing\Domain\Quote\ShippingRateQuote;
use SEOCart\Support\Error\CodedException;

defined( 'ABSPATH' ) || exit;

/**
 * Quotes the shipping rates for a cart's lines and destination.
 *
 * Owns one fact: the contract every shipping provider meets. The calculator calls it once per
 * calculation, before any transaction and outside the engine, and the engine then applies the
 * rates as quoted facts. A provider that cannot answer throws, never answers with a made-up or
 * zero rate: the calculation then fails, which is the one policy there is.
 *
 * @since 0.1.0
 */
interface ShippingRateQuoter {

	/**
	 * Quotes the rates.
	 *
	 * @since 0.1.0
	 *
	 * @throws CodedException With PricingError::QuoteUnavailable when the provider cannot answer.
	 *
	 * @param ShippingQuoteRequest $request What to quote for.
	 * @return list<ShippingRateQuote> The rates, in the cart's currency, in the provider's order; none for a destination nothing reaches.
	 */
	public function quote( ShippingQuoteRequest $request ): array;
}
