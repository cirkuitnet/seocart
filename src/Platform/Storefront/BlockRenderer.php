<?php
/**
 * BlockRenderer: what renders one storefront block
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Storefront;

defined( 'ABSPATH' ) || exit;

/**
 * Renders one storefront block: the callback WordPress calls for it, resolved from the container when the block renders (Blocks).
 *
 * A renderer is an adapter. It reads the block's attributes and context, asks the application for
 * what to show, enqueues what the block needs through StorefrontAssets, and returns the markup a
 * pure component writes from explicit arguments.
 *
 * @since 0.2.0
 */
interface BlockRenderer {

	/**
	 * Returns the block's markup.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $attributes The block's attributes.
	 * @param string               $content    The block's saved inner content: empty, since no storefront block has any.
	 * @param \WP_Block            $block      The block, with the context it uses.
	 * @return string The markup, escaped; empty when the block has nothing to show.
	 */
	public function render( array $attributes, string $content, \WP_Block $block ): string;
}
