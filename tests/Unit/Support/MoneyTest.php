<?php
/**
 * Tests Money: minor units, one currency, checked arithmetic, declared rounding
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use SEOCart\Support\ArithmeticOverflowException;
use SEOCart\Support\Currency;
use SEOCart\Support\CurrencyMismatchException;
use SEOCart\Support\CurrencyRoundingRule;
use SEOCart\Support\Decimal;
use SEOCart\Support\Money;
use SEOCart\Support\Percentage;
use SEOCart\Support\RoundingMode;
use SEOCart\Tests\Support\MoneyAssertions;

/**
 * Proves Money's arithmetic, its currency rule, its overflow checks and its rounding boundaries.
 *
 * @since 0.1.0
 */
final class MoneyTest extends TestCase {

	use MoneyAssertions;

	/**
	 * Tests construction and the two things an amount is made of.
	 *
	 * @since 0.1.0
	 */
	public function test_an_amount_is_minor_units_and_a_currency(): void {
		$amount = Money::of( 1234, Currency::of( 'USD' ) );

		$this->assertSame( 1234, $amount->minorUnits() );
		$this->assertSame( 'USD', $amount->currency()->code() );
		$this->assertMoneyEquals( Money::of( 0, Currency::of( 'EUR' ) ), Money::zero( Currency::of( 'EUR' ) ) );
		$this->assertTrue( Money::zero( Currency::of( 'EUR' ) )->isZero() );
		$this->assertTrue( Money::of( -1, Currency::of( 'EUR' ) )->isNegative() );
		$this->assertFalse( Money::of( 0, Currency::of( 'EUR' ) )->isNegative() );
	}

	/**
	 * Tests addition, subtraction and negation within one currency.
	 *
	 * @since 0.1.0
	 */
	public function test_add_subtract_and_negate_within_one_currency(): void {
		$usd = Currency::of( 'USD' );

		$this->assertMoneyEquals( Money::of( 1500, $usd ), Money::of( 1000, $usd )->add( Money::of( 500, $usd ) ) );
		$this->assertMoneyEquals( Money::of( -250, $usd ), Money::of( 250, $usd )->subtract( Money::of( 500, $usd ) ) );
		$this->assertMoneyEquals( Money::of( -250, $usd ), Money::of( 250, $usd )->negate() );
		$this->assertMoneyEquals( Money::of( 0, $usd ), Money::of( 0, $usd )->negate() );
	}

	/**
	 * Tests that amounts in different currencies are never combined or compared.
	 *
	 * @since 0.1.0
	 */
	public function test_combining_two_currencies_is_a_programming_error(): void {
		$dollars = Money::of( 100, Currency::of( 'USD' ) );
		$euros   = Money::of( 100, Currency::of( 'EUR' ) );
		$refused = 0;

		foreach ( array( 'add', 'subtract', 'compare' ) as $operation ) {
			try {
				$dollars->$operation( $euros );
			} catch ( CurrencyMismatchException $mismatch ) {
				$this->assertInstanceOf( \LogicException::class, $mismatch );
				$this->assertSame( 'An amount in USD cannot be combined with an amount in EUR.', $mismatch->getMessage() );
				++$refused;
			}
		}

		$this->assertSame( 3, $refused );
		$this->assertFalse( $dollars->equals( $euros ), 'Equality across currencies is simply false.' );
	}

	/**
	 * Tests that every integer operation refuses to overflow into a float, at both ends.
	 *
	 * @since 0.1.0
	 *
	 * @dataProvider data_overflows
	 *
	 * @param callable $operation An operation whose result does not fit in 64 bits.
	 * @param string   $name      The operation's name, as the exception names it.
	 */
	public function test_overflow_throws_instead_of_becoming_a_float( callable $operation, string $name ): void {
		$this->expectException( ArithmeticOverflowException::class );
		$this->expectExceptionMessage( $name );

		$operation( Currency::of( 'USD' ) );
	}

	/**
	 * Provides operations that overflow.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{callable, string}> Test cases.
	 */
	public static function data_overflows(): array {
		return array(
			'add past the maximum'           => array( static fn( Currency $c ) => Money::of( PHP_INT_MAX, $c )->add( Money::of( 1, $c ) ), 'Money::add()' ),
			'add past the minimum'           => array( static fn( Currency $c ) => Money::of( PHP_INT_MIN, $c )->add( Money::of( -1, $c ) ), 'Money::add()' ),
			'subtract past the minimum'      => array( static fn( Currency $c ) => Money::of( PHP_INT_MIN, $c )->subtract( Money::of( 1, $c ) ), 'Money::subtract()' ),
			'subtract past the maximum'      => array( static fn( Currency $c ) => Money::of( PHP_INT_MAX, $c )->subtract( Money::of( -1, $c ) ), 'Money::subtract()' ),
			'negate the minimum'             => array( static fn( Currency $c ) => Money::of( PHP_INT_MIN, $c )->negate(), 'Money::negate()' ),
			'round a decimal too large'      => array( static fn( Currency $c ) => Money::ofDecimal( Decimal::of( '92233720368547758.08' ), $c, RoundingMode::HalfUp ), 'Decimal::toUnscaledInt()' ),
			'cash rounding past the maximum' => array(
				static fn( Currency $c ) => Money::of( PHP_INT_MAX, $c )->roundToCashStep( new CurrencyRoundingRule( $c, RoundingMode::HalfUp, 10 ) ),
				'Decimal::toUnscaledInt()',
			),
		);
	}

	/**
	 * Tests that the largest and smallest results that do fit are still computed.
	 *
	 * @since 0.1.0
	 */
	public function test_results_at_the_edge_of_the_range_are_exact(): void {
		$usd = Currency::of( 'USD' );

		$this->assertSame( PHP_INT_MAX, Money::of( PHP_INT_MAX - 1, $usd )->add( Money::of( 1, $usd ) )->minorUnits() );
		$this->assertSame( PHP_INT_MIN, Money::of( PHP_INT_MIN + 1, $usd )->subtract( Money::of( 1, $usd ) )->minorUnits() );
		$this->assertSame( -1, Money::of( PHP_INT_MIN, $usd )->add( Money::of( PHP_INT_MAX, $usd ) )->minorUnits() );
		$this->assertSame( -PHP_INT_MAX, Money::of( PHP_INT_MAX, $usd )->negate()->minorUnits() );
	}

	/**
	 * Tests that multiplying by a rate yields an exact Decimal, not Money.
	 *
	 * @since 0.1.0
	 */
	public function test_multiplying_by_a_rate_gives_an_exact_decimal(): void {
		$price = Money::of( 1234, Currency::of( 'USD' ) );

		$this->assertSame( '0.92550', $price->multiply( Decimal::of( '0.075' ) )->toString() );
		$this->assertSame( '2.4680000000', $price->multiply( Percentage::fromString( '20' ) )->toString() );
		$this->assertSame( '-2.4680000000', $price->negate()->multiply( Percentage::fromString( '20' ) )->toString() );
	}

	/**
	 * Tests that an amount in major units is exact at the currency's exponent.
	 *
	 * @since 0.1.0
	 *
	 * @group international
	 */
	public function test_to_decimal_uses_the_currency_exponent(): void {
		$this->assertSame( '12.34', Money::of( 1234, Currency::of( 'USD' ) )->toDecimal()->toString() );
		$this->assertSame( '1234', Money::of( 1234, Currency::of( 'JPY' ) )->toDecimal()->toString() );
		$this->assertSame( '1.234', Money::of( 1234, Currency::of( 'KWD' ) )->toDecimal()->toString() );
		$this->assertSame( '0.1234', Money::of( 1234, Currency::of( 'CLF' ) )->toDecimal()->toString() );
		$this->assertSame( '-0.05', Money::of( -5, Currency::of( 'USD' ) )->toDecimal()->toString() );
	}

	/**
	 * Tests rounding a Decimal to minor units at the declared boundary, in every exponent.
	 *
	 * @since 0.1.0
	 *
	 * @group international
	 *
	 * @dataProvider data_decimals_to_round
	 *
	 * @param string $amount      The decimal amount in major units.
	 * @param string $currency    The currency.
	 * @param int    $half_up     The minor units rounded half up.
	 * @param int    $toward_zero The minor units rounded toward zero.
	 */
	public function test_of_decimal_rounds_once_by_the_named_mode( string $amount, string $currency, int $half_up, int $toward_zero ): void {
		$this->assertSame( $half_up, Money::ofDecimal( Decimal::of( $amount ), Currency::of( $currency ), RoundingMode::HalfUp )->minorUnits() );
		$this->assertSame( $toward_zero, Money::ofDecimal( Decimal::of( $amount ), Currency::of( $currency ), RoundingMode::TowardZero )->minorUnits() );
	}

	/**
	 * Provides decimal amounts and their rounded minor units.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{string, string, int, int}> Test cases.
	 */
	public static function data_decimals_to_round(): array {
		return array(
			'dollars, a tie'           => array( '0.925', 'USD', 93, 92 ),
			'dollars, a negative tie'  => array( '-0.925', 'USD', -93, -92 ),
			'dollars, exact'           => array( '12.34', 'USD', 1234, 1234 ),
			'dollars, fewer digits'    => array( '12.3', 'USD', 1230, 1230 ),
			'yen, a tie'               => array( '1234.5', 'JPY', 1235, 1234 ),
			'yen, below a tie'         => array( '1234.4999', 'JPY', 1234, 1234 ),
			'dinar, a tie'             => array( '1.2345', 'KWD', 1235, 1234 ),
			'dinar, a negative tie'    => array( '-1.2345', 'KWD', -1235, -1234 ),
			'Unidad de Fomento, exact' => array( '37000.1234', 'CLF', 370001234, 370001234 ),
			'Unidad de Fomento, a tie' => array( '0.00005', 'CLF', 1, 0 ),
		);
	}

	/**
	 * Tests comparison and equality within one currency.
	 *
	 * @since 0.1.0
	 */
	public function test_compare_and_equals(): void {
		$usd = Currency::of( 'USD' );

		$this->assertSame( -1, Money::of( 99, $usd )->compare( Money::of( 100, $usd ) ) );
		$this->assertSame( 0, Money::of( 100, $usd )->compare( Money::of( 100, $usd ) ) );
		$this->assertSame( 1, Money::of( 100, $usd )->compare( Money::of( -100, $usd ) ) );
		$this->assertTrue( Money::of( 100, $usd )->equals( Money::of( 100, Currency::of( 'USD' ) ) ) );
		$this->assertFalse( Money::of( 100, $usd )->equals( Money::of( 101, $usd ) ) );
	}

	/**
	 * Tests cash rounding to a step, separate from the ISO exponent.
	 *
	 * @since 0.1.0
	 *
	 * @group international
	 *
	 * @dataProvider data_cash_roundings
	 *
	 * @param int $minor_units The amount in minor units.
	 * @param int $step        The cash rounding step in minor units.
	 * @param int $expected    The rounded amount in minor units.
	 */
	public function test_cash_rounding_rounds_to_the_step_by_the_rule_mode( int $minor_units, int $step, int $expected ): void {
		$chf  = Currency::of( 'CHF' );
		$rule = new CurrencyRoundingRule( $chf, RoundingMode::HalfUp, $step );

		$this->assertMoneyEquals( Money::of( $expected, $chf ), Money::of( $minor_units, $chf )->roundToCashStep( $rule ) );
	}

	/**
	 * Provides amounts, steps and their cash-rounded results.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{int, int, int}> Test cases.
	 */
	public static function data_cash_roundings(): array {
		return array(
			'1.02 to 0.05 goes down'   => array( 102, 5, 100 ),
			'1.03 to 0.05 goes up'     => array( 103, 5, 105 ),
			'1.07 to 0.05 goes down'   => array( 107, 5, 105 ),
			'1.08 to 0.05 goes up'     => array( 108, 5, 110 ),
			'negative, symmetric'      => array( -103, 5, -105 ),
			'a tie at 0.10 goes up'    => array( 105, 10, 110 ),
			'a negative tie goes down' => array( -105, 10, -110 ),
			'step 0 changes nothing'   => array( 103, 0, 103 ),
			'step 1 changes nothing'   => array( 103, 1, 103 ),
			'already on a step'        => array( 250, 5, 250 ),
		);
	}

	/**
	 * Tests that cash rounding follows the rule's mode, not a fixed one.
	 *
	 * @since 0.1.0
	 */
	public function test_cash_rounding_uses_the_rule_mode(): void {
		$chf = Currency::of( 'CHF' );

		$this->assertSame( 100, Money::of( 104, $chf )->roundToCashStep( new CurrencyRoundingRule( $chf, RoundingMode::TowardZero, 5 ) )->minorUnits() );
	}

	/**
	 * Tests that a rule for another currency is refused.
	 *
	 * @since 0.1.0
	 */
	public function test_cash_rounding_refuses_another_currency_rule(): void {
		$this->expectException( CurrencyMismatchException::class );

		Money::of( 103, Currency::of( 'CHF' ) )->roundToCashStep( CurrencyRoundingRule::defaultFor( Currency::of( 'EUR' ) ) );
	}

	/**
	 * Tests the helper that the Support tests compare amounts with.
	 *
	 * @since 0.1.0
	 */
	public function test_assert_money_equals_compares_minor_units_and_currency(): void {
		$this->assertMoneyEquals( Money::of( 5, Currency::of( 'USD' ) ), Money::of( 5, Currency::of( 'USD' ) ) );

		foreach ( array( Money::of( 6, Currency::of( 'USD' ) ), Money::of( 5, Currency::of( 'CAD' ) ) ) as $different ) {
			try {
				$this->assertMoneyEquals( Money::of( 5, Currency::of( 'USD' ) ), $different );
				$this->fail( 'assertMoneyEquals() accepted ' . self::describeMoney( $different ) . ' for USD 5.' );
			} catch ( \PHPUnit\Framework\ExpectationFailedException $failure ) {
				$this->assertStringContainsString( self::describeMoney( $different ), $failure->getComparisonFailure() ? $failure->getComparisonFailure()->getActualAsString() : $failure->getMessage() );
			}
		}
	}
}
