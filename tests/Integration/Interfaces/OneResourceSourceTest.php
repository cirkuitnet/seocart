<?php
/**
 * Tests that the permission check and the service read a resource id from the same place
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Interfaces;

use SEOCart\Application\Operations\CliBinding;
use SEOCart\Application\Operations\OperationDefinition;
use SEOCart\Application\Operations\OperationRegistry;
use SEOCart\Application\Operations\RestBinding;
use SEOCart\Application\Operations\WriteMethod;
use SEOCart\Platform\Authorization\CapabilityDeclaration;
use SEOCart\Platform\Authorization\CapabilityMapper;
use SEOCart\Platform\Authorization\MetaCapabilityResolver;
use SEOCart\Tests\Fixtures\Operations\FixtureStockOperation;
use SEOCart\Tests\Support\OperationSurfaces;
use WP_UnitTestCase;

/**
 * One source for a resource id: the URL segment the route declares.
 *
 * WP_REST_Request::get_param() prefers a JSON body value, then a form body value, then a query
 * value, and only then the URL segment. A permission check that read get_param() and a service
 * that read the URL could therefore check one resource and change another. The REST adapter refuses
 * a request that sends a route parameter anywhere but the URL, before the permission check runs,
 * so both read the segment.
 *
 * The fixture requires a primitive; a variant of it checks the meta capability
 * `seocart_view_order` on the item instead, through the one map_meta_cap callback and a test
 * resolver that lets the user act on OWNED only and records every item it is asked about.
 *
 * @since 0.1.0
 */
final class OneResourceSourceTest extends WP_UnitTestCase {

	/**
	 * The item the test user may act on.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const OWNED = 'dddddddd-0000-4000-8000-000000000001';

	/**
	 * An item the test user may not act on.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const OTHER = 'dddddddd-0000-4000-8000-000000000002';

	/**
	 * The route of the meta-capability variant, below the namespace.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const OWNED_ROUTE = '/fixture-owned-stock/%s/adjustments';

	/**
	 * The items the resolver was asked about, in order.
	 *
	 * @since 0.1.0
	 *
	 * @var list<mixed>
	 */
	private static array $asked = array();

	/**
	 * The surfaces, wired for the fixture and its variant.
	 *
	 * @since 0.1.0
	 *
	 * @var OperationSurfaces
	 */
	private OperationSurfaces $surfaces;

	/**
	 * Hooks the mapper with the test resolver, registers both operations and logs in the test user.
	 *
	 * @since 0.1.0
	 */
	public function set_up(): void {
		parent::set_up();

		self::$asked = array();

		$mapper = new CapabilityMapper( new CapabilityDeclaration() );
		$mapper->registerMetaCapability( 'seocart_view_order', array( self::class, 'resolver' ) );

		add_filter( 'map_meta_cap', array( $mapper, 'map' ), 10, 4 );

		$registry = new OperationRegistry();
		FixtureStockOperation::register( $registry );
		$registry->add( 'fixture_stock.adjust_owned_stock', array( self::class, 'ownedDefinition' ) );

		$this->surfaces = new OperationSurfaces( $registry );

		$user = self::factory()->user->create_and_get( array( 'role' => 'subscriber' ) );
		$user->add_cap( 'seocart_manage_inventory' );
		$user->add_cap( 'seocart_view_orders' );

		wp_set_current_user( $user->ID );
	}

	/**
	 * Discards the REST server and the Abilities registries.
	 *
	 * @since 0.1.0
	 */
	public function tear_down(): void {
		OperationSurfaces::discard();

		parent::tear_down();
	}

	/**
	 * Provides the places a request can repeat a route parameter.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{string}> The place.
	 */
	public static function otherPlaces(): array {
		return array(
			'the query'     => array( 'query' ),
			'the JSON body' => array( 'json' ),
			'a form body'   => array( 'form' ),
		);
	}

	/**
	 * Tests that the fixture refuses an item id sent anywhere but the URL, and never calls the service.
	 *
	 * @since 0.1.0
	 *
	 * @dataProvider otherPlaces
	 *
	 * @param string $place Where the request repeats the item id.
	 */
	public function test_a_route_parameter_sent_outside_the_url_is_refused( string $place ): void {
		$response = $this->send( '/fixture-stock/' . self::OWNED . '/adjustments', $place, self::OTHER );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'rest_invalid_param', $response->get_data()['code'] );
		$this->assertStringContainsString( 'item_id is part of the URL', (string) wp_json_encode( $response->get_data() ) );
		$this->assertSame( array(), $this->surfaces->service->calls );
	}

	/**
	 * Tests that the meta-capability check never sees an item id sent outside the URL: the request is refused first.
	 *
	 * @since 0.1.0
	 *
	 * @dataProvider otherPlaces
	 *
	 * @param string $place Where the request repeats the item id.
	 */
	public function test_the_permission_check_never_sees_an_item_id_from_outside_the_url( string $place ): void {
		$response = $this->send( sprintf( self::OWNED_ROUTE, self::OTHER ), $place, self::OWNED );

		$this->assertSame( array(), array_column( $this->surfaces->service->calls, 'item_id' ), 'The service changed an item while the permission check looked at another.' );
		$this->assertSame( array(), self::$asked, 'The resolver was asked about an item: the permission check ran on a request that should have been refused.' );
		$this->assertSame( 400, $response->get_status() );
	}

	/**
	 * Tests that, from the URL alone, the permission check and the service both get the URL's item.
	 *
	 * @since 0.1.0
	 */
	public function test_the_permission_check_and_the_service_read_the_url(): void {
		$allowed = $this->surfaces->rest( 'POST', sprintf( self::OWNED_ROUTE, self::OWNED ), array( 'delta' => 1 ) );

		$this->assertSame( 200, $allowed->get_status() );
		$this->assertSame( array( self::OWNED ), self::$asked );
		$this->assertSame( self::OWNED, $this->surfaces->service->calls[0]['item_id'] );

		$refused = $this->surfaces->rest( 'POST', sprintf( self::OWNED_ROUTE, self::OTHER ), array( 'delta' => 1 ) );

		$this->assertSame( 403, $refused->get_status() );
		$this->assertSame( array( self::OWNED, self::OTHER ), self::$asked );
		$this->assertCount( 1, $this->surfaces->service->calls );
	}

	/**
	 * Tests that the ability and the command check the meta capability on the item of their input.
	 *
	 * @since 0.1.0
	 */
	public function test_the_ability_and_the_command_check_the_item_of_their_input(): void {
		$refused_ability = $this->surfaces->ability(
			'seocart/fixture-adjust-owned-stock',
			array(
				'item_id' => self::OTHER,
				'delta'   => 1,
			)
		);
		$refused_command = $this->surfaces->cli( 'seocart fixture-owned-stock adjust', array( self::OTHER ), array( 'delta' => '1' ) );
		$allowed_ability = $this->surfaces->ability(
			'seocart/fixture-adjust-owned-stock',
			array(
				'item_id' => self::OWNED,
				'delta'   => 1,
			)
		);
		$allowed_command = $this->surfaces->cli( 'seocart fixture-owned-stock adjust', array( self::OWNED ), array( 'delta' => '1' ) );

		$this->assertWPError( $refused_ability );
		$this->assertSame( 'ability_invalid_permissions', $refused_ability->get_error_code() );
		$this->assertStringStartsWith( 'rest_forbidden: ', (string) $refused_command['failure'] );
		$this->assertIsArray( $allowed_ability );
		$this->assertNull( $allowed_command['failure'] );
		$this->assertSame( array( self::OTHER, self::OTHER, self::OWNED, self::OWNED ), self::$asked );
		$this->assertSame( array( self::OWNED, self::OWNED ), array_column( $this->surfaces->service->calls, 'item_id' ) );
	}

	/**
	 * Declares the fixture's variant that checks `seocart_view_order` on the item.
	 *
	 * @since 0.1.0
	 *
	 * @return OperationDefinition The definition.
	 */
	public static function ownedDefinition(): OperationDefinition {
		$fixture = FixtureStockOperation::definition();

		return new OperationDefinition(
			id: 'fixture_stock.adjust_owned_stock',
			label: $fixture->label(),
			summary: 'Adjusts the stock level of one fixture item the user may act on.',
			input: $fixture->input(),
			output: $fixture->output(),
			capability: 'seocart_view_order',
			resource_field: 'item_id',
			errors: $fixture->errors(),
			annotations: $fixture->annotations(),
			service: $fixture->service(),
			rest: new RestBinding( '/fixture-owned-stock/{item_id}/adjustments', WriteMethod::Post ),
			ability: 'fixture-adjust-owned-stock',
			cli: new CliBinding( array( 'fixture-owned-stock', 'adjust' ), array( 'item_id' ) )
		);
	}

	/**
	 * Builds the test resolver: OWNED needs `seocart_view_orders`, any other item cannot be resolved.
	 *
	 * @since 0.1.0
	 *
	 * @return MetaCapabilityResolver The resolver.
	 */
	public static function resolver(): MetaCapabilityResolver {
		return new class() implements MetaCapabilityResolver {

			/**
			 * Returns the primitives a user needs for an item.
			 *
			 * @param int               $userId The user.
			 * @param array<int, mixed> $args   The item first.
			 * @return list<string>|null The primitives, or null for an item that cannot be resolved.
			 */
			public function primitivesFor( int $userId, array $args ): ?array {
				OneResourceSourceTest::recordAsked( $args[0] ?? null );

				return OneResourceSourceTest::OWNED_ITEM === ( $args[0] ?? null ) ? array( 'seocart_view_orders' ) : null;
			}
		};
	}

	/**
	 * Records an item the resolver was asked about.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $item The item.
	 */
	public static function recordAsked( $item ): void {
		self::$asked[] = $item;
	}

	/**
	 * The item the test user may act on, for the resolver.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const OWNED_ITEM = self::OWNED;

	/**
	 * Sends an adjustment whose URL names one item and which repeats `item_id` in one other place.
	 *
	 * @since 0.1.0
	 *
	 * @param string $route The route below the namespace.
	 * @param string $place `query`, `json` or `form`.
	 * @param string $item  The item id repeated there.
	 * @return \WP_REST_Response The response.
	 */
	private function send( string $route, string $place, string $item ): \WP_REST_Response {
		$request = new \WP_REST_Request( 'POST', '/' . RestBinding::NAMESPACE . $route );

		if ( 'query' === $place ) {
			$request->set_query_params( array( 'item_id' => $item ) );
			$request->set_body_params( array( 'delta' => 1 ) );
		} elseif ( 'json' === $place ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body(
				(string) wp_json_encode(
					array(
						'item_id' => $item,
						'delta'   => 1,
					)
				)
			);
		} else {
			$request->set_body_params(
				array(
					'item_id' => $item,
					'delta'   => 1,
				)
			);
		}

		return rest_do_request( $request );
	}
}
