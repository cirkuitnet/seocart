<?php
/**
 * PriceProjection: the rule that gives a product its sort price in each currency
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Catalog\Application\Projection;

use SEOCart\Support\Money;

defined( 'ABSPATH' ) || exit;

/**
 * Decides which price a product sorts by in each currency the store sells in, from its variants' prices and the currency terms.
 *
 * Owns one fact: the price projection's rule. A product is priced by its listed variant: the
 * enabled variant of the lowest position in its published generation, the one the listing shows
 * (listed()). It has no row at all without a listed variant, or when that variant has no price in
 * the base currency: such a product is incomplete, and never listed. Otherwise it has a row in
 * each currency prices are offered in: the price authored in that currency, when there is one;
 * else the base price converted, when the currency lets a base price be sold in it, at the current
 * exchange-rate version; else none, so the product is left out of that currency's price order, as
 * a cart in it could not price it. The sort is on the authored amount, in the basis it was authored
 * in. Pure: the terms are asked, nothing is read or written here.
 *
 * @since 0.2.0
 */
final class PriceProjection {

	/**
	 * Returns the variant a product is listed and sorted by.
	 *
	 * @since 0.2.0
	 *
	 * @param array $variants The product's enabled variants of its published generation, lowest position first,
	 *                        each with its prices by currency code.
	 * @return array|null The first of them, or null for a product with none.
	 *
	 * @phpstan-param list<array{variant_id: int, prices: array<string, array{price_minor: int, amount_basis: string}>}> $variants
	 * @phpstan-return array{variant_id: int, prices: array<string, array{price_minor: int, amount_basis: string}>}|null
	 */
	public static function listed( array $variants ): ?array {
		return $variants[0] ?? null;
	}

	/**
	 * Returns the rows a product has, by currency.
	 *
	 * @since 0.2.0
	 *
	 * @param array         $variants The product's enabled variants of its published generation, lowest position first,
	 *                                each with its prices by currency code.
	 * @param CurrencyTerms $terms    The currencies prices are offered in, and the conversion.
	 * @return array<string, array{sort_price_minor: int, amount_basis: string, has_explicit_price: bool, rate_version: int|null}> By currency
	 *         code, in the order the currencies are offered; none for a product without a listed variant or a base price.
	 *
	 * @phpstan-param list<array{variant_id: int, prices: array<string, array{price_minor: int, amount_basis: string}>}> $variants
	 */
	public static function rowsOf( array $variants, CurrencyTerms $terms ): array {
		$listed  = self::listed( $variants );
		$offered = null === $listed ? array() : $terms->offered();
		$base    = $offered[0] ?? null;
		$price   = null === $base ? null : ( $listed['prices'][ $base->code() ] ?? null );
		$rows    = array();

		if ( null === $listed || null === $base || null === $price ) {
			return $rows;
		}

		foreach ( $offered as $currency ) {
			$explicit = $listed['prices'][ $currency->code() ] ?? null;

			if ( null !== $explicit ) {
				$rows[ $currency->code() ] = array(
					'sort_price_minor'   => $explicit['price_minor'],
					'amount_basis'       => $explicit['amount_basis'],
					'has_explicit_price' => true,
					'rate_version'       => null,
				);

				continue;
			}

			$converted = $terms->convert( Money::of( $price['price_minor'], $base ), $currency );

			if ( null !== $converted ) {
				$rows[ $currency->code() ] = array(
					'sort_price_minor'   => $converted->minorUnits(),
					'amount_basis'       => $price['amount_basis'],
					'has_explicit_price' => false,
					'rate_version'       => $terms->rateVersion(),
				);
			}
		}

		return $rows;
	}
}
