<?php
/**
 * Tests TaxedMoney: net + tax = gross, in minor units, whatever the entry mode
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Random\Randomizer;
use SEOCart\Support\Currency;
use SEOCart\Support\CurrencyMismatchException;
use SEOCart\Support\Decimal;
use SEOCart\Support\Money;
use SEOCart\Support\Percentage;
use SEOCart\Support\RoundingMode;
use SEOCart\Support\TaxedMoney;
use SEOCart\Tests\Support\MoneyAssertions;
use SEOCart\Tests\Support\SeededCases;

/**
 * Proves the invariant at construction and through every operation, and both entry modes.
 *
 * The property oracle is exact integer arithmetic on minor units: from a net amount N and a
 * rate of m millionths of a percent, the tax is N × m ÷ 10^8; from a gross amount G, it is
 * G × m ÷ (10^8 + m). Each is rounded once, by the mode, on its magnitude.
 *
 * @since 0.1.0
 */
final class TaxedMoneyTest extends TestCase {

	use MoneyAssertions;

	/**
	 * Tests that three figures that add up are accepted and kept.
	 *
	 * @since 0.1.0
	 */
	public function test_a_triple_that_adds_up_is_accepted(): void {
		$usd   = Currency::of( 'USD' );
		$taxed = new TaxedMoney( Money::of( 1000, $usd ), Money::of( 200, $usd ), Money::of( 1200, $usd ) );

		$this->assertMoneyEquals( Money::of( 1000, $usd ), $taxed->net() );
		$this->assertMoneyEquals( Money::of( 200, $usd ), $taxed->tax() );
		$this->assertMoneyEquals( Money::of( 1200, $usd ), $taxed->gross() );
		$this->assertSame( 'USD', $taxed->currency()->code() );
		$this->assertTaxedMoneyEquals( new TaxedMoney( Money::zero( $usd ), Money::zero( $usd ), Money::zero( $usd ) ), TaxedMoney::zero( $usd ) );
	}

	/**
	 * Tests that a triple that does not add up is refused.
	 *
	 * @since 0.1.0
	 */
	public function test_a_triple_that_does_not_add_up_is_refused(): void {
		$usd = Currency::of( 'USD' );

		$this->expectException( \InvalidArgumentException::class );

		new TaxedMoney( Money::of( 1000, $usd ), Money::of( 200, $usd ), Money::of( 1201, $usd ) );
	}

	/**
	 * Tests that figures in two currencies are refused.
	 *
	 * @since 0.1.0
	 */
	public function test_a_triple_in_two_currencies_is_refused(): void {
		$this->expectException( CurrencyMismatchException::class );

		new TaxedMoney( Money::of( 1000, Currency::of( 'USD' ) ), Money::of( 200, Currency::of( 'CAD' ) ), Money::of( 1200, Currency::of( 'USD' ) ) );
	}

	/**
	 * Tests adding tax to an authored net amount.
	 *
	 * @since 0.1.0
	 *
	 * @dataProvider data_net_amounts
	 *
	 * @param int    $net         The net amount in minor units.
	 * @param string $percent     The rate in percent.
	 * @param int    $half_up     The tax rounded half up.
	 * @param int    $toward_zero The tax rounded toward zero.
	 */
	public function test_from_net_computes_tax_once_and_derives_gross( int $net, string $percent, int $half_up, int $toward_zero ): void {
		$usd = Currency::of( 'USD' );

		foreach ( array( array( $half_up, RoundingMode::HalfUp ), array( $toward_zero, RoundingMode::TowardZero ) ) as list( $tax, $mode ) ) {
			$taxed = TaxedMoney::fromNet( Money::of( $net, $usd ), Percentage::fromString( $percent ), $mode );

			$this->assertTaxedMoneyEquals( new TaxedMoney( Money::of( $net, $usd ), Money::of( $tax, $usd ), Money::of( $net + $tax, $usd ) ), $taxed, $mode->value );
		}
	}

	/**
	 * Provides net amounts with their tax in both modes.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{int, string, int, int}> Test cases.
	 */
	public static function data_net_amounts(): array {
		return array(
			'twenty percent of ten dollars' => array( 1000, '20', 200, 200 ),
			'7.25 percent of 9.99'          => array( 999, '7.25', 72, 72 ),
			'a tie: five percent of 2.50'   => array( 250, '5', 13, 12 ),
			'a negative tie'                => array( -250, '5', -13, -12 ),
			'a zero rate'                   => array( 999, '0', 0, 0 ),
		);
	}

	/**
	 * Tests taking the tax out of an authored gross amount, in every exponent.
	 *
	 * @since 0.1.0
	 *
	 * @group international
	 *
	 * @dataProvider data_gross_amounts
	 *
	 * @param string $currency    The currency.
	 * @param int    $gross       The gross amount in minor units.
	 * @param string $percent     The rate in percent.
	 * @param int    $half_up     The tax rounded half up.
	 * @param int    $toward_zero The tax rounded toward zero.
	 */
	public function test_from_gross_computes_tax_once_and_derives_net( string $currency, int $gross, string $percent, int $half_up, int $toward_zero ): void {
		$code = Currency::of( $currency );

		foreach ( array( array( $half_up, RoundingMode::HalfUp ), array( $toward_zero, RoundingMode::TowardZero ) ) as list( $tax, $mode ) ) {
			$taxed = TaxedMoney::fromGross( Money::of( $gross, $code ), Percentage::fromString( $percent ), $mode );

			$this->assertTaxedMoneyEquals( new TaxedMoney( Money::of( $gross - $tax, $code ), Money::of( $tax, $code ), Money::of( $gross, $code ) ), $taxed, $mode->value );
		}
	}

	/**
	 * Provides gross amounts with their tax in both modes.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{string, int, string, int, int}> Test cases.
	 */
	public static function data_gross_amounts(): array {
		return array(
			'UK VAT on 12.00'       => array( 'GBP', 1200, '20', 200, 200 ),
			'UK VAT on 9.99, a tie' => array( 'GBP', 999, '20', 167, 166 ),
			'the same, negative'    => array( 'GBP', -999, '20', -167, -166 ),
			'German VAT on 1.00'    => array( 'EUR', 100, '19', 16, 15 ),
			'yen, no minor unit'    => array( 'JPY', 1000, '10', 91, 90 ),
			'dinar, three places'   => array( 'KWD', 1000, '5', 48, 47 ),
			'a zero rate'           => array( 'EUR', 999, '0', 0, 0 ),
		);
	}

	/**
	 * Tests that a Decimal factor and the same Percentage give the same result.
	 *
	 * @since 0.1.0
	 *
	 * @group international
	 */
	public function test_a_decimal_factor_is_the_same_rate_as_its_percentage(): void {
		$gross = Money::of( 999, Currency::of( 'GBP' ) );

		$this->assertTaxedMoneyEquals(
			TaxedMoney::fromGross( $gross, Percentage::fromString( '20' ), RoundingMode::HalfUp ),
			TaxedMoney::fromGross( $gross, Decimal::of( '0.2' ), RoundingMode::HalfUp )
		);
		$this->assertTaxedMoneyEquals(
			TaxedMoney::fromNet( $gross, Percentage::fromString( '7.25' ), RoundingMode::HalfUp ),
			TaxedMoney::fromNet( $gross, Decimal::of( '0.0725' ), RoundingMode::HalfUp )
		);
	}

	/**
	 * Tests that a negative rate is refused in both entry modes.
	 *
	 * @since 0.1.0
	 */
	public function test_a_negative_rate_is_refused(): void {
		$amount  = Money::of( 1000, Currency::of( 'USD' ) );
		$refused = 0;

		foreach ( array( 'fromNet', 'fromGross' ) as $factory ) {
			try {
				TaxedMoney::$factory( $amount, Percentage::fromString( '-5' ), RoundingMode::HalfUp );
			} catch ( \InvalidArgumentException $negative ) {
				++$refused;
			}
		}

		$this->assertSame( 2, $refused );
	}

	/**
	 * Tests that adding, subtracting and negating work figure by figure and keep the invariant.
	 *
	 * @since 0.1.0
	 */
	public function test_add_subtract_and_negate_keep_the_invariant(): void {
		$usd = Currency::of( 'USD' );
		$a   = TaxedMoney::fromNet( Money::of( 999, $usd ), Percentage::fromString( '7.25' ), RoundingMode::HalfUp );
		$b   = TaxedMoney::fromNet( Money::of( 250, $usd ), Percentage::fromString( '5' ), RoundingMode::HalfUp );

		$this->assertTaxedMoneyEquals( new TaxedMoney( Money::of( 1249, $usd ), Money::of( 85, $usd ), Money::of( 1334, $usd ) ), $a->add( $b ) );
		$this->assertTaxedMoneyEquals( new TaxedMoney( Money::of( 749, $usd ), Money::of( 59, $usd ), Money::of( 808, $usd ) ), $a->subtract( $b ) );
		$this->assertTaxedMoneyEquals( new TaxedMoney( Money::of( -999, $usd ), Money::of( -72, $usd ), Money::of( -1071, $usd ) ), $a->negate() );
		$this->assertTrue( $a->add( $b )->subtract( $b )->equals( $a ) );
	}

	/**
	 * Tests that a taxed amount in another currency cannot be added.
	 *
	 * @since 0.1.0
	 */
	public function test_adding_another_currency_is_refused(): void {
		$this->expectException( CurrencyMismatchException::class );

		TaxedMoney::zero( Currency::of( 'USD' ) )->add( TaxedMoney::zero( Currency::of( 'EUR' ) ) );
	}

	/**
	 * Tests allocation on a worked example: net and tax allocated, gross derived per share.
	 *
	 * @since 0.1.0
	 */
	public function test_allocate_keeps_the_invariant_per_share_and_in_total(): void {
		$usd    = Currency::of( 'USD' );
		$shares = ( new TaxedMoney( Money::of( 100, $usd ), Money::of( 20, $usd ), Money::of( 120, $usd ) ) )->allocate( array( 1, 1, 1 ) );

		$this->assertCount( 3, $shares );
		$this->assertTaxedMoneyEquals( new TaxedMoney( Money::of( 34, $usd ), Money::of( 7, $usd ), Money::of( 41, $usd ) ), $shares[0] );
		$this->assertTaxedMoneyEquals( new TaxedMoney( Money::of( 33, $usd ), Money::of( 7, $usd ), Money::of( 40, $usd ) ), $shares[1] );
		$this->assertTaxedMoneyEquals( new TaxedMoney( Money::of( 33, $usd ), Money::of( 6, $usd ), Money::of( 39, $usd ) ), $shares[2] );
	}

	/**
	 * Tests both entry modes on seeded random net or gross amounts and rates, against integer arithmetic.
	 *
	 * @since 0.1.0
	 *
	 * @group international
	 */
	public function test_property_both_entry_modes_round_the_tax_once_and_keep_the_invariant(): void {
		SeededCases::check(
			20261001,
			500,
			static fn( Randomizer $random ): array => array(
				$random->getInt( -1000000000, 1000000000 ),
				$random->getInt( 0, 100000000 ),
				RoundingMode::cases()[ $random->getInt( 0, count( RoundingMode::cases() ) - 1 ) ],
				array( 'USD', 'JPY', 'KWD', 'CLF', 'EUR', 'GBP' )[ $random->getInt( 0, 5 ) ],
			),
			function ( int $amount, int $micropercent, RoundingMode $mode, string $code ): void {
				$currency = Currency::of( $code );
				$rate     = Percentage::fromMicropercent( $micropercent );

				$from_net   = TaxedMoney::fromNet( Money::of( $amount, $currency ), $rate, $mode );
				$from_gross = TaxedMoney::fromGross( Money::of( $amount, $currency ), $rate, $mode );

				$this->assertSame( $amount, $from_net->net()->minorUnits(), 'fromNet keeps the authored net amount.' );
				$this->assertSame( self::roundedTax( $amount, $micropercent, 100000000, $mode ), $from_net->tax()->minorUnits(), 'fromNet tax.' );
				$this->assertSame( $from_net->net()->minorUnits() + $from_net->tax()->minorUnits(), $from_net->gross()->minorUnits() );

				$this->assertSame( $amount, $from_gross->gross()->minorUnits(), 'fromGross keeps the authored gross amount.' );
				$this->assertSame( self::roundedTax( $amount, $micropercent, 100000000 + $micropercent, $mode ), $from_gross->tax()->minorUnits(), 'fromGross tax.' );
				$this->assertSame( $from_gross->net()->minorUnits() + $from_gross->tax()->minorUnits(), $from_gross->gross()->minorUnits() );

				$this->assertTrue( TaxedMoney::fromNet( Money::of( -$amount, $currency ), $rate, $mode )->equals( $from_net->negate() ), 'fromNet is symmetric.' );
				$this->assertTrue( TaxedMoney::fromGross( Money::of( -$amount, $currency ), $rate, $mode )->equals( $from_gross->negate() ), 'fromGross is symmetric.' );
			}
		);
	}

	/**
	 * Tests allocation on seeded random taxed amounts and ratios.
	 *
	 * Every share keeps the invariant; each figure sums to its total; net and tax are within one
	 * minor unit of their exact proportions and gross within two.
	 *
	 * @since 0.1.0
	 */
	public function test_property_allocation_keeps_the_invariant_per_share_and_in_total(): void {
		SeededCases::check(
			20261002,
			300,
			static function ( Randomizer $random ): array {
				$ratios = array();

				for ( $share = $random->getInt( 1, 8 ); $share > 0; $share-- ) {
					$ratios[] = $random->getInt( 0, 50 );
				}

				$ratios[] = $random->getInt( 1, 50 );

				return array( $random->getInt( -1000000000, 1000000000 ), $random->getInt( 0, 30000000 ), $ratios );
			},
			function ( int $net, int $micropercent, array $ratios ): void {
				$usd    = Currency::of( 'USD' );
				$taxed  = TaxedMoney::fromNet( Money::of( $net, $usd ), Percentage::fromMicropercent( $micropercent ), RoundingMode::HalfUp );
				$shares = $taxed->allocate( $ratios );
				$sum    = array_sum( $ratios );
				$totals = array(
					'net'   => 0,
					'tax'   => 0,
					'gross' => 0,
				);

				foreach ( $shares as $index => $share ) {
					$this->assertSame( $share->net()->minorUnits() + $share->tax()->minorUnits(), $share->gross()->minorUnits(), 'Share ' . $index . ' adds up.' );

					foreach ( array(
						'net'   => 1,
						'tax'   => 1,
						'gross' => 2,
					) as $figure => $tolerance ) {
						$part  = $share->$figure()->minorUnits();
						$whole = $taxed->$figure()->minorUnits();

						// |part - whole × ratio ÷ sum| < tolerance, multiplied through by sum.
						$this->assertLessThan( $tolerance * $sum, abs( $part * $sum - $whole * $ratios[ $index ] ), $figure . ' of share ' . $index );

						$totals[ $figure ] += $part;
					}
				}

				$this->assertSame(
					array(
						'net'   => $taxed->net()->minorUnits(),
						'tax'   => $taxed->tax()->minorUnits(),
						'gross' => $taxed->gross()->minorUnits(),
					),
					$totals
				);
			}
		);
	}

	/**
	 * Rounds amount × numerator ÷ denominator once, on its magnitude, by a mode.
	 *
	 * @since 0.1.0
	 *
	 * @param int          $amount      The amount in minor units, at most 10^9 in magnitude.
	 * @param int          $numerator   The rate's numerator, at most 10^8.
	 * @param int          $denominator The rate's denominator.
	 * @param RoundingMode $mode        The rounding mode.
	 * @return int The rounded tax in minor units.
	 */
	private static function roundedTax( int $amount, int $numerator, int $denominator, RoundingMode $mode ): int {
		$product   = abs( $amount ) * $numerator;
		$quotient  = intdiv( $product, $denominator );
		$remainder = $product % $denominator;

		if ( RoundingMode::HalfUp === $mode && 2 * $remainder >= $denominator ) {
			++$quotient;
		}

		return $amount < 0 ? -$quotient : $quotient;
	}
}
