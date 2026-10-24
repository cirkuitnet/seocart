<?php
/**
 * ProductTaxonomies: the registration of the product categories, brands and tags
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Catalog\Infrastructure;

use SEOCart\Platform\Authorization\ProductCapabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the three taxonomies products are organised by: categories, a tree, and brands and tags, each a flat list.
 *
 * Owns one fact: the names and registration arguments of the product taxonomies. The product
 * post type's registration registers them with the type on `init`; the installation's rewrite
 * flush, which can run in a request that passed `init` before the plugin was loaded, registers
 * them when they are missing; deactivation unregisters them before its flush. All of them read
 * this class, and so do the tests.
 *
 * Each taxonomy is public and shown in the REST API at `wp/v2/<rest base>`, served by
 * WordPress's own terms controller, so WordPress's term screens under the Products menu, the
 * block editor's panels, navigation menus, the sitemaps and SEO plugins work with them as they
 * do with core's. Each term has an archive page that the active theme renders, at
 * `product-category/<parent>/<child>/`, `product-brand/<slug>/` and `product-tag/<slug>/`,
 * whatever front the site's permalink structure gives its posts. Managing, editing and deleting
 * terms takes the catalog capability; assigning them to a product takes the capability to edit
 * products (ProductCapabilities::termCapabilities()). In the flat brands and tags, WordPress lets
 * whoever may assign a term create one too, as it does for post tags: its terms controller and
 * a post's `tax_input` create a flat term on the assigning capability. No taxonomy has a default
 * term, and the term counts are WordPress's own.
 *
 * register() only fills WordPress's in-memory registry. Rewrite rules are flushed when the
 * plugin is installed on a site, never here.
 *
 * @since 0.2.0
 */
final class ProductTaxonomies {

	/**
	 * The product categories: a tree, a product in any number of them.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const CATEGORY = 'seocart_category';

	/**
	 * The product brands: a flat list.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const BRAND = 'seocart_brand';

	/**
	 * The product tags: a flat list.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const TAG = 'seocart_tag';

	/**
	 * What sets each taxonomy apart: its shape, its REST base, the base of its archive URLs and where WordPress shows it.
	 *
	 * @since 0.2.0
	 *
	 * @var array<string, array{hierarchical: bool, rest_base: string, rewrite_slug: string, show_in_nav_menus: bool, show_admin_column: bool}>
	 */
	private const DEFINITIONS = array(
		self::CATEGORY => array(
			'hierarchical'      => true,
			'rest_base'         => 'seocart-categories',
			'rewrite_slug'      => 'product-category',
			'show_in_nav_menus' => true,
			'show_admin_column' => true,
		),
		self::BRAND    => array(
			'hierarchical'      => false,
			'rest_base'         => 'seocart-brands',
			'rewrite_slug'      => 'product-brand',
			'show_in_nav_menus' => true,
			'show_admin_column' => false,
		),
		self::TAG      => array(
			'hierarchical'      => false,
			'rest_base'         => 'seocart-tags',
			'rewrite_slug'      => 'product-tag',
			'show_in_nav_menus' => false,
			'show_admin_column' => false,
		),
	);

	/**
	 * Returns the names of the product taxonomies.
	 *
	 * @since 0.2.0
	 *
	 * @return list<string> The names: categories, brands, tags.
	 */
	public static function names(): array {
		return array_keys( self::DEFINITIONS );
	}

	/**
	 * Registers the three taxonomies on the product post type. Called by ProductPostType::register().
	 *
	 * @since 0.2.0
	 */
	public static function register(): void {
		foreach ( self::names() as $taxonomy ) {
			register_taxonomy( $taxonomy, ProductCapabilities::POST_TYPE, self::arguments( $taxonomy ) );
		}
	}

	/**
	 * Tells whether all three taxonomies are registered.
	 *
	 * @since 0.2.0
	 *
	 * @return bool True when WordPress knows every one of them.
	 */
	public static function registered(): bool {
		foreach ( self::names() as $taxonomy ) {
			if ( ! taxonomy_exists( $taxonomy ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Unregisters those of the three taxonomies that are registered, and with them their rewrite rules.
	 *
	 * Unregistering the product post type does not do it: WordPress only detaches a taxonomy
	 * from the type, and keeps the taxonomy and its archive rules.
	 *
	 * @since 0.2.0
	 */
	public static function unregister(): void {
		foreach ( self::names() as $taxonomy ) {
			if ( taxonomy_exists( $taxonomy ) ) {
				unregister_taxonomy( $taxonomy );
			}
		}
	}

	/**
	 * Returns the arguments register_taxonomy() is given for one of the three taxonomies.
	 *
	 * @since 0.2.0
	 *
	 * @throws \InvalidArgumentException When the taxonomy is not one of the three.
	 *
	 * @param string $taxonomy One of the names().
	 * @return array<string, mixed> The arguments.
	 */
	public static function arguments( string $taxonomy ): array {
		if ( ! isset( self::DEFINITIONS[ $taxonomy ] ) ) {
			throw new \InvalidArgumentException( 'Only a product taxonomy has registration arguments here.' );
		}

		$definition = self::DEFINITIONS[ $taxonomy ];

		return array(
			'labels'            => self::labels( $taxonomy ),
			'description'       => self::description( $taxonomy ),
			'public'            => true,
			'hierarchical'      => $definition['hierarchical'],
			'show_in_rest'      => true,
			'rest_base'         => $definition['rest_base'],
			'show_in_nav_menus' => $definition['show_in_nav_menus'],
			'show_admin_column' => $definition['show_admin_column'],
			'rewrite'           => array(
				'slug'         => $definition['rewrite_slug'],
				'with_front'   => false,
				'hierarchical' => $definition['hierarchical'],
			),
			'capabilities'      => ProductCapabilities::termCapabilities(),
		);
	}

	/**
	 * Returns a taxonomy's description, translated.
	 *
	 * @since 0.2.0
	 *
	 * @param string $taxonomy One of the names().
	 * @return string The description.
	 */
	private static function description( string $taxonomy ): string {
		return match ( $taxonomy ) {
			self::CATEGORY => __( 'The categories products are sorted into, arranged in a tree.', 'seocart' ),
			self::BRAND    => __( 'The brands of the products your store sells.', 'seocart' ),
			default        => __( 'Keywords that describe products.', 'seocart' ),
		};
	}

	/**
	 * Returns a taxonomy's labels, translated.
	 *
	 * The name says which content the taxonomy belongs to, as navigation menus and multilingual
	 * plugins list it beside the posts' own categories and tags; the menu name is the short one
	 * the Products menu and the block editor's panel show.
	 *
	 * @since 0.2.0
	 *
	 * @param string $taxonomy One of the names().
	 * @return array<string, string> The labels.
	 */
	private static function labels( string $taxonomy ): array {
		return match ( $taxonomy ) {
			self::CATEGORY => self::categoryLabels(),
			self::BRAND    => self::brandLabels(),
			default        => self::tagLabels(),
		};
	}

	/**
	 * Returns the product categories' labels, translated.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, string> The labels.
	 */
	private static function categoryLabels(): array {
		return array(
			'name'                  => _x( 'Product categories', 'taxonomy general name', 'seocart' ),
			'singular_name'         => _x( 'Product category', 'taxonomy singular name', 'seocart' ),
			'menu_name'             => _x( 'Categories', 'product categories admin menu', 'seocart' ),
			'search_items'          => __( 'Search Categories', 'seocart' ),
			'all_items'             => __( 'All Categories', 'seocart' ),
			'parent_item'           => __( 'Parent Category', 'seocart' ),
			'parent_item_colon'     => __( 'Parent Category:', 'seocart' ),
			'edit_item'             => __( 'Edit Category', 'seocart' ),
			'view_item'             => __( 'View Category', 'seocart' ),
			'update_item'           => __( 'Update Category', 'seocart' ),
			'add_new_item'          => __( 'Add Category', 'seocart' ),
			'new_item_name'         => __( 'New Category Name', 'seocart' ),
			'not_found'             => __( 'No categories found.', 'seocart' ),
			'no_terms'              => __( 'No categories', 'seocart' ),
			'filter_by_item'        => __( 'Filter by category', 'seocart' ),
			'items_list_navigation' => __( 'Categories list navigation', 'seocart' ),
			'items_list'            => __( 'Categories list', 'seocart' ),
			'back_to_items'         => __( '&larr; Go to Categories', 'seocart' ),
			'item_link'             => _x( 'Product Category Link', 'navigation link block title', 'seocart' ),
			'item_link_description' => _x( 'A link to a product category.', 'navigation link block description', 'seocart' ),
		);
	}

	/**
	 * Returns the product brands' labels, translated.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, string> The labels.
	 */
	private static function brandLabels(): array {
		return array(
			'name'                       => _x( 'Product brands', 'taxonomy general name', 'seocart' ),
			'singular_name'              => _x( 'Product brand', 'taxonomy singular name', 'seocart' ),
			'menu_name'                  => _x( 'Brands', 'product brands admin menu', 'seocart' ),
			'search_items'               => __( 'Search Brands', 'seocart' ),
			'popular_items'              => __( 'Popular Brands', 'seocart' ),
			'all_items'                  => __( 'All Brands', 'seocart' ),
			'edit_item'                  => __( 'Edit Brand', 'seocart' ),
			'view_item'                  => __( 'View Brand', 'seocart' ),
			'update_item'                => __( 'Update Brand', 'seocart' ),
			'add_new_item'               => __( 'Add Brand', 'seocart' ),
			'new_item_name'              => __( 'New Brand Name', 'seocart' ),
			'separate_items_with_commas' => __( 'Separate brands with commas', 'seocart' ),
			'add_or_remove_items'        => __( 'Add or remove brands', 'seocart' ),
			'choose_from_most_used'      => __( 'Choose from the most used brands', 'seocart' ),
			'not_found'                  => __( 'No brands found.', 'seocart' ),
			'no_terms'                   => __( 'No brands', 'seocart' ),
			'items_list_navigation'      => __( 'Brands list navigation', 'seocart' ),
			'items_list'                 => __( 'Brands list', 'seocart' ),
			'back_to_items'              => __( '&larr; Go to Brands', 'seocart' ),
			'item_link'                  => _x( 'Product Brand Link', 'navigation link block title', 'seocart' ),
			'item_link_description'      => _x( 'A link to a product brand.', 'navigation link block description', 'seocart' ),
		);
	}

	/**
	 * Returns the product tags' labels, translated.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, string> The labels.
	 */
	private static function tagLabels(): array {
		return array(
			'name'                       => _x( 'Product tags', 'taxonomy general name', 'seocart' ),
			'singular_name'              => _x( 'Product tag', 'taxonomy singular name', 'seocart' ),
			'menu_name'                  => _x( 'Tags', 'product tags admin menu', 'seocart' ),
			'search_items'               => __( 'Search Tags', 'seocart' ),
			'popular_items'              => __( 'Popular Tags', 'seocart' ),
			'all_items'                  => __( 'All Tags', 'seocart' ),
			'edit_item'                  => __( 'Edit Tag', 'seocart' ),
			'view_item'                  => __( 'View Tag', 'seocart' ),
			'update_item'                => __( 'Update Tag', 'seocart' ),
			'add_new_item'               => __( 'Add Tag', 'seocart' ),
			'new_item_name'              => __( 'New Tag Name', 'seocart' ),
			'separate_items_with_commas' => __( 'Separate tags with commas', 'seocart' ),
			'add_or_remove_items'        => __( 'Add or remove tags', 'seocart' ),
			'choose_from_most_used'      => __( 'Choose from the most used tags', 'seocart' ),
			'not_found'                  => __( 'No tags found.', 'seocart' ),
			'no_terms'                   => __( 'No tags', 'seocart' ),
			'items_list_navigation'      => __( 'Tags list navigation', 'seocart' ),
			'items_list'                 => __( 'Tags list', 'seocart' ),
			'back_to_items'              => __( '&larr; Go to Tags', 'seocart' ),
			'item_link'                  => _x( 'Product Tag Link', 'navigation link block title', 'seocart' ),
			'item_link_description'      => _x( 'A link to a product tag.', 'navigation link block description', 'seocart' ),
		);
	}
}
