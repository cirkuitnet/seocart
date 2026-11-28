<?php
/**
 * Blocks: registers the storefront's blocks with WordPress
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Storefront;

use SEOCart\Platform\Kernel\Container;

defined( 'ABSPATH' ) || exit;

/**
 * Registers each storefront block from its block.json file, with a render callback that builds the block's renderer only when the block renders.
 *
 * Owns one fact: how a storefront block reaches WordPress's registry. A block is a block.json file
 * in a folder of its own under blocks/, beside this class, named after the block without its
 * namespace (`seocart/product-price` is blocks/product-price/block.json), and a renderer. The file
 * names no script, module, stylesheet or render file: the renderer enqueues what its block needs
 * through StorefrontAssets while the block renders, so a page that renders no storefront block
 * loads none of the plugin's files. Nothing in it is compiled, so a checkout without a build
 * registers the same blocks as the plugin's zip.
 *
 * Every block declares `supports.autoRegister`, so the block editor offers it and previews it
 * through WordPress's own server render, and the plugin ships no editor script for it.
 *
 * Registering builds nothing: each callback resolves its renderer from the container when
 * WordPress renders the block, on a page or in the editor's preview. An idle request pays this
 * file, one hook and the read of each block.json file, and sends no query.
 *
 * @since 0.2.0
 */
final class Blocks {

	/**
	 * Registers the blocks. Called on `init`, on every kind of request.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, string> $blocks    Each block's name, with the class that renders it.
	 * @param Container             $container The container the renderers are resolved from, each the first time its block renders.
	 *
	 * @phpstan-param array<string, class-string<BlockRenderer>> $blocks
	 */
	public static function register( array $blocks, Container $container ): void {
		foreach ( $blocks as $name => $renderer ) {
			register_block_type(
				self::folder( $name ),
				array(
					'render_callback' => static fn( array $attributes, string $content, \WP_Block $block ): string => $container->get( $renderer )->render( $attributes, $content, $block ),
				)
			);
		}
	}

	/**
	 * Returns the folder that holds a block's block.json file.
	 *
	 * @since 0.2.0
	 *
	 * @param string $name The block's name, such as `seocart/product-price`.
	 * @return string The folder's absolute path, without a trailing slash.
	 */
	public static function folder( string $name ): string {
		return __DIR__ . '/blocks/' . substr( $name, (int) strpos( $name, '/' ) + 1 );
	}
}
