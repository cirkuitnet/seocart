<?php
/**
 * TaxQuote: the tax rates a provider quoted for the cart's destination
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Pricing\Domain\Quote;

use SEOCart\Pricing\Domain\InstantFormat;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The message names a value built by code, for the developer; it is never rendered.

/**
 * The rates of every tax class the cart needs, at the destination and at the store's reference jurisdiction.
 *
 * Owns one fact: the tax rates the calculation applies, as quoted facts. The destination's rates
 * are the ones charged. The reference rates are the store's own, the rates a gross-authored
 * price was worked out at; a policy that keeps the net amount fixed across zones needs both.
 * Where the store sells at home, the two are the same.
 *
 * @since 0.1.0
 */
final readonly class TaxQuote {

	/**
	 * Holds the quote.
	 *
	 * @since 0.1.0
	 *
	 * @param string             $quoteId             The quote's identifier.
	 * @param string             $jurisdictionCode    The destination's jurisdiction.
	 * @param string             $providerFingerprint The provider and version that quoted it.
	 * @param array              $destinationRates    The destination's rates, by tax class.
	 * @param array              $referenceRates      The store's reference rates, by tax class.
	 * @param \DateTimeImmutable $expiresAt           When the quote stops holding.
	 *
	 * @phpstan-param array<string, list<TaxRateComponent>> $destinationRates
	 * @phpstan-param array<string, list<TaxRateComponent>> $referenceRates
	 */
	public function __construct(
		public string $quoteId,
		public string $jurisdictionCode,
		public string $providerFingerprint,
		public array $destinationRates,
		public array $referenceRates,
		public \DateTimeImmutable $expiresAt
	) {
	}

	/**
	 * Tells whether the quote has rates, at the destination and at the reference, for every class asked for.
	 *
	 * @since 0.1.0
	 *
	 * @param string ...$taxClasses The classes.
	 * @return bool True when every class is quoted both ways.
	 */
	public function covers( string ...$taxClasses ): bool {
		foreach ( $taxClasses as $taxClass ) {
			if ( ! isset( $this->destinationRates[ $taxClass ], $this->referenceRates[ $taxClass ] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Returns the destination's rates of a class.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException When the class was not quoted: the quote was taken for other amounts.
	 *
	 * @param string $taxClass The class.
	 * @return list<TaxRateComponent> The rates; none for a class nothing taxes.
	 */
	public function destinationRatesFor( string $taxClass ): array {
		return $this->destinationRates[ $taxClass ] ?? throw new \LogicException( sprintf( 'The tax quote has no rates for the class "%s".', $taxClass ) );
	}

	/**
	 * Returns the store's reference rates of a class.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException When the class was not quoted: the quote was taken for other amounts.
	 *
	 * @param string $taxClass The class.
	 * @return list<TaxRateComponent> The rates; none for a class nothing taxes.
	 */
	public function referenceRatesFor( string $taxClass ): array {
		return $this->referenceRates[ $taxClass ] ?? throw new \LogicException( sprintf( 'The tax quote has no reference rates for the class "%s".', $taxClass ) );
	}

	/**
	 * Returns the quote as scalars, for the trace.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, string> The quote.
	 */
	public function toArray(): array {
		return array(
			'quote_id'     => $this->quoteId,
			'jurisdiction' => $this->jurisdictionCode,
			'expires_at'   => InstantFormat::of( $this->expiresAt ),
			'provider'     => $this->providerFingerprint,
		);
	}
}
