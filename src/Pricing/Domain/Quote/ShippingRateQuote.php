<?php
/**
 * ShippingRateQuote: one shipping rate a provider quoted for the cart
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Pricing\Domain\Quote;

use SEOCart\Pricing\Domain\AuthoredAmount;
use SEOCart\Pricing\Domain\InstantFormat;

defined( 'ABSPATH' ) || exit;

/**
 * A shipping rate as a quoted fact: which method, what it costs, how it is taxed, and until when it holds.
 *
 * Owns one fact: what the calculation knows of a shipping rate. It was obtained before the
 * calculation ran and is never fetched again by it; a quoted rate of zero is a fact like any
 * other and can be selected. A rate is never negative: taking shipping off is a promotion's
 * work, with a source of its own.
 *
 * @since 0.1.0
 */
final readonly class ShippingRateQuote {

	/**
	 * Checks and holds the quote.
	 *
	 * @since 0.1.0
	 *
	 * @throws \InvalidArgumentException When the rate is negative: a provider that pays the customer to ship is a broken quote, not a discount.
	 *
	 * @param string             $quoteId             The quote's identifier.
	 * @param string             $methodKey           The shipping method's key, which the adjustment's source names.
	 * @param string             $labelKey            The key the storefront turns into the method's name.
	 * @param AuthoredAmount     $rate                The rate, in the cart's currency, with its basis.
	 * @param string             $taxClass            The tax class of the rate.
	 * @param \DateTimeImmutable $expiresAt           When the quote stops holding.
	 * @param string             $providerFingerprint The provider and version that quoted it.
	 */
	public function __construct(
		public string $quoteId,
		public string $methodKey,
		public string $labelKey,
		public AuthoredAmount $rate,
		public string $taxClass,
		public \DateTimeImmutable $expiresAt,
		public string $providerFingerprint
	) {
		if ( $rate->amount->isNegative() ) {
			throw new \InvalidArgumentException( 'A shipping rate is never negative.' );
		}
	}

	/**
	 * Returns the quote as scalars, for the trace.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, int|string> The quote.
	 */
	public function toArray(): array {
		return array(
			'quote_id'   => $this->quoteId,
			'method_key' => $this->methodKey,
			'rate_minor' => $this->rate->amount->minorUnits(),
			'rate_basis' => $this->rate->basis->value,
			'tax_class'  => $this->taxClass,
			'expires_at' => InstantFormat::of( $this->expiresAt ),
			'provider'   => $this->providerFingerprint,
		);
	}
}
