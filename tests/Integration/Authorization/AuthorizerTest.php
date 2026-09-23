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
use SEOCart\Support\Error\CodedException;
use SEOCart\Support\Error\ErrorDefinition;
use SEOCart\Tests\Support\ReloadsRoles;
use WP_UnitTestCase;

/**
 * The authorizer with real users, real roles and the plugin's map_meta_cap callback hooked.
 *
 * The callback is hooked with a test resolver for `seocart_view_order` that knows one order,
 * 42, which needs `seocart_view_orders`. Roles are reloaded from the database around each
 * test, because WordPress keeps role changes in the WP_Roles object as well as in the
 * rolled-back option.
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
	 * Hooks the mapper with the test resolver and builds the authorizer.
	 *
	 * @since 0.1.0
	 */
	public function set_up(): void {
		parent::set_up();

		self::reloadRoles();

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
	 * Rolls the test back, then reloads the roles so the next test sees what the database holds.
	 *
	 * @since 0.1.0
	 */
	public function tear_down(): void {
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
	 * Planted violation: in Authorizer::allows(), change the primitive branch's `$actor->userId()`
	 * to `$actor->userId() ?: get_current_user_id()`.
	 *
	 * @since 0.1.0
	 */
	public function test_the_actor_is_judged_never_the_logged_in_user(): void {
		$administrator = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$subscriber    = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		get_role( 'administrator' )->add_cap( 'seocart_manage_inventory' );
		wp_set_current_user( $administrator );

		$this->assertSame( AuthorizationError::Denied, $this->denial( Actor::user( $subscriber ), 'seocart_manage_inventory' )->errorCode(), 'A subscriber, while an administrator is logged in.' );
		$this->assertSame( AuthorizationError::Denied, $this->denial( Actor::user( 0 ), 'seocart_manage_inventory' )->errorCode(), 'A visitor, while an administrator is logged in.' );

		wp_set_current_user( 0 );

		$this->authorizer->authorize( Actor::system( 'cli', $administrator ), 'seocart_manage_inventory' );
		$this->assertTrue( $this->authorizer->allows( Actor::system( 'cli', $administrator ), 'seocart_manage_inventory' ), 'A CLI actor is judged as the user it is bound to.' );
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
