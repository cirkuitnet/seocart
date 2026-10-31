<?php
/**
 * SearchResults: the product posts of one page of the listing, and whether another follows
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Catalog\Application\Search;

defined( 'ABSPATH' ) || exit;

/**
 * What CatalogSearch answers: the ids of the page's product posts, in order, and whether another page follows.
 *
 * Owns one fact: a search's answer. It carries no total: counting every match would be a second
 * walk of them, and a listing pages by number.
 *
 * @since 0.2.0
 */
final readonly class SearchResults {

	/**
	 * Records the answer.
	 *
	 * @since 0.2.0
	 *
	 * @param int[] $postIds The page's product posts, in the order asked for.
	 * @param bool  $hasMore Whether at least one more product follows the page.
	 *
	 * @phpstan-param list<int> $postIds
	 */
	public function __construct(
		public array $postIds,
		public bool $hasMore
	) {
	}
}
