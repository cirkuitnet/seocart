<?php
/**
 * Currency: an ISO 4217 currency and the number of its minor-unit digits
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Support;

use SEOCart\Support\Error\CodedException;

defined( 'ABSPATH' ) || exit;

/**
 * An active ISO 4217 currency.
 *
 * This class owns one fact: which ISO 4217 codes exist and how many minor-unit digits each
 * one has. The exponent is data shipped in code, never a constant 2 and never a
 * merchant-editable column (data storage §3.1), so a merchant cannot mistype JPY as a
 * two-decimal currency. What a store does with a currency — whether it is enabled, how it
 * rounds, whether converted prices may fall back to it — is configuration, carried by
 * CurrencyRoundingRule and the `currencies` table, not by this class.
 *
 * The table is ISO 4217 List One as published by SIX Financial Information on 2026-09-17:
 * every active code whose minor unit is a number. Codes whose minor unit is "N.A." (gold,
 * silver, platinum, palladium, the SDR and the bond-market and test codes XBA to XBD, XDR,
 * XSU, XTS, XUA and XXX) are left out, because an amount in them has no minor unit to count.
 * Exponents 0, 2, 3 and 4 all occur: JPY, USD, KWD and CLF. Updating the table is a code
 * change reviewed against a newer publication; nothing reads it at run time.
 *
 * @since 0.1.0
 */
final class Currency {

	/**
	 * The minor-unit exponent of every supported code, in code order.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, int>
	 */
	private const EXPONENTS = array(
		'AED' => 2,
		'AFN' => 2,
		'ALL' => 2,
		'AMD' => 2,
		'AOA' => 2,
		'ARS' => 2,
		'AUD' => 2,
		'AWG' => 2,
		'AZN' => 2,
		'BAM' => 2,
		'BBD' => 2,
		'BDT' => 2,
		'BHD' => 3,
		'BIF' => 0,
		'BMD' => 2,
		'BND' => 2,
		'BOB' => 2,
		'BOV' => 2,
		'BRL' => 2,
		'BSD' => 2,
		'BTN' => 2,
		'BWP' => 2,
		'BYN' => 2,
		'BZD' => 2,
		'CAD' => 2,
		'CDF' => 2,
		'CHE' => 2,
		'CHF' => 2,
		'CHW' => 2,
		'CLF' => 4,
		'CLP' => 0,
		'CNY' => 2,
		'COP' => 2,
		'COU' => 2,
		'CRC' => 2,
		'CUP' => 2,
		'CVE' => 2,
		'CZK' => 2,
		'DJF' => 0,
		'DKK' => 2,
		'DOP' => 2,
		'DZD' => 2,
		'EGP' => 2,
		'ERN' => 2,
		'ETB' => 2,
		'EUR' => 2,
		'FJD' => 2,
		'FKP' => 2,
		'GBP' => 2,
		'GEL' => 2,
		'GHS' => 2,
		'GIP' => 2,
		'GMD' => 2,
		'GNF' => 0,
		'GTQ' => 2,
		'GYD' => 2,
		'HKD' => 2,
		'HNL' => 2,
		'HTG' => 2,
		'HUF' => 2,
		'IDR' => 2,
		'ILS' => 2,
		'INR' => 2,
		'IQD' => 3,
		'IRR' => 2,
		'ISK' => 0,
		'JMD' => 2,
		'JOD' => 3,
		'JPY' => 0,
		'KES' => 2,
		'KGS' => 2,
		'KHR' => 2,
		'KMF' => 0,
		'KPW' => 2,
		'KRW' => 0,
		'KWD' => 3,
		'KYD' => 2,
		'KZT' => 2,
		'LAK' => 2,
		'LBP' => 2,
		'LKR' => 2,
		'LRD' => 2,
		'LSL' => 2,
		'LYD' => 3,
		'MAD' => 2,
		'MDL' => 2,
		'MGA' => 2,
		'MKD' => 2,
		'MMK' => 2,
		'MNT' => 2,
		'MOP' => 2,
		'MRU' => 2,
		'MUR' => 2,
		'MVR' => 2,
		'MWK' => 2,
		'MXN' => 2,
		'MXV' => 2,
		'MYR' => 2,
		'MZN' => 2,
		'NAD' => 2,
		'NGN' => 2,
		'NIO' => 2,
		'NOK' => 2,
		'NPR' => 2,
		'NZD' => 2,
		'OMR' => 3,
		'PAB' => 2,
		'PEN' => 2,
		'PGK' => 2,
		'PHP' => 2,
		'PKR' => 2,
		'PLN' => 2,
		'PYG' => 0,
		'QAR' => 2,
		'RON' => 2,
		'RSD' => 2,
		'RUB' => 2,
		'RWF' => 0,
		'SAR' => 2,
		'SBD' => 2,
		'SCR' => 2,
		'SDG' => 2,
		'SEK' => 2,
		'SGD' => 2,
		'SHP' => 2,
		'SLE' => 2,
		'SOS' => 2,
		'SRD' => 2,
		'SSP' => 2,
		'STN' => 2,
		'SVC' => 2,
		'SYP' => 2,
		'SZL' => 2,
		'THB' => 2,
		'TJS' => 2,
		'TMT' => 2,
		'TND' => 3,
		'TOP' => 2,
		'TRY' => 2,
		'TTD' => 2,
		'TWD' => 2,
		'TZS' => 2,
		'UAH' => 2,
		'UGX' => 0,
		'USD' => 2,
		'USN' => 2,
		'UYI' => 0,
		'UYU' => 2,
		'UYW' => 4,
		'UZS' => 2,
		'VED' => 2,
		'VES' => 2,
		'VND' => 0,
		'VUV' => 0,
		'WST' => 2,
		'XAD' => 2,
		'XAF' => 0,
		'XCD' => 2,
		'XCG' => 2,
		'XOF' => 0,
		'XPF' => 0,
		'YER' => 2,
		'ZAR' => 2,
		'ZMW' => 2,
		'ZWG' => 2,
	);

	/**
	 * The ISO 4217 alphabetic code, for example 'EUR'.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private string $code;

	/**
	 * The number of digits after the decimal point in one major unit.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private int $exponent;

	/**
	 * Creates a currency from a code already found in the table.
	 *
	 * @since 0.1.0
	 *
	 * @param string $code     The ISO 4217 alphabetic code.
	 * @param int    $exponent Its minor-unit exponent.
	 */
	private function __construct( string $code, int $exponent ) {
		$this->code     = $code;
		$this->exponent = $exponent;
	}

	/**
	 * Returns the currency with an ISO 4217 code.
	 *
	 * The code must be written exactly as ISO 4217 writes it: three upper-case letters.
	 * Normalizing what a visitor typed is the job of the adapter that received it.
	 *
	 * @since 0.1.0
	 *
	 * @throws CodedException With SupportError::UnknownCurrency when the code is not an active
	 *                        ISO 4217 code with a numeric minor unit, including when it is
	 *                        malformed.
	 *
	 * @param string $code The ISO 4217 alphabetic code, for example 'USD'.
	 * @return self The currency.
	 */
	public static function of( string $code ): self {
		if ( ! isset( self::EXPONENTS[ $code ] ) ) {
			CodedException::raise( SupportError::UnknownCurrency, array( 'currency' => $code ) );
		}

		return new self( $code, self::EXPONENTS[ $code ] );
	}

	/**
	 * Returns the ISO 4217 alphabetic code.
	 *
	 * @since 0.1.0
	 *
	 * @return string Three upper-case letters, for example 'EUR'.
	 */
	public function code(): string {
		return $this->code;
	}

	/**
	 * Returns the number of digits after the decimal point in one major unit.
	 *
	 * @since 0.1.0
	 *
	 * @return int 0, 2, 3 or 4: 0 for JPY, 2 for USD, 3 for KWD, 4 for CLF.
	 */
	public function exponent(): int {
		return $this->exponent;
	}

	/**
	 * Tells whether two currencies are the same.
	 *
	 * @since 0.1.0
	 *
	 * @param Currency $other The currency to compare with.
	 * @return bool True when both have the same code.
	 */
	public function equals( Currency $other ): bool {
		return $this->code === $other->code;
	}
}
