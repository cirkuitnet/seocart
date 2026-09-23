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

use SEOCart\Application\Operations\OperationRegistry;
use SEOCart\Application\Operations\Operations;
use SEOCart\Interfaces\Operations\CliAdapter;
use SEOCart\Interfaces\Operations\OperationInvoker;
use SEOCart\Platform\Authorization\PermissionCallback;
use SEOCart\Tests\Fixtures\Operations\Cli\FixtureMaintenanceCommand;
use SEOCart\Tests\Fixtures\Operations\FixtureStockOperation;
use SEOCart\Tests\Support\Doubles\TableErrorTranslator;
use SEOCart\Tests\Support\OperationSurfaces;
use SEOCart\Tests\Support\OperationSurfaceWalker;
use WP_Ability;
use WP_UnitTestCase;

/**
 * One declaration per operation, on every surface, in both directions.
 *
 * The first tests walk what the plugin itself registers — the routes of a REST server booted as a
 * request boots it, and the abilities WordPress holds — against the production registry. The
 * command half is split in two:
 *
 * - the operation commands: the commands the command adapter registers for the production registry
 *   must be exactly the registry's commands. Real WP-CLI registrations are not observed yet: the
 *   suite runs without WP-CLI, so the recorded list is the adapter's alone until the kernel
 *   registers every command through a recording `add_command`, which then feeds this walk;
 * - the maintenance commands: OperationSurfaceWalker::MAINTENANCE_COMMANDS must list exactly the
 *   command classes under the `Cli/` directories of src/, each with its reason. They are checked
 *   against that class search only, never against the registered list.
 *
 * The plugin registers no operation yet, so the operation walks find nothing on either side; they
 * are the gate for the first operation and for the kernel's wiring. The self-tests register the
 * fixture through the adapters and must find nothing wrong, then plant each violation — a route,
 * an ability and a command that no operation declares, an operation whose surfaces are not
 * registered, an unlisted command class and a listed one that is gone — and require each to be
 * reported.
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
		$this->assertSame( array(), OperationSurfaceWalker::restViolations( $server, Operations::registry() ) );
		$this->assertSame( array(), OperationSurfaceWalker::abilityViolations( self::abilityNames(), Operations::registry() ) );
	}

	/**
	 * Tests that the commands registered for the production registry are exactly its operation commands.
	 *
	 * @since 0.1.0
	 */
	public function test_the_plugins_operation_commands_resolve_to_its_operations(): void {
		$commands = array();

		( new CliAdapter( Operations::registry(), self::invoker() ) )->register(
			static function ( string $name ) use ( &$commands ): void {
				$commands[] = $name;
			},
			static function (): void {},
			static function (): void {}
		);

		$this->assertSame( array(), OperationSurfaceWalker::commandViolations( $commands, Operations::registry(), OperationSurfaceWalker::MAINTENANCE_COMMANDS ) );
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
	 * Tests that an operation whose route, ability and command were never registered is reported, so
	 * a walk over an empty surface cannot pass for a clean one.
	 *
	 * @since 0.1.0
	 */
	public function test_an_operation_not_registered_is_reported(): void {
		OperationSurfaces::discard();

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

	/**
	 * Returns an invoker whose services are never resolved.
	 *
	 * @since 0.1.0
	 *
	 * @return OperationInvoker The invoker.
	 */
	private static function invoker(): OperationInvoker {
		return new OperationInvoker(
			static function ( string $class_name ): object {
				throw new \LogicException( 'No service is resolved while commands are registered: ' . $class_name ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a test double's message, never rendered.
			},
			new TableErrorTranslator()
		);
	}
}
