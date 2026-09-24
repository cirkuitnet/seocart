<?php
/**
 * ProductPostType: the registration of the `seocart_product` post type
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Catalog\Infrastructure;

use SEOCart\Platform\Authorization\ProductCapabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the post type that gives each product its editorial face: title, content, excerpt, image, permalink.
 *
 * Owns one fact: the post type's registration arguments. Its name and its capabilities come from
 * ProductCapabilities, merged in unchanged, so neither is restated here. It is public and shown
 * in the REST API at `wp/v2/seocart-products`, where the block editor, autosaves, revisions and
 * other plugins' REST fields expect it. It supports no custom fields, so its editor offers no box
 * where commerce data could be written to post meta; it is left out of WordPress's export, which
 * would carry the post without its variants, prices and stock; and deleting a user deletes none
 * of it.
 *
 * register() runs on every request's `init`: it only fills WordPress's in-memory registry, and
 * loads this file and ProductCapabilities. Rewrite rules are flushed when the plugin is installed
 * on a site, never here.
 *
 * @since 0.1.0
 */
final class ProductPostType {

	/**
	 * The REST base: the post type's endpoint is `wp/v2/seocart-products`.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const REST_BASE = 'seocart-products';

	/**
	 * The features the editor offers: no `custom-fields`, so commerce data never goes to post meta.
	 *
	 * @since 0.1.0
	 *
	 * @var list<string>
	 */
	public const SUPPORTS = array( 'title', 'editor', 'excerpt', 'thumbnail', 'revisions', 'author' );

	/**
	 * Registers the post type. Hooked to `init`.
	 *
	 * @since 0.1.0
	 */
	public static function register(): void {
		register_post_type( ProductCapabilities::POST_TYPE, self::arguments() );
	}

	/**
	 * Returns the arguments register_post_type() is given, the capability arguments merged in.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed> The arguments.
	 */
	public static function arguments(): array {
		return array_merge(
			array(
				'labels'           => self::labels(),
				'description'      => __( 'The products your store sells.', 'seocart' ),
				'public'           => true,
				'hierarchical'     => false,
				'show_in_rest'     => true,
				'rest_base'        => self::REST_BASE,
				'menu_icon'        => 'dashicons-products',
				'supports'         => self::SUPPORTS,
				'has_archive'      => true,
				'can_export'       => false,
				'delete_with_user' => false,
			),
			ProductCapabilities::registrationArguments()
		);
	}

	/**
	 * Returns the post type's labels, translated.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, string> The labels.
	 */
	private static function labels(): array {
		return array(
			'name'                     => _x( 'Products', 'post type general name', 'seocart' ),
			'singular_name'            => _x( 'Product', 'post type singular name', 'seocart' ),
			'add_new'                  => _x( 'Add Product', 'product', 'seocart' ),
			'add_new_item'             => __( 'Add Product', 'seocart' ),
			'edit_item'                => __( 'Edit Product', 'seocart' ),
			'new_item'                 => __( 'New Product', 'seocart' ),
			'view_item'                => __( 'View Product', 'seocart' ),
			'view_items'               => __( 'View Products', 'seocart' ),
			'search_items'             => __( 'Search Products', 'seocart' ),
			'not_found'                => __( 'No products found.', 'seocart' ),
			'not_found_in_trash'       => __( 'No products found in Trash.', 'seocart' ),
			'all_items'                => __( 'All Products', 'seocart' ),
			'archives'                 => __( 'Product Archives', 'seocart' ),
			'insert_into_item'         => __( 'Insert into product', 'seocart' ),
			'uploaded_to_this_item'    => __( 'Uploaded to this product', 'seocart' ),
			'filter_items_list'        => __( 'Filter products list', 'seocart' ),
			'items_list_navigation'    => __( 'Products list navigation', 'seocart' ),
			'items_list'               => __( 'Products list', 'seocart' ),
			'item_published'           => __( 'Product published.', 'seocart' ),
			'item_published_privately' => __( 'Product published privately.', 'seocart' ),
			'item_reverted_to_draft'   => __( 'Product reverted to draft.', 'seocart' ),
			'item_scheduled'           => __( 'Product scheduled.', 'seocart' ),
			'item_updated'             => __( 'Product updated.', 'seocart' ),
			'item_link'                => _x( 'Product Link', 'navigation link block title', 'seocart' ),
			'item_link_description'    => _x( 'A link to a product.', 'navigation link block description', 'seocart' ),
		);
	}
}
