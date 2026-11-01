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

use SEOCart\Support\Currency;
use SEOCart\Support\Locale;

defined( 'ABSPATH' ) || exit;

/**
 * What a listing asks CatalogSearch for: the locale, the page, the order, the words, the facets and the price range.
 *
 * Owns one fact: the shape of a search, once its input is resolved. The facets are term-taxonomy
 * ids, so the search needs no term lookup of its own: a category comes with every descendant, and
 * an empty list means no category filter. The words are the shopper's, as typed, each matched on
 * its own; how a word is matched is the adapter's. The currency is the one a price order sorts in
 * and a price range is in, in its minor units; a search that asks nothing of prices has none. Building one checks only what no adapter could
 * honour: a page before the first, an empty page, an order the listing does not offer, too many
 * words, a relevance order without words, a negative bound or a range whose lowest price is above
 * its highest.
 *
 * @since 0.2.0
 */
final readonly class SearchQuery {

	/**
	 * The newest product post first.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const NEWEST = 'newest';

	/**
	 * The lowest price first, in the query's currency.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const PRICE_ASC = 'price_asc';

	/**
	 * The highest price first, in the query's currency.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const PRICE_DESC = 'price_desc';

	/**
	 * The product that matches the search's words best first; only for a search.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const RELEVANCE = 'relevance';

	/**
	 * The orders the listing offers, the default first.
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	public const ORDERS = array( self::NEWEST, self::PRICE_ASC, self::PRICE_DESC, self::RELEVANCE );

	/**
	 * The most words a search may hold.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	public const MOST_WORDS = 3;

	/**
	 * The shortest run of letters and digits a search by relevance keeps: the shortest word the servers index, by default.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	public const WORD_MIN = 3;

	/**
	 * The words a search by relevance leaves out: the words the servers never index, by default.
	 *
	 * InnoDB's default fulltext stopword list, the same on MySQL and MariaDB, each word once, in
	 * lower case. The index holds none of them, and a boolean search that requires one matches
	 * nothing, so a search that keeps one lists nothing. The server lowercases a word before it
	 * compares it with the list, but folds no accent. A host that names its own stopword table, or
	 * turns stopwords off, is not read: like WORD_MIN, the list is the host's default.
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	public const STOPWORDS = array( 'a', 'about', 'an', 'are', 'as', 'at', 'be', 'by', 'com', 'de', 'en', 'for', 'from', 'how', 'i', 'in', 'is', 'it', 'la', 'of', 'on', 'or', 'that', 'the', 'this', 'to', 'und', 'was', 'what', 'when', 'where', 'who', 'will', 'with', 'www' );

	/**
	 * Describes the search.
	 *
	 * @since 0.2.0
	 *
	 * @throws \InvalidArgumentException When the page is before the first, the page holds no item, the order is not one of
	 *                                   ORDERS, there are more than MOST_WORDS words, RELEVANCE has no word, a bound is
	 *                                   negative, the lowest price is above the highest, or a price order or a price range
	 *                                   has no currency.
	 *
	 * @param Locale        $locale                  The locale whose product posts are listed.
	 * @param int           $page                    The page, from 1.
	 * @param int           $perPage                 How many products a page holds, at least 1.
	 * @param string        $orderBy                 The order: one of ORDERS.
	 * @param string[]      $words                   The search words, each non-empty; none for no search.
	 * @param int[]         $categoryTermTaxonomyIds The category and its descendants, as term-taxonomy ids; none for no category filter.
	 * @param int|null      $brandTermTaxonomyId     The brand, as a term-taxonomy id; null for no brand filter.
	 * @param int|null      $tagTermTaxonomyId       The tag, as a term-taxonomy id; null for no tag filter.
	 * @param bool          $inStock                 Whether to leave out every product not in stock.
	 * @param Currency|null $currency           The currency a price order sorts in and the price range is in; null for a search that asks nothing of prices.
	 * @param int|null      $minPriceMinor           Optional. The lowest price listed, in the currency's minor units; null for none. Default null.
	 * @param int|null      $maxPriceMinor           Optional. The highest price listed, in the currency's minor units; null for none. Default null.
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
		public bool $inStock,
		public ?Currency $currency,
		public ?int $minPriceMinor = null,
		public ?int $maxPriceMinor = null
	) {
		if ( $page < 1 || $perPage < 1 ) {
			throw new \InvalidArgumentException( 'A search asks for a page from 1, of at least one product.' );
		}

		if ( ! in_array( $orderBy, self::ORDERS, true ) ) {
			throw new \InvalidArgumentException( sprintf( 'A search is ordered %s; %s is not an order the listing offers.', implode( ', ', self::ORDERS ), $orderBy ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- A programming error for the developer, never HTML.
		}

		if ( count( $words ) > self::MOST_WORDS ) {
			throw new \InvalidArgumentException( sprintf( 'A search holds at most %d words.', self::MOST_WORDS ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- A programming error for the developer, never HTML.
		}

		if ( self::RELEVANCE === $orderBy && array() === $words ) {
			throw new \InvalidArgumentException( 'A search is ordered by relevance only when it has words.' );
		}

		if ( null === $currency && ( in_array( $orderBy, array( self::PRICE_ASC, self::PRICE_DESC ), true ) || null !== $minPriceMinor || null !== $maxPriceMinor ) ) {
			throw new \InvalidArgumentException( 'A price order and a price range are in a currency.' );
		}

		if ( ( $minPriceMinor ?? 0 ) < 0 || ( $maxPriceMinor ?? 0 ) < 0 || ( null !== $minPriceMinor && null !== $maxPriceMinor && $minPriceMinor > $maxPriceMinor ) ) {
			throw new \InvalidArgumentException( 'A price range runs from a lowest price of 0 or more up to a highest price no lower than it.' );
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

	/**
	 * Returns the code of the search's currency.
	 *
	 * @since 0.2.0
	 *
	 * @return string The ISO 4217 code; empty for a search that asks nothing of prices.
	 */
	public function currencyCode(): string {
		return null === $this->currency ? '' : $this->currency->code();
	}

	/**
	 * Tells whether the search lists only products in a price range.
	 *
	 * @since 0.2.0
	 *
	 * @return bool True when a lowest or a highest price is given.
	 */
	public function hasPriceRange(): bool {
		return null !== $this->minPriceMinor || null !== $this->maxPriceMinor;
	}
}
