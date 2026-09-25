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
use SEOCart\Catalog\Domain\SellabilityReason;
use SEOCart\Support\Locale;

defined( 'ABSPATH' ) || exit;

/**
 * Answers, for a set of variants, whether each may be sold and if not why.
 *
 * Owns one fact: how a reader gets a verdict. It reads every variant's facts with the
 * repository's one fetch and judges each with the domain's one rule, so a REST response, a cart
 * and a checkout all see the same verdict for the same variant. A variant no row has is
 * `unknown_variant`. A post with no variant to judge, because no product is bound to it or its
 * product has none yet, gets the rule's verdict on that without a fetch.
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
		$ids = array_values( array_unique( array_map( 'intval', $variantIds ) ) );

		if ( array() === $ids ) {
			return array();
		}

		$facts = array();

		foreach ( null === $locale ? $this->products->sellabilityFacts( ...$ids ) : $this->products->sellabilityFactsIn( $locale, ...$ids ) as $fact ) {
			$facts[ $fact->variantId ] = $fact;
		}

		$verdicts = array();

		foreach ( $ids as $id ) {
			$verdicts[ $id ] = SellabilityRule::verdict( $facts[ $id ] ?? null, $canReadPrivate );
		}

		return $verdicts;
	}

	/**
	 * Returns the verdict on the default variant of a post's product, or on the post itself when there is no variant to judge.
	 *
	 * @since 0.1.0
	 *
	 * @param Product|null $product        The product bound to the post, as loaded, or null when none is.
	 * @param bool         $canReadPrivate Whether the reader may read private products.
	 * @param Locale|null  $locale         Optional. The locale of the post, or null for the product's source post. Default null.
	 * @return SellabilityReason The verdict: from the one fetch when the product has a default variant, one query; otherwise none.
	 */
	public function ofProduct( ?Product $product, bool $canReadPrivate, ?Locale $locale = null ): SellabilityReason {
		$variantId = $product?->defaultVariant()?->id();

		if ( null === $variantId ) {
			return SellabilityRule::withoutVariant( $product?->generation() );
		}

		return $this->of( array( $variantId ), $canReadPrivate, $locale )[ $variantId ];
	}
}
