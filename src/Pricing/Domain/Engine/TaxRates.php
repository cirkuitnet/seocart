<?php
/**
 * TaxRates: the effective rates of each tax class, at the destination and at the store's reference
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Pricing\Domain\Engine;

use SEOCart\Pricing\Domain\Quote\TaxQuote;
use SEOCart\Pricing\Domain\Quote\TaxRateComponent;
use SEOCart\Tax\Domain\CrossZonePolicy;
use SEOCart\Tax\Domain\EffectiveRate;

defined( 'ABSPATH' ) || exit;

/**
 * Composes the quoted rates of a tax class into the rates the second phase works with.
 *
 * Owns one fact: which composed rate applies where. The destination's rates are the ones
 * charged; the reference rates are the store's own, the ones its gross prices were set at. The
 * rates of a class are composed as quoted: those that are not compound add up, and the compound
 * ones multiply on top in the order of their priority, lowest first, in quote order on a tie.
 *
 * @since 0.1.0
 */
final readonly class TaxRates {

	/**
	 * Holds the quote and the policy.
	 *
	 * @since 0.1.0
	 *
	 * @param TaxQuote        $quote  The tax quote of the calculation.
	 * @param CrossZonePolicy $policy The calculation's cross-zone policy.
	 */
	public function __construct(
		private TaxQuote $quote,
		private CrossZonePolicy $policy
	) {
	}

	/**
	 * Returns the destination's rate of a class: the rate charged.
	 *
	 * @since 0.1.0
	 *
	 * @param string $taxClass The class.
	 * @return EffectiveRate The rate, keyed by the positions of the quoted rates.
	 */
	public function destination( string $taxClass ): EffectiveRate {
		return self::composed( $this->quote->destinationRatesFor( $taxClass ) );
	}

	/**
	 * Returns the store's reference rate of a class: the rate its gross prices were set at.
	 *
	 * @since 0.1.0
	 *
	 * @param string $taxClass The class.
	 * @return EffectiveRate The rate.
	 */
	public function reference( string $taxClass ): EffectiveRate {
		return self::composed( $this->quote->referenceRatesFor( $taxClass ) );
	}

	/**
	 * Tells whether a class is charged at the store's own rate, so that a gross price there is the price paid under either policy.
	 *
	 * @since 0.1.0
	 *
	 * @param string $taxClass The class.
	 * @return bool True when the destination's rate is the reference rate.
	 */
	public function atHome( string $taxClass ): bool {
		return $this->destination( $taxClass )->multiplier()->equals( $this->reference( $taxClass )->multiplier() );
	}

	/**
	 * Returns the rate at which a gross amount of a class and a net amount are the same price, under the policy.
	 *
	 * A gross amount stands for the net amount `gross ÷ (1 + r)` at the rate the policy prices
	 * gross amounts at. Under the fixed-gross policy that is the destination's rate: the gross is
	 * what the customer pays there. Under the fixed-net policy it is the store's reference rate:
	 * the gross was set at home and keeps its net amount wherever it is sold. The tax step takes
	 * a gross amount's net at this rate, and the exempt customer pays that net; so an amount
	 * brought from one basis to the other at this rate changes a line exactly as the same amount
	 * authored in the line's own basis would.
	 *
	 * For example, under fixed-net with a reference rate of 20 % and a destination rate of 25 %, a
	 * discount of 1.00 net on a line priced 12.00 gross is 1.20 off the gross price: the line's
	 * net goes from 10.00 to 9.00, exactly 1.00, as it would on a line priced net, and an exempt
	 * customer pays 1.00 less too. At the destination's rate it would be 1.25 off the gross price,
	 * which takes 1.25 ÷ 1.20 = 1.04 off the net. At home, and under fixed-gross, the two rates
	 * are the same.
	 *
	 * @since 0.1.0
	 *
	 * @param string $taxClass The class.
	 * @return EffectiveRate The rate.
	 */
	public function basisRate( string $taxClass ): EffectiveRate {
		return CrossZonePolicy::FixedGross === $this->policy ? $this->destination( $taxClass ) : $this->reference( $taxClass );
	}

	/**
	 * Composes the quoted rates of a class into one.
	 *
	 * @since 0.1.0
	 *
	 * @param TaxRateComponent[] $rates The rates, in quote order.
	 * @return EffectiveRate Their composition, keyed by position in the quote.
	 *
	 * @phpstan-param list<TaxRateComponent> $rates
	 */
	private static function composed( array $rates ): EffectiveRate {
		$onNet    = array();
		$compound = array();

		foreach ( $rates as $position => $rate ) {
			if ( $rate->isCompound ) {
				$compound[ $position ] = $rate;
			} else {
				$onNet[ $position ] = $rate->rate;
			}
		}

		// Lowest priority first; uasort() keeps quote order on a tie.
		uasort( $compound, static fn( TaxRateComponent $first, TaxRateComponent $second ): int => $first->priority <=> $second->priority );

		return EffectiveRate::of( $onNet, array_map( static fn( TaxRateComponent $rate ) => $rate->rate, $compound ) );
	}
}
