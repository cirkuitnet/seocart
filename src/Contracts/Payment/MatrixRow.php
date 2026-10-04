<?php
/**
 * MatrixRow: what a gateway can do in one currency, for accounts of one country
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Contracts\Payment;

use SEOCart\Support\Currency;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- These exceptions report a gateway's programming error to its developer; they are never HTML.

/**
 * One cell of a capability matrix: a currency, the country of the merchant's provider account, and the operations the gateway supports there.
 *
 * Owns one fact: the operations of one cell. The country is the account's, as the gateway's
 * `account_country` setting names it, or `*` for a provider that does not distinguish. Every row
 * declares the required operations (Operations::REQUIRED); a currency or country with no row is
 * one the gateway does not take.
 *
 * @since 0.2.0
 *
 * @api
 */
final readonly class MatrixRow {

	/**
	 * The account country of a row that applies to an account of any country.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const ANY_COUNTRY = '*';

	/**
	 * Records the row.
	 *
	 * @since 0.2.0
	 *
	 * @throws \InvalidArgumentException When the country is neither `*` nor two upper-case letters, or the operations
	 *                                   are not distinct names of Operations::ALL that include Operations::REQUIRED.
	 *
	 * @param Currency $currency       The currency.
	 * @param string   $accountCountry The account's country, ISO 3166-1 alpha-2, or `*`.
	 * @param array    $operations     The operations supported, names from Operations::ALL.
	 *
	 * @phpstan-param list<string> $operations
	 */
	public function __construct(
		public Currency $currency,
		public string $accountCountry,
		public array $operations
	) {
		if ( self::ANY_COUNTRY !== $accountCountry && 1 !== preg_match( '/^[A-Z]{2}\z/', $accountCountry ) ) {
			throw new \InvalidArgumentException( sprintf( 'A capability row\'s account country is two upper-case letters or *, not "%s".', $accountCountry ) );
		}

		if ( count( array_unique( $operations ) ) !== count( $operations ) || array() !== array_diff( $operations, Operations::ALL ) ) {
			throw new \InvalidArgumentException( 'A capability row lists distinct operations, each a name of Operations::ALL.' );
		}

		$missing = array_diff( Operations::REQUIRED, $operations );

		if ( array() !== $missing ) {
			throw new \InvalidArgumentException( sprintf( 'A capability row must declare every required operation; %s is missing.', implode( ', ', $missing ) ) );
		}
	}

	/**
	 * Tells whether the row supports an operation.
	 *
	 * @since 0.2.0
	 *
	 * @param string $operation A name of Operations::ALL.
	 * @return bool True when the row declares it.
	 */
	public function supports( string $operation ): bool {
		return in_array( $operation, $this->operations, true );
	}

	/**
	 * Tells whether the row is the cell for a currency and an account's country.
	 *
	 * @since 0.2.0
	 *
	 * @param Currency    $currency       The currency.
	 * @param string|null $accountCountry The account's country; null when the gateway has none set, which only a `*` row matches.
	 * @return bool True when the currency is the row's and the row's country is `*` or the account's.
	 */
	public function covers( Currency $currency, ?string $accountCountry ): bool {
		return $this->currency->equals( $currency ) && ( self::ANY_COUNTRY === $this->accountCountry || $this->accountCountry === $accountCountry );
	}
}
