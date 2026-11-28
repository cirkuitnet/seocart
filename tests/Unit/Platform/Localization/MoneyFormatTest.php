<?php
/**
 * Tests how an amount is written for a shopper, with PHP's intl extension and without it
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Platform\Localization;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use SEOCart\Platform\Localization\MoneyFormat;
use SEOCart\Support\Currency;
use SEOCart\Support\Money;

/**
 * With `intl`, an amount is written in the locale's currency style with exactly the currency's
 * minor units; without it, in WordPress's number format at those minor units with the ISO code;
 * and an amount too long for a double is written as its decimal, both ways.
 *
 * The texts with `intl` are ICU's, byte for byte: the spaces in the German and French texts are
 * the no-break spaces ICU writes. WordPress's number format is stood in for by PHP's, given the
 * separators of a locale, since the unit suite loads no WordPress.
 *
 * @since 0.2.0
 */
final class MoneyFormatTest extends TestCase {

	/**
	 * Sets Brain Monkey up.
	 *
	 * @since 0.2.0
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	/**
	 * Tears Brain Monkey down.
	 *
	 * @since 0.2.0
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Returns amounts and locales with the text intl writes for each.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, array{0: int, 1: string, 2: string, 3: string}> The minor units, the currency, the locale and the text.
	 */
	public static function intlTexts(): array {
		return array(
			'US dollars in American English' => array( 1800, 'USD', 'en_US', '$18.00' ),
			'a large amount, grouped'        => array( 123456789, 'USD', 'en_US', '$1,234,567.89' ),
			'a negative amount under one'    => array( -5, 'USD', 'en_US', '-$0.05' ),
			'nothing'                        => array( 0, 'USD', 'en_US', '$0.00' ),
			'pounds in British English'      => array( 1800, 'GBP', 'en_GB', '£18.00' ),
			'euros in German'                => array( 123456, 'EUR', 'de_DE', "1.234,56\u{00A0}€" ),
			'euros in French'                => array( 123456, 'EUR', 'fr_FR', "1\u{202F}234,56\u{00A0}€" ),
			'yen, which has no minor unit'   => array( 1800, 'JPY', 'ja', '￥1,800' ),
			'dinars, three minor digits'     => array( 1234, 'BHD', 'en_US', "BHD\u{00A0}1.234" ),
		);
	}

	/**
	 * Tests the text of an amount with intl.
	 *
	 * @since 0.2.0
	 *
	 * @requires extension intl
	 *
	 * @dataProvider intlTexts
	 *
	 * @param int    $minor    The minor units.
	 * @param string $currency The currency's code.
	 * @param string $locale   The locale.
	 * @param string $text     The text.
	 */
	public function test_with_intl_an_amount_is_written_in_the_locale_s_currency_style( int $minor, string $currency, string $locale, string $text ): void {
		$this->assertSame( $text, MoneyFormat::text( Money::of( $minor, Currency::of( $currency ) ), $locale ) );
	}

	/**
	 * Returns amounts with the text written without intl, in English and in German separators.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, array{0: int, 1: string, 2: string, 3: string, 4: string}> The minor units, the currency, the decimal point, the thousands separator and the text.
	 */
	public static function plainTexts(): array {
		return array(
			'US dollars'                   => array( 1800, 'USD', '.', ',', '18.00 USD' ),
			'a large amount, grouped'      => array( 123456789, 'USD', '.', ',', '1,234,567.89 USD' ),
			'euros, German separators'     => array( 123456, 'EUR', ',', '.', '1.234,56 EUR' ),
			'yen, which has no minor unit' => array( 1800, 'JPY', '.', ',', '1,800 JPY' ),
			'dinars, three minor digits'   => array( 1234, 'BHD', '.', ',', '1.234 BHD' ),
			'a negative amount under one'  => array( -5, 'USD', '.', ',', '-0.05 USD' ),
		);
	}

	/**
	 * Tests the text of an amount without intl.
	 *
	 * @since 0.2.0
	 *
	 * @dataProvider plainTexts
	 *
	 * @param int    $minor     The minor units.
	 * @param string $currency  The currency's code.
	 * @param string $point     The locale's decimal point.
	 * @param string $thousands The locale's thousands separator.
	 * @param string $text      The text.
	 */
	public function test_without_intl_an_amount_is_written_with_its_minor_units_and_code( int $minor, string $currency, string $point, string $thousands, string $text ): void {
		Functions\when( 'number_format_i18n' )->alias( static fn( float $number, int $decimals ): string => number_format( $number, $decimals, $point, $thousands ) );

		$this->assertSame( $text, MoneyFormat::plain( Money::of( $minor, Currency::of( $currency ) ) ) );
	}

	/**
	 * Tests that an amount of more digits than a double holds exactly is written as its decimal, never rounded through a float.
	 *
	 * @since 0.2.0
	 */
	public function test_an_amount_too_long_for_a_double_is_written_as_its_decimal(): void {
		Functions\expect( 'number_format_i18n' )->never();

		$money = Money::of( PHP_INT_MAX, Currency::of( 'USD' ) );

		$this->assertSame( '92233720368547758.07 USD', MoneyFormat::plain( $money ) );
		$this->assertSame( '92233720368547758.07 USD', MoneyFormat::text( $money, 'en_US' ) );
	}
}
