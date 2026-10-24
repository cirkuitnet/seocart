<?php
/**
 * Tests the product taxonomies: how WordPress holds their registration, their REST routes, and who may manage their terms
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Catalog;

use SEOCart\Catalog\Infrastructure\ProductPostType;
use SEOCart\Catalog\Infrastructure\ProductTaxonomies;
use SEOCart\Platform\Authorization\CapabilityDeclaration;
use SEOCart\Platform\Authorization\CapabilityInstaller;
use SEOCart\Platform\Authorization\ProductCapabilities;
use SEOCart\Tests\Support\Doubles\InMemoryGrantLedger;
use SEOCart\Tests\Support\ReloadsRoles;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

/**
 * The kernel registers `seocart_category`, `seocart_brand` and `seocart_tag` on the product post type, on `init`, as ProductTaxonomies declares them.
 *
 * Each is public, at `wp/v2/<rest base>` under core's own terms controller, with its archive
 * base and no front, and its capabilities from ProductCapabilities: a catalog editor creates,
 * edits and deletes terms; a store customer and an order agent only read them.
 *
 * Planted violations, one at a time, each put back afterwards:
 *
 * - In ProductTaxonomies::DEFINITIONS, make the brands hierarchical: the registration test fails.
 * - In ProductTaxonomies::arguments(), set `show_in_rest` to false: the routes test fails.
 * - In ProductCapabilities::termCapabilities(), map `edit_terms` to `seocart_manage_settings`:
 *   the catalog editor cannot create a category, and the management test fails.
 * - In ProductCapabilities::termCapabilities(), map `edit_terms` and `assign_terms` to `read`: a
 *   store customer creates terms, and the read-only test fails.
 *
 * @since 0.2.0
 */
final class ProductTaxonomiesTest extends WP_UnitTestCase {

	use ReloadsRoles;

	/**
	 * What each taxonomy is declared to be: hierarchical, its REST base, its archive base, in menus, a column in the products list.
	 *
	 * @since 0.2.0
	 *
	 * @var array<string, array{0: bool, 1: string, 2: string, 3: bool, 4: bool}>
	 */
	private const EXPECTED = array(
		ProductTaxonomies::CATEGORY => array( true, 'seocart-categories', 'product-category', true, true ),
		ProductTaxonomies::BRAND    => array( false, 'seocart-brands', 'product-brand', true, false ),
		ProductTaxonomies::TAG      => array( false, 'seocart-tags', 'product-tag', false, false ),
	);

	/**
	 * Installs the plugin's roles and discards the REST server.
	 *
	 * @since 0.2.0
	 */
	public function set_up(): void {
		parent::set_up();

		self::reloadRoles();

		( new CapabilityInstaller( new CapabilityDeclaration(), new InMemoryGrantLedger() ) )->install();

		if ( ! post_type_exists( ProductCapabilities::POST_TYPE ) || ! ProductTaxonomies::registered() ) {
			ProductPostType::register();
		}

		self::discardRestServer();
	}

	/**
	 * Discards the REST server, logs out, rolls the test back and reloads the roles.
	 *
	 * @since 0.2.0
	 */
	public function tear_down(): void {
		self::discardRestServer();
		wp_set_current_user( 0 );

		parent::tear_down();

		self::reloadRoles();
	}

	/**
	 * Tests that the kernel's `init` registration gives WordPress the three taxonomies as declared.
	 *
	 * @since 0.2.0
	 */
	public function test_the_kernel_registers_the_three_taxonomies_on_init_as_declared(): void {
		$this->assertSame( 10, has_action( 'init', array( ProductPostType::class, 'register' ) ), 'The kernel does not register the post type and its taxonomies on init.' );
		$this->assertEqualsCanonicalizing( ProductTaxonomies::names(), get_object_taxonomies( ProductCapabilities::POST_TYPE ), 'The product post type has other taxonomies than the three.' );

		foreach ( self::EXPECTED as $name => list( $hierarchical, $rest_base, $slug, $in_menus, $column ) ) {
			$taxonomy = get_taxonomy( $name );

			$this->assertInstanceOf( \WP_Taxonomy::class, $taxonomy, $name );
			$this->assertSame( array( ProductCapabilities::POST_TYPE ), $taxonomy->object_type, "{$name}: on the product post type only." );
			$this->assertTrue( $taxonomy->public, "{$name}: public." );
			$this->assertTrue( $taxonomy->publicly_queryable, "{$name}: has archives." );
			$this->assertSame( $hierarchical, $taxonomy->hierarchical, "{$name}: hierarchical." );
			$this->assertTrue( $taxonomy->show_in_rest, "{$name}: in the REST API." );
			$this->assertSame( $rest_base, $taxonomy->rest_base, "{$name}: REST base." );
			$this->assertSame( 'wp/v2', $taxonomy->rest_namespace, "{$name}: REST namespace." );
			$this->assertSame( \WP_REST_Terms_Controller::class, get_class( (object) $taxonomy->get_rest_controller() ), "{$name}: core's terms controller." );
			$this->assertTrue( $taxonomy->show_ui, "{$name}: WordPress's term screens." );
			$this->assertTrue( $taxonomy->show_in_menu, "{$name}: under the Products menu." );
			$this->assertSame( $in_menus, $taxonomy->show_in_nav_menus, "{$name}: in navigation menus." );
			$this->assertSame( $column, $taxonomy->show_admin_column, "{$name}: a column in the products list." );
			$this->assertSame( $name, $taxonomy->query_var, "{$name}: query variable." );

			// WordPress adds its rewrite defaults only under a permalink structure, so only the declared keys are compared.
			$declared = array(
				'hierarchical' => $hierarchical,
				'slug'         => $slug,
				'with_front'   => false,
			);
			$rewrite  = array_intersect_key( (array) $taxonomy->rewrite, $declared );

			ksort( $rewrite );

			$this->assertSame( $declared, $rewrite, "{$name}: archive URLs." );
			$this->assertArrayNotHasKey( 'default_term', ProductTaxonomies::arguments( $name ), "{$name}: no default term." );
			$this->assertArrayNotHasKey( 'update_count_callback', ProductTaxonomies::arguments( $name ), "{$name}: WordPress's own term counts." );
			$this->assertSame( ProductCapabilities::termCapabilities(), (array) $taxonomy->cap, "{$name}: capabilities." );
			$this->assertSame( ProductTaxonomies::arguments( $name )['description'], $taxonomy->description, "{$name}: description." );
			$this->assertNotSame( '', $taxonomy->labels->menu_name, "{$name}: menu name." );
		}

		$this->assertSame( 'Categories', get_taxonomy( ProductTaxonomies::CATEGORY )->labels->menu_name );
		$this->assertSame( 'Brands', get_taxonomy( ProductTaxonomies::BRAND )->labels->menu_name );
		$this->assertSame( 'Tags', get_taxonomy( ProductTaxonomies::TAG )->labels->menu_name );
	}

	/**
	 * Tests that each taxonomy has core's two term routes, and that the product resource carries each as a field.
	 *
	 * @since 0.2.0
	 */
	public function test_each_taxonomy_has_its_term_routes_and_a_field_on_the_product(): void {
		$routes = rest_get_server()->get_routes();
		$schema = get_post_type_object( ProductCapabilities::POST_TYPE )->get_rest_controller()->get_item_schema();

		foreach ( self::EXPECTED as $name => list( , $rest_base ) ) {
			$this->assertArrayHasKey( '/wp/v2/' . $rest_base, $routes, "{$name}: the collection route." );
			$this->assertArrayHasKey( '/wp/v2/' . $rest_base . '/(?P<id>[\d]+)', $routes, "{$name}: the item route." );
			$this->assertArrayHasKey( $rest_base, $schema['properties'], "{$name}: the product's field." );
		}
	}

	/**
	 * Tests that a catalog editor creates, renames and deletes categories, brands and tags through core's term routes.
	 *
	 * @since 0.2.0
	 */
	public function test_a_catalog_editor_creates_edits_and_deletes_terms(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'seocart_catalog_editor' ) ) );

		$coffee = $this->created( ProductTaxonomies::CATEGORY, array( 'name' => 'Coffee' ) );
		$origin = $this->created(
			ProductTaxonomies::CATEGORY,
			array(
				'name'        => 'Single origin',
				'parent'      => $coffee,
				'description' => 'Coffees from one farm.',
			)
		);

		$this->assertSame( $coffee, (int) get_term( $origin, ProductTaxonomies::CATEGORY )->parent, 'The subcategory has its parent.' );

		foreach ( array(
			ProductTaxonomies::BRAND => 'Fernwood',
			ProductTaxonomies::TAG   => 'Washed',
		) as $taxonomy => $term ) {
			$id = $this->created( $taxonomy, array( 'name' => $term ) );

			$this->assertSame( 200, $this->send( 'POST', $taxonomy, $id, array( 'name' => $term . ' renamed' ) )->get_status(), "{$taxonomy}: rename." );
			$this->assertSame( $term . ' renamed', get_term( $id, $taxonomy )->name );
			$this->assertSame( 200, $this->send( 'DELETE', $taxonomy, $id, array( 'force' => true ) )->get_status(), "{$taxonomy}: delete." );
			$this->assertNull( get_term( $id, $taxonomy ) );
		}

		$this->assertSame( 200, $this->send( 'POST', ProductTaxonomies::CATEGORY, $origin, array( 'name' => 'Single-origin coffee' ) )->get_status() );
		$this->assertSame( 200, $this->send( 'DELETE', ProductTaxonomies::CATEGORY, $origin, array( 'force' => true ) )->get_status() );
		$this->assertNull( get_term( $origin, ProductTaxonomies::CATEGORY ) );
	}

	/**
	 * Tests that a store customer and an order agent read terms and change none.
	 *
	 * @since 0.2.0
	 */
	public function test_a_customer_and_an_order_agent_only_read_terms(): void {
		foreach ( array( 'seocart_customer', 'seocart_order_agent' ) as $role ) {
			wp_set_current_user( self::factory()->user->create( array( 'role' => $role ) ) );

			foreach ( ProductTaxonomies::names() as $taxonomy ) {
				$term = self::factory()->term->create(
					array(
						'taxonomy' => $taxonomy,
						'name'     => "Read by {$role}",
					)
				);
				$list = $this->send( 'GET', $taxonomy, null, array( 'hide_empty' => false ) );

				$this->assertSame( 200, $list->get_status(), "{$role}, {$taxonomy}: list." );
				$this->assertContains( $term, array_column( (array) $list->get_data(), 'id' ), "{$role}, {$taxonomy}: the term is listed." );
				$this->assertSame( 200, $this->send( 'GET', $taxonomy, $term )->get_status(), "{$role}, {$taxonomy}: read." );
				$this->assertSame( 'rest_cannot_create', $this->errorCode( $this->send( 'POST', $taxonomy, null, array( 'name' => 'Planted' ) ) ), "{$role}, {$taxonomy}: create." );
				$this->assertSame( 'rest_cannot_update', $this->errorCode( $this->send( 'POST', $taxonomy, $term, array( 'name' => 'Planted' ) ) ), "{$role}, {$taxonomy}: update." );
				$this->assertSame( 'rest_cannot_delete', $this->errorCode( $this->send( 'DELETE', $taxonomy, $term, array( 'force' => true ) ) ), "{$role}, {$taxonomy}: delete." );
				$this->assertSame( "Read by {$role}", get_term( $term, $taxonomy )->name, "{$role}, {$taxonomy}: the term is unchanged." );
			}
		}
	}

	/**
	 * Creates a term through its REST route and returns its id.
	 *
	 * @since 0.2.0
	 *
	 * @param string               $taxonomy The taxonomy.
	 * @param array<string, mixed> $body     The term's fields.
	 * @return int The new term's id.
	 */
	private function created( string $taxonomy, array $body ): int {
		$response = $this->send( 'POST', $taxonomy, null, $body );
		$data     = (array) $response->get_data();

		$this->assertSame( 201, $response->get_status(), "Creating a term in {$taxonomy}: " . wp_json_encode( $data ) );

		return (int) $data['id'];
	}

	/**
	 * Sends a request to a taxonomy's term routes.
	 *
	 * @since 0.2.0
	 *
	 * @param string               $method   The HTTP method.
	 * @param string               $taxonomy The taxonomy.
	 * @param int|null             $term     The term the request names, or null for the collection.
	 * @param array<string, mixed> $params   The parameters: the body of a write, the query of a read.
	 * @return WP_REST_Response The response.
	 */
	private function send( string $method, string $taxonomy, ?int $term, array $params = array() ): WP_REST_Response {
		$request = new WP_REST_Request( $method, '/wp/v2/' . ProductTaxonomies::arguments( $taxonomy )['rest_base'] . ( null === $term ? '' : '/' . $term ) );

		if ( 'GET' === $method ) {
			$request->set_query_params( $params );
		} else {
			$request->set_body_params( $params );
		}

		return rest_do_request( $request );
	}

	/**
	 * Returns the error code of a response, or an empty string when it is no error.
	 *
	 * @since 0.2.0
	 *
	 * @param WP_REST_Response $response The response.
	 * @return string The code.
	 */
	private function errorCode( WP_REST_Response $response ): string {
		$data = $response->get_data();

		return is_array( $data ) && isset( $data['code'] ) ? (string) $data['code'] : '';
	}

	/**
	 * Discards the current REST server, so the next request builds one and fires `rest_api_init` again.
	 *
	 * @since 0.2.0
	 */
	private static function discardRestServer(): void {
		global $wp_rest_server;

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- resets core's REST server global: the next request must build a new server.
		$wp_rest_server = null;
	}
}
