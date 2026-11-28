<?php
/**
 * VariantPickerBlock: renders the `seocart/variant-picker` block
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
 * Renders the `seocart/variant-picker` block: the product's options.
 *
 * @since 0.2.0
 */
final class VariantPickerBlock implements BlockRenderer {

	/**
	 * Returns the block's markup: nothing yet.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $attributes The block's attributes.
	 * @param string               $content    The block's saved inner content.
	 * @param \WP_Block            $block      The block.
	 * @return string The markup.
	 */
	public function render( array $attributes, string $content, \WP_Block $block ): string {
		unset( $attributes, $content, $block );

		return '';
	}
}
