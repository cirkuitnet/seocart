<?php
/**
 * ProductPage: reads a product the way a page shows it, once a request
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Catalog\Application\Query;

use SEOCart\Catalog\Application\ProductRepository;
use SEOCart\Catalog\Domain\Variant;
use SEOCart\Catalog\Domain\VariantPrice;
use SEOCart\Support\Currency;
use SEOCart\Support\Money;

defined( 'ABSPATH' ) || exit;

/**
 * Answers what a page shows of the product bound to a post, read once a request whatever number of blocks ask.
 *
 * Owns one fact: how a page reads a product. One load of the product bound to the post, with its
 * options and the variants of the generation it publishes; the verdict on those variants from the
 * one sellability rule, through its one fetch; and one read of the units for sale of the variants
 * that may be sold, for any market, reduced to in stock or not: a figure of none means no limit,
 * so it is in stock, as is a figure above zero. The page's currency is the store's base currency,
 * whose prices are the public ones every visitor's page shows, and the exchange-rate version
 * comes from the installation record at no cost. Each view is kept for the rest of the request,
 * by post id, so the blocks of one product cost one read.
 *
 * A post no product is bound to has no view. The variants are those the rule lets a visitor buy,
 * judged for a reader who cannot read private products, since the page is the same for every
 * visitor: none for a product whose generation is not complete, being written or left half-built,
 * nor for one whose post is not published, and no disabled variant.
 *
 * @since 0.2.0
 *
 * @phpstan-import-type PageVariant from ProductPageView
 */
final class ProductPage {

	/**
	 * Loads the product.
	 *
	 * @since 0.2.0
	 *
	 * @var ProductRepository
	 */
	private ProductRepository $products;

	/**
	 * Judges whether each variant may be sold: the one rule, through its one fetch.
	 *
	 * @since 0.2.0
	 *
	 * @var Sellability
	 */
	private Sellability $sellability;

	/**
	 * Returns the units for sale of each variant, for any market: null for no limit.
	 *
	 * @since 0.2.0
	 *
	 * @var \Closure(list<int>): array<int, int|null>
	 */
	private \Closure $forSale;

	/**
	 * Returns the page's currency.
	 *
	 * @since 0.2.0
	 *
	 * @var \Closure(): Currency
	 */
	private \Closure $currency;

	/**
	 * Returns the current exchange-rate version, or null when there is none.
	 *
	 * @since 0.2.0
	 *
	 * @var \Closure(): (int|null)
	 */
	private \Closure $priceVersion;

	/**
	 * The views read in this request, by post id; null for a post with no product.
	 *
	 * @since 0.2.0
	 *
	 * @var array<int, ProductPageView|null>
	 */
	private array $views = array();

	/**
	 * Creates the query. Reads nothing.
	 *
	 * @since 0.2.0
	 *
	 * @param ProductRepository $products     Loads the product.
	 * @param Sellability       $sellability  Judges whether each variant may be sold.
	 * @param callable          $forSale      Returns the units for sale of each variant id given (a list of int), for any
	 *                                        market, keyed by variant id: an int, or null for no limit.
	 * @param callable          $currency     Returns the page's currency: the store's base currency.
	 * @param callable          $priceVersion Returns the current exchange-rate version, or null.
	 *
	 * @phpstan-param callable(list<int>): array<int, int|null> $forSale
	 * @phpstan-param callable(): Currency                       $currency
	 * @phpstan-param callable(): (int|null)                     $priceVersion
	 */
	public function __construct( ProductRepository $products, Sellability $sellability, callable $forSale, callable $currency, callable $priceVersion ) {
		$this->products     = $products;
		$this->sellability  = $sellability;
		$this->forSale      = \Closure::fromCallable( $forSale );
		$this->currency     = \Closure::fromCallable( $currency );
		$this->priceVersion = \Closure::fromCallable( $priceVersion );
	}

	/**
	 * Returns what a page shows of the product bound to a post: read the first time in a request, kept for the rest of it.
	 *
	 * @since 0.2.0
	 *
	 * @param int $postId The post.
	 * @return ProductPageView|null The view, or null when no product is bound to the post.
	 */
	public function of( int $postId ): ?ProductPageView {
		if ( ! array_key_exists( $postId, $this->views ) ) {
			$this->views[ $postId ] = $this->read( $postId );
		}

		return $this->views[ $postId ];
	}

	/**
	 * Reads the view of a post's product.
	 *
	 * @since 0.2.0
	 *
	 * @param int $postId The post.
	 * @return ProductPageView|null The view, or null when no product is bound to the post.
	 */
	private function read( int $postId ): ?ProductPageView {
		$product = $this->products->findByPost( $postId );

		if ( null === $product ) {
			return null;
		}

		$currency = ( $this->currency )();
		$sold     = $this->sold( $product->variants() );
		$ids      = array_map( static fn( Variant $variant ): int => (int) $variant->id(), $sold );
		$forSale  = array() === $ids ? array() : ( $this->forSale )( $ids );
		$variants = array();

		foreach ( $sold as $variant ) {
			$variants[] = self::pageVariant( $variant, $currency, array_key_exists( (int) $variant->id(), $forSale ) ? $forSale[ (int) $variant->id() ] : 0 );
		}

		return new ProductPageView( $postId, $product->uuid(), $product->options()->axes, $variants, $currency, ( $this->priceVersion )() );
	}

	/**
	 * Returns the variants a visitor may buy, in their order: those the sellability rule lets be sold.
	 *
	 * @since 0.2.0
	 *
	 * @param Variant[] $variants The variants of the generation the product publishes.
	 * @return list<Variant> The variants that may be sold; the rule's fetch is sent only when there is a variant to judge.
	 *
	 * @phpstan-param list<Variant> $variants
	 */
	private function sold( array $variants ): array {
		$stored   = array_values( array_filter( $variants, static fn( Variant $variant ): bool => null !== $variant->id() ) );
		$verdicts = array() === $stored ? array() : $this->sellability->of( array_map( static fn( Variant $variant ): int => (int) $variant->id(), $stored ), false );

		return array_values( array_filter( $stored, static fn( Variant $variant ): bool => isset( $verdicts[ (int) $variant->id() ] ) && $verdicts[ (int) $variant->id() ]->sells() ) );
	}

	/**
	 * Returns what the page shows of one variant.
	 *
	 * @since 0.2.0
	 *
	 * @param Variant  $variant  The variant.
	 * @param Currency $currency The page's currency.
	 * @param int|null $forSale  Its units for sale, or null for no limit.
	 * @return array The variant as the view holds it.
	 *
	 * @phpstan-return PageVariant
	 */
	private static function pageVariant( Variant $variant, Currency $currency, ?int $forSale ): array {
		$price = self::priceIn( $variant, $currency );

		return array(
			'id'        => (int) $variant->id(),
			'values'    => array_map( 'strval', $variant->selection()->pairs() ),
			'label'     => $variant->label(),
			'price'     => null === $price ? null : Money::of( $price->priceMinor(), $currency ),
			'compareAt' => null === $price || null === $price->compareAtMinor() ? null : Money::of( $price->compareAtMinor(), $currency ),
			'inStock'   => null === $forSale || $forSale > 0,
		);
	}

	/**
	 * Returns a variant's price in a currency: the one its merchant entered in that currency, never one converted.
	 *
	 * @since 0.2.0
	 *
	 * @param Variant  $variant  The variant.
	 * @param Currency $currency The currency.
	 * @return VariantPrice|null The price, or null when the variant has none in that currency.
	 */
	private static function priceIn( Variant $variant, Currency $currency ): ?VariantPrice {
		foreach ( $variant->prices() as $price ) {
			if ( $price->currency()->equals( $currency ) ) {
				return $price;
			}
		}

		return null;
	}
}
