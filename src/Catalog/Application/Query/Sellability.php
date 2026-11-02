<?php
/**
 * Sellability: the one place a reader asks whether variants may be sold
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Catalog\Application\Query;

use SEOCart\Catalog\Application\ProductRepository;
use SEOCart\Catalog\Domain\Product;
use SEOCart\Catalog\Domain\Sellability as SellabilityRule;
use SEOCart\Catalog\Domain\SellabilityFacts;
use SEOCart\Catalog\Domain\SellabilityReason;
use SEOCart\Catalog\Domain\Variant;
use SEOCart\Support\Locale;

defined( 'ABSPATH' ) || exit;

/**
 * Answers, for a set of variants, whether each may be sold and if not why.
 *
 * Owns one fact: how a reader gets a verdict. It reads every variant's facts with the
 * repository's one fetch and judges each with the domain's one rule, so a REST response, a cart
 * and a checkout all see the same verdict for the same variant. A variant no row has is
 * `unknown_variant`. A product may be sold when any of its variants may: its verdict is
 * `sellable` then, and otherwise the reason of its first variant. A post with no variant to judge,
 * because no product is bound to it or its product has none yet, gets the rule's verdict on that
 * without a fetch.
 *
 * A verdict in a locale judges the product's post in that locale, which a product without one
 * does not have: it is `not_translated` there. Without a locale, the product's source post is
 * judged.
 *
 * @since 0.1.0
 */
final class Sellability {

	/**
	 * Reads the facts.
	 *
	 * @since 0.1.0
	 *
	 * @var ProductRepository
	 */
	private ProductRepository $products;

	/**
	 * Creates the query. Reads nothing.
	 *
	 * @since 0.1.0
	 *
	 * @param ProductRepository $products Reads the facts.
	 */
	public function __construct( ProductRepository $products ) {
		$this->products = $products;
	}

	/**
	 * Returns the verdict on each variant.
	 *
	 * @since 0.1.0
	 *
	 * @param int[]       $variantIds     The variants' ids.
	 * @param bool        $canReadPrivate Whether the reader may read private products: the capability
	 *                                    `read_private_seocart_products`, checked by the caller.
	 * @param Locale|null $locale         Optional. The locale the variants are sold in, or null for the product's
	 *                                    source post. Default null.
	 * @return array<int, SellabilityReason> Each variant's verdict, keyed by its id, in the order asked; one query, none for no id.
	 */
	public function of( array $variantIds, bool $canReadPrivate, ?Locale $locale = null ): array {
		return array_map( static fn( array $sale ): SellabilityReason => $sale['verdict'], $this->forSale( $variantIds, $canReadPrivate, $locale ) );
	}

	/**
	 * Returns the verdict on each variant, with the facts it was judged on: what a sale records of the variant it sells.
	 *
	 * The same one query as of(). The facts carry the variant's product, its SKU and the title of
	 * the post judged, as stored: in the locale asked for, the product's post in that locale;
	 * without one, its source post.
	 *
	 * @since 0.1.0
	 *
	 * @param int[]       $variantIds     The variants' ids.
	 * @param bool        $canReadPrivate Whether the reader may read private products.
	 * @param Locale|null $locale         Optional. The locale the variants are sold in, or null for the product's
	 *                                    source post. Default null.
	 * @return array<int, array{verdict: SellabilityReason, facts: SellabilityFacts|null}> Each variant's verdict and
	 *         facts, keyed by its id, in the order asked; the facts are null for an id no variant has.
	 */
	public function forSale( array $variantIds, bool $canReadPrivate, ?Locale $locale = null ): array {
		$ids = array_values( array_unique( array_map( 'intval', $variantIds ) ) );

		if ( array() === $ids ) {
			return array();
		}

		$facts = array();

		foreach ( null === $locale ? $this->products->sellabilityFacts( ...$ids ) : $this->products->sellabilityFactsIn( $locale, ...$ids ) as $fact ) {
			$facts[ $fact->variantId ] = $fact;
		}

		$sales = array();

		foreach ( $ids as $id ) {
			$sales[ $id ] = array(
				'verdict' => SellabilityRule::verdict( $facts[ $id ] ?? null, $canReadPrivate ),
				'facts'   => $facts[ $id ] ?? null,
			);
		}

		return $sales;
	}

	/**
	 * Returns the verdict on a post's product: `sellable` when any variant of the generation it publishes may be sold, else the first variant's reason, or the verdict on the post itself when there is no variant to judge.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 Every variant of the published generation, not the default variant alone.
	 *
	 * @param Product|null $product        The product bound to the post, as loaded, or null when none is.
	 * @param bool         $canReadPrivate Whether the reader may read private products.
	 * @param Locale|null  $locale         Optional. The locale of the post, or null for the product's source post. Default null.
	 * @return SellabilityReason The verdict: from the one fetch when the product has a variant, one query; otherwise none.
	 */
	public function ofProduct( ?Product $product, bool $canReadPrivate, ?Locale $locale = null ): SellabilityReason {
		$variantIds = array_values( array_filter( array_map( static fn( Variant $variant ): ?int => $variant->id(), $product?->variants() ?? array() ) ) );

		if ( array() === $variantIds ) {
			return SellabilityRule::withoutVariant( $product?->generation() );
		}

		return self::verdictOnProduct( $this->of( $variantIds, $canReadPrivate, $locale ) );
	}

	/**
	 * Returns the verdict on a product from the verdicts on its variants: the first that sells, else the first.
	 *
	 * @since 0.2.0
	 *
	 * @param non-empty-array<int, SellabilityReason> $verdicts The verdict on each variant, in the variants' order.
	 * @return SellabilityReason The verdict on the product.
	 */
	public static function verdictOnProduct( array $verdicts ): SellabilityReason {
		foreach ( $verdicts as $verdict ) {
			if ( $verdict->sells() ) {
				return $verdict;
			}
		}

		return reset( $verdicts );
	}
}
