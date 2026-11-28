<?php
/**
 * AddToCartBlock: renders the `seocart/add-to-cart` block
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Catalog\Interfaces\Storefront;

use SEOCart\Platform\Storefront\BlockRenderer;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the `seocart/add-to-cart` block: the product's add-to-cart form.
 *
 * @since 0.2.0
 */
final class AddToCartBlock implements BlockRenderer {

	/**
	 * Finds the product the block shows.
	 *
	 * @since 0.2.0
	 *
	 * @var ProductBlocks
	 */
	private ProductBlocks $products;

	/**
	 * Creates the renderer. Reads nothing.
	 *
	 * @since 0.2.0
	 *
	 * @param ProductBlocks $products Finds the product the block shows.
	 */
	public function __construct( ProductBlocks $products ) {
		$this->products = $products;
	}

	/**
	 * Returns the block's markup: nothing yet; it reads the product it shows.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $attributes The block's attributes.
	 * @param string               $content    The block's saved inner content.
	 * @param \WP_Block            $block      The block, with the post in its context.
	 * @return string The markup.
	 */
	public function render( array $attributes, string $content, \WP_Block $block ): string {
		unset( $attributes, $content );

		$this->products->view( $block );

		return '';
	}
}
