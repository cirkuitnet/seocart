<?php
/**
 * Tests the authorizer's decisions with WordPress stubbed
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Platform\Authorization;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Platform\Authorization\AuthorizationError;
use SEOCart\Platform\Authorization\Authorizer;
use SEOCart\Platform\Authorization\CapabilityDeclaration;
use SEOCart\Support\Error\CodedException;
use SEOCart\Support\Error\ErrorDefinition;

/**
 * Proves what the authorizer asks WordPress, and what it does with the answer.
 *
 * The function user_can() is stubbed with a fixed set of grants and records every call, so each
 * test can assert both the decision and the question behind it. get_current_user_id() and
 * current_user_can() are stubbed as an administrator who may do anything, and count their
 * calls: the authorizer must never consult them. The integration test of the same name makes
 * the same checks through WordPress and the one map_meta_cap callback.
 *
 * @since 0.1.0
 */
final class AuthorizerTest extends TestCase {

	/**
	 * The logged-in user the stubs report: an administrator the authorizer must ignore.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private const LOGGED_IN_ADMINISTRATOR = 1;

	/**
	 * The authorizer under test.
	 *
	 * @since 0.1.0
	 *
	 * @var Authorizer
	 */
	private Authorizer $authorizer;

	/**
	 * The capabilities user_can() grants, keyed by user id; a check on a resource is written `capability@resource`.
	 *
	 * @since 0.1.0
	 *
	 * @var array<int, list<string>>
	 */
	private array $grants = array();

	/**
	 * Every call to user_can(): user id, capability, and the resource if one was passed.
	 *
	 * @since 0.1.0
	 *
	 * @var list<list<mixed>>
	 */
	private array $userCanCalls = array();

	/**
	 * How many times the logged-in user was consulted.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private int $loggedInUserReads = 0;

	/**
	 * Stubs WordPress and builds the authorizer.
	 *
	 * @since 0.1.0
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->authorizer = new Authorizer( new CapabilityDeclaration() );
		$this->grants     = array( self::LOGGED_IN_ADMINISTRATOR => array( 'seocart_manage_inventory', 'seocart_view_orders', 'seocart_view_order@42' ) );

		Functions\when( 'user_can' )->alias(
			function ( $user, string $capability, ...$args ): bool {
				$this->userCanCalls[] = array_merge( array( $user, $capability ), $args );
				$key                  = array() === $args ? $capability : $capability . '@' . $args[0];

				return in_array( $key, $this->grants[ $user ] ?? array(), true );
			}
		);

		Functions\when( 'get_current_user_id' )->alias(
			function (): int {
				++$this->loggedInUserReads;

				return self::LOGGED_IN_ADMINISTRATOR;
			}
		);

		Functions\when( 'current_user_can' )->alias(
			function (): bool {
				++$this->loggedInUserReads;

				return true;
			}
		);

		// Post 10 is a product, post 11 an ordinary post; nothing else exists.
		Functions\when( 'get_post_type' )->alias(
			static function ( $post ) {
				$types = array(
					10 => 'seocart_product',
					11 => 'post',
				);

				return $types[ $post ] ?? false;
			}
		);

		Functions\when( '__' )->returnArg();
	}

	/**
	 * Tears Brain Monkey down.
	 *
	 * @since 0.1.0
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Tests that a user holding the primitive is allowed, and that the question was about that user.
	 *
	 * @since 0.1.0
	 */
	public function test_a_user_holding_the_primitive_is_allowed(): void {
		$this->grants[5] = array( 'seocart_manage_inventory' );

		$this->authorizer->authorize( Actor::user( 5 ), 'seocart_manage_inventory' );

		$this->assertSame( array( array( 5, 'seocart_manage_inventory' ) ), $this->userCanCalls );
	}

	/**
	 * Tests that a user without the capability is stopped with `authorization.denied`, HTTP 403.
	 *
	 * Planted violation: in Authorizer::allows(), change the primitive branch's
	 * `return user_can( $actor->userId(), $capability );` to
	 * `return true || user_can( $actor->userId(), $capability );`.
	 *
	 * @since 0.1.0
	 */
	public function test_a_user_without_the_capability_is_denied(): void {
		$denied = $this->denial( fn() => $this->authorizer->authorize( Actor::user( 5 ), 'seocart_manage_inventory' ) );

		$this->assertSame( AuthorizationError::Denied, $denied->errorCode() );
		$this->assertSame( 'authorization.denied', $denied->getMessage() );
		$this->assertSame( array( 'capability' => 'seocart_manage_inventory' ), $denied->context() );

		$row = ErrorDefinition::of( AuthorizationError::Denied );

		$this->assertSame( 403, $row->httpStatus() );
		$this->assertSame( 'Sorry, you are not allowed to do that. It requires the seocart_manage_inventory capability.', $row->render( $denied->context() ) );
	}

	/**
	 * Tests that the actor is checked, never the logged-in user.
	 *
	 * The stubs report an administrator as logged in who may do anything. A visitor, another
	 * user and a system actor bound to another user must each be judged on their own grants.
	 *
	 * Planted violations: in Authorizer::allows(), change `$actor->userId()` to
	 * `$actor->userId() ?: get_current_user_id()`, in the primitive branch or in the meta
	 * capability branch: a visitor then acts as whoever is logged in.
	 *
	 * @since 0.1.0
	 */
	public function test_the_actor_is_checked_never_the_logged_in_user(): void {
		$this->assertFalse( $this->authorizer->allows( Actor::user( 0 ), 'seocart_manage_inventory' ), 'A visitor.' );
		$this->assertFalse( $this->authorizer->allows( Actor::user( 7 ), 'seocart_manage_inventory' ), 'Another user.' );
		$this->assertFalse( $this->authorizer->allows( Actor::system( 'cli', 7 ), 'seocart_manage_inventory' ), 'A system actor bound to another user.' );
		$this->assertFalse( $this->authorizer->allows( Actor::user( 0 ), 'seocart_view_order', 42 ), 'A visitor, on a resource the logged-in administrator may see.' );

		$this->assertSame( 0, $this->loggedInUserReads, 'The authorizer consulted the logged-in user.' );
		$this->assertSame( array( 0, 7, 7, 0 ), array_column( $this->userCanCalls, 0 ), 'user_can() must be asked about the actor\'s user.' );
	}

	/**
	 * Tests that a product meta capability is checked only on a product.
	 *
	 * Core maps `edit_seocart_product` through the type of the post the check names, so on an
	 * ordinary post it would answer with the capabilities for editing posts, which an editor
	 * holds. The user here holds the capability on posts 10 and 11 alike; only post 10, the
	 * product, may be allowed.
	 *
	 * Planted violation: in Authorizer::isAbout(), return `true` first.
	 *
	 * @since 0.1.0
	 */
	public function test_a_product_meta_capability_is_checked_only_on_a_product(): void {
		$this->grants[5] = array( 'edit_seocart_product@10', 'edit_seocart_product@11', 'delete_seocart_product@11', 'read_seocart_product@11' );

		$this->assertTrue( $this->authorizer->allows( Actor::user( 5 ), 'edit_seocart_product', 10 ), 'A product.' );
		$this->assertTrue( $this->authorizer->allows( Actor::user( 5 ), 'edit_seocart_product', '10' ), 'A product, its id written as digits.' );
		$this->assertFalse( $this->authorizer->allows( Actor::user( 5 ), 'edit_seocart_product', 11 ), 'An ordinary post.' );
		$this->assertFalse( $this->authorizer->allows( Actor::user( 5 ), 'delete_seocart_product', 11 ), 'An ordinary post.' );
		$this->assertFalse( $this->authorizer->allows( Actor::user( 5 ), 'read_seocart_product', 11 ), 'An ordinary post.' );
		$this->assertFalse( $this->authorizer->allows( Actor::user( 5 ), 'edit_seocart_product', 12 ), 'A post that does not exist.' );
		$this->assertFalse( $this->authorizer->allows( Actor::user( 5 ), 'edit_seocart_product', '0190c8a2-uuid' ), 'A product is addressed by its post id.' );

		$this->assertSame( array( array( 5, 'edit_seocart_product', 10 ), array( 5, 'edit_seocart_product', 10 ) ), $this->userCanCalls, 'Only the product may be asked about.' );
	}

	/**
	 * Tests that a system actor is checked as the user it is bound to, and is exempt from nothing.
	 *
	 * @since 0.1.0
	 */
	public function test_a_system_actor_is_checked_as_its_user(): void {
		$this->grants[5] = array( 'seocart_manage_inventory' );

		$this->assertTrue( $this->authorizer->allows( Actor::system( 'cli', 5 ), 'seocart_manage_inventory' ) );
		$this->assertFalse( $this->authorizer->allows( Actor::system( 'cli', 5 ), 'seocart_refund_orders' ) );
	}

	/**
	 * Tests that a meta capability is checked on its resource, with digits read as an id.
	 *
	 * @since 0.1.0
	 */
	public function test_a_meta_capability_is_checked_on_its_resource(): void {
		$this->grants[5] = array( 'seocart_view_order@42', 'seocart_view_order@0190c8a2-uuid' );

		$this->authorizer->authorize( Actor::user( 5 ), 'seocart_view_order', 42 );
		$this->authorizer->authorize( Actor::user( 5 ), 'seocart_view_order', '42' );
		$this->authorizer->authorize( Actor::user( 5 ), 'seocart_view_order', '0190c8a2-uuid' );

		$this->assertSame(
			array(
				array( 5, 'seocart_view_order', 42 ),
				array( 5, 'seocart_view_order', 42 ),
				array( 5, 'seocart_view_order', '0190c8a2-uuid' ),
			),
			$this->userCanCalls
		);

		$this->assertSame( AuthorizationError::Denied, $this->denial( fn() => $this->authorizer->authorize( Actor::user( 5 ), 'seocart_view_order', 7 ) )->errorCode(), 'Another resource.' );
	}

	/**
	 * Tests that a meta capability with no usable resource is denied without asking anyone.
	 *
	 * @since 0.1.0
	 *
	 * @dataProvider unusableResources
	 *
	 * @param mixed $identifier A value that names no resource.
	 */
	public function test_a_meta_capability_without_a_usable_resource_is_denied_without_asking( mixed $identifier ): void {
		$this->assertFalse( $this->authorizer->allows( Actor::user( self::LOGGED_IN_ADMINISTRATOR ), 'seocart_view_order', $identifier ) );
		$this->assertSame( array(), $this->userCanCalls, 'Without a usable resource there is nothing to ask about.' );
	}

	/**
	 * Provides values that name no resource.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{mixed}>
	 */
	public function unusableResources(): array {
		return array(
			'none'              => array( null ),
			'zero'              => array( 0 ),
			'zero as digits'    => array( '0' ),
			'a negative number' => array( -3 ),
			'an empty string'   => array( '' ),
			'a blank string'    => array( '  ' ),
			'an array'          => array( array( 42 ) ),
			'a float'           => array( 42.0 ),
			'a boolean'         => array( true ),
		);
	}

	/**
	 * Tests that a capability the plugin does not declare cannot be checked at all.
	 *
	 * `exist` is held by every visitor and `read` by every customer, so a use case guarded by
	 * either would be open to them.
	 *
	 * @since 0.1.0
	 *
	 * @dataProvider uncheckableCapabilities
	 *
	 * @param string $capability The capability.
	 * @param mixed  $identifier The resource passed with it.
	 */
	public function test_an_uncheckable_capability_is_a_programming_error( string $capability, mixed $identifier ): void {
		try {
			$this->authorizer->allows( Actor::user( self::LOGGED_IN_ADMINISTRATOR ), $capability, $identifier );
			$this->fail( 'The authorizer answered a question it must refuse.' );
		} catch ( \InvalidArgumentException $refusal ) {
			$this->assertSame( array(), $this->userCanCalls );
		}
	}

	/**
	 * Provides checks the authorizer must refuse.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{string, mixed}>
	 */
	public function uncheckableCapabilities(): array {
		return array(
			'core exist'                      => array( 'exist', null ),
			'core read'                       => array( 'read', null ),
			'core manage_options'             => array( 'manage_options', null ),
			'core edit_post on a resource'    => array( 'edit_post', 42 ),
			'an undeclared plugin capability' => array( 'seocart_frobnicate', null ),
			'a primitive with a resource'     => array( 'seocart_view_orders', 42 ),
		);
	}

	/**
	 * Runs work that must be denied, and returns the denial.
	 *
	 * @since 0.1.0
	 *
	 * @param callable(): mixed $work The work.
	 * @return CodedException The exception the work raised.
	 */
	private function denial( callable $work ): CodedException {
		try {
			$work();
		} catch ( CodedException $denied ) {
			return $denied;
		}

		$this->fail( 'The authorizer let the actor through.' );
	}
}
