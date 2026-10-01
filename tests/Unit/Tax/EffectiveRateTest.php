<?php
/**
 * Tests EffectiveRate: how several rates of one class compose, and that extraction inverts the composition
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Tax;

use PHPUnit\Framework\TestCase;
use SEOCart\Support\Currency;
use SEOCart\Support\Decimal;
use SEOCart\Support\Money;
use SEOCart\Support\Percentage;
use SEOCart\Support\RoundingMode;
use SEOCart\Support\TaxedMoney;
use SEOCart\Tax\Domain\EffectiveRate;

/**
 * Proves the composition of rates on the net amount and of compound rates, and each rate's part of it.
 *
 * Planted violation, shown red and removed: in EffectiveRate::of(), add a compound rate's own
 * rate to the factor instead of its rate times the amount it is charged on: 5 % and a compound
 * 9.975 % come to 14.975 %, not 15.47375 %, and the compound rate's part to 9.975 %, not 10.47375 %.
 *
 * @since 0.1.0
 */
final class EffectiveRateTest extends TestCase {

	/**
	 * Tests the factor and the multiplier of rates charged on the net amount.
	 *
	 * @since 0.1.0
	 *
	 * @dataProvider data_rates
	 *
	 * @param string[] $rates      The rates, in percent.
	 * @param string   $factor     The factor they compose to.
	 * @param string   $multiplier One plus the factor.
	 */
	public function test_rates_charged_on_the_net_amount_add_up( array $rates, string $factor, string $multiplier ): void {
		$rate = EffectiveRate::of( self::percentages( $rates ) );

		$this->assertSame( $factor, $rate->factor()->toString() );
		$this->assertSame( $multiplier, $rate->multiplier()->toString() );
		$this->assertSame( array_map( static fn( Percentage $percentage ): string => $percentage->toFactor()->toString(), self::percentages( $rates ) ), array_map( static fn( Decimal $part ): string => $part->toString(), $rate->parts() ), 'Each rate on the net amount is its own part.' );
	}

	/**
	 * Provides rates and what they compose to.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{list<string>, string, string}> Test cases.
	 */
	public static function data_rates(): array {
		return array(
			'no rate is zero'           => array( array(), '0', '1' ),
			'one rate'                  => array( array( '20' ), '0.20000000', '1.20000000' ),
			'a state and a county rate' => array( array( '6.25', '2' ), '0.08250000', '1.08250000' ),
			'three rates, exactly'      => array( array( '0.000001', '0.000002', '7' ), '0.07000003', '1.07000003' ),
		);
	}

	/**
	 * Tests that a compound rate multiplies on top of the rates before it, and that each rate's part is what it adds.
	 *
	 * @since 0.1.0
	 */
	public function test_a_compound_rate_multiplies_on_top_of_the_rates_before_it(): void {
		$rate = EffectiveRate::of( self::percentages( array( '5' ) ), array( 1 => Percentage::fromString( '9.975' ) ) );

		$this->assertTrue( $rate->multiplier()->equals( Decimal::of( '1.05' )->multiply( Decimal::of( '1.09975' ) ) ), '1.05 × 1.09975.' );
		$this->assertTrue( $rate->factor()->equals( Decimal::of( '0.1547375' ) ) );
		$this->assertSame( array( '0.05', '0.1047375' ), self::strings( $rate->parts() ), 'The compound rate adds 9.975 % of 1.05 per unit of net amount.' );
		$this->assertSame(
			array(
				0 => array(),
				1 => array( 0 ),
			),
			$rate->includedTaxes()
		);
	}

	/**
	 * Tests two compound rates: each is charged on the net amount and every tax before it, and the parts add up to the factor.
	 *
	 * @since 0.1.0
	 */
	public function test_compound_rates_apply_in_the_order_given(): void {
		$rate = EffectiveRate::of(
			array( 2 => Percentage::fromString( '10' ) ),
			array(
				0 => Percentage::fromString( '5' ),
				1 => Percentage::fromString( '2' ),
			)
		);

		$this->assertTrue( $rate->multiplier()->equals( Decimal::of( '1.1781' ) ), '1.10 × 1.05 × 1.02.' );
		$this->assertSame(
			array(
				2 => '0.1',
				0 => '0.055',
				1 => '0.0231',
			),
			self::strings( $rate->parts() ),
			'Keyed as given, in the order the rates apply.'
		);
		$this->assertSame(
			array(
				2 => array(),
				0 => array( 2 ),
				1 => array( 2, 0 ),
			),
			$rate->includedTaxes()
		);
		$this->assertTrue( array_reduce( $rate->parts(), static fn( Decimal $sum, Decimal $part ): Decimal => $sum->add( $part ), Decimal::of( '0' ) )->equals( $rate->factor() ), 'The parts add up to the factor.' );

		$swapped = EffectiveRate::of(
			array( 2 => Percentage::fromString( '10' ) ),
			array(
				1 => Percentage::fromString( '2' ),
				0 => Percentage::fromString( '5' ),
			)
		);

		$this->assertTrue( $swapped->factor()->equals( $rate->factor() ), 'The order of compound rates does not change the total.' );
		$this->assertSame(
			array(
				2 => '0.1',
				1 => '0.022',
				0 => '0.0561',
			),
			self::strings( $swapped->parts() ),
			'It changes which rate is charged on which tax.'
		);
	}

	/**
	 * Tests that a negative rate, and two rates sharing a key, are refused.
	 *
	 * @since 0.1.0
	 *
	 * @dataProvider data_refused
	 *
	 * @param array<int, Percentage> $onNet    The rates on the net amount.
	 * @param array<int, Percentage> $compound The compound rates.
	 */
	public function test_a_negative_rate_or_a_shared_key_is_refused( array $onNet, array $compound ): void {
		$this->expectException( \InvalidArgumentException::class );

		EffectiveRate::of( $onNet, $compound );
	}

	/**
	 * Provides rates that cannot be composed.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{array<int, Percentage>, array<int, Percentage>}> Test cases.
	 */
	public static function data_refused(): array {
		return array(
			'a negative rate'          => array( array( Percentage::fromString( '20' ), Percentage::fromString( '-1' ) ), array() ),
			'a negative compound rate' => array( array( Percentage::fromString( '20' ) ), array( 1 => Percentage::fromString( '-1' ) ) ),
			'two rates with one key'   => array( array( Percentage::fromString( '5' ) ), array( 0 => Percentage::fromString( '9.975' ) ) ),
		);
	}

	/**
	 * Tests that tax added to a net amount and taken back out of its gross gives the net amount again, for additive and compound rates, in both rounding modes.
	 *
	 * Extraction inverts the same composition that added the tax: a net amount taxed at the
	 * composed rate, and its gross then taxed as a gross amount at that rate, comes back to the
	 * same net, tax and gross, whichever way the tax was rounded.
	 *
	 * @group international
	 *
	 * @since 0.1.0
	 *
	 * @dataProvider data_compositions
	 *
	 * @param EffectiveRate $rate The composed rate.
	 */
	public function test_adding_tax_then_taking_it_out_is_the_identity( EffectiveRate $rate ): void {
		$currency = Currency::of( 'EUR' );
		$broken   = array();

		foreach ( RoundingMode::cases() as $mode ) {
			for ( $minor = 1; $minor <= 5000; $minor += 7 ) {
				$added = TaxedMoney::fromNet( Money::of( $minor, $currency ), $rate->factor(), $mode );
				$taken = TaxedMoney::fromGross( $added->gross(), $rate->factor(), $mode );

				if ( ! $taken->equals( $added ) ) {
					$broken[] = sprintf( '%s %d: %d + %d, taken out as %d + %d', $mode->value, $minor, $added->net()->minorUnits(), $added->tax()->minorUnits(), $taken->net()->minorUnits(), $taken->tax()->minorUnits() );
				}
			}
		}

		$this->assertSame( array(), $broken );
	}

	/**
	 * Provides compositions: additive, compound, and both.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{EffectiveRate}> Test cases.
	 */
	public static function data_compositions(): array {
		return array(
			'a single rate'                   => array( EffectiveRate::of( self::percentages( array( '20' ) ) ) ),
			'two rates that add'              => array( EffectiveRate::of( self::percentages( array( '6.25', '2' ) ) ) ),
			'a rate and a compound one'       => array( EffectiveRate::of( self::percentages( array( '5' ) ), array( 1 => Percentage::fromString( '9.975' ) ) ) ),
			'a rate and two compound ones'    => array(
				EffectiveRate::of(
					self::percentages( array( '10' ) ),
					array(
						1 => Percentage::fromString( '5' ),
						2 => Percentage::fromString( '2' ),
					)
				),
			),
			'two compound rates and no other' => array(
				EffectiveRate::of(
					array(),
					array(
						0 => Percentage::fromString( '7' ),
						1 => Percentage::fromString( '8.5' ),
					)
				),
			),
		);
	}

	/**
	 * Reads rates in percent.
	 *
	 * @since 0.1.0
	 *
	 * @param string[] $rates The rates.
	 * @return list<Percentage> The percentages.
	 *
	 * @phpstan-param list<string> $rates
	 */
	private static function percentages( array $rates ): array {
		return array_map( static fn( string $percent ): Percentage => Percentage::fromString( $percent ), $rates );
	}

	/**
	 * Writes decimals as their shortest exact strings, keyed as given.
	 *
	 * @since 0.1.0
	 *
	 * @param array<int, Decimal> $decimals The decimals.
	 * @return array<int, string> Their values without trailing zeros.
	 */
	private static function strings( array $decimals ): array {
		return array_map( static fn( Decimal $decimal ): string => rtrim( rtrim( $decimal->toString(), '0' ), '.' ), $decimals );
	}
}
