<?php
/**
 * EffectiveRate: the one rate that several tax rates on the same amount come to
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
 * The combined rate of the tax rates that apply to one amount, as an exact factor, and each rate's part of it.
 *
 * Owns one fact: how several rates of one tax class compose into the single rate that adds tax
 * to a net amount or takes it out of a gross one. Taking tax out uses the same composition as
 * adding it, so a net amount taxed and then taken back out of its gross is the net amount again.
 *
 * - A rate that is not compound is charged on the net amount, so such rates add up: 6.25 % and
 *   2 % make 8.25 %.
 * - A compound rate is charged on the net amount together with the tax of every rate before it,
 *   so it multiplies on top of the sum, in the order the rates are given: 5 % and then a
 *   compound 9.975 % make 1.05 × 1.09975 = 1.1547375, an effective 15.47375 %.
 *
 * Each rate's part of the factor is what that rate adds per unit of net amount: its own rate for
 * a rate on the net amount, and, for a compound rate, its rate times the amount it is charged on
 * (1.05 × 9.975 % = 10.47375 % above). The parts add up to the factor exactly, so a tax shared
 * out in proportion to them gives each rate its exact share. Everything is kept exact, at the
 * scale of the rates' factors multiplied out, so composing rates never rounds; the one rounding
 * happens where the calculation turns the taxed amount into minor units.
 *
 * @since 0.1.0
 */
final readonly class EffectiveRate {

	/**
	 * Holds the composed rate.
	 *
	 * @since 0.1.0
	 *
	 * @param Decimal $factor   The rate as a factor: 0.2 for twenty percent. Never negative.
	 * @param array   $parts    Each rate's part of the factor, keyed as the rates were given.
	 * @param array   $includes For each rate, the keys of the rates whose tax is in the amount it is charged on.
	 *
	 * @phpstan-param array<int, Decimal>   $parts
	 * @phpstan-param array<int, list<int>> $includes
	 */
	private function __construct(
		private Decimal $factor,
		private array $parts,
		private array $includes
	) {
	}

	/**
	 * Composes rates: those on the net amount add up, and compound ones multiply on top, in the order given.
	 *
	 * No rate at all is a rate of zero: an amount of a tax class that nothing taxes. Each rate is
	 * keyed by the caller, and the parts and the included taxes are keyed the same way.
	 *
	 * @since 0.1.0
	 *
	 * @throws \InvalidArgumentException When a rate is negative, or two rates share a key.
	 *
	 * @param Percentage[] $onNet    The rates charged on the net amount, by key.
	 * @param Percentage[] $compound Optional. The compound rates, by key, in the order they apply. Default none.
	 * @return self The composed rate.
	 *
	 * @phpstan-param array<int, Percentage> $onNet
	 * @phpstan-param array<int, Percentage> $compound
	 */
	public static function of( array $onNet, array $compound = array() ): self {
		if ( array() !== array_intersect_key( $onNet, $compound ) ) {
			throw new \InvalidArgumentException( 'Two tax rates of one amount share a key.' );
		}

		$parts    = array();
		$includes = array();
		$sum      = Decimal::ofUnscaled( 0, 0 );

		foreach ( $onNet as $key => $rate ) {
			$parts[ $key ]    = self::factorOf( $rate );
			$includes[ $key ] = array();
			$sum              = $sum->add( $parts[ $key ] );
		}

		// The amount a compound rate is charged on, per unit of net amount: one, plus every tax before it.
		$chargedOn = Decimal::ofUnscaled( 1, 0 )->add( $sum );
		$before    = array_keys( $onNet );

		foreach ( $compound as $key => $rate ) {
			$parts[ $key ]    = $chargedOn->multiply( self::factorOf( $rate ) );
			$includes[ $key ] = $before;
			$chargedOn        = $chargedOn->add( $parts[ $key ] );
			$before[]         = $key;
		}

		return new self( $chargedOn->subtract( Decimal::ofUnscaled( 1, 0 ) ), $parts, $includes );
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

	/**
	 * Returns each rate's part of the factor: what it adds per unit of net amount.
	 *
	 * @since 0.1.0
	 *
	 * @return array<int, Decimal> The parts, keyed as the rates were given, in the order they apply; they add up to the factor.
	 */
	public function parts(): array {
		return $this->parts;
	}

	/**
	 * Returns, for each rate, the rates whose tax is in the amount it is charged on.
	 *
	 * @since 0.1.0
	 *
	 * @return array<int, list<int>> By rate key: no key for a rate on the net amount; every rate before it for a compound rate.
	 */
	public function includedTaxes(): array {
		return $this->includes;
	}

	/**
	 * Returns a rate as a factor, refusing a negative one.
	 *
	 * @since 0.1.0
	 *
	 * @throws \InvalidArgumentException When the rate is negative.
	 *
	 * @param Percentage $rate The rate.
	 * @return Decimal Its factor.
	 */
	private static function factorOf( Percentage $rate ): Decimal {
		if ( $rate->micropercent() < 0 ) {
			throw new \InvalidArgumentException( 'A tax rate cannot be negative.' );
		}

		return $rate->toFactor();
	}
}
