<?php
/**
 * RegistersProductType: the product post type registered from its capability arguments alone, and put back after the test
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support;

use SEOCart\Catalog\Infrastructure\ProductPostType;
use SEOCart\Platform\Authorization\ProductCapabilities;

/**
 * Registers `seocart_product` for a test from ProductCapabilities alone, and leaves the site's registration as it found it.
 *
 * Owns one fact: what a test that registers its own product post type must put back. The test
 * registers the type from its capability arguments only, so that it checks the capabilities
 * without the rest of the catalog's registration. Afterwards the type is unregistered and, when
 * it was registered before the test, as the kernel registers it on `init`, registered again
 * through the plugin's own registration, so the tests that follow find it. Registering a type with
 * `map_meta_cap => true` also records its meta capabilities in core's `$post_type_meta_caps`,
 * which unregistering leaves behind, so that global is restored too.
 *
 * The test calls registerProductType() from its set_up() and restoreProductType() from its
 * tear_down().
 *
 * @since 0.1.0
 */
trait RegistersProductType {

	/**
	 * Whether the product post type was registered before the test registered its own.
	 *
	 * @since 0.1.0
	 *
	 * @var bool
	 */
	private bool $productTypeRegisteredBefore = false;

	/**
	 * Core's record of custom meta capabilities, as it was before the test.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, string>|null
	 */
	private ?array $metaCapabilitiesBefore = null;

	/**
	 * Registers the product post type from its capability arguments, not public, after remembering what it replaces.
	 *
	 * @since 0.1.0
	 */
	protected function registerProductType(): void {
		global $post_type_meta_caps;

		$this->productTypeRegisteredBefore = post_type_exists( ProductCapabilities::POST_TYPE );
		$this->metaCapabilitiesBefore      = is_array( $post_type_meta_caps ) ? $post_type_meta_caps : null;

		register_post_type( ProductCapabilities::POST_TYPE, array_merge( array( 'public' => false ), ProductCapabilities::registrationArguments() ) );
	}

	/**
	 * Unregisters the test's product post type, registers the plugin's again when there was one, and restores core's meta capabilities.
	 *
	 * @since 0.1.0
	 */
	protected function restoreProductType(): void {
		global $post_type_meta_caps;

		unregister_post_type( ProductCapabilities::POST_TYPE );

		if ( $this->productTypeRegisteredBefore ) {
			ProductPostType::register();
		}

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- core's global, restored to its state before the test registered a post type.
		$post_type_meta_caps = $this->metaCapabilitiesBefore;
	}
}
