<?php
/**
 * ProductBlocks: which product a product block shows
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Catalog\Interfaces\Storefront;

use SEOCart\Catalog\Application\Query\ProductPage;
use SEOCart\Catalog\Application\Query\ProductPageView;
use SEOCart\Platform\Authorization\ProductCapabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Answers, for a product block as it renders, the product it shows: the post in its context, when that post is a product's.
 *
 * Owns one fact: where a product block finds its product. A block placed in a product's content,
 * or in the template a product's page is rendered with, has the post in its context; any other
 * post, or none, shows no product, and nothing is read for it. The product blocks of one page
 * share this, and through it the one read of the product a request makes.
 *
 * @since 0.2.0
 */
final class ProductBlocks {

	/**
	 * Reads a product as a page shows it, once a request.
	 *
	 * @since 0.2.0
	 *
	 * @var ProductPage
	 */
	private ProductPage $page;

	/**
	 * Creates the lookup. Reads nothing.
	 *
	 * @since 0.2.0
	 *
	 * @param ProductPage $page Reads a product as a page shows it.
	 */
	public function __construct( ProductPage $page ) {
		$this->page = $page;
	}

	/**
	 * Returns the product a block shows.
	 *
	 * @since 0.2.0
	 *
	 * @param \WP_Block $block The block, with the post in its context.
	 * @return ProductPageView|null The product as the page shows it, or null when the post in the context is not a product's, or there is none.
	 */
	public function view( \WP_Block $block ): ?ProductPageView {
		if ( ProductCapabilities::POST_TYPE !== ( $block->context['postType'] ?? null ) ) {
			return null;
		}

		return $this->page->of( (int) ( $block->context['postId'] ?? 0 ) );
	}
}
