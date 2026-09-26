<?php
/**
 * TaxQuoter: the port through which a calculation obtains tax rates
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Pricing\Application;

use SEOCart\Pricing\Domain\PricingError;
use SEOCart\Pricing\Domain\Quote\TaxQuote;
use SEOCart\Support\Error\CodedException;

defined( 'ABSPATH' ) || exit;

/**
 * Quotes the tax rates of a cart's tax classes at its destination, and at the store's reference jurisdiction.
 *
 * Owns one fact: the contract every tax provider meets. The calculator calls it once per
 * calculation, before any transaction and outside the engine. The quote answers every class it
 * was asked for, with the destination's rates and the store's own; a provider that cannot
 * answer throws, never answers with a silent zero.
 *
 * @since 0.1.0
 */
interface TaxQuoter {

	/**
	 * Quotes the rates.
	 *
	 * @since 0.1.0
	 *
	 * @throws CodedException With PricingError::QuoteUnavailable when the provider cannot answer.
	 *
	 * @param TaxQuoteRequest $request What to quote for.
	 * @return TaxQuote The rates of every class asked for.
	 */
	public function quote( TaxQuoteRequest $request ): TaxQuote;
}
