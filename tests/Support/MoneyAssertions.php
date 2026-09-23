<?php
/**
 * MoneyAssertions: compares money as minor units and a currency code, never as a float
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support;

use PHPUnit\Framework\Assert;
use SEOCart\Support\Money;
use SEOCart\Support\TaxedMoney;

/**
 * Money assertions for test cases.
 *
 * Two amounts are equal when they have the same ISO 4217 code and the same integer number of
 * minor units (test strategy §9). A failure shows both as "USD 1234", so the diff names the
 * currency and the exact minor units.
 *
 * @since 0.1.0
 */
trait MoneyAssertions {

	/**
	 * Asserts that two amounts have the same currency and the same number of minor units.
	 *
	 * @since 0.1.0
	 *
	 * @param Money  $expected The expected amount.
	 * @param Money  $actual   The actual amount.
	 * @param string $message  Optional. A message to show on failure. Default empty.
	 */
	public static function assertMoneyEquals( Money $expected, Money $actual, string $message = '' ): void {
		Assert::assertSame( self::describeMoney( $expected ), self::describeMoney( $actual ), $message );
	}

	/**
	 * Asserts that two taxed amounts have the same net, tax and gross, figure by figure.
	 *
	 * @since 0.1.0
	 *
	 * @param TaxedMoney $expected The expected taxed amount.
	 * @param TaxedMoney $actual   The actual taxed amount.
	 * @param string     $message  Optional. A message to show on failure. Default empty.
	 */
	public static function assertTaxedMoneyEquals( TaxedMoney $expected, TaxedMoney $actual, string $message = '' ): void {
		Assert::assertSame( self::describeTaxedMoney( $expected ), self::describeTaxedMoney( $actual ), $message );
	}

	/**
	 * Writes an amount as its currency code and minor units.
	 *
	 * @since 0.1.0
	 *
	 * @param Money $money The amount.
	 * @return string For example "USD 1234".
	 */
	public static function describeMoney( Money $money ): string {
		return $money->currency()->code() . ' ' . $money->minorUnits();
	}

	/**
	 * Writes a taxed amount as its three figures.
	 *
	 * @since 0.1.0
	 *
	 * @param TaxedMoney $taxed The taxed amount.
	 * @return string For example "net USD 1000, tax USD 200, gross USD 1200".
	 */
	public static function describeTaxedMoney( TaxedMoney $taxed ): string {
		return sprintf(
			'net %s, tax %s, gross %s',
			self::describeMoney( $taxed->net() ),
			self::describeMoney( $taxed->tax() ),
			self::describeMoney( $taxed->gross() )
		);
	}
}
