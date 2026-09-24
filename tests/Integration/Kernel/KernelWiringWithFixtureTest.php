<?php
/**
 * Tests the production wiring end to end with a fixture operation in place of the production ones
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Kernel;

use SEOCart\Application\Operations\OperationRegistry;
use SEOCart\Application\Operations\Operations;
use SEOCart\Application\Operations\RestBinding;
use SEOCart\Platform\Jobs\JobHandlers;
use SEOCart\Platform\Kernel\Container;
use SEOCart\Platform\Kernel\Kernel;
use SEOCart\Platform\Kernel\Modules;
use SEOCart\Tests\Fixtures\Operations\FixtureStockOperation;
use SEOCart\Tests\Fixtures\Operations\FixtureStockService;
use SEOCart\Tests\Support\KernelHooks;
use SEOCart\Tests\Support\OperationSurfaces;
use SEOCart\Tests\Support\OperationSurfaceWalker;
use WP_Ability;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * The real Modules::register() and subscribe(), on a container whose only replacements are the
 * operation registry, holding the fixture operation, and the fixture's service. Its route,
 * ability and command must appear on all three surfaces and reach the service: the production
 * path, not a test's own registration of the adapters.
 *
 * The same container shape also answers what the wiring must hold for every production
 * operation and job: each service an operation names, and each job handler, is bound.
 *
 * The kernel's own callbacks, which it hooked when the plugin booted, are taken off the REST and
 * ability hooks first (KernelHooks), so what the surfaces hold comes from this container.
 *
 * Planted violations, one at a time, each put back afterwards:
 *
 * - In Modules::kernelSubscribe(), remove `$container->get( RestAdapter::class )->register();` from
 *   the `rest_api_init` closure: the route walk reports that the fixture declares a route that is
 *   not registered.
 * - In Modules::operationsRegister(), resolve services with `static fn( string $service ): object => new \stdClass()`:
 *   no surface reaches the fixture's service.
 * - In Modules::jobsRegister(), remove the binding of MigrationAttempt:
 *   test_every_job_handler_is_bound fails on it.
 *
 * @since 0.1.0
 *
 * @group contract
 */
final class KernelWiringWithFixtureTest extends WP_UnitTestCase {

	/**
	 * Discards the REST server and the Abilities registries the test built.
	 *
	 * @since 0.1.0
	 */
	public function tear_down(): void {
		OperationSurfaces::discard();

		parent::tear_down();
	}

	/**
	 * Tests that the fixture's route, ability and command are registered by the real wiring, and each reaches its service.
	 *
	 * @since 0.1.0
	 */
	public function test_the_fixture_is_served_on_every_surface_through_the_production_wiring(): void {
		$service   = new FixtureStockService();
		$registry  = new OperationRegistry();
		$container = new Container(
			array(
				OperationRegistry::class   => static fn(): OperationRegistry => $registry,
				FixtureStockService::class => static fn(): FixtureStockService => $service,
			)
		);

		FixtureStockOperation::register( $registry );
		Modules::register( $container );

		KernelHooks::detach( 'rest_api_init', 'wp_abilities_api_categories_init', 'wp_abilities_api_init' );
		OperationSurfaces::discard();
		Modules::subscribe( $container );

		$commands = array();

		Modules::registerCommands(
			$container,
			static function ( string $name, callable $command ) use ( &$commands ): void {
				$commands[ $name ] = $command;
			},
			static function (): void {},
			function ( string $message ): void {
				$this->fail( 'The command failed: ' . $message );
			}
		);

		$this->assertSame( array(), OperationSurfaceWalker::restViolations( rest_get_server(), $registry ) );
		$this->assertSame( array(), OperationSurfaceWalker::abilityViolations( self::abilityNames(), $registry ) );
		$this->assertSame( array(), OperationSurfaceWalker::commandViolations( array_keys( $commands ), $registry, OperationSurfaceWalker::MAINTENANCE_COMMANDS ) );

		add_filter(
			'user_has_cap',
			static function ( array $allcaps ): array {
				$allcaps[ FixtureStockService::CAPABILITY ] = true;

				return $allcaps;
			}
		);
		wp_set_current_user( 1 );

		$request = new WP_REST_Request( 'POST', '/' . RestBinding::NAMESPACE . str_replace( '{item_id}', FixtureStockOperation::EXAMPLE_ITEM, FixtureStockOperation::ROUTE ) );

		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( array( 'delta' => 1 ) ) );

		$this->assertSame( 200, rest_do_request( $request )->get_status(), 'The route did not run the operation.' );

		$ability = wp_get_ability( FixtureStockOperation::ABILITY );

		$this->assertInstanceOf( WP_Ability::class, $ability );
		$this->assertIsArray(
			$ability->execute(
				array(
					'item_id' => FixtureStockOperation::EXAMPLE_ITEM,
					'delta'   => 1,
				)
			),
			'The ability did not run the operation.'
		);

		$commands[ FixtureStockOperation::COMMAND ]( array( FixtureStockOperation::EXAMPLE_ITEM ), array( 'delta' => '1' ) );

		$this->assertCount( 3, $service->calls, 'The route, the ability and the command must each reach the service the container resolves.' );
	}

	/**
	 * Tests that every service a production operation names is bound, so the invoker can resolve it.
	 *
	 * @since 0.1.0
	 */
	public function test_every_operation_service_is_bound(): void {
		$container = Kernel::container();
		$services  = array();

		foreach ( Operations::registry()->all() as $definition ) {
			$services[] = $definition->service()[0];
		}

		$this->assertNotSame( array(), $services, 'The production registry names no service, so the check would prove nothing.' );

		foreach ( array_unique( $services ) as $service ) {
			$this->assertTrue( $container->has( $service ), "The service {$service} is not bound, so its operation cannot run." );
			$this->assertInstanceOf( $service, $container->get( $service ) );
		}
	}

	/**
	 * Tests that every production job handler is bound, so the runner can build it when one of its jobs runs.
	 *
	 * @since 0.1.0
	 */
	public function test_every_job_handler_is_bound(): void {
		$container = Kernel::container();

		foreach ( JobHandlers::PRODUCTION as $handler ) {
			$this->assertTrue( $container->has( $handler ), "The job handler {$handler} is not bound, so its jobs would fail." );
			$this->assertInstanceOf( $handler, $container->get( $handler ) );
		}
	}

	/**
	 * Returns the name of every ability WordPress holds.
	 *
	 * @since 0.1.0
	 *
	 * @return list<string> The names.
	 */
	private static function abilityNames(): array {
		return array_values( array_map( static fn( WP_Ability $ability ): string => $ability->get_name(), wp_get_abilities() ) );
	}
}
