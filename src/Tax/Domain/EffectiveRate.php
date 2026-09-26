<?php
/**
 * EffectiveRate: the one rate that several tax rates on the same amount add up to
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tax\Domain;

use SEOCart\Support\Decimal;
use SEOCart\Support\Percentage;

defined( 'ABSPATH' ) || exit;

/**
 * The combined rate of the tax rates that apply to one amount, as an exact factor.
 *
 * Owns one fact: how several rates of one tax class compose into the single rate that adds tax
 * to a net amount or takes it out of a gross one. Rates that are not compound are each charged
 * on the net amount, so they add: 6 % and 2 % make 8 %. The factor is kept exact, at the scale
 * of a Percentage's factor, so composing rates never rounds; the one rounding happens where the
 * calculation turns the taxed amount into minor units.
 *
 * @since 0.1.0
 */
final readonly class EffectiveRate {

	/**
	 * Holds the factor.
	 *
	 * @since 0.1.0
	 *
	 * @param Decimal $factor The rate as a factor: 0.2 for twenty percent. Never negative.
	 */
	private function __construct( private Decimal $factor ) {
	}

	/**
	 * Adds up rates that are each charged on the net amount.
	 *
	 * No rate at all is a rate of zero: an amount of a tax class that nothing taxes.
	 *
	 * @since 0.1.0
	 *
	 * @throws \InvalidArgumentException When a rate is negative.
	 *
	 * @param Percentage ...$rates The rates, none of them compound.
	 * @return self Their sum.
	 */
	public static function additive( Percentage ...$rates ): self {
		$factor = Decimal::ofUnscaled( 0, 0 );

		foreach ( $rates as $rate ) {
			if ( $rate->micropercent() < 0 ) {
				throw new \InvalidArgumentException( 'A tax rate cannot be negative.' );
			}

			$factor = $factor->add( $rate->toFactor() );
		}

		return new self( $factor );
	}

	/**
	 * Returns the rate as a factor.
	 *
	 * @since 0.1.0
	 *
	 * @return Decimal The factor: 0.2 for twenty percent.
	 */
	public function factor(): Decimal {
		return $this->factor;
	}

	/**
	 * Returns what a net amount is multiplied by to include the tax: one plus the factor.
	 *
	 * @since 0.1.0
	 *
	 * @return Decimal The multiplier: 1.2 for twenty percent.
	 */
	public function multiplier(): Decimal {
		return Decimal::ofUnscaled( 1, 0 )->add( $this->factor );
	}
}
