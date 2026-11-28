<?php
/**
 * MoneyFormat: writes an amount of money as the text a shopper reads
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Localization;

use SEOCart\Support\Money;

defined( 'ABSPATH' ) || exit;

/**
 * Writes an amount as text in a locale: the one place PHP formats money for a person.
 *
 * Owns one fact: how an amount is written for a reader. With PHP's `intl` extension the amount is
 * written in the locale's currency style ("$18.00", "18,00 €", "￥1,800"), with exactly as many
 * decimals as the currency has minor units, so nothing is rounded. Without `intl` it is written
 * with WordPress's number format at the currency's minor units, followed by a space and the
 * currency's ISO code ("18.00 USD"), which is unambiguous in every locale. A caller formats; it
 * never computes. Nothing here does arithmetic on the amount.
 *
 * The amount reaches a formatter as a float, which is what both of them take, and the float is
 * never the result of a division. The decimal is written from the minor units by digits, the
 * point placed by the currency's exponent ("1800" in USD is "18.00"), and PHP reads that decimal
 * into the nearest double. A decimal of at most fifteen significant digits survives that trip, so
 * written back at the currency's number of decimals, as both formatters write it, it gives its
 * own digits again: the conversion is exact. An amount of more digits, which no price has, is
 * written as the decimal itself followed by the code.
 *
 * @since 0.2.0
 */
final class MoneyFormat {

	/**
	 * The most digits of minor units the conversion to a double gives back exactly.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	private const EXACT_DIGITS = 15;

	/**
	 * Returns the text of an amount in a locale: in its currency style when PHP has `intl`, otherwise as plain() writes it.
	 *
	 * @since 0.2.0
	 *
	 * @param Money  $money  The amount.
	 * @param string $locale The locale, as WordPress names it, such as `en_US` or `ja`.
	 * @return string The text.
	 */
	public static function text( Money $money, string $locale ): string {
		if ( ! class_exists( \NumberFormatter::class ) || ! self::exact( $money ) ) {
			return self::plain( $money );
		}

		$decimals  = $money->currency()->exponent();
		$formatter = new \NumberFormatter( $locale, \NumberFormatter::CURRENCY );

		$formatter->setTextAttribute( \NumberFormatter::CURRENCY_CODE, $money->currency()->code() );
		$formatter->setAttribute( \NumberFormatter::MIN_FRACTION_DIGITS, $decimals );
		$formatter->setAttribute( \NumberFormatter::MAX_FRACTION_DIGITS, $decimals );

		$text = $formatter->format( (float) self::decimal( $money ) );

		return false === $text ? self::plain( $money ) : $text;
	}

	/**
	 * Returns the text of an amount without `intl`: WordPress's number format at the currency's minor units, a space and the ISO code.
	 *
	 * What text() returns when PHP has no `intl` extension, such as "1,800.00 USD" in English.
	 *
	 * @since 0.2.0
	 *
	 * @param Money $money The amount.
	 * @return string The text.
	 */
	public static function plain( Money $money ): string {
		$code = $money->currency()->code();

		if ( ! self::exact( $money ) ) {
			return self::decimal( $money ) . ' ' . $code;
		}

		return number_format_i18n( (float) self::decimal( $money ), $money->currency()->exponent() ) . ' ' . $code;
	}

	/**
	 * Writes an amount as a decimal by its digits: the minor units, with the point placed by the currency's exponent.
	 *
	 * @since 0.2.0
	 *
	 * @param Money $money The amount.
	 * @return string The decimal, such as `18.00`, `-0.05` or `1800`.
	 */
	private static function decimal( Money $money ): string {
		$minor    = (string) $money->minorUnits();
		$sign     = str_starts_with( $minor, '-' ) ? '-' : '';
		$exponent = $money->currency()->exponent();
		$digits   = str_pad( ltrim( $minor, '-' ), $exponent + 1, '0', STR_PAD_LEFT );

		return 0 === $exponent ? $sign . $digits : $sign . substr( $digits, 0, -$exponent ) . '.' . substr( $digits, -$exponent );
	}

	/**
	 * Tells whether an amount's minor units are few enough digits for a double to hold them exactly.
	 *
	 * @since 0.2.0
	 *
	 * @param Money $money The amount.
	 * @return bool True for at most EXACT_DIGITS digits.
	 */
	private static function exact( Money $money ): bool {
		return strlen( ltrim( (string) $money->minorUnits(), '-' ) ) <= self::EXACT_DIGITS;
	}
}
