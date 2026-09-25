<?php
/**
 * Tests the product post type: how WordPress holds its registration, and when its rewrite rules are flushed
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Catalog;

use SEOCart\Catalog\Infrastructure\ProductPostType;
use SEOCart\Catalog\Interfaces\Rest\ProductPostsController;
use SEOCart\Platform\Authorization\ProductCapabilities;
use SEOCart\Platform\Kernel\Lifecycle;
use SEOCart\Tests\Support\KernelTestCase;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- The tests read and restore the stored rewrite rules as the database holds them.

/**
 * The kernel registers `seocart_product` on `init`, with the capability map merged in unchanged,
 * at `wp/v2/seocart-products` under core's own controller, exportable by nobody, without custom
 * fields. Installing the plugin on a site flushes the rewrite rules with the product permalinks,
 * registering the post type does not, and deactivating the plugin flushes them away.
 *
 * Planted violations, one at a time, each put back afterwards:
 *
 * - Add `'custom-fields'` to ProductPostType::SUPPORTS: the registration test fails.
 * - Add `flush_rewrite_rules( false );` at the end of ProductPostType::register(): registering
 *   the type rewrites the stored rules, and test_only_installing_the_plugin_flushes_the_rewrite_rules fails.
 * - Remove `$this->flushRewriteRules();` from Lifecycle::installSite(): the installed site has
 *   no rule for the product permalinks, and the same test fails.
 * - Remove `$this->flushRewriteRulesWithoutProducts();` from Lifecycle::deactivate(): the product
 *   rules survive deactivation, and test_deactivating_flushes_the_product_rules_away fails.
 * - In Lifecycle::flushRewriteRulesWithoutProducts(), remove the unregister_post_type() call: the
 *   flush writes the product rules again, and the same test fails.
 * - In ProductPostType::arguments(), drop `with_front => false` from the `rewrite` argument: under
 *   a structure with a front, test_permalinks_use_the_products_base_with_and_without_a_front fails.
 *
 * @since 0.1.0
 */
final class ProductPostTypeTest extends KernelTestCase {

	/**
	 * The permalink structure the flush test installs under.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const STRUCTURE = '/%postname%/';

	/**
	 * The rule WordPress writes for a product's permalink under that structure.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const PERMALINK_RULE = 'products/([^/]+)(?:/([0-9]+))?/?$';

	/**
	 * The rule WordPress writes for the product archive.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const ARCHIVE_RULE = 'products/?$';

	/**
	 * The stored rows of the rewrite options before the test, to put back; null for an option that did not exist.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, array<string, mixed>|null>
	 */
	private array $rewriteBefore = array();

	/**
	 * The rewrite rules WordPress held in memory before the test, to put back: a flush keeps the rules it generated there for the rest of the process.
	 *
	 * @since 0.1.0
	 *
	 * @var array{extra_rules_top: array<string, string>, extra_permastructs: array<string, array<string, mixed>>}
	 */
	private array $rulesInMemoryBefore = array(
		'extra_rules_top'    => array(),
		'extra_permastructs' => array(),
	);

	/**
	 * Remembers the stored rewrite options.
	 *
	 * @since 0.1.0
	 */
	public function set_up(): void {
		parent::set_up();

		global $wpdb;

		foreach ( array( 'permalink_structure', 'rewrite_rules' ) as $name ) {
			$this->rewriteBefore[ $name ] = $wpdb->get_row( $wpdb->prepare( 'SELECT option_name, option_value, autoload FROM %i WHERE option_name = %s', $wpdb->options, $name ), ARRAY_A );
		}

		$this->rulesInMemoryBefore = array(
			'extra_rules_top'    => $GLOBALS['wp_rewrite']->extra_rules_top,
			'extra_permastructs' => $GLOBALS['wp_rewrite']->extra_permastructs,
		);

		if ( ! post_type_exists( ProductCapabilities::POST_TYPE ) ) {
			ProductPostType::register();
		}
	}

	/**
	 * Puts the stored rewrite options back, and the rewrite object with them.
	 *
	 * @since 0.1.0
	 */
	public function tear_down(): void {
		global $wpdb, $wp_rewrite;

		foreach ( $this->rewriteBefore as $name => $row ) {
			$wpdb->delete( $wpdb->options, array( 'option_name' => $name ) );

			if ( null !== $row ) {
				$wpdb->insert( $wpdb->options, $row );
			}
		}

		wp_cache_flush();
		$wp_rewrite->init();

		$wp_rewrite->extra_rules_top    = $this->rulesInMemoryBefore['extra_rules_top'];
		$wp_rewrite->extra_permastructs = $this->rulesInMemoryBefore['extra_permastructs'];

		parent::tear_down();
	}

	/**
	 * Tests that the kernel registers the type on `init`, and what WordPress holds of the registration.
	 *
	 * @since 0.1.0
	 */
	public function test_the_kernel_registers_the_type_on_init_as_declared(): void {
		$this->assertSame( 10, has_action( 'init', array( ProductPostType::class, 'register' ) ), 'The kernel does not register the post type on init.' );

		$type = get_post_type_object( ProductCapabilities::POST_TYPE );

		$this->assertInstanceOf( \WP_Post_Type::class, $type );
		$this->assertTrue( $type->public );
		$this->assertFalse( $type->hierarchical );
		$this->assertTrue( $type->show_in_rest );
		$this->assertSame( 'seocart-products', $type->rest_base );
		$this->assertSame( 'wp/v2', $type->rest_namespace );
		$this->assertSame( ProductPostsController::class, $type->rest_controller_class, 'The plugin serves the type with its own controller.' );
		$this->assertInstanceOf( ProductPostsController::class, $type->get_rest_controller() );
		$this->assertTrue( $type->map_meta_cap );
		$this->assertFalse( $type->can_export );
		$this->assertFalse( $type->delete_with_user );
		$this->assertSame( 'products', $type->has_archive, 'The archive base.' );
		$this->assertSame( 'Products', $type->labels->name );
		$this->assertSame( 'Product', $type->labels->singular_name );

		foreach ( ProductCapabilities::map() as $key => $capability ) {
			$this->assertSame( $capability, $type->cap->{$key}, "The capability map's {$key}." );
		}

		foreach ( ProductPostType::SUPPORTS as $feature ) {
			$this->assertTrue( post_type_supports( ProductCapabilities::POST_TYPE, $feature ), $feature );
		}

		$this->assertFalse( post_type_supports( ProductCapabilities::POST_TYPE, 'custom-fields' ), 'No custom fields: commerce data never goes to post meta.' );
		$this->assertSame( ProductCapabilities::registrationArguments(), array_intersect_key( ProductPostType::arguments(), ProductCapabilities::registrationArguments() ), 'The capability arguments are merged in unchanged.' );
	}

	/**
	 * Tests that a product's permalink and its archive link use the `products` base, under a structure with a front and under one without.
	 *
	 * `with_front => false` keeps the base off any front the site's structure gives every other
	 * post type, such as `/blog/`.
	 *
	 * @since 0.1.0
	 */
	public function test_permalinks_use_the_products_base_with_and_without_a_front(): void {
		global $wp_rewrite;

		foreach ( array( self::STRUCTURE, '/blog/%postname%/' ) as $structure ) {
			$wp_rewrite->set_permalink_structure( $structure );
			unregister_post_type( ProductCapabilities::POST_TYPE );
			ProductPostType::register();

			$postId = wp_insert_post(
				array(
					'post_type'   => ProductCapabilities::POST_TYPE,
					'post_status' => 'publish',
					'post_title'  => 'A product',
					'post_name'   => 'a-product',
				),
				true
			);

			$this->assertIsInt( $postId, "Creating the product under {$structure}." );
			$this->assertSame( home_url( '/products/a-product/' ), get_permalink( $postId ), "The permalink under {$structure}." );
			$this->assertSame( home_url( '/products/' ), get_post_type_archive_link( ProductCapabilities::POST_TYPE ), "The archive link under {$structure}." );

			wp_delete_post( $postId, true );
		}
	}

	/**
	 * Tests that registering the type rewrites nothing, and installing the plugin on a site flushes the rules with the type's.
	 *
	 * @since 0.1.0
	 */
	public function test_only_installing_the_plugin_flushes_the_rewrite_rules(): void {
		global $wpdb, $wp_rewrite;

		$wp_rewrite->set_permalink_structure( self::STRUCTURE );
		$wpdb->delete( $wpdb->options, array( 'option_name' => 'rewrite_rules' ) );
		wp_cache_flush();

		ProductPostType::register();

		$this->assertNull( $this->storedOption( 'rewrite_rules' ), 'Registering the post type flushed the rewrite rules.' );

		$this->container()->get( Lifecycle::class )->activate();

		$rules = maybe_unserialize( (string) $this->storedOption( 'rewrite_rules' ) );

		$this->assertIsArray( $rules, 'Installing the plugin did not flush the rewrite rules.' );
		$this->assertArrayHasKey( self::PERMALINK_RULE, $rules, 'The flushed rules have no product permalink.' );
		$this->assertArrayHasKey( self::ARCHIVE_RULE, $rules, 'The flushed rules have no product archive.' );
	}

	/**
	 * Tests that deactivating the plugin rebuilds the rules without the product permalinks and archive.
	 *
	 * @since 0.1.0
	 */
	public function test_deactivating_flushes_the_product_rules_away(): void {
		global $wp_rewrite;

		$wp_rewrite->set_permalink_structure( self::STRUCTURE );

		// WordPress adds a post type's rules when it is registered under a permalink structure, as every request after this change does on `init`.
		ProductPostType::register();

		$lifecycle    = $this->container()->get( Lifecycle::class );
		$registeredOn = $wp_rewrite->extra_rules_top;

		$lifecycle->activate();

		$installed = maybe_unserialize( (string) $this->storedOption( 'rewrite_rules' ) );

		$this->assertIsArray( $installed );
		$this->assertArrayHasKey( self::PERMALINK_RULE, $installed, 'The installation wrote no product rule, so its absence below would prove nothing.' );

		// Deactivation is a request of its own, which holds in memory only the rules registered on
		// `init`; WordPress keeps the rules the activation's flush generated for the rest of that
		// request, so this process goes back to the rules it held before that flush.
		$wp_rewrite->extra_rules_top = $registeredOn;

		$lifecycle->deactivate();

		$rules = maybe_unserialize( (string) $this->storedOption( 'rewrite_rules' ) );

		$this->assertIsArray( $rules, 'Deactivation did not rebuild the rewrite rules.' );
		$this->assertNotSame( array(), $rules, 'Deactivation left no rewrite rule at all.' );
		$this->assertArrayNotHasKey( self::PERMALINK_RULE, $rules, 'The product permalinks outlive the plugin.' );
		$this->assertArrayNotHasKey( self::ARCHIVE_RULE, $rules, 'The product archive outlives the plugin.' );
		$this->assertFalse( post_type_exists( ProductCapabilities::POST_TYPE ), 'The post type is still registered after deactivation.' );
	}

	/**
	 * Tests that an installation in a request that passed `init` before the plugin was loaded, as activation does, registers the type before it flushes.
	 *
	 * @since 0.1.0
	 */
	public function test_installing_after_init_registers_the_type_before_the_flush(): void {
		global $wp_rewrite;

		$wp_rewrite->set_permalink_structure( self::STRUCTURE );
		unregister_post_type( ProductCapabilities::POST_TYPE );

		$this->container()->get( Lifecycle::class )->activate();

		$rules = maybe_unserialize( (string) $this->storedOption( 'rewrite_rules' ) );

		$this->assertTrue( post_type_exists( ProductCapabilities::POST_TYPE ) );
		$this->assertIsArray( $rules );
		$this->assertArrayHasKey( self::PERMALINK_RULE, $rules, 'The rules were flushed before the post type was registered.' );
	}
}
