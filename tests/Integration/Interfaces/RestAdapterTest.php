<?php
/**
 * Tests the REST route an operation is registered as
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Interfaces;

use SEOCart\Application\Operations\CompiledOperation;
use SEOCart\Application\Operations\OperationRegistry;
use SEOCart\Interfaces\Operations\OperationInvoker;
use SEOCart\Interfaces\Operations\RestAdapter;
use SEOCart\Platform\Authorization\PermissionCallback;
use SEOCart\Tests\Fixtures\Operations\FixtureStockOperation;
use SEOCart\Tests\Fixtures\Operations\FixtureStockService;
use SEOCart\Tests\Support\OperationSurfaces;
use SEOCart\Tests\Support\RoutePermissionWalker;
use WP_UnitTestCase;

/**
 * The fixture's route as WordPress holds it: method, arguments, permission callback and schema;
 * the route walker's verdict on it; and what the route does with a success and with each kind
 * of failure.
 *
 * @since 0.1.0
 */
final class RestAdapterTest extends WP_UnitTestCase {

	/**
	 * An item id.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const ITEM = 'bbbbbbbb-0000-4000-8000-000000000001';

	/**
	 * The surfaces, wired for the fixture.
	 *
	 * @since 0.1.0
	 *
	 * @var OperationSurfaces
	 */
	private OperationSurfaces $surfaces;

	/**
	 * Registers the fixture and logs in a user who may adjust stock.
	 *
	 * @since 0.1.0
	 */
	public function set_up(): void {
		parent::set_up();

		$registry = new OperationRegistry();
		FixtureStockOperation::register( $registry );

		$this->surfaces = new OperationSurfaces( $registry );

		$user = self::factory()->user->create_and_get( array( 'role' => 'subscriber' ) );
		$user->add_cap( 'seocart_manage_inventory' );

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
	 * Tests that the route holds the derived method, the compiled arguments, the factory's callback and the compiled schema.
	 *
	 * @since 0.1.0
	 */
	public function test_the_route_is_registered_from_the_declaration(): void {
		$definition = FixtureStockOperation::definition();
		$compiled   = new CompiledOperation( $definition );
		$route      = RestAdapter::serverRoute( $definition->rest() ?? $this->fail( 'The fixture has a route.' ) );
		$routes     = $this->surfaces->server()->get_routes();

		$this->assertSame( '/seocart/v1/fixture-stock/(?P<item_id>[^/]+)/adjustments', $route );
		$this->assertArrayHasKey( $route, $routes );
		$this->assertCount( 1, $routes[ $route ] );

		$endpoint = $routes[ $route ][0];

		$this->assertSame( array( 'POST' => true ), $endpoint['methods'] );
		$this->assertInstanceOf( PermissionCallback::class, $endpoint['permission_callback'] );
		$this->assertSame( 'seocart_manage_inventory', $endpoint['permission_callback']->capability() );

		$arguments = $endpoint['args'];

		$this->assertSame( array( RestAdapter::class, 'validatePathParameter' ), $arguments['item_id']['validate_callback'] );

		unset( $arguments['item_id']['validate_callback'] );

		$this->assertSame( $compiled->restArguments(), $arguments, 'The arguments are the compiled ones, with nothing added but the URL-only rule.' );

		$options = $this->surfaces->server()->get_route_options( $route );

		$this->assertIsArray( $options );
		$this->assertSame( $compiled->outputSchema(), call_user_func( $options['schema'] ) );
	}

	/**
	 * Tests that the route walker finds nothing wrong with the fixture's route.
	 *
	 * @since 0.1.0
	 */
	public function test_the_route_walker_passes(): void {
		$walk = ( new RoutePermissionWalker() )->walk( $this->surfaces->server() );

		$this->assertContains( '/seocart/v1/fixture-stock/(?P<item_id>[^/]+)/adjustments', $walk['plugin_routes'] );
		$this->assertSame( array(), $walk['violations'], RoutePermissionWalker::describe( $walk['violations'] ) );
	}

	/**
	 * Tests that the route cannot be reached with GET: the operation changes the store.
	 *
	 * @since 0.1.0
	 */
	public function test_a_changing_operation_cannot_be_reached_with_get(): void {
		$response = $this->surfaces->rest( 'GET', '/fixture-stock/' . self::ITEM . '/adjustments', array(), array( 'delta' => 1 ) );

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'rest_no_route', $response->get_data()['code'] );
		$this->assertSame( array(), $this->surfaces->service->calls );
	}

	/**
	 * Tests that a success answers 200 with the serialized output.
	 *
	 * @since 0.1.0
	 */
	public function test_a_success_answers_with_the_serialized_output(): void {
		$response = $this->surfaces->rest( 'POST', '/fixture-stock/' . self::ITEM . '/adjustments', array( 'delta' => 3 ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			array(
				'item_id' => self::ITEM,
				'on_hand' => FixtureStockService::INITIAL_LEVEL + 3,
				'reason'  => 'correction',
			),
			$response->get_data()
		);
	}

	/**
	 * Tests that the route serves its output schema to an OPTIONS request.
	 *
	 * @since 0.1.0
	 */
	public function test_the_route_serves_its_schema(): void {
		$response = $this->surfaces->rest( 'OPTIONS', '/fixture-stock/' . self::ITEM . '/adjustments' );

		$this->assertSame( ( new CompiledOperation( FixtureStockOperation::definition() ) )->outputSchema(), $response->get_data()['schema'] );
	}

	/**
	 * Tests that a coded error the operation does not declare is still translated, and reported to the developer.
	 *
	 * @since 0.1.0
	 */
	public function test_an_undeclared_code_is_translated_and_reported(): void {
		$this->setExpectedIncorrectUsage( OperationInvoker::class . '::invoke' );

		$response = $this->surfaces->rest(
			'POST',
			'/fixture-stock/' . self::ITEM . '/adjustments',
			array(
				'delta' => 1,
				'note'  => FixtureStockService::FAIL_UNDECLARED,
			)
		);

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'currency.unknown', $response->get_data()['code'] );
	}
}
