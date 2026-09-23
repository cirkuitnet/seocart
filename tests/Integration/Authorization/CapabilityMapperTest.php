<?php
/**
 * Tests that the one map_meta_cap callback fails closed through current_user_can()
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Authorization;

use SEOCart\Platform\Authorization\CapabilityDeclaration;
use SEOCart\Platform\Authorization\CapabilityMapper;
use SEOCart\Platform\Authorization\MetaCapabilityResolver;
use SEOCart\Tests\Support\ReloadsRoles;
use WP_UnitTestCase;

// phpcs:disable WordPress.WP.Capabilities.Unknown -- the sniff knows core's capabilities only; this test checks the plugin's, and undeclared ones on purpose.

/**
 * The mapper hooked to `map_meta_cap`, checked the way every consumer checks: with user_can().
 *
 * The kernel does not hook the mapper yet, so each test hooks it itself; the hook is removed
 * again after the test. Roles are reloaded from the database around each test, because
 * WordPress keeps role changes in the WP_Roles object as well as in the rolled-back option.
 *
 * @since 0.1.0
 */
final class CapabilityMapperTest extends WP_UnitTestCase {

	use ReloadsRoles;

	/**
	 * The mapper under test.
	 *
	 * @since 0.1.0
	 *
	 * @var CapabilityMapper
	 */
	private CapabilityMapper $mapper;

	/**
	 * Hooks a fresh mapper and reloads the roles.
	 *
	 * @since 0.1.0
	 */
	public function set_up(): void {
		parent::set_up();

		self::reloadRoles();

		$this->mapper = new CapabilityMapper( new CapabilityDeclaration() );

		add_filter( 'map_meta_cap', array( $this->mapper, 'map' ), 10, 4 );
	}

	/**
	 * Rolls the test back, then reloads the roles so the next test sees what the database holds.
	 *
	 * @since 0.1.0
	 */
	public function tear_down(): void {
		parent::tear_down();

		self::reloadRoles();
	}

	/**
	 * Tests that an administrator is denied an unknown plugin capability even when a role was wrongly given it.
	 *
	 * Planted violation: in CapabilityMapper::map(), make the branch for a capability with no
	 * registered resolver return `$caps` instead of `array( self::DENY )`.
	 *
	 * @since 0.1.0
	 */
	public function test_an_unknown_plugin_capability_is_denied_even_when_a_role_grants_it(): void {
		$administrator = self::factory()->user->create( array( 'role' => 'administrator' ) );

		get_role( 'administrator' )->add_cap( 'seocart_frobnicate' );
		get_role( 'administrator' )->add_cap( 'frobnicate_seocart_products' );

		// The plant is real: without the callback, the wrongly granted capability would pass.
		remove_filter( 'map_meta_cap', array( $this->mapper, 'map' ), 10 );
		$this->assertTrue( user_can( $administrator, 'seocart_frobnicate' ), 'Without the callback the grant did not take, so the denial below would prove nothing.' );
		add_filter( 'map_meta_cap', array( $this->mapper, 'map' ), 10, 4 );

		$this->assertFalse( user_can( $administrator, 'seocart_frobnicate' ), 'An unknown seocart_ capability was allowed.' );
		$this->assertFalse( user_can( $administrator, 'frobnicate_seocart_products' ), 'An unknown capability on the product base was allowed.' );
	}

	/**
	 * Tests that a declared primitive follows the roles, as any capability does.
	 *
	 * @since 0.1.0
	 */
	public function test_a_declared_primitive_follows_the_roles(): void {
		$administrator = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$subscriber    = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$this->assertFalse( user_can( $administrator, 'seocart_view_orders' ), 'Nothing granted the capability yet.' );

		get_role( 'administrator' )->add_cap( 'seocart_view_orders' );

		$this->assertTrue( user_can( $administrator, 'seocart_view_orders' ) );
		$this->assertFalse( user_can( $subscriber, 'seocart_view_orders' ) );
		$this->assertFalse( user_can( 0, 'seocart_view_orders' ), 'A visitor who is not logged in holds no capability.' );
	}

	/**
	 * Tests that a registered meta capability resolves per resource, and denies a resource it cannot resolve.
	 *
	 * Granting the meta capability itself to a role changes nothing: meta capabilities are never
	 * role-assignable, and the resolver's answer is the only way to hold one.
	 *
	 * Planted violation: in CapabilityMapper::map(), return `$caps` instead of
	 * `array( self::DENY )` when the resolver returns null.
	 *
	 * @since 0.1.0
	 */
	public function test_a_meta_capability_resolves_per_resource_and_an_unresolvable_one_denies(): void {
		$administrator = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$this->mapper->registerMetaCapability(
			'seocart_selftest_view_widget',
			static function (): MetaCapabilityResolver {
				return new class() implements MetaCapabilityResolver {

					/**
					 * Knows one resource, 42, which needs the order-viewing primitive.
					 *
					 * @since 0.1.0
					 *
					 * @param int               $userId The user being checked.
					 * @param array<int, mixed> $args   The resource id first.
					 * @return list<string>|null The primitives, or null for any other resource.
					 */
					public function primitivesFor( int $userId, array $args ): ?array {
						return 42 === ( $args[0] ?? null ) ? array( 'seocart_view_orders' ) : null;
					}
				};
			}
		);

		get_role( 'administrator' )->add_cap( 'seocart_view_orders' );
		get_role( 'administrator' )->add_cap( 'seocart_selftest_view_widget' );

		$this->assertTrue( user_can( $administrator, 'seocart_selftest_view_widget', 42 ), 'The resolvable resource.' );
		$this->assertFalse( user_can( $administrator, 'seocart_selftest_view_widget', 7 ), 'A resource the resolver cannot resolve.' );
		$this->assertFalse( user_can( $administrator, 'seocart_selftest_view_widget' ), 'No resource at all.' );

		get_role( 'administrator' )->remove_cap( 'seocart_view_orders' );

		$this->assertFalse( user_can( $administrator, 'seocart_selftest_view_widget', 42 ), 'The resolver names a primitive the role no longer holds.' );
	}

	/**
	 * Tests that core's own capabilities are left exactly as core decides them.
	 *
	 * @since 0.1.0
	 */
	public function test_core_capabilities_are_untouched(): void {
		$administrator = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$subscriber    = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$post          = self::factory()->post->create( array( 'post_author' => $administrator ) );

		$this->assertTrue( user_can( $administrator, 'manage_options' ) );
		$this->assertTrue( user_can( $administrator, 'edit_post', $post ) );
		$this->assertTrue( user_can( $subscriber, 'read' ) );
		$this->assertFalse( user_can( $subscriber, 'edit_post', $post ) );
	}

	/**
	 * Tests that a multisite super admin, who holds everything by definition, is still denied an unknown plugin capability.
	 *
	 * Runs only when the suite runs as multisite: `WP_MULTISITE=1 composer test:integration`.
	 *
	 * @since 0.1.0
	 */
	public function test_a_super_admin_is_denied_an_unknown_plugin_capability(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Runs only as multisite: WP_MULTISITE=1 composer test:integration.' );
		}

		$super_admin = self::factory()->user->create();

		grant_super_admin( $super_admin );

		$this->assertTrue( user_can( $super_admin, 'seocart_view_orders' ), 'A super admin holds every declared capability.' );
		$this->assertFalse( user_can( $super_admin, 'seocart_frobnicate' ), 'do_not_allow must hold even for a super admin.' );
	}
}
