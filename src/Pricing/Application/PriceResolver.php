<?php
/**
 * PriceResolver: finds the unit price of every line to calculate, in one read
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Pricing\Application;

use SEOCart\Catalog\Application\ProductRepository;
use SEOCart\Catalog\Domain\VariantPrice;
use SEOCart\Pricing\Domain\AmountBasis;
use SEOCart\Pricing\Domain\AuthoredAmount;
use SEOCart\Pricing\Domain\InputLine;
use SEOCart\Pricing\Domain\PriceSource;
use SEOCart\Support\Money;

defined( 'ABSPATH' ) || exit;

/**
 * Turns the lines a caller asks to price into calculation lines, each with its authored price.
 *
 * Owns one fact: where a line's price comes from. The prices of every line are read in one
 * query, in the cart's currency and the base currency together, so the number of queries does
 * not grow with the lines. A price the merchant authored in the cart's currency is used as it
 * is, with its basis, and wins over any other.
 *
 * A line without one is priced from its base price when the currency allows conversion: the
 * base price at the currency's current rate, rounded once by the currency's rule and then to its
 * cash step, in the basis the base price was authored in. That is the one rounding of a
 * calculation outside its engine; the line says its price was converted, and the calculation
 * records the rate it was converted at. Otherwise the line is not priced at zero or at a guess:
 * it is reported as unpriced, with no price in the currency when the variant has a base price,
 * and as an unknown variant when no price names it at all. Whether a variant may be sold is not
 * decided here.
 *
 * A price without a tax class is taxed by the `standard` class.
 *
 * @since 0.1.0
 */
final class PriceResolver {

	/**
	 * The tax class of a price that names none.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const DEFAULT_TAX_CLASS = 'standard';

	/**
	 * Creates the resolver. Sends nothing.
	 *
	 * @since 0.1.0
	 *
	 * @param ProductRepository $products The catalog's storage, which holds the prices.
	 */
	public function __construct( private ProductRepository $products ) {
	}

	/**
	 * Prices the lines, in one query.
	 *
	 * @since 0.1.0
	 *
	 * @param LineRequest[]       $lines    The lines, in cart order.
	 * @param PresentmentCurrency $currency The cart's currency, with the store's base currency, read in the same query, and the rate between them.
	 * @return ResolvedPrices The priced lines and the unpriced ones, each in cart order.
	 *
	 * @phpstan-param list<LineRequest> $lines
	 */
	public function resolve( array $lines, PresentmentCurrency $currency ): ResolvedPrices {
		if ( array() === $lines ) {
			return new ResolvedPrices( array(), array() );
		}

		$prices = array();

		foreach ( $this->products->explicitPrices( array_map( static fn( LineRequest $line ): int => $line->variantId, $lines ), $currency->currency(), $currency->baseCurrency() ) as $row ) {
			$prices[ $row['variantId'] ][ $row['price']->currency()->code() ] = $row;
		}

		$priced   = array();
		$unpriced = array();

		foreach ( $lines as $line ) {
			$row = $prices[ $line->variantId ][ $currency->currency()->code() ] ?? null;

			if ( null === $row ) {
				$converted = $this->converted( $line, $prices[ $line->variantId ][ $currency->baseCurrency()->code() ] ?? null, $currency );

				if ( null !== $converted ) {
					$priced[] = $converted;
					continue;
				}

				$unpriced[] = new UnpricedLine( $line->key, $line->variantId, isset( $prices[ $line->variantId ] ) ? UnpricedLine::NO_PRICE_IN_CURRENCY : UnpricedLine::UNKNOWN_VARIANT );
				continue;
			}

			$unit     = new AuthoredAmount( Money::of( $row['price']->priceMinor(), $currency->currency() ), AmountBasis::from( $row['price']->amountBasis() ) );
			$priced[] = new InputLine( $line->key, $line->variantId, $line->quantity, $unit, PriceSource::Explicit, self::taxClassOf( $row ) );
		}

		return new ResolvedPrices( $priced, $unpriced );
	}

	/**
	 * Prices a line from its base price, when the currency allows it.
	 *
	 * @since 0.1.0
	 *
	 * @param LineRequest         $line     The line.
	 * @param array|null          $base     The variant's price in the base currency, as the catalog read it, or null for none.
	 * @param PresentmentCurrency $currency The cart's currency.
	 * @return InputLine|null The line at its converted price, or null when it cannot be converted.
	 *
	 * @phpstan-param array{variantId: int, price: VariantPrice, taxClassId: int|null}|null $base
	 */
	private function converted( LineRequest $line, ?array $base, PresentmentCurrency $currency ): ?InputLine {
		if ( null === $base || ! $currency->conversionFallbackAllowed ) {
			return null;
		}

		$rule = $currency->roundingRule;
		$unit = $currency->context->convertToQuoteMoney( Money::of( $base['price']->priceMinor(), $currency->baseCurrency() ), $rule->roundingMode() )->roundToCashStep( $rule );

		return new InputLine( $line->key, $line->variantId, $line->quantity, new AuthoredAmount( $unit, AmountBasis::from( $base['price']->amountBasis() ) ), PriceSource::Converted, self::taxClassOf( $base ) );
	}

	/**
	 * Returns the tax class of a price: the `standard` class when it names none.
	 *
	 * @since 0.1.0
	 *
	 * @param array $row A price, as the catalog read it.
	 * @return string The tax class.
	 *
	 * @phpstan-param array{variantId: int, price: VariantPrice, taxClassId: int|null} $row
	 */
	private static function taxClassOf( array $row ): string {
		return null === $row['taxClassId'] ? self::DEFAULT_TAX_CLASS : 'class_' . $row['taxClassId'];
	}
}
