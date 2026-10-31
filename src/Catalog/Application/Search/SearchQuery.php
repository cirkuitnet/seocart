<?php
/**
 * SearchQuery: one page of the product listing, as the listing asks the search for it
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Catalog\Application\Search;

use SEOCart\Support\Locale;

defined( 'ABSPATH' ) || exit;

/**
 * What a listing asks CatalogSearch for: the locale, the page, the order, the words and the facets.
 *
 * Owns one fact: the shape of a search, once its input is resolved. The facets are term-taxonomy
 * ids, so the search needs no term lookup of its own: a category comes with every descendant, and
 * an empty list means no category filter. The words are the shopper's, as typed, each matched on
 * its own; how a word is matched is the adapter's. Building one checks only what no adapter could
 * honour: a page before the first, an empty page, an order the listing does not offer, too many
 * words.
 *
 * @since 0.2.0
 */
final readonly class SearchQuery {

	/**
	 * The one order offered: the newest product post first.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const NEWEST = 'newest';

	/**
	 * The most words a search may hold.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	public const MOST_WORDS = 3;

	/**
	 * Describes the search.
	 *
	 * @since 0.2.0
	 *
	 * @throws \InvalidArgumentException When the page is before the first, the page holds no item,
	 *                                   the order is not NEWEST, or there are more than MOST_WORDS words.
	 *
	 * @param Locale   $locale                  The locale whose product posts are listed.
	 * @param int      $page                    The page, from 1.
	 * @param int      $perPage                 How many products a page holds, at least 1.
	 * @param string   $orderBy                 The order: NEWEST.
	 * @param string[] $words                   The search words, each non-empty; none for no search.
	 * @param int[]    $categoryTermTaxonomyIds The category and its descendants, as term-taxonomy ids; none for no category filter.
	 * @param int|null $brandTermTaxonomyId     The brand, as a term-taxonomy id; null for no brand filter.
	 * @param int|null $tagTermTaxonomyId       The tag, as a term-taxonomy id; null for no tag filter.
	 * @param bool     $inStock                 Whether to leave out every product not in stock.
	 *
	 * @phpstan-param list<string> $words
	 * @phpstan-param list<int>    $categoryTermTaxonomyIds
	 */
	public function __construct(
		public Locale $locale,
		public int $page,
		public int $perPage,
		public string $orderBy,
		public array $words,
		public array $categoryTermTaxonomyIds,
		public ?int $brandTermTaxonomyId,
		public ?int $tagTermTaxonomyId,
		public bool $inStock
	) {
		if ( $page < 1 || $perPage < 1 ) {
			throw new \InvalidArgumentException( 'A search asks for a page from 1, of at least one product.' );
		}

		if ( self::NEWEST !== $orderBy ) {
			throw new \InvalidArgumentException( sprintf( 'A search is ordered %s; %s is not an order the listing offers.', self::NEWEST, $orderBy ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- A programming error for the developer, never HTML.
		}

		if ( count( $words ) > self::MOST_WORDS ) {
			throw new \InvalidArgumentException( sprintf( 'A search holds at most %d words.', self::MOST_WORDS ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- A programming error for the developer, never HTML.
		}
	}

	/**
	 * Returns how many products come before the page.
	 *
	 * @since 0.2.0
	 *
	 * @return int The offset.
	 */
	public function offset(): int {
		return ( $this->page - 1 ) * $this->perPage;
	}
}
