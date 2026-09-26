<?php
/**
 * ShippingQuoteRequest: what a shipping provider is asked to quote for
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Pricing\Application;

use SEOCart\Pricing\Domain\Engine\ResolvedLine;
use SEOCart\Support\Address;
use SEOCart\Support\ConversionContext;
use SEOCart\Support\Currency;

defined( 'ABSPATH' ) || exit;

/**
 * The lines to ship, where to, in which currency, at which frozen rate, and when.
 *
 * Owns one fact: what a shipping quoter is given. The lines are the first phase's, so the rates
 * are quoted for exactly what will be priced. A rate is answered in the cart's currency; a
 * provider that quotes in the base currency converts through the context.
 *
 * @since 0.1.0
 */
final readonly class ShippingQuoteRequest {

	/**
	 * Holds the request.
	 *
	 * @since 0.1.0
	 *
	 * @param array              $lines             The lines, as the first phase resolved them.
	 * @param Address|null       $destination       Where the order ships, or null when it is not known yet.
	 * @param Currency           $currency          The cart's currency, the one rates are answered in.
	 * @param ConversionContext  $conversionContext The frozen rate from the base currency to the cart's.
	 * @param \DateTimeImmutable $calculatedAt      When the calculation was asked for; a quote's expiry counts from it.
	 *
	 * @phpstan-param list<ResolvedLine> $lines
	 */
	public function __construct(
		public array $lines,
		public ?Address $destination,
		public Currency $currency,
		public ConversionContext $conversionContext,
		public \DateTimeImmutable $calculatedAt
	) {
	}
}
