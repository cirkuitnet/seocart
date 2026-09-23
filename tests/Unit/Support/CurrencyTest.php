<?php
/**
 * Tests Currency and its ISO 4217 data
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use SEOCart\Support\Currency;
use SEOCart\Support\CurrencyRoundingRule;
use SEOCart\Support\Error\CodedException;
use SEOCart\Support\RoundingMode;
use SEOCart\Support\SupportError;

/**
 * Proves the exponents shipped in code and the refusal of anything that is not an active code.
 *
 * The expected exponent sets are ISO 4217 List One as SIX published it on 2026-09-17. Every
 * currency whose exponent is not 2 is listed here, so a mistyped exponent in the table cannot
 * pass.
 *
 * @since 0.1.0
 */
final class CurrencyTest extends TestCase {

	/**
	 * Tests the four exponents that occur, one currency each.
	 *
	 * @since 0.1.0
	 *
	 * @group international
	 *
	 * @dataProvider data_one_currency_per_exponent
	 *
	 * @param string $code     The ISO 4217 code.
	 * @param int    $exponent The expected exponent.
	 */
	public function test_the_exponent_comes_from_the_iso_data( string $code, int $exponent ): void {
		$currency = Currency::of( $code );

		$this->assertSame( $code, $currency->code() );
		$this->assertSame( $exponent, $currency->exponent() );
	}

	/**
	 * Provides one currency for each exponent that occurs.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{string, int}> Test cases.
	 */
	public static function data_one_currency_per_exponent(): array {
		return array(
			'zero: yen'               => array( 'JPY', 0 ),
			'two: dollar'             => array( 'USD', 2 ),
			'three: Kuwaiti dinar'    => array( 'KWD', 3 ),
			'four: Unidad de Fomento' => array( 'CLF', 4 ),
		);
	}

	/**
	 * Tests the shipped table against the published list, exponent by exponent.
	 *
	 * @since 0.1.0
	 *
	 * @group international
	 */
	public function test_the_table_is_iso_4217_list_one_of_2026_09_17(): void {
		$table = self::table();
		$codes = array_keys( $table );
		$by    = array();

		foreach ( $table as $code => $exponent ) {
			$this->assertMatchesRegularExpression( '/^[A-Z]{3}$/', $code );
			$by[ $exponent ][] = $code;
		}

		$sorted = $codes;
		sort( $sorted, SORT_STRING );

		$this->assertSame( $sorted, $codes, 'The table is in code order, so a duplicate or a misplaced row is easy to see.' );
		$this->assertCount( 165, $table, 'List One has 165 active codes with a numeric minor unit.' );
		$this->assertSame( array( 0, 2, 3, 4 ), self::sortedKeys( $by ), 'Exponents 0, 2, 3 and 4 occur, and no other.' );
		$this->assertSame( array( 'BIF', 'CLP', 'DJF', 'GNF', 'ISK', 'JPY', 'KMF', 'KRW', 'PYG', 'RWF', 'UGX', 'UYI', 'VND', 'VUV', 'XAF', 'XOF', 'XPF' ), $by[0] );
		$this->assertSame( array( 'BHD', 'IQD', 'JOD', 'KWD', 'LYD', 'OMR', 'TND' ), $by[3] );
		$this->assertSame( array( 'CLF', 'UYW' ), $by[4] );
		$this->assertCount( 139, $by[2] );
	}

	/**
	 * Tests that a code that is not an active ISO 4217 code with a numeric minor unit is refused
	 * with the typed error from the error table.
	 *
	 * @since 0.1.0
	 *
	 * @group international
	 *
	 * @dataProvider data_codes_that_are_refused
	 *
	 * @param string $code The code.
	 */
	public function test_an_unknown_or_malformed_code_is_refused_with_its_error_code( string $code ): void {
		try {
			Currency::of( $code );
			$this->fail( 'The code was accepted: ' . $code );
		} catch ( CodedException $refused ) {
			$this->assertSame( SupportError::UnknownCurrency, $refused->errorCode() );
			$this->assertSame( array( 'currency' => $code ), $refused->context() );
			$this->assertSame( 'currency.unknown', $refused->getMessage() );
		}
	}

	/**
	 * Provides codes that must be refused.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{string}> Test cases.
	 */
	public static function data_codes_that_are_refused(): array {
		return array(
			'not a code'                      => array( 'XYZ' ),
			'lower case'                      => array( 'usd' ),
			'two letters'                     => array( 'US' ),
			'four letters'                    => array( 'USDD' ),
			'empty'                           => array( '' ),
			'leading space'                   => array( ' USD' ),
			'trailing line feed'              => array( "USD\n" ),
			'gold has no minor unit'          => array( 'XAU' ),
			'no currency has no minor unit'   => array( 'XXX' ),
			'withdrawn: Netherlands Antilles' => array( 'ANG' ),
			'withdrawn: Croatian kuna'        => array( 'HRK' ),
			'withdrawn: Sierra Leone, old'    => array( 'SLL' ),
		);
	}

	/**
	 * Tests currency equality.
	 *
	 * @since 0.1.0
	 */
	public function test_equality_is_by_code(): void {
		$this->assertTrue( Currency::of( 'EUR' )->equals( Currency::of( 'EUR' ) ) );
		$this->assertFalse( Currency::of( 'EUR' )->equals( Currency::of( 'GBP' ) ) );
	}

	/**
	 * Tests the rounding rule's defaults and the names its modes are stored under.
	 *
	 * @since 0.1.0
	 */
	public function test_the_rounding_rule_matches_the_currencies_columns(): void {
		$rule = CurrencyRoundingRule::defaultFor( Currency::of( 'CHF' ) );

		$this->assertSame( 'CHF', $rule->currency()->code() );
		$this->assertSame( RoundingMode::HalfUp, $rule->roundingMode(), 'currencies.rounding_mode defaults to half_up.' );
		$this->assertSame( 0, $rule->cashRoundingStepMinor(), 'currencies.cash_rounding_step_minor defaults to 0.' );
		$this->assertSame( RoundingMode::HalfUp, RoundingMode::from( 'half_up' ) );
		$this->assertSame( RoundingMode::TowardZero, RoundingMode::from( 'toward_zero' ) );
		$this->assertSame( 5, ( new CurrencyRoundingRule( Currency::of( 'CHF' ), RoundingMode::HalfUp, 5 ) )->cashRoundingStepMinor() );
	}

	/**
	 * Tests that a negative cash rounding step is refused, as the unsigned column would.
	 *
	 * @since 0.1.0
	 */
	public function test_a_negative_cash_rounding_step_is_refused(): void {
		$this->expectException( \InvalidArgumentException::class );

		new CurrencyRoundingRule( Currency::of( 'CHF' ), RoundingMode::HalfUp, -5 );
	}

	/**
	 * Reads the shipped table.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, int> The exponents, keyed by code, in table order.
	 */
	private static function table(): array {
		$table = ( new \ReflectionClassConstant( Currency::class, 'EXPONENTS' ) )->getValue();

		self::assertIsArray( $table );

		return $table;
	}

	/**
	 * Returns an array's keys, sorted.
	 *
	 * @since 0.1.0
	 *
	 * @param array<int, mixed> $values The array.
	 * @return list<int> The sorted keys.
	 */
	private static function sortedKeys( array $values ): array {
		$keys = array_keys( $values );
		sort( $keys );

		return $keys;
	}
}
