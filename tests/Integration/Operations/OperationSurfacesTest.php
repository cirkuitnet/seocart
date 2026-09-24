<?php
/**
 * Tests that every plugin route, ability and command resolves to one operation definition (DRY rule 1)
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Operations;

use SEOCart\Application\Operations\OperationDefinition;
use SEOCart\Application\Operations\OperationRegistry;
use SEOCart\Application\Operations\Operations;
use SEOCart\Platform\Authorization\PermissionCallback;
use SEOCart\Platform\Kernel\Kernel;
use SEOCart\Platform\Kernel\Modules;
use SEOCart\Platform\Settings\SettingsOperations;
use SEOCart\Tests\Fixtures\Operations\Cli\FixtureMaintenanceCommand;
use SEOCart\Tests\Fixtures\Operations\FixtureStockOperation;
use SEOCart\Tests\Support\KernelHooks;
use SEOCart\Tests\Support\OperationSurfaces;
use SEOCart\Tests\Support\OperationSurfaceWalker;
use SEOCart\Tests\Support\RoutePermissionWalker;
use WP_Ability;
use WP_UnitTestCase;

/**
 * One declaration per operation, on every surface, in both directions.
 *
 * The first tests walk what the plugin itself registers — the routes of a REST server booted as a
 * request boots it, on which the kernel registered the operations' routes, and the abilities
 * WordPress holds — against the production registry. The command half is split in two:
 *
 * - the operation commands: the commands the kernel registers, through the same function
 *   `cli_init` calls, given a recorder in place of WP_CLI::add_command(), must be exactly the
 *   registry's commands;
 * - the maintenance commands: OperationSurfaceWalker::MAINTENANCE_COMMANDS must list exactly the
 *   command classes under the `Cli/` directories of src/, each with its reason, and the kernel
 *   must register every one of them.
 *
 * The walks see real addresses: the settings routes and commands. The self-tests register the
 * fixture through the adapters and must find nothing wrong, then plant each violation — a route,
 * an ability and a command that no operation declares, an operation whose surfaces are not
 * registered, a route at an operation's address guarded by another capability, an unlisted
 * command class and a listed one that is gone — and require each to be reported.
 *
 * Planted violation for the kernel's registration: in Modules::MAINTENANCE_COMMANDS, remove the
 * `seocart migrate` line. test_the_kernel_registers_every_command reports that the maintenance
 * command is listed but not registered.
 *
 * Planted violation for the guard: in PermissionFactory::forRest(), return
 * PermissionCallback::requiring( 'seocart_manage_inventory' ) for every operation.
 * test_the_plugins_routes_and_abilities_resolve_to_its_operations reports both settings routes as
 * guarded by seocart_manage_inventory while their operations declare seocart_manage_settings.
 *
 * @group contract
 *
 * @since 0.1.0
 */
final class OperationSurfacesTest extends WP_UnitTestCase {

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
	 * Tests that every route and ability the plugin registers resolves to a production operation, and back.
	 *
	 * @since 0.1.0
	 */
	public function test_the_plugins_routes_and_abilities_resolve_to_its_operations(): void {
		OperationSurfaces::discard();

		$server = rest_get_server();

		$this->assertArrayHasKey( '/wp/v2/posts', $server->get_routes(), 'The server was not booted the way a request boots it.' );
		$this->assertArrayHasKey( '/seocart/v1/settings', $server->get_routes(), 'The kernel registered no operation route, so a clean walk would prove nothing.' );
		$this->assertSame( array(), OperationSurfaceWalker::restViolations( $server, Operations::registry() ) );
		$this->assertSame( array(), OperationSurfaceWalker::abilityViolations( self::abilityNames(), Operations::registry() ) );
	}

	/**
	 * Tests that the kernel registers exactly the production registry's operation commands, and every maintenance command.
	 *
	 * @since 0.1.0
	 */
	public function test_the_kernel_registers_every_command(): void {
		$commands = array();

		Modules::registerCommands(
			Kernel::container(),
			static function ( string $name ) use ( &$commands ): void {
				$commands[] = $name;
			},
			static function (): void {},
			static function (): void {}
		);

		$this->assertContains( 'seocart settings get', $commands, 'The kernel registered no operation command, so a clean walk would prove nothing.' );
		$this->assertSame( array(), OperationSurfaceWalker::commandViolations( $commands, Operations::registry(), OperationSurfaceWalker::MAINTENANCE_COMMANDS ) );

		foreach ( array_keys( OperationSurfaceWalker::MAINTENANCE_COMMANDS ) as $maintenance ) {
			$this->assertContains( $maintenance, $commands, "The maintenance command {$maintenance} is listed, but the kernel does not register it." );
		}

		$this->assertSame( count( $commands ), count( array_unique( $commands ) ), 'A command is registered twice.' );
	}

	/**
	 * Tests that the maintenance list names exactly the command classes under src/.
	 *
	 * @since 0.1.0
	 */
	public function test_the_maintenance_list_names_exactly_the_command_classes_under_src(): void {
		$this->assertSame( array(), OperationSurfaceWalker::maintenanceViolations( OperationSurfaceWalker::MAINTENANCE_COMMANDS, OperationSurfaceWalker::commandClasses( 'src' ) ) );
	}

	/**
	 * Tests the maintenance check on a fixture tree: a listed class passes, an unlisted one and a listed one that is gone fail.
	 *
	 * @since 0.1.0
	 */
	public function test_a_listed_maintenance_class_passes_and_an_unlisted_one_fails(): void {
		$classes = OperationSurfaceWalker::commandClasses( 'tests/Fixtures/Operations' );
		$listed  = array(
			'seocart fixture-maintenance' => array(
				'class'  => FixtureMaintenanceCommand::class,
				'reason' => 'Stands for a maintenance command: operational tooling with no REST route or ability.',
			),
		);

		$this->assertSame( array( FixtureMaintenanceCommand::class ), $classes, 'The class search did not find the fixture class, so the checks below would prove nothing.' );
		$this->assertSame( array(), OperationSurfaceWalker::maintenanceViolations( $listed, $classes ) );
		$this->assertSame(
			array( 'The command class ' . FixtureMaintenanceCommand::class . ' is neither an operation\'s command nor listed as a maintenance command with its reason.' ),
			OperationSurfaceWalker::maintenanceViolations( array(), $classes )
		);
		$this->assertSame(
			array( 'The maintenance command seocart fixture-maintenance is listed, but its class ' . FixtureMaintenanceCommand::class . ' no longer exists: remove it from the list.' ),
			OperationSurfaceWalker::maintenanceViolations( $listed, array() )
		);
	}

	/**
	 * Tests that a registered maintenance command is not an unresolved command, and an unlisted one is.
	 *
	 * @since 0.1.0
	 */
	public function test_a_registered_maintenance_command_is_not_an_unresolved_operation(): void {
		$listed = array(
			'seocart fixture-maintenance' => array(
				'class'  => FixtureMaintenanceCommand::class,
				'reason' => 'Stands for a maintenance command.',
			),
		);

		$this->assertSame( array(), OperationSurfaceWalker::commandViolations( array( 'seocart fixture-maintenance' ), new OperationRegistry(), $listed ) );
		$this->assertSame(
			array( 'The command seocart fixture-maintenance resolves to no operation definition.' ),
			OperationSurfaceWalker::commandViolations( array( 'seocart fixture-maintenance' ), new OperationRegistry(), array() )
		);
		$this->assertSame( array(), OperationSurfaceWalker::commandViolations( array(), new OperationRegistry(), $listed ), 'A listed maintenance command is not required to be registered.' );
	}

	/**
	 * Tests that the fixture, registered through the adapters, resolves on every surface.
	 *
	 * @since 0.1.0
	 */
	public function test_an_operation_registered_through_the_adapters_resolves_everywhere(): void {
		$registry = self::fixtureRegistry();
		$surfaces = new OperationSurfaces( $registry );

		$this->assertSame( array(), OperationSurfaceWalker::restViolations( $surfaces->server(), $registry ) );
		$this->assertSame( array(), OperationSurfaceWalker::abilityViolations( self::abilityNames(), $registry ) );
		$this->assertSame( array(), OperationSurfaceWalker::commandViolations( array_keys( $surfaces->commands ), $registry, array() ) );
	}

	/**
	 * Tests that a route, an ability and a command no operation declares are each reported.
	 *
	 * @since 0.1.0
	 */
	public function test_a_surface_without_an_operation_is_reported(): void {
		add_action( 'rest_api_init', array( self::class, 'registerPlantedRoute' ) );
		add_action( 'wp_abilities_api_init', array( self::class, 'registerPlantedAbility' ) );

		$registry = self::fixtureRegistry();
		$surfaces = new OperationSurfaces( $registry );

		$this->assertSame(
			array( 'The REST route POST /seocart/v1/planted resolves to no operation definition.' ),
			OperationSurfaceWalker::restViolations( $surfaces->server(), $registry )
		);
		$this->assertSame(
			array( 'The ability seocart/planted resolves to no operation definition.' ),
			OperationSurfaceWalker::abilityViolations( self::abilityNames(), $registry )
		);
		$this->assertSame(
			array( 'The command seocart planted resolves to no operation definition.' ),
			OperationSurfaceWalker::commandViolations( array_merge( array_keys( $surfaces->commands ), array( 'seocart planted' ) ), $registry, array() )
		);
	}

	/**
	 * Tests that an endpoint at an operation's address, guarded by another declared capability, is
	 * reported in the walk over the real routes: a caller with that capability would reach the
	 * operation's service.
	 *
	 * @since 0.1.0
	 */
	public function test_an_operation_route_guarded_by_another_capability_is_reported(): void {
		OperationSurfaces::discard();
		add_action( 'rest_api_init', array( self::class, 'registerMisguardedSettingsRoute' ), 20 );

		$this->assertSame(
			array( 'The REST route PATCH /seocart/v1/settings is guarded by seocart_manage_inventory, but ' . SettingsOperations::UPDATE . ' declares ' . SettingsOperations::CAPABILITY . '.' ),
			OperationSurfaceWalker::restViolations( rest_get_server(), Operations::registry() )
		);
		$this->assertSame( array(), ( new RoutePermissionWalker() )->walk( rest_get_server() )['violations'], 'The route walk alone passes the endpoint: its callback is of the plugin\'s type.' );
	}

	/**
	 * Tests that an endpoint at an operation's address that checks the operation's meta capability
	 * on another request parameter is reported: it asks about another resource.
	 *
	 * @since 0.1.0
	 */
	public function test_an_operation_route_checking_another_resource_is_reported(): void {
		add_action( 'rest_api_init', array( self::class, 'registerRouteOnAnotherResource' ), 20 );

		$registry = new OperationRegistry();
		$registry->add( FixtureStockOperation::ID, array( self::class, 'metaCapabilityDefinition' ) );
		$surfaces = new OperationSurfaces( $registry );

		$this->assertSame(
			array( 'The REST route POST /seocart/v1/fixture-stock/(?P<item_id>[^/]+)/adjustments is guarded by seocart_edit_order on delta, but ' . FixtureStockOperation::ID . ' declares seocart_edit_order on item_id.' ),
			OperationSurfaceWalker::restViolations( $surfaces->server(), $registry )
		);
	}

	/**
	 * Tests that an operation whose route, ability and command were never registered is reported, so
	 * a walk over an empty surface cannot pass for a clean one.
	 *
	 * @since 0.1.0
	 */
	public function test_an_operation_not_registered_is_reported(): void {
		OperationSurfaces::discard();
		KernelHooks::detach( 'rest_api_init', 'wp_abilities_api_categories_init', 'wp_abilities_api_init' );

		$registry = self::fixtureRegistry();

		$this->assertSame(
			array( 'fixture_stock.adjust_stock declares the REST route POST /seocart/v1/fixture-stock/(?P<item_id>[^/]+)/adjustments, which is not registered.' ),
			OperationSurfaceWalker::restViolations( rest_get_server(), $registry )
		);
		$this->assertSame(
			array( 'fixture_stock.adjust_stock declares the ability seocart/fixture-adjust-stock, which is not registered.' ),
			OperationSurfaceWalker::abilityViolations( self::abilityNames(), $registry )
		);
		$this->assertSame(
			array( 'fixture_stock.adjust_stock declares the command seocart fixture-stock adjust, which is not registered.' ),
			OperationSurfaceWalker::commandViolations( array(), $registry, array() )
		);
	}

	/**
	 * Tests that the command class search finds the classes of `Cli/` directories only.
	 *
	 * @since 0.1.0
	 */
	public function test_the_command_class_search_finds_only_command_classes(): void {
		foreach ( OperationSurfaceWalker::commandClasses( 'src' ) as $class ) {
			$this->assertMatchesRegularExpression( '/\\\\Cli\\\\[A-Za-z0-9]+Command$/', $class );
		}

		$this->assertNotContains( 'SEOCart\\Interfaces\\Operations\\CliCommand', OperationSurfaceWalker::commandClasses( 'src' ), 'The operations\' command is registered per operation, not as a maintenance command.' );
	}

	/**
	 * Registers a route no operation declares. Hooked to `rest_api_init`.
	 *
	 * @since 0.1.0
	 */
	public static function registerPlantedRoute(): void {
		register_rest_route(
			'seocart/v1',
			'/planted',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => '__return_null',
					'permission_callback' => PermissionCallback::requiring( 'seocart_manage_inventory' ),
				),
				'schema' => static fn(): array => array( 'title' => 'planted' ),
			)
		);
	}

	/**
	 * Registers a second endpoint at the settings update's address, guarded by the inventory capability. Hooked to `rest_api_init`, after the kernel.
	 *
	 * @since 0.1.0
	 */
	public static function registerMisguardedSettingsRoute(): void {
		register_rest_route(
			'seocart/v1',
			'/settings',
			array(
				array(
					'methods'             => 'PATCH',
					'callback'            => '__return_null',
					'permission_callback' => PermissionCallback::requiring( 'seocart_manage_inventory' ),
				),
			)
		);
	}

	/**
	 * Registers a second endpoint at the fixture's address that checks its meta capability on the `delta` parameter. Hooked to `rest_api_init`, after the adapter.
	 *
	 * @since 0.1.0
	 */
	public static function registerRouteOnAnotherResource(): void {
		register_rest_route(
			'seocart/v1',
			'/fixture-stock/(?P<item_id>[^/]+)/adjustments',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => '__return_null',
					'permission_callback' => PermissionCallback::requiringOn( 'seocart_edit_order', 'delta' ),
				),
			)
		);
	}

	/**
	 * Declares the fixture's route with a meta capability checked on its `item_id` parameter.
	 *
	 * @since 0.1.0
	 *
	 * @return OperationDefinition The definition.
	 */
	public static function metaCapabilityDefinition(): OperationDefinition {
		$fixture = FixtureStockOperation::definition();

		return new OperationDefinition(
			id: $fixture->id(),
			label: $fixture->label(),
			summary: $fixture->summary(),
			input: $fixture->input(),
			output: $fixture->output(),
			capability: 'seocart_edit_order',
			resource_field: 'item_id',
			errors: $fixture->errors(),
			annotations: $fixture->annotations(),
			service: $fixture->service(),
			rest: $fixture->rest()
		);
	}

	/**
	 * Registers an ability no operation declares. Hooked to `wp_abilities_api_init`, after the adapter.
	 *
	 * @since 0.1.0
	 */
	public static function registerPlantedAbility(): void {
		wp_register_ability(
			'seocart/planted',
			array(
				'label'               => 'Planted',
				'description'         => 'An ability no operation declares.',
				'category'            => 'seocart',
				'execute_callback'    => '__return_null',
				'permission_callback' => '__return_false',
			)
		);
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

	/**
	 * Returns a registry holding the fixture.
	 *
	 * @since 0.1.0
	 *
	 * @return OperationRegistry The registry.
	 */
	private static function fixtureRegistry(): OperationRegistry {
		$registry = new OperationRegistry();

		FixtureStockOperation::register( $registry );

		return $registry;
	}
}
