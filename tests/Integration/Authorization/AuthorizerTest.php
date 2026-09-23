<?php
/**
 * Tests the authorizer through WordPress and the one map_meta_cap callback
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Authorization;

use SEOCart\Platform\Authorization\Actor;
use SEOCart\Platform\Authorization\AuthorizationError;
use SEOCart\Platform\Authorization\Authorizer;
use SEOCart\Platform\Authorization\CapabilityDeclaration;
use SEOCart\Platform\Authorization\CapabilityMapper;
use SEOCart\Platform\Authorization\MetaCapabilityResolver;
use SEOCart\Platform\Authorization\ProductCapabilities;
use SEOCart\Support\Error\CodedException;
use SEOCart\Support\Error\ErrorDefinition;
use SEOCart\Tests\Support\ReloadsRoles;
use WP_UnitTestCase;

// phpcs:disable WordPress.WP.Capabilities.Unknown -- the sniff knows core's capabilities only; the product post type's are declared by ProductCapabilities.

/**
 * The authorizer with real users, real roles and the plugin's map_meta_cap callback hooked.
 *
 * The callback is hooked with a test resolver for `seocart_view_order` that knows one order,
 * 42, which needs `seocart_view_orders`. The product post type is registered the way the
 * catalog will register it, from ProductCapabilities alone; registering it also records its
 * meta capabilities in core's `$post_type_meta_caps`, which unregistering leaves behind, so that
 * global is restored afterwards. Roles are reloaded from the database around each test, because
 * WordPress keeps role changes in the WP_Roles object as well as in the rolled-back option.
 *
 * @since 0.1.0
 */
final class AuthorizerTest extends WP_UnitTestCase {

	use ReloadsRoles;

	/**
	 * The authorizer under test.
	 *
	 * @since 0.1.0
	 *
	 * @var Authorizer
	 */
	private Authorizer $authorizer;

	/**
	 * Core's record of custom meta capabilities, as it was before the test.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, string>|null
	 */
	private ?array $metaCapabilitiesBefore;

	/**
	 * Registers the product post type, hooks the mapper with the test resolver and builds the authorizer.
	 *
	 * @since 0.1.0
	 */
	public function set_up(): void {
		global $post_type_meta_caps;

		parent::set_up();

		self::reloadRoles();

		$this->metaCapabilitiesBefore = is_array( $post_type_meta_caps ) ? $post_type_meta_caps : null;

		register_post_type( ProductCapabilities::POST_TYPE, array_merge( array( 'public' => false ), ProductCapabilities::registrationArguments() ) );

		$declaration = new CapabilityDeclaration();
		$mapper      = new CapabilityMapper( $declaration );

		$mapper->registerMetaCapability(
			'seocart_view_order',
			static fn(): MetaCapabilityResolver => new class() implements MetaCapabilityResolver {

				/**
				 * Knows order 42, which needs the order-viewing primitive.
				 *
				 * @since 0.1.0
				 *
				 * @param int               $userId The user being checked.
				 * @param array<int, mixed> $args   The resource id first.
				 * @return list<string>|null The primitives, or null for any other order.
				 */
				public function primitivesFor( int $userId, array $args ): ?array {
					return 42 === ( $args[0] ?? null ) ? array( 'seocart_view_orders' ) : null;
				}
			}
		);

		add_filter( 'map_meta_cap', array( $mapper, 'map' ), 10, 4 );

		$this->authorizer = new Authorizer( $declaration );
	}

	/**
	 * Unregisters the product post type, rolls the test back and reloads the roles.
	 *
	 * @since 0.1.0
	 */
	public function tear_down(): void {
		global $post_type_meta_caps;

		unregister_post_type( ProductCapabilities::POST_TYPE );

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- core's global, restored to its state before the test registered a post type.
		$post_type_meta_caps = $this->metaCapabilitiesBefore;

		parent::tear_down();

		self::reloadRoles();
	}

	/**
	 * Tests that a subscriber is denied, with `authorization.denied`, HTTP 403 and a message naming the capability.
	 *
	 * Planted violation: in Authorizer::allows(), change the primitive branch's
	 * `return user_can( $actor->userId(), $capability );` to
	 * `return true || user_can( $actor->userId(), $capability );`.
	 *
	 * @since 0.1.0
	 */
	public function test_a_subscriber_is_denied(): void {
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$denied     = $this->denial( Actor::user( $subscriber ), 'seocart_manage_inventory' );

		$this->assertSame( AuthorizationError::Denied, $denied->errorCode() );
		$this->assertSame( array( 'capability' => 'seocart_manage_inventory' ), $denied->context() );

		$row = ErrorDefinition::of( $denied->errorCode() );

		$this->assertSame( 403, $row->httpStatus() );
		$this->assertSame( 'Sorry, you are not allowed to do that. It requires the seocart_manage_inventory capability.', $row->render( $denied->context() ) );
	}

	/**
	 * Tests that a user whose role holds the primitive is allowed, and one whose role lost it is not.
	 *
	 * @since 0.1.0
	 */
	public function test_a_user_holding_the_primitive_is_allowed(): void {
		$administrator = self::factory()->user->create( array( 'role' => 'administrator' ) );

		get_role( 'administrator' )->add_cap( 'seocart_manage_inventory' );

		$this->authorizer->authorize( Actor::user( $administrator ), 'seocart_manage_inventory' );
		$this->assertTrue( $this->authorizer->allows( Actor::user( $administrator ), 'seocart_manage_inventory' ) );

		get_role( 'administrator' )->remove_cap( 'seocart_manage_inventory' );

		$this->assertFalse( $this->authorizer->allows( Actor::user( $administrator ), 'seocart_manage_inventory' ), 'The check must follow the role, not a copy of it.' );
	}

	/**
	 * Tests that a meta capability is allowed on the resource its resolver resolves, and denied on one it cannot.
	 *
	 * @since 0.1.0
	 */
	public function test_a_meta_capability_with_an_unresolvable_resource_is_denied(): void {
		$administrator = self::factory()->user->create( array( 'role' => 'administrator' ) );

		get_role( 'administrator' )->add_cap( 'seocart_view_orders' );

		$this->authorizer->authorize( Actor::user( $administrator ), 'seocart_view_order', 42 );

		$this->assertSame( AuthorizationError::Denied, $this->denial( Actor::user( $administrator ), 'seocart_view_order', 7 )->errorCode(), 'An order the resolver cannot resolve.' );
		$this->assertSame( AuthorizationError::Denied, $this->denial( Actor::user( $administrator ), 'seocart_view_order', 'not-an-order' )->errorCode(), 'An identifier the resolver cannot resolve.' );
		$this->assertSame( AuthorizationError::Denied, $this->denial( Actor::user( $administrator ), 'seocart_view_order' )->errorCode(), 'No resource at all.' );
	}

	/**
	 * Tests that the actor is judged, never the logged-in user.
	 *
	 * An administrator who holds the capability is logged in throughout. A subscriber and a
	 * visitor acting while they are logged in must still be denied, and a CLI actor bound to the
	 * administrator must be allowed while nobody is logged in.
	 *
	 * Planted violations: in Authorizer::allows(), change `$actor->userId()` to
	 * `$actor->userId() ?: get_current_user_id()`, in the primitive branch or in the meta
	 * capability branch.
	 *
	 * @since 0.1.0
	 */
	public function test_the_actor_is_judged_never_the_logged_in_user(): void {
		$administrator = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$subscriber    = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		get_role( 'administrator' )->add_cap( 'seocart_manage_inventory' );
		get_role( 'administrator' )->add_cap( 'seocart_view_orders' );
		wp_set_current_user( $administrator );

		$this->assertSame( AuthorizationError::Denied, $this->denial( Actor::user( $subscriber ), 'seocart_manage_inventory' )->errorCode(), 'A subscriber, while an administrator is logged in.' );
		$this->assertSame( AuthorizationError::Denied, $this->denial( Actor::user( 0 ), 'seocart_manage_inventory' )->errorCode(), 'A visitor, while an administrator is logged in.' );
		$this->assertSame( AuthorizationError::Denied, $this->denial( Actor::user( 0 ), 'seocart_view_order', 42 )->errorCode(), 'A visitor, on an order the logged-in administrator may see.' );

		wp_set_current_user( 0 );

		$this->authorizer->authorize( Actor::system( 'cli', $administrator ), 'seocart_manage_inventory' );
		$this->assertTrue( $this->authorizer->allows( Actor::system( 'cli', $administrator ), 'seocart_manage_inventory' ), 'A CLI actor is judged as the user it is bound to.' );
	}

	/**
	 * Tests that a product meta capability is allowed on a product only, never on an ordinary post.
	 *
	 * An editor may edit other people's published posts, and core maps `edit_seocart_product`
	 * through the type of whichever post the check names; the first assertion shows that without
	 * the authorizer, the editor would pass on an ordinary post.
	 *
	 * Planted violation: in Authorizer::isAbout(), return `true` first.
	 *
	 * @since 0.1.0
	 */
	public function test_a_product_meta_capability_is_checked_only_on_a_product(): void {
		$author        = self::factory()->user->create( array( 'role' => 'author' ) );
		$editor        = self::factory()->user->create( array( 'role' => 'editor' ) );
		$administrator = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$ordinary_post = self::factory()->post->create(
			array(
				'post_author' => $author,
				'post_status' => 'publish',
			)
		);
		$product       = self::factory()->post->create(
			array(
				'post_type'   => ProductCapabilities::POST_TYPE,
				'post_author' => $author,
				'post_status' => 'publish',
			)
		);

		foreach ( array( 'edit_seocart_products', 'edit_others_seocart_products', 'edit_published_seocart_products', 'delete_others_seocart_products', 'delete_published_seocart_products' ) as $primitive ) {
			get_role( 'administrator' )->add_cap( $primitive );
		}

		$this->assertTrue( user_can( $editor, 'edit_seocart_product', $ordinary_post ), 'Core does not map the product capability through the ordinary post, so the denial below would prove nothing.' );

		$this->assertFalse( $this->authorizer->allows( Actor::user( $editor ), 'edit_seocart_product', $ordinary_post ), 'An editor, on an ordinary post.' );
		$this->assertFalse( $this->authorizer->allows( Actor::user( $administrator ), 'delete_seocart_product', $ordinary_post ), 'An administrator, on an ordinary post.' );
		$this->assertFalse( $this->authorizer->allows( Actor::user( $administrator ), 'read_seocart_product', $ordinary_post ), 'An administrator, on an ordinary post.' );

		$this->assertTrue( $this->authorizer->allows( Actor::user( $administrator ), 'edit_seocart_product', $product ), 'A user holding the product capabilities, on a product.' );
		$this->assertTrue( $this->authorizer->allows( Actor::user( $administrator ), 'delete_seocart_product', $product ), 'A user holding the product capabilities, on a product.' );
		$this->assertFalse( $this->authorizer->allows( Actor::user( $editor ), 'edit_seocart_product', $product ), 'An editor holds no product capability.' );

		$this->assertSame( AuthorizationError::Denied, $this->denial( Actor::user( $administrator ), 'edit_seocart_product', $product + 1000 )->errorCode(), 'A post that does not exist.' );
	}

	/**
	 * Runs a check that must be denied, and returns the denial.
	 *
	 * @since 0.1.0
	 *
	 * @param Actor           $actor      Who is acting.
	 * @param string          $capability The capability.
	 * @param int|string|null $identifier Optional. The resource. Default null.
	 * @return CodedException The exception the check raised.
	 */
	private function denial( Actor $actor, string $capability, int|string|null $identifier = null ): CodedException {
		try {
			$this->authorizer->authorize( $actor, $capability, $identifier );
		} catch ( CodedException $denied ) {
			return $denied;
		}

		$this->fail( 'The authorizer let the actor through.' );
	}
}
