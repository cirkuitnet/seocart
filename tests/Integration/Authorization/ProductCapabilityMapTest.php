<?php
/**
 * Tests the product post type's capability map against core's own mapping
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Authorization;

use SEOCart\Platform\Authorization\CapabilityDeclaration;
use SEOCart\Platform\Authorization\CapabilityInstaller;
use SEOCart\Platform\Authorization\CapabilityMapper;
use SEOCart\Platform\Authorization\ProductCapabilities;
use SEOCart\Tests\Support\Doubles\InMemoryGrantLedger;
use SEOCart\Tests\Support\ReloadsRoles;
use WP_UnitTestCase;

// phpcs:disable WordPress.WP.Capabilities.Unknown -- the sniff knows core's capabilities only; the product post type's are declared by ProductCapabilities.

/**
 * Registers `seocart_product` the way the catalog module will, from the registration
 * arguments alone, and checks what core makes of it with the plugin's callback hooked.
 *
 * The post type is registered and unregistered around each test. Registering a post type with
 * `map_meta_cap => true` also records its meta capabilities in core's `$post_type_meta_caps`
 * global, which unregistering leaves behind, so that global is restored too.
 *
 * @since 0.1.0
 */
final class ProductCapabilityMapTest extends WP_UnitTestCase {

	use ReloadsRoles;

	/**
	 * The post type the catalog module registers.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const POST_TYPE = 'seocart_product';

	/**
	 * Core's record of custom meta capabilities, as it was before the test.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, string>|null
	 */
	private ?array $metaCapabilitiesBefore;

	/**
	 * Registers the post type and hooks the plugin's callback.
	 *
	 * @since 0.1.0
	 */
	public function set_up(): void {
		global $post_type_meta_caps;

		parent::set_up();

		self::reloadRoles();

		$this->metaCapabilitiesBefore = is_array( $post_type_meta_caps ) ? $post_type_meta_caps : null;

		register_post_type( self::POST_TYPE, array_merge( array( 'public' => false ), ProductCapabilities::registrationArguments() ) );

		add_filter( 'map_meta_cap', array( new CapabilityMapper( new CapabilityDeclaration() ), 'map' ), 10, 4 );
	}

	/**
	 * Unregisters the post type and restores what registering it changed.
	 *
	 * @since 0.1.0
	 */
	public function tear_down(): void {
		global $post_type_meta_caps;

		unregister_post_type( self::POST_TYPE );

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- core's global, restored to its state before the test registered a post type.
		$post_type_meta_caps = $this->metaCapabilitiesBefore;

		parent::tear_down();

		self::reloadRoles();
	}

	/**
	 * Tests that the map covers every key core's get_post_type_capabilities() produces, and that core keeps it as it is.
	 *
	 * Planted violation: delete the `'edit_private_posts'` line from ProductCapabilities::map().
	 *
	 * @since 0.1.0
	 */
	public function test_the_map_covers_every_key_core_produces(): void {
		$core_defaults = (array) get_post_type_capabilities(
			(object) array(
				'capability_type' => ProductCapabilities::capabilityType(),
				'map_meta_cap'    => true,
				'capabilities'    => array(),
			)
		);

		$this->assertEqualsCanonicalizing( array_keys( $core_defaults ), array_keys( ProductCapabilities::map() ), 'The map does not name exactly the keys core produces.' );

		$registered = (array) get_post_type_object( self::POST_TYPE )->cap;
		$expected   = ProductCapabilities::map();

		ksort( $registered );
		ksort( $expected );

		$this->assertSame( $expected, $registered, 'Core registered a capability the map does not declare, or changed one it does.' );
		$this->assertTrue( get_post_type_object( self::POST_TYPE )->map_meta_cap );
	}

	/**
	 * Tests that core maps `edit_post`, `read_post` and `delete_post` on a product through the map, with the plugin's callback hooked.
	 *
	 * @since 0.1.0
	 */
	public function test_core_maps_the_meta_capabilities_through_the_map(): void {
		$author    = self::factory()->user->create();
		$other     = self::factory()->user->create();
		$draft     = $this->product( $author, 'draft' );
		$published = $this->product( $author, 'publish' );
		$private   = $this->product( $author, 'private' );

		$this->assertSame( array( 'edit_seocart_products' ), map_meta_cap( 'edit_post', $author, $draft ) );
		$this->assertSame( array( 'edit_published_seocart_products' ), map_meta_cap( 'edit_post', $author, $published ) );
		$this->assertSame( array( 'edit_others_seocart_products', 'edit_published_seocart_products' ), map_meta_cap( 'edit_post', $other, $published ) );
		$this->assertSame( array( 'edit_others_seocart_products', 'edit_private_seocart_products' ), map_meta_cap( 'edit_post', $other, $private ) );
		$this->assertSame( array( 'read_private_seocart_products' ), map_meta_cap( 'read_post', $other, $private ) );
		$this->assertSame( array( 'read' ), map_meta_cap( 'read_post', $other, $published ) );
		$this->assertSame( array( 'delete_published_seocart_products' ), map_meta_cap( 'delete_post', $author, $published ) );

		// The post type's own names for its meta capabilities reach the same mapping.
		$this->assertSame( map_meta_cap( 'edit_post', $other, $published ), map_meta_cap( 'edit_seocart_product', $other, $published ) );
		$this->assertSame( map_meta_cap( 'delete_post', $other, $private ), map_meta_cap( 'delete_seocart_product', $other, $private ) );
	}

	/**
	 * Tests that the installed roles get from the map exactly what the security model promises.
	 *
	 * @since 0.1.0
	 */
	public function test_the_installed_roles_get_what_the_model_promises(): void {
		( new CapabilityInstaller( new CapabilityDeclaration(), new InMemoryGrantLedger() ) )->install();

		$author    = self::factory()->user->create( array( 'role' => 'seocart_manager' ) );
		$published = $this->product( $author, 'publish' );
		$private   = $this->product( $author, 'private' );

		$users = array(
			'administrator'          => self::factory()->user->create( array( 'role' => 'administrator' ) ),
			'seocart_catalog_editor' => self::factory()->user->create( array( 'role' => 'seocart_catalog_editor' ) ),
			'seocart_order_agent'    => self::factory()->user->create( array( 'role' => 'seocart_order_agent' ) ),
			'seocart_reporter'       => self::factory()->user->create( array( 'role' => 'seocart_reporter' ) ),
			'seocart_customer'       => self::factory()->user->create( array( 'role' => 'seocart_customer' ) ),
		);

		$may_edit = array( 'administrator', 'seocart_catalog_editor' );

		foreach ( $users as $role => $user ) {
			$expected = in_array( $role, $may_edit, true );

			$this->assertSame( $expected, user_can( $user, 'edit_post', $published ), "{$role}: edit someone else's published product." );
			$this->assertSame( $expected, user_can( $user, 'edit_post', $private ), "{$role}: edit someone else's private product." );
			$this->assertSame( $expected, user_can( $user, 'delete_post', $published ), "{$role}: delete someone else's published product." );
			$this->assertSame( $expected, user_can( $user, 'read_post', $private ), "{$role}: read someone else's private product." );
			$this->assertTrue( user_can( $user, 'read_post', $published ), "{$role}: read a published product." );
		}
	}

	/**
	 * Creates a product post.
	 *
	 * @since 0.1.0
	 *
	 * @param int    $author The author's user id.
	 * @param string $status The post status.
	 * @return int The post id.
	 */
	private function product( int $author, string $status ): int {
		return self::factory()->post->create(
			array(
				'post_type'   => self::POST_TYPE,
				'post_author' => $author,
				'post_status' => $status,
			)
		);
	}
}
