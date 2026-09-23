<?php
/**
 * ProductCapabilities: the capability arguments of the product post type, as data
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Authorization;

defined( 'ABSPATH' ) || exit;

/**
 * Names the capabilities of the `seocart_product` post type and maps core's keys onto them.
 *
 * This class owns one fact: which capability each post-type capability key of the product
 * post type resolves to. The catalog module passes registrationArguments() to
 * register_post_type() unchanged, so the registration restates nothing, and
 * CapabilityDeclaration takes the post type's primitives from here, so the capability
 * vocabulary restates nothing either.
 *
 * The map is complete: it names every key that core's get_post_type_capabilities() produces
 * for a post type registered with `map_meta_cap => true`, so no capability of the post type
 * falls back to a default that nobody declared. The flag is not optional. Core sets it to
 * false when a custom capabilities array arrives without it, and map_meta_cap() then collapses
 * `edit_post` into one primitive, with no owner, published, private or trashed distinction.
 *
 * Three keys are meta capabilities: `edit_post`, `read_post` and `delete_post`. Core maps them
 * per post, through this map, before the plugin's map_meta_cap callback sees the check, and
 * they are never granted to a role. `read` stays core's own primitive: a published product may
 * be read by anyone who may read a published post. Core maps them through the type of whichever
 * post the check names, so the Authorizer checks them only on a post of POST_TYPE.
 *
 * Nothing here calls WordPress, so the whole map is built and tested without it.
 *
 * @since 0.1.0
 */
final class ProductCapabilities {

	/**
	 * The name of the product post type these capabilities belong to.
	 *
	 * The catalog module registers the post type under this name; the Authorizer checks the
	 * product meta capabilities only on posts of this type.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const POST_TYPE = 'seocart_product';

	/**
	 * The singular capability base: meta capabilities are named after it.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const SINGULAR_BASE = 'seocart_product';

	/**
	 * The plural capability base: primitive capabilities are named after it.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const PLURAL_BASE = 'seocart_products';

	/**
	 * The keys of the map that name meta capabilities, which core maps per post.
	 *
	 * @since 0.1.0
	 *
	 * @var list<string>
	 */
	private const META_KEYS = array( 'edit_post', 'read_post', 'delete_post' );

	/**
	 * Core's own primitive for reading published content. It is core's, not the plugin's.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const CORE_READ = 'read';

	/**
	 * Returns the capability arguments for register_post_type(), to be merged into the others.
	 *
	 * @since 0.1.0
	 *
	 * @return array{capability_type: array{string, string}, map_meta_cap: bool, capabilities: array<string, string>} The arguments.
	 */
	public static function registrationArguments(): array {
		return array(
			'capability_type' => self::capabilityType(),
			'map_meta_cap'    => true,
			'capabilities'    => self::map(),
		);
	}

	/**
	 * Returns the singular and plural capability bases, in the order core expects them.
	 *
	 * @since 0.1.0
	 *
	 * @return array{string, string} The singular base, then the plural base.
	 */
	public static function capabilityType(): array {
		return array( self::SINGULAR_BASE, self::PLURAL_BASE );
	}

	/**
	 * Returns the complete capability map: core's post-type capability key => capability.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, string> The map, meta capabilities first.
	 */
	public static function map(): array {
		return array(
			'edit_post'              => 'edit_' . self::SINGULAR_BASE,
			'read_post'              => 'read_' . self::SINGULAR_BASE,
			'delete_post'            => 'delete_' . self::SINGULAR_BASE,
			'edit_posts'             => 'edit_' . self::PLURAL_BASE,
			'edit_others_posts'      => 'edit_others_' . self::PLURAL_BASE,
			'edit_published_posts'   => 'edit_published_' . self::PLURAL_BASE,
			'edit_private_posts'     => 'edit_private_' . self::PLURAL_BASE,
			'publish_posts'          => 'publish_' . self::PLURAL_BASE,
			'read_private_posts'     => 'read_private_' . self::PLURAL_BASE,
			'delete_posts'           => 'delete_' . self::PLURAL_BASE,
			'delete_others_posts'    => 'delete_others_' . self::PLURAL_BASE,
			'delete_published_posts' => 'delete_published_' . self::PLURAL_BASE,
			'delete_private_posts'   => 'delete_private_' . self::PLURAL_BASE,
			'create_posts'           => 'edit_' . self::PLURAL_BASE,
			'read'                   => self::CORE_READ,
		);
	}

	/**
	 * Returns the plugin primitives the map resolves to, each once.
	 *
	 * `create_posts` shares its primitive with `edit_posts`, and core's `read` is not the
	 * plugin's, so neither adds an entry.
	 *
	 * @since 0.1.0
	 *
	 * @return list<string> The primitives, in map order.
	 */
	public static function primitives(): array {
		$primitives = array();

		foreach ( self::map() as $key => $capability ) {
			if ( ! in_array( $key, self::META_KEYS, true ) && self::CORE_READ !== $capability ) {
				$primitives[ $capability ] = true;
			}
		}

		return array_keys( $primitives );
	}

	/**
	 * Returns the meta capabilities of the post type, which core maps per post.
	 *
	 * @since 0.1.0
	 *
	 * @return list<string> The meta capabilities, in map order.
	 */
	public static function metaCapabilities(): array {
		$map  = self::map();
		$meta = array();

		foreach ( self::META_KEYS as $key ) {
			$meta[] = $map[ $key ];
		}

		return $meta;
	}
}
