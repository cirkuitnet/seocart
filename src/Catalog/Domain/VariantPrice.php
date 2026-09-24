<?php
/**
 * VariantPrice: the price a merchant authors for a variant in one currency
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Catalog\Domain;

use SEOCart\Support\Currency;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- A LogicException names a value built by code, for the developer; it is never rendered.

/**
 * One authored price of a variant: an amount in minor units, an optional compare-at amount, the currency and the basis.
 *
 * Owns one fact: what an authored price holds. Amounts are integers of the currency's minor
 * unit and never negative. The basis says whether the amount includes tax: `net` or `gross`.
 * Prices are authored `net` for now; a stored `gross` row reads back as written. A price in
 * another currency than the store's base is refused by Product, which knows the base.
 *
 * @since 0.1.0
 */
final class VariantPrice {

	/**
	 * The amount excludes tax.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const NET = 'net';

	/**
	 * The amount includes tax.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const GROSS = 'gross';

	/**
	 * The currency.
	 *
	 * @since 0.1.0
	 *
	 * @var Currency
	 */
	private Currency $currency;

	/**
	 * The price, in minor units.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private int $priceMinor;

	/**
	 * The compare-at price, in minor units, or null.
	 *
	 * @since 0.1.0
	 *
	 * @var int|null
	 */
	private ?int $compareAtMinor;

	/**
	 * NET or GROSS.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private string $amountBasis;

	/**
	 * Creates a price from checked values.
	 *
	 * @since 0.1.0
	 *
	 * @throws \InvalidArgumentException When an amount is negative or the basis is neither NET nor GROSS.
	 *
	 * @param Currency $currency       The currency.
	 * @param int      $priceMinor     The price, in minor units.
	 * @param int|null $compareAtMinor The compare-at price, in minor units, or null.
	 * @param string   $amountBasis    NET or GROSS.
	 */
	private function __construct( Currency $currency, int $priceMinor, ?int $compareAtMinor, string $amountBasis ) {
		if ( $priceMinor < 0 || ( null !== $compareAtMinor && $compareAtMinor < 0 ) ) {
			throw new \InvalidArgumentException( 'A price is never negative.' );
		}

		if ( self::NET !== $amountBasis && self::GROSS !== $amountBasis ) {
			throw new \InvalidArgumentException( sprintf( 'An amount basis is "net" or "gross", not "%s".', $amountBasis ) );
		}

		$this->currency       = $currency;
		$this->priceMinor     = $priceMinor;
		$this->compareAtMinor = $compareAtMinor;
		$this->amountBasis    = $amountBasis;
	}

	/**
	 * Returns a price authored without tax.
	 *
	 * @since 0.1.0
	 *
	 * @param Currency $currency       The currency.
	 * @param int      $priceMinor     The price, in minor units, 0 or more.
	 * @param int|null $compareAtMinor Optional. The compare-at price, in minor units, 0 or more. Default null.
	 * @return self The price.
	 */
	public static function net( Currency $currency, int $priceMinor, ?int $compareAtMinor = null ): self {
		return new self( $currency, $priceMinor, $compareAtMinor, self::NET );
	}

	/**
	 * Returns a price as it was stored.
	 *
	 * @since 0.1.0
	 *
	 * @param Currency $currency       The currency.
	 * @param int      $priceMinor     The price, in minor units.
	 * @param int|null $compareAtMinor The compare-at price, in minor units, or null.
	 * @param string   $amountBasis    NET or GROSS.
	 * @return self The price.
	 */
	public static function stored( Currency $currency, int $priceMinor, ?int $compareAtMinor, string $amountBasis ): self {
		return new self( $currency, $priceMinor, $compareAtMinor, $amountBasis );
	}

	/**
	 * Returns the currency.
	 *
	 * @since 0.1.0
	 *
	 * @return Currency The currency.
	 */
	public function currency(): Currency {
		return $this->currency;
	}

	/**
	 * Returns the price.
	 *
	 * @since 0.1.0
	 *
	 * @return int The price, in minor units.
	 */
	public function priceMinor(): int {
		return $this->priceMinor;
	}

	/**
	 * Returns the compare-at price.
	 *
	 * @since 0.1.0
	 *
	 * @return int|null The compare-at price, in minor units, or null when there is none.
	 */
	public function compareAtMinor(): ?int {
		return $this->compareAtMinor;
	}

	/**
	 * Returns whether the amounts include tax.
	 *
	 * @since 0.1.0
	 *
	 * @return string NET or GROSS.
	 */
	public function amountBasis(): string {
		return $this->amountBasis;
	}
}
