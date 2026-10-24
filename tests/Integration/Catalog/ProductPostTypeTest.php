<?php
/**
 * Tests the product post type: how WordPress holds its registration, and when its rewrite rules and its taxonomies' are flushed
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Catalog;

use SEOCart\Catalog\Infrastructure\ProductPostType;
use SEOCart\Catalog\Infrastructure\ProductTaxonomies;
use SEOCart\Catalog\Interfaces\Rest\ProductPostsController;
use SEOCart\Platform\Authorization\ProductCapabilities;
use SEOCart\Platform\Kernel\Lifecycle;
use SEOCart\Tests\Support\Catalog\CreatesTerms;
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
 * The product taxonomies' archives are registered with the post type and flushed with it, at
 * `product-category/<parent>/<child>/`, `product-brand/<slug>/` and `product-tag/<slug>/`, each
 * with its paged form, and leave the product's own URLs as they were. Their plants:
 *
 * - Remove `ProductTaxonomies::register();` from ProductPostType::register(): the installed site
 *   has no archive rule for the taxonomies, and test_only_installing_the_plugin_flushes_the_rewrite_rules
 *   fails.
 * - In Lifecycle::flushRewriteRules(), register only a missing post type, dropping
 *   `|| ! ProductTaxonomies::registered()`: test_installing_registers_missing_taxonomies_before_the_flush fails.
 * - In Lifecycle::flushRewriteRulesWithoutProducts(), remove `ProductTaxonomies::unregister();`:
 *   the archive rules survive deactivation, and test_deactivating_flushes_the_product_rules_away fails.
 * - In ProductTaxonomies::arguments(), drop `'with_front' => false`: under a structure with a front,
 *   test_term_links_use_their_bases_with_and_without_a_front fails.
 * - In ProductTaxonomies::DEFINITIONS, make the categories flat: a category two levels deep no
 *   longer parses, and test_the_term_archive_urls_parse_and_the_product_urls_are_unchanged fails.
 * - In ProductTaxonomies::register(), register the taxonomies on `post` instead of the product
 *   post type: a term's archive no longer queries products, and
 *   test_a_term_archive_queries_the_products_in_the_term fails.
 *
 * @since 0.1.0
 * @since 0.2.0 The product taxonomies' archives.
 */
final class ProductPostTypeTest extends KernelTestCase {

	use CreatesTerms;

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
	 * The rules WordPress writes for the product taxonomies' archives under that structure, and their paged forms.
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	private const TERM_RULES = array(
		'product-category/(.+?)/?$',
		'product-category/(.+?)/page/?([0-9]{1,})/?$',
		'product-brand/([^/]+)/?$',
		'product-brand/([^/]+)/page/?([0-9]{1,})/?$',
		'product-tag/([^/]+)/?$',
		'product-tag/([^/]+)/page/?([0-9]{1,})/?$',
	);

	/**
	 * The posts the test created, deleted after it.
	 *
	 * @since 0.2.0
	 *
	 * @var list<int>
	 */
	private array $posts = array();

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

		if ( ! post_type_exists( ProductCapabilities::POST_TYPE ) || ! ProductTaxonomies::registered() ) {
			ProductPostType::register();
		}
	}

	/**
	 * Deletes the posts the test created, registers the post type and its taxonomies again when a test left them unregistered, deletes the test's terms and WordPress's cache of the category tree, and puts the stored rewrite options back, and the rewrite object with them.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 Deletes the test's posts and terms, and registers the post type and its taxonomies again.
	 */
	public function tear_down(): void {
		global $wpdb, $wp_rewrite;

		foreach ( $this->posts as $postId ) {
			wp_delete_post( $postId, true );
		}

		// The terms are deleted once their taxonomies are registered again: WordPress deletes no term of an unknown taxonomy.
		if ( ! post_type_exists( ProductCapabilities::POST_TYPE ) || ! ProductTaxonomies::registered() ) {
			ProductPostType::register();
		}

		$this->deleteCreatedTerms();

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

		foreach ( self::TERM_RULES as $rule ) {
			$this->assertArrayHasKey( $rule, $rules, "The flushed rules have no {$rule}." );
		}
	}

	/**
	 * Tests that the installed rules parse a category two levels deep, a brand and a tag, each with its second page, and parse a product and the product archive as before.
	 *
	 * @since 0.2.0
	 */
	public function test_the_term_archive_urls_parse_and_the_product_urls_are_unchanged(): void {
		global $wp_rewrite;

		$wp_rewrite->set_permalink_structure( self::STRUCTURE );
		$this->reregister();
		$this->container()->get( Lifecycle::class )->activate();

		$cases = array(
			'/product-category/coffee/single-origin/' => array( ProductTaxonomies::CATEGORY => 'coffee/single-origin' ),
			'/product-category/coffee/single-origin/page/2/' => array(
				ProductTaxonomies::CATEGORY => 'coffee/single-origin',
				'paged'                     => '2',
			),
			'/product-brand/fernwood/'                => array( ProductTaxonomies::BRAND => 'fernwood' ),
			'/product-brand/fernwood/page/2/'         => array(
				ProductTaxonomies::BRAND => 'fernwood',
				'paged'                  => '2',
			),
			'/product-tag/washed/'                    => array( ProductTaxonomies::TAG => 'washed' ),
			'/product-tag/washed/page/2/'             => array(
				ProductTaxonomies::TAG => 'washed',
				'paged'                => '2',
			),
			'/products/a-product/'                    => array(
				ProductCapabilities::POST_TYPE => 'a-product',
				'post_type'                    => ProductCapabilities::POST_TYPE,
				'name'                         => 'a-product',
			),
			'/products/'                              => array( 'post_type' => ProductCapabilities::POST_TYPE ),
		);

		foreach ( $cases as $path => $expected ) {
			ksort( $expected );

			$this->assertSame( $expected, $this->parsed( $path ), "The query {$path} parses to." );
		}
	}

	/**
	 * Tests that a term's link uses its taxonomy's base and, for a category, its parent's slug, under a structure with a front and under one without.
	 *
	 * @since 0.2.0
	 */
	public function test_term_links_use_their_bases_with_and_without_a_front(): void {
		global $wp_rewrite;

		$coffee = $this->term( ProductTaxonomies::CATEGORY, 'Coffee' );
		$origin = $this->term( ProductTaxonomies::CATEGORY, 'Single origin', $coffee );
		$brand  = $this->term( ProductTaxonomies::BRAND, 'Fernwood' );
		$tag    = $this->term( ProductTaxonomies::TAG, 'Washed' );

		foreach ( array( self::STRUCTURE, '/blog/%postname%/' ) as $structure ) {
			$wp_rewrite->set_permalink_structure( $structure );
			$this->reregister();

			$this->assertSame( home_url( '/product-category/coffee/single-origin/' ), get_term_link( $origin ), "A subcategory's link under {$structure}." );
			$this->assertSame( home_url( '/product-brand/fernwood/' ), get_term_link( $brand ), "A brand's link under {$structure}." );
			$this->assertSame( home_url( '/product-tag/washed/' ), get_term_link( $tag ), "A tag's link under {$structure}." );
		}
	}

	/**
	 * Tests that each term's archive is a query of the products in it: a subcategory's, its parent's, a brand's and a tag's.
	 *
	 * @since 0.2.0
	 */
	public function test_a_term_archive_queries_the_products_in_the_term(): void {
		global $wp_rewrite;

		$wp_rewrite->set_permalink_structure( self::STRUCTURE );
		$this->reregister();
		$this->container()->get( Lifecycle::class )->activate();

		$coffee = $this->term( ProductTaxonomies::CATEGORY, 'Coffee' );
		$origin = $this->term( ProductTaxonomies::CATEGORY, 'Single origin', $coffee );
		$brand  = $this->term( ProductTaxonomies::BRAND, 'Fernwood' );
		$tag    = $this->term( ProductTaxonomies::TAG, 'Washed' );
		$other  = $this->term( ProductTaxonomies::TAG, 'Natural' );
		$postId = wp_insert_post(
			array(
				'post_type'   => ProductCapabilities::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Ethiopia Yirgacheffe',
				'post_name'   => 'ethiopia-yirgacheffe',
			),
			true
		);

		$this->assertIsInt( $postId );

		$this->posts[] = $postId;

		wp_set_object_terms( $postId, array( $origin ), ProductTaxonomies::CATEGORY );
		wp_set_object_terms( $postId, array( $brand ), ProductTaxonomies::BRAND );
		wp_set_object_terms( $postId, array( $tag ), ProductTaxonomies::TAG );

		foreach ( array( array( ProductTaxonomies::CATEGORY, $origin ), array( ProductTaxonomies::CATEGORY, $coffee ), array( ProductTaxonomies::BRAND, $brand ), array( ProductTaxonomies::TAG, $tag ) ) as list( $taxonomy, $term ) ) {
			$this->go_to( (string) get_term_link( $term ) );

			$this->assertTrue( is_tax( $taxonomy, $term ), "The archive of {$taxonomy} {$term} is not its term's archive." );
			$this->assertSame( array( $postId ), wp_list_pluck( $GLOBALS['wp_query']->posts, 'ID' ), "The archive of {$taxonomy} {$term} does not list its product." );
		}

		$this->go_to( (string) get_term_link( $other ) );

		$this->assertTrue( is_tax( ProductTaxonomies::TAG, $other ) );
		$this->assertSame( array(), $GLOBALS['wp_query']->posts, 'A tag the product does not have lists it.' );
	}

	/**
	 * Tests that an installation finding the post type registered but not its taxonomies registers them before the flush.
	 *
	 * @since 0.2.0
	 */
	public function test_installing_registers_missing_taxonomies_before_the_flush(): void {
		global $wp_rewrite;

		$wp_rewrite->set_permalink_structure( self::STRUCTURE );
		ProductTaxonomies::unregister();

		$this->assertTrue( post_type_exists( ProductCapabilities::POST_TYPE ), 'The post type is not registered, so this would test the other case.' );

		$this->container()->get( Lifecycle::class )->activate();

		$rules = maybe_unserialize( (string) $this->storedOption( 'rewrite_rules' ) );

		$this->assertTrue( ProductTaxonomies::registered() );
		$this->assertIsArray( $rules );

		foreach ( self::TERM_RULES as $rule ) {
			$this->assertArrayHasKey( $rule, $rules, "The rules were flushed before the taxonomies were registered: no {$rule}." );
		}
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
		$this->assertArrayHasKey( self::TERM_RULES[0], $installed, 'The installation wrote no taxonomy archive rule, so its absence below would prove nothing.' );

		foreach ( self::TERM_RULES as $rule ) {
			$this->assertArrayNotHasKey( $rule, $rules, "The taxonomy archive {$rule} outlives the plugin." );
		}

		foreach ( ProductTaxonomies::names() as $taxonomy ) {
			$this->assertFalse( taxonomy_exists( $taxonomy ), "{$taxonomy} is still registered after deactivation." );
		}
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

	/**
	 * Registers the post type and its taxonomies again, as a request does on `init` under the current permalink structure.
	 *
	 * @since 0.2.0
	 */
	private function reregister(): void {
		ProductTaxonomies::unregister();
		unregister_post_type( ProductCapabilities::POST_TYPE );
		ProductPostType::register();
	}

	/**
	 * Returns the query variables WordPress parses a request path into with the stored rules, those with a value only, sorted by name.
	 *
	 * The request is parsed by a WordPress object of its own, given the query variables the
	 * registrations added to the site's, so the site's own object keeps its state.
	 *
	 * @since 0.2.0
	 *
	 * @param string $path The path below the site's home, with its leading slash.
	 * @return array<string, mixed> The variables.
	 */
	private function parsed( string $path ): array {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- read only to be put back after the parse; nothing is output or stored.
		$before = $_SERVER['REQUEST_URI'] ?? null;

		$_SERVER['REQUEST_URI'] = $path;

		try {
			$wp                    = new \WP();
			$wp->public_query_vars = $GLOBALS['wp']->public_query_vars;
			$wp->parse_request();
		} finally {
			if ( null === $before ) {
				unset( $_SERVER['REQUEST_URI'] );
			} else {
				$_SERVER['REQUEST_URI'] = $before;
			}
		}

		$vars = array_filter( $wp->query_vars, static fn( $value ): bool => '' !== $value );

		ksort( $vars );

		return $vars;
	}
}
