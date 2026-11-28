<?php
/**
 * ProductPriceBlock: renders the `seocart/product-price` block
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
 * Renders the `seocart/product-price` block: the price of the variant the page selects.
 *
 * An adapter: ProductBlocks finds the product in the block's context and prints its state, and the
 * component writes the markup. A shopper does not act on it, so it loads no script of its own: beside the picker, the picker's
 * script follows the selection for it too.
 *
 * @since 0.2.0
 */
final class ProductPriceBlock implements BlockRenderer {

	/**
	 * Renders the product blocks.
	 *
	 * @since 0.2.0
	 *
	 * @var ProductBlocks
	 */
	private ProductBlocks $blocks;

	/**
	 * Creates the renderer. Reads nothing.
	 *
	 * @since 0.2.0
	 *
	 * @param ProductBlocks $blocks Renders the product blocks.
	 */
	public function __construct( ProductBlocks $blocks ) {
		$this->blocks = $blocks;
	}

	/**
	 * Returns the block's markup.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $attributes The block's attributes: none.
	 * @param string               $content    The block's saved inner content: none.
	 * @param \WP_Block            $block      The block, with the post in its context.
	 * @return string The markup, escaped; empty where there is no product.
	 */
	public function render( array $attributes, string $content, \WP_Block $block ): string {
		unset( $attributes, $content );

		return $this->blocks->render( $block, false, static fn( int $postId, array $product, array $strings ): string => ProductPrice::markup( $postId, $product, $strings ) );
	}
}
