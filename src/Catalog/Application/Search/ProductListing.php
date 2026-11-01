<?php
/**
 * ProductListing: the storefront's list of products, searched and filtered, one page at a time
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Catalog\Application\Search;

use SEOCart\Catalog\Application\Projection\CurrencyTerms;
use SEOCart\Catalog\Domain\CatalogError;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Platform\Localization\PostLocales;
use SEOCart\Support\Currency;
use SEOCart\Support\Error\CodedException;
use SEOCart\Support\Locale;

defined( 'ABSPATH' ) || exit;

/**
 * Performs `catalog.list_products`: resolves the request, asks CatalogSearch for the page, and shapes its items.
 *
 * Owns one fact: what a page of the listing holds. A product is listed when its post in the
 * request's locale is published and its product is `complete`; nobody sees more, whoever asks.
 * The order is newest, by price in a currency the store sells in, the base currency by default,
 * in which a price range is given too, or, for a search, by relevance; a price order lists only the
 * products with a price in the currency, and the order by relevance those the word index holds. The locale defaults to the site's and must be one the site publishes in. The category, brand
 * and tag are slugs, resolved together by one get_terms(), and a category's descendants by one
 * more, so the search is given term-taxonomy ids. A search holds up to SearchQuery::MOST_WORDS
 * words. Each item is the post's id, title, permalink and featured image at the `medium` size, and
 * the product's id, uuid, listed variant and SKU, and its stock summary; no price, no excerpt, no
 * content: prices are hydrated by the page that shows them.
 *
 * The page costs the same statements whatever its length or its order, on a cold cache: the
 * search, the page's posts with their terms and meta, the facts, and the featured images' posts
 * and meta. A request that asks anything of prices (a price order, a price range or a currency)
 * reads the store's base currency too, one statement, and a currency other than the base one the
 * read of its terms. It
 * renders nothing and knows no theme.
 *
 * @since 0.2.0
 */
final class ProductListing {

	/**
	 * The search.
	 *
	 * @since 0.2.0
	 *
	 * @var CatalogSearch
	 */
	private CatalogSearch $search;

	/**
	 * The facts of the page's products.
	 *
	 * @since 0.2.0
	 *
	 * @var ListingFacts
	 */
	private ListingFacts $facts;

	/**
	 * The locales the site publishes in.
	 *
	 * @since 0.2.0
	 *
	 * @var PostLocales
	 */
	private PostLocales $locales;

	/**
	 * The taxonomy of each term facet: the category's, the brand's and the tag's.
	 *
	 * @since 0.2.0
	 *
	 * @var array{category: string, brand: string, tag: string}
	 */
	private array $taxonomies;

	/**
	 * Which currencies the store sells in.
	 *
	 * @since 0.2.0
	 *
	 * @var CurrencyTerms
	 */
	private CurrencyTerms $terms;

	/**
	 * Returns the store's base currency.
	 *
	 * @since 0.2.0
	 *
	 * @var callable(): Currency
	 */
	private $baseCurrency;

	/**
	 * Creates the listing. Sends nothing.
	 *
	 * @since 0.2.0
	 *
	 * @param CatalogSearch $search     The search.
	 * @param ListingFacts  $facts      The facts of the page's products.
	 * @param PostLocales   $locales    The locales the site publishes in.
	 * @param string[]      $taxonomies The taxonomy of each term facet, keyed by CatalogSearch::FACET_CATEGORY,
	 *                                  FACET_BRAND and FACET_TAG: the product taxonomies.
	 * @param CurrencyTerms $terms        Which currencies the store sells in.
	 * @param callable      $baseCurrency Returns the store's base currency (Currency).
	 *
	 * @phpstan-param array{category: string, brand: string, tag: string} $taxonomies
	 * @phpstan-param callable(): Currency                                $baseCurrency
	 */
	public function __construct( CatalogSearch $search, ListingFacts $facts, PostLocales $locales, array $taxonomies, CurrencyTerms $terms, callable $baseCurrency ) {
		$this->search       = $search;
		$this->facts        = $facts;
		$this->locales      = $locales;
		$this->taxonomies   = $taxonomies;
		$this->terms        = $terms;
		$this->baseCurrency = $baseCurrency;
	}

	/**
	 * Performs `catalog.list_products`: one page of the listing.
	 *
	 * @since 0.2.0
	 *
	 * @throws CodedException CatalogError::LocaleUnsupported, SearchInvalid, OrderUnavailable, PriceRangeInvalid, CurrencyUnsupported and TermNotFound, before anything is searched.
	 *
	 * @param array<string, mixed> $input The prepared input: locale, search, category, brand, tag, in_stock, orderby, currency, min_price_minor, max_price_minor, page and per_page.
	 * @param Actor                $actor Who reads; every reader is shown the same page.
	 * @return array{items: list<array<string, mixed>>, page: int, per_page: int, has_more: bool} The page, keyed by wire name.
	 */
	public function list( array $input, Actor $actor ): array {
		unset( $actor );

		$locale  = $this->locale( (string) ( $input['locale'] ?? '' ) );
		$words   = self::words( (string) ( $input['search'] ?? '' ) );
		$orderBy = (string) ( $input['orderby'] ?? SearchQuery::NEWEST );

		if ( SearchQuery::RELEVANCE === $orderBy && array() === $words ) {
			CodedException::raise( CatalogError::OrderUnavailable, array( 'orderby' => $orderBy ) );
		}

		$range   = self::priceRange( $input );
		$given   = (string) ( $input['currency'] ?? '' );
		$money   = self::pricesAsked( $orderBy, $range, $given ) ? $this->currency( $given ) : null;
		$terms   = $this->terms( $input );
		$page    = (int) ( $input['page'] ?? 1 );
		$perPage = (int) ( $input['per_page'] ?? 1 );
		$found   = $this->search->search(
			new SearchQuery(
				$locale,
				$page,
				$perPage,
				$orderBy,
				$words,
				$terms[ CatalogSearch::FACET_CATEGORY ],
				$terms[ CatalogSearch::FACET_BRAND ][0] ?? null,
				$terms[ CatalogSearch::FACET_TAG ][0] ?? null,
				(bool) ( $input['in_stock'] ?? false ),
				$money,
				$range[0],
				$range[1]
			)
		);

		return array(
			'items'    => $this->items( $found->postIds ),
			'page'     => $page,
			'per_page' => $perPage,
			'has_more' => $found->hasMore,
		);
	}

	/**
	 * Returns the locale asked for, or the site's when none is.
	 *
	 * @since 0.2.0
	 *
	 * @throws CodedException CatalogError::LocaleUnsupported for a locale the site does not publish in, or no locale at all.
	 *
	 * @param string $given The locale given, or an empty string.
	 * @return Locale The locale.
	 */
	private function locale( string $given ): Locale {
		if ( '' === $given ) {
			return $this->locales->siteLocale();
		}

		try {
			$locale = Locale::of( $given );
		} catch ( \InvalidArgumentException $notALocale ) {
			CodedException::raise( CatalogError::LocaleUnsupported, array( 'locale' => $given ) );
		}

		if ( ! $this->locales->publishesIn( $locale ) ) {
			CodedException::raise( CatalogError::LocaleUnsupported, array( 'locale' => $given ) );
		}

		return $locale;
	}

	/**
	 * Tells whether the request asks anything of prices: a price order, a price range, or a currency.
	 *
	 * Only such a request reads the currency, since the store's base currency is a setting, and
	 * reading it is one statement on a cold request: a listing that asks nothing of prices costs
	 * none of it.
	 *
	 * @since 0.2.0
	 *
	 * @param string                          $orderBy The order asked for.
	 * @param array{0: int|null, 1: int|null} $range   The price range asked for.
	 * @param string                          $given   The currency's code asked for, or an empty string.
	 * @return bool True when the request needs its currency.
	 */
	private static function pricesAsked( string $orderBy, array $range, string $given ): bool {
		return in_array( $orderBy, array( SearchQuery::PRICE_ASC, SearchQuery::PRICE_DESC ), true ) || null !== $range[0] || null !== $range[1] || '' !== $given;
	}

	/**
	 * Returns the currency asked for, or the store's base currency when none is.
	 *
	 * The base currency is a setting, read once: one statement on a cold request. It is offered
	 * without a further read; another currency is asked of the currency terms, with one read of its
	 * terms.
	 *
	 * @since 0.2.0
	 *
	 * @throws CodedException CatalogError::CurrencyUnsupported for a code that is no currency, or a currency the store does not sell in.
	 *
	 * @param string $given The currency's code, or an empty string.
	 * @return Currency The currency.
	 */
	private function currency( string $given ): Currency {
		$base = ( $this->baseCurrency )();

		if ( '' === $given ) {
			return $base;
		}

		try {
			$currency = Currency::of( $given );
		} catch ( CodedException $notACurrency ) {
			// Not an ISO 4217 code the store knows: a currency it cannot sell in.
			CodedException::raise( CatalogError::CurrencyUnsupported, array( 'currency' => $given ) );
		}

		if ( ! $currency->equals( $base ) && ! $this->terms->isOffered( $currency ) ) {
			CodedException::raise( CatalogError::CurrencyUnsupported, array( 'currency' => $given ) );
		}

		return $currency;
	}

	/**
	 * Returns the price range asked for: its lowest and its highest price, each null when not given.
	 *
	 * @since 0.2.0
	 *
	 * @throws CodedException CatalogError::PriceRangeInvalid when the lowest price is above the highest.
	 *
	 * @param array<string, mixed> $input The prepared input.
	 * @return array{0: int|null, 1: int|null} The lowest and the highest price, in the currency's minor units.
	 */
	private static function priceRange( array $input ): array {
		$lowest  = isset( $input['min_price_minor'] ) ? (int) $input['min_price_minor'] : null;
		$highest = isset( $input['max_price_minor'] ) ? (int) $input['max_price_minor'] : null;

		if ( null !== $lowest && null !== $highest && $lowest > $highest ) {
			CodedException::raise(
				CatalogError::PriceRangeInvalid,
				array(
					'min_price_minor' => $lowest,
					'max_price_minor' => $highest,
				)
			);
		}

		return array( $lowest, $highest );
	}

	/**
	 * Splits a search into its words.
	 *
	 * @since 0.2.0
	 *
	 * @throws CodedException CatalogError::SearchInvalid for a search that is not valid UTF-8 text, or holds more words than a search may.
	 *
	 * @param string $search The search, as typed.
	 * @return list<string> The words, in order; none for an empty search.
	 */
	private static function words( string $search ): array {
		// preg_split() fails on text that is not valid UTF-8: such a search is refused, never read as no words.
		$words = preg_split( '/\s+/u', trim( $search ), -1, PREG_SPLIT_NO_EMPTY );

		if ( false === $words || count( $words ) > SearchQuery::MOST_WORDS ) {
			CodedException::raise( CatalogError::SearchInvalid, array( 'most' => SearchQuery::MOST_WORDS ) );
		}

		return $words;
	}

	/**
	 * Resolves the category, brand and tag slugs to term-taxonomy ids, the category with its descendants.
	 *
	 * @since 0.2.0
	 *
	 * @throws CodedException CatalogError::TermNotFound for a slug no term of its taxonomy has.
	 *
	 * @param array<string, mixed> $input The prepared input.
	 * @return array<string, list<int>> By facet: the term-taxonomy ids, none when the filter is not asked for.
	 */
	private function terms( array $input ): array {
		$taxonomies = $this->taxonomies;
		$resolved   = array_fill_keys( array_keys( $taxonomies ), array() );
		$slugs      = array();

		foreach ( array_keys( $taxonomies ) as $facet ) {
			$slug = sanitize_title( (string) ( $input[ $facet ] ?? '' ) );

			if ( '' !== $slug ) {
				$slugs[ $facet ] = $slug;
			}
		}

		if ( array() === $slugs ) {
			return $resolved;
		}

		$found = get_terms(
			array(
				'taxonomy'               => array_values( array_intersect_key( $taxonomies, $slugs ) ),
				'slug'                   => array_values( array_unique( $slugs ) ),
				'hide_empty'             => false,
				'update_term_meta_cache' => false,
			)
		);
		$found = is_array( $found ) ? $found : array();

		foreach ( $slugs as $facet => $slug ) {
			$term = self::termOf( $found, $taxonomies[ $facet ], $slug );

			if ( null === $term ) {
				CodedException::raise(
					CatalogError::TermNotFound,
					array(
						'filter' => $facet,
						'slug'   => $slug,
					)
				);
			}

			$resolved[ $facet ] = array( (int) $term->term_taxonomy_id );

			if ( CatalogSearch::FACET_CATEGORY === $facet ) {
				$resolved[ $facet ] = array_merge( $resolved[ $facet ], self::descendants( $taxonomies[ $facet ], (int) $term->term_id ) );
			}
		}

		return $resolved;
	}

	/**
	 * Returns the term of a taxonomy with a slug, among the terms found.
	 *
	 * @since 0.2.0
	 *
	 * @param array<int|string, mixed> $found    What get_terms() returned.
	 * @param string                   $taxonomy The taxonomy.
	 * @param string                   $slug     The slug.
	 * @return \WP_Term|null The term, or null when none was found.
	 */
	private static function termOf( array $found, string $taxonomy, string $slug ): ?\WP_Term {
		foreach ( $found as $term ) {
			if ( $term instanceof \WP_Term && $taxonomy === $term->taxonomy && $slug === $term->slug ) {
				return $term;
			}
		}

		return null;
	}

	/**
	 * Returns every descendant of a category, as term-taxonomy ids.
	 *
	 * @since 0.2.0
	 *
	 * @param string $taxonomy The category taxonomy.
	 * @param int    $termId   The category's term id.
	 * @return list<int> The descendants' term-taxonomy ids; none for a category without children.
	 */
	private static function descendants( string $taxonomy, int $termId ): array {
		$children = get_terms(
			array(
				'taxonomy'               => $taxonomy,
				'child_of'               => $termId,
				'fields'                 => 'tt_ids',
				'hide_empty'             => false,
				'update_term_meta_cache' => false,
			)
		);

		return is_array( $children ) ? array_map( 'intval', $children ) : array();
	}

	/**
	 * Shapes a page's items, reading each kind of thing once for the whole page.
	 *
	 * @since 0.2.0
	 *
	 * @param int[] $postIds The page's product posts, in order.
	 * @return list<array<string, mixed>> The items, keyed by wire name, in the same order; a post
	 *         deleted or no longer published since the search is left out.
	 *
	 * @phpstan-param list<int> $postIds
	 */
	private function items( array $postIds ): array {
		if ( array() === $postIds ) {
			return array();
		}

		// The posts, their terms (a multilingual plugin's permalinks read them) and their meta, which holds the featured image.
		_prime_post_caches( $postIds, true, true );

		$facts  = $this->facts->of( $postIds );
		$images = array();

		foreach ( $postIds as $postId ) {
			$image = (int) get_post_thumbnail_id( $postId );

			if ( $image > 0 ) {
				$images[ $postId ] = $image;
			}
		}

		if ( array() !== $images ) {
			// The images' posts and meta, which hold their files, sizes and alternative text.
			_prime_post_caches( array_values( array_unique( $images ) ), false, true );
		}

		$items = array();

		foreach ( $postIds as $postId ) {
			$post = get_post( $postId );
			$fact = $facts[ $postId ] ?? null;

			// The post is read after the search: one made private, drafted or trashed since the search found it is left out.
			if ( ! $post instanceof \WP_Post || 'publish' !== $post->post_status || null === $fact ) {
				continue;
			}

			$items[] = array(
				'post_id'      => $postId,
				'product_id'   => $fact['product_id'],
				'product_uuid' => $fact['product_uuid'],
				'variant_id'   => $fact['variant_id'],
				'sku'          => $fact['sku'],
				'title'        => get_the_title( $post ),
				'permalink'    => (string) get_permalink( $post ),
				'image'        => isset( $images[ $postId ] ) ? self::image( $images[ $postId ] ) : null,
				'availability' => $fact['stock_summary'],
			);
		}

		return $items;
	}

	/**
	 * Describes a featured image at the `medium` size.
	 *
	 * @since 0.2.0
	 *
	 * @param int $attachmentId The image.
	 * @return array{id: int, url: string, alt: string}|null The image, or null when it has no file to show.
	 */
	private static function image( int $attachmentId ): ?array {
		$source = wp_get_attachment_image_src( $attachmentId, 'medium' );

		if ( false === $source ) {
			return null;
		}

		return array(
			'id'  => $attachmentId,
			'url' => (string) $source[0],
			'alt' => trim( wp_strip_all_tags( (string) get_post_meta( $attachmentId, '_wp_attachment_image_alt', true ) ) ),
		);
	}
}
