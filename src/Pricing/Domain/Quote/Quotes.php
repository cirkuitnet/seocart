<?php
/**
 * Quotes: the shipping and tax quotes the second phase of a calculation applies
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Pricing\Domain\Quote;

defined( 'ABSPATH' ) || exit;

/**
 * Everything the calculation needed from outside, obtained after its first phase and before its second.
 *
 * Owns one fact: which quotes belong to which lines. The fingerprint names the lines the
 * shipping was quoted for, the first phase's lines; the second phase refuses quotes whose
 * fingerprint is not its own, so a rate quoted for other lines is never applied.
 *
 * @since 0.1.0
 */
final readonly class Quotes {

	/**
	 * Holds the quotes.
	 *
	 * @since 0.1.0
	 *
	 * @param array    $shippingRates       The shipping rates, in the provider's order.
	 * @param TaxQuote $taxQuote            The tax rates.
	 * @param string   $packagesFingerprint The fingerprint of the lines the quotes were taken for.
	 *
	 * @phpstan-param list<ShippingRateQuote> $shippingRates
	 */
	public function __construct(
		public array $shippingRates,
		public TaxQuote $taxQuote,
		public string $packagesFingerprint
	) {
	}
}
