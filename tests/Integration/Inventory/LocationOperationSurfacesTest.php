<?php
/**
 * Tests the location operations and the stock list on their REST routes, abilities and commands, against real tables
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Inventory;

use SEOCart\Application\Operations\OperationDefinition;
use SEOCart\Application\Operations\OperationRegistry;
use SEOCart\Application\Operations\Operations;
use SEOCart\Inventory\Application\InventoryOperations;
use SEOCart\Inventory\Application\LocationOperations;
use SEOCart\Inventory\Application\Locations;
use SEOCart\Inventory\Application\StockService;
use SEOCart\Inventory\Infrastructure\InventoryTables;
use SEOCart\Platform\Authorization\CapabilityDeclaration;
use SEOCart\Platform\Authorization\CapabilityInstaller;
use SEOCart\Tests\Support\CreatesUsers;
use SEOCart\Tests\Support\Doubles\InMemoryGrantLedger;
use SEOCart\Tests\Support\Doubles\SequentialIdGenerator;
use SEOCart\Tests\Support\GrantsCapabilities;
use SEOCart\Tests\Support\Inventory\StockServices;
use SEOCart\Tests\Support\Inventory\StockTestCase;
use SEOCart\Tests\Support\OperationSurfaces;
use SEOCart\Tests\Support\ReloadsRoles;
use SEOCart\Tests\Support\SecondConnection;
use WP_Error;
use WP_REST_Response;

/**
 * The six location operations and the adjustment, each through the three surfaces its one declaration is compiled into.
 *
 * The adapters are wired as the kernel wires them, over the locations service and the stock
 * service on real tables. The plugin's roles are installed as activation installs them, and the
 * roles option is put back afterwards. Every user is created for the test and deleted again.
 *
 * Planted violations, each named on its test.
 *
 * @since 0.2.0
 *
 * @group contract
 */
final class LocationOperationSurfacesTest extends StockTestCase {

	use CreatesUsers;
	use GrantsCapabilities;
	use ReloadsRoles;

	/**
	 * The fields a location is created with on every surface.
	 *
	 * @since 0.2.0
	 *
	 * @var array<string, mixed>
	 */
	private const FIELDS = array(
		'name'            => 'Leeds shop',
		'address'         => array(
			'country'  => 'GB',
			'line1'    => '12 Market Street',
			'city'     => 'Leeds',
			'postcode' => 'LS1 6AB',
		),
		'phone'           => '+44 113 496 0000',
		'offers_pickup'   => true,
		'pickup_ready_in' => '2h',
		'priority'        => 4,
		'hours'           => array(
			array(
				'weekday'   => 6,
				'opens_at'  => '10:00',
				'closes_at' => '16:00',
			),
		),
	);

	/**
	 * The three surfaces, wired for the operations.
	 *
	 * @since 0.2.0
	 *
	 * @var OperationSurfaces
	 */
	private OperationSurfaces $surfaces;

	/**
	 * The roles option as it was before the test installed the plugin's roles.
	 *
	 * @since 0.2.0
	 *
	 * @var mixed
	 */
	private mixed $roles = null;

	/**
	 * Installs the plugin's roles and wires the operations on every surface.
	 *
	 * @since 0.2.0
	 */
	public function set_up(): void {
		parent::set_up();

		$this->roles = get_option( self::rolesOption() );

		( new CapabilityInstaller( new CapabilityDeclaration(), new InMemoryGrantLedger() ) )->install();

		$registry = new OperationRegistry();

		$registry->add( InventoryOperations::ADJUST_STOCK, array( InventoryOperations::class, 'adjustStock' ) );

		foreach ( self::locationOperations() as $id => $factory ) {
			$registry->add( $id, array( LocationOperations::class, $factory ) );
		}

		$this->surfaces = new OperationSurfaces(
			$registry,
			null,
			array(
				Locations::class    => StockServices::locations( $this->db, new SequentialIdGenerator( 400000 ), $this->transfersOver( $this->db, new SequentialIdGenerator( 410000 ) ) ),
				StockService::class => $this->service,
			)
		);
	}

	/**
	 * Deletes the users, puts the roles back and discards the surfaces.
	 *
	 * @since 0.2.0
	 */
	public function tear_down(): void {
		OperationSurfaces::discard();

		$this->deleteCreatedUsers();

		update_option( self::rolesOption(), $this->roles );

		parent::tear_down();

		self::reloadRoles();
	}

	/**
	 * Tests that a creation, a change, the list, a deactivation and a deletion answer the same on the route, the ability and the command, and each does once what it says.
	 *
	 * @since 0.2.0
	 */
	public function test_each_location_operation_answers_the_same_on_every_surface(): void {
		$b = $this->secondConnection();

		wp_set_current_user( $this->createUser( 'administrator' ) );

		$created = array(
			'rest'    => $this->surfaces->rest( 'POST', LocationOperations::ROUTE, self::FIELDS ),
			'ability' => $this->surfaces->ability( 'seocart/create-location', self::FIELDS ),
			'cli'     => $this->surfaces->cli( 'seocart location create', array(), self::options( self::FIELDS ) ),
		);
		$uuids   = array();

		foreach ( self::answers( $created ) as $surface => $answer ) {
			$uuids[ $surface ] = $answer['location_uuid'];

			unset( $answer['location_uuid'] );

			$this->assertSame( self::expectedLocation(), $answer, "{$surface} created another location." );
		}

		$this->assertCount( 3, array_unique( $uuids ), 'Each surface created one location of its own.' );
		$this->assertSame( '4', $b->fetchValue( sprintf( 'SELECT COUNT(*) FROM `%s`', $this->table( InventoryTables::LOCATIONS ) ) ) );

		$renamed = array(
			'rest'    => $this->surfaces->rest( 'PATCH', '/locations/' . $uuids['rest'], array( 'name' => 'Renamed' ) ),
			'ability' => $this->surfaces->ability(
				'seocart/update-location',
				array(
					'location_uuid' => $uuids['ability'],
					'name'          => 'Renamed',
				)
			),
			'cli'     => $this->surfaces->cli( 'seocart location update', array( $uuids['cli'] ), array( 'name' => 'Renamed' ) ),
		);

		foreach ( self::answers( $renamed ) as $surface => $answer ) {
			$this->assertSame(
				array(
					'location_uuid' => $uuids[ $surface ],
					'name'          => 'Renamed',
				) + self::expectedLocation(),
				$answer,
				"{$surface} changed more than the name."
			);
		}

		$listed = $this->surfaces->everywhere( LocationOperations::listLocations(), array() );
		$lists  = self::answers( $listed );

		$this->assertSame( $lists['rest'], $lists['ability'] );
		$this->assertSame( $lists['rest'], $lists['cli'] );
		$this->assertSame( array( $this->uuidOf( $b, $this->defaultLocation ), $uuids['rest'], $uuids['ability'], $uuids['cli'] ), array_column( $lists['rest']['locations'], 'location_uuid' ), 'The list is in routing order: priority, then creation.' );

		foreach ( array( 'rest', 'ability', 'cli' ) as $surface ) {
			$deactivated = self::answers( $this->surfaces->everywhere( LocationOperations::deactivateLocation(), array( 'location_uuid' => $uuids[ $surface ] ) ) );

			$this->assertSame( array( false, false, false ), array_column( $deactivated, 'active' ), "Deactivating the {$surface} location answers it inactive on every surface." );
		}

		$deleted = array(
			'rest'    => $this->surfaces->rest( 'DELETE', '/locations/' . $uuids['rest'] ),
			'ability' => $this->surfaces->ability( 'seocart/delete-location', array( 'location_uuid' => $uuids['ability'] ) ),
			'cli'     => $this->surfaces->cli( 'seocart location delete', array( $uuids['cli'] ), array() ),
		);

		foreach ( self::answers( $deleted ) as $surface => $answer ) {
			$this->assertSame(
				array(
					'location_uuid'       => $uuids[ $surface ],
					'stock_items_removed' => 0,
				),
				$answer
			);
		}

		$this->assertSame( '1', $b->fetchValue( sprintf( 'SELECT COUNT(*) FROM `%s`', $this->table( InventoryTables::LOCATIONS ) ) ), 'Each surface deleted its location once.' );
		$this->assertSame( array(), $this->surfaces->reported, 'An expected outcome was reported as an unexpected failure.' );
	}

	/**
	 * Tests that the stock list answers the same on every surface: each variant's policy, a row for every active location and for an inactive one that has an item, zeros where there is none, each row's own figures, and the figure for sale.
	 *
	 * The two locations' items hold different, nonzero units allocated and held, so a figure
	 * answered in another's place is seen.
	 *
	 * Planted violations, shown red and removed: in StockService::listStock(), list only the
	 * locations that have an item (drop `|| $location->active`): the active location without an
	 * item of a variant loses its zero row; and answer `held` in the `allocated` slot.
	 *
	 * @since 0.2.0
	 * @since 0.2.0 Units allocated and held.
	 */
	public function test_the_stock_list_is_the_same_on_every_surface(): void {
		$counted   = self::variant();
		$elsewhere = self::variant();
		$untracked = self::variant();
		$active    = $this->plantLocation();
		$inactive  = $this->plantLocation( 2, false );
		$b         = $this->secondConnection();

		$this->plantItem( $counted, 10, 3, 2 );
		$this->plantItem( $elsewhere, 7, 1, 4, locationId: $active );
		$this->plantItem( $counted, locationId: $inactive );
		$this->plantItem( $untracked, track: false );

		wp_set_current_user( $this->createUser( 'seocart_catalog_editor' ) );

		$outcomes = $this->surfaces->everywhere( LocationOperations::listStock(), array( 'variant_ids' => $untracked . ', ' . $counted . ',' . $elsewhere . ',999999,' . $counted ) );
		$answers  = self::answers( $outcomes );
		$row      = static fn( string $uuid, int $onHand = 0, int $allocated = 0, int $held = 0 ): array => array(
			'location_uuid' => $uuid,
			'on_hand'       => $onHand,
			'allocated'     => $allocated,
			'held'          => $held,
			'available'     => $onHand - $allocated - $held,
		);
		$default  = $this->uuidOf( $b, $this->defaultLocation );
		$second   = $this->uuidOf( $b, $active );
		$expected = array(
			'variants' => array(
				array(
					'variant_id'          => $counted,
					'track'               => true,
					'backorder_policy'    => 'no',
					'low_stock_threshold' => null,
					'for_sale'            => 5,
					'locations'           => array( $row( $default, 10, 3, 2 ), $row( $second ), $row( $this->uuidOf( $b, $inactive ) ) ),
				),
				array(
					'variant_id'          => $elsewhere,
					'track'               => true,
					'backorder_policy'    => 'no',
					'low_stock_threshold' => null,
					'for_sale'            => 2,
					'locations'           => array( $row( $default ), $row( $second, 7, 1, 4 ) ),
				),
				array(
					'variant_id'          => $untracked,
					'track'               => false,
					'backorder_policy'    => 'no',
					'low_stock_threshold' => null,
					'for_sale'            => null,
					'locations'           => array( $row( $default ), $row( $second ) ),
				),
			),
		);

		foreach ( $answers as $surface => $answer ) {
			$this->assertSame( $expected, $answer, "{$surface} answered another stock list." );
		}
	}

	/**
	 * Tests the capabilities on every surface: a catalog editor lists the locations and the stock and adjusts, but cannot change a location; a user who may manage only the settings cannot list stock.
	 *
	 * Planted violation, shown red and removed: in LocationOperations::listStock(), require
	 * `seocart_manage_settings`. The catalog editor is refused the stock list, and the settings
	 * manager is let through.
	 *
	 * @since 0.2.0
	 */
	public function test_each_surface_checks_the_capability_its_declaration_names(): void {
		$variant = self::variant();
		$b       = $this->secondConnection();
		$planted = $this->plantLocation();
		$uuid    = $this->uuidOf( $b, $planted );
		$record  = sprintf( 'SELECT name, active, priority FROM `%s` WHERE id = %d', $this->table( InventoryTables::LOCATIONS ), $planted );
		$before  = $b->fetchRow( $record );

		$this->stockItem( $variant, 5 );

		wp_set_current_user( $this->createUser( 'seocart_catalog_editor' ) );

		$allowed = array(
			array( LocationOperations::listLocations(), array() ),
			array( LocationOperations::listStock(), array( 'variant_ids' => (string) $variant ) ),
			array(
				InventoryOperations::adjustStock(),
				array(
					'variant_id'    => $variant,
					'location_uuid' => $uuid,
					'delta'         => 1,
					'reason'        => 'received',
				),
			),
		);

		foreach ( $allowed as list( $definition, $input ) ) {
			$this->assertAllowedEverywhere( $definition, $input );
		}

		$refused = array(
			array( LocationOperations::createLocation(), array( 'name' => 'Garage' ) ),
			array(
				LocationOperations::updateLocation(),
				array(
					'location_uuid' => $uuid,
					'name'          => 'Garage',
				),
			),
			array( LocationOperations::deactivateLocation(), array( 'location_uuid' => $uuid ) ),
			array( LocationOperations::deleteLocation(), array( 'location_uuid' => $uuid ) ),
		);

		foreach ( $refused as list( $definition, $input ) ) {
			$this->assertRefusedEverywhere( $definition, $input );
		}

		$this->assertSame( $before, $b->fetchRow( $record ), 'A refused user changed the location.' );

		wp_set_current_user( $this->userGranted( LocationOperations::CAPABILITY )->userId() );

		$this->assertRefusedEverywhere( LocationOperations::listStock(), array( 'variant_ids' => (string) $variant ) );
		$this->assertRefusedEverywhere( LocationOperations::listLocations(), array() );
		$this->assertSame( 200, $this->surfaces->rest( 'PATCH', '/locations/' . $uuid, array( 'priority' => 3 ) )->get_status(), 'The settings manager may change a location.' );
	}

	/**
	 * Tests that the phone is shown only to a user who may see personal data, on every surface, and that no other field is hidden.
	 *
	 * Planted violation, shown red and removed: in LocationOperations::phone(), declare the phone
	 * public. The catalog editor then reads it.
	 *
	 * @since 0.2.0
	 */
	public function test_the_phone_is_shown_only_to_who_may_see_personal_data(): void {
		wp_set_current_user( $this->createUser( 'administrator' ) );

		$this->assertSame( 200, $this->surfaces->rest( 'POST', LocationOperations::ROUTE, self::FIELDS )->get_status() );

		foreach ( array(
			'seocart_manager'        => true,
			'seocart_catalog_editor' => false,
		) as $role => $sees ) {
			wp_set_current_user( $this->createUser( $role ) );

			foreach ( self::answers( $this->surfaces->everywhere( LocationOperations::listLocations(), array() ) ) as $surface => $answer ) {
				$created = $answer['locations'][1];

				$this->assertSame( $sees, array_key_exists( 'phone', $created ), "{$role} on {$surface}." );
				$this->assertSame( $sees ? self::FIELDS['phone'] : null, $created['phone'] ?? null );
				$this->assertSame( self::FIELDS['address']['line1'], $created['address']['line1'] ?? null, 'The address is public.' );
			}
		}
	}

	/**
	 * Tests that the location a change, a deactivation or a deletion acts on comes from the URL only: a uuid in the query or the body is refused, and nothing changes.
	 *
	 * Planted violation, shown red and removed: in RestAdapter::arguments(), drop the URL-only
	 * validation callback. The routes then act on the location in the URL with a 200, and the
	 * refusals below fail.
	 *
	 * @since 0.2.0
	 */
	public function test_the_location_comes_from_the_url_only(): void {
		$b     = $this->secondConnection();
		$inUrl = $this->uuidOf( $b, $this->plantLocation() );
		$other = $this->uuidOf( $b, $this->plantLocation() );

		wp_set_current_user( $this->createUser( 'administrator' ) );

		$routes = array(
			array( 'PATCH', '/locations/' . $inUrl, array( 'name' => 'Moved' ) ),
			array( 'POST', '/locations/' . $inUrl . '/deactivation', array() ),
			array( 'DELETE', '/locations/' . $inUrl, array() ),
		);

		foreach ( $routes as list( $method, $route, $body ) ) {
			$answers = array(
				'query' => $this->surfaces->rest( $method, $route, $body, array( 'location_uuid' => $other ) ),
				'body'  => $this->surfaces->rest( $method, $route, array( 'location_uuid' => $other ) + $body ),
			);

			foreach ( $answers as $where => $response ) {
				$this->assertSame( 400, $response->get_status(), "{$method} {$route}: a location in the {$where} was not refused." );
				$this->assertSame( 'rest_invalid_param', $response->as_error()->get_error_code() );
			}
		}

		$this->assertSame( '2', $b->fetchValue( sprintf( "SELECT COUNT(*) FROM `%s` WHERE uuid IN ( '%s', '%s' ) AND active = 1 AND name LIKE 'Location %%'", $this->table( InventoryTables::LOCATIONS ), $inUrl, $other ) ), 'A refused request changed a location.' );
		$this->assertSame( 200, $this->surfaces->rest( 'PATCH', '/locations/' . $inUrl, array( 'name' => 'Moved' ) )->get_status(), 'The URL alone is accepted.' );
	}

	/**
	 * Tests that each refusal of the services is the same code on every surface, with its status, and its details on REST.
	 *
	 * @since 0.2.0
	 */
	public function test_every_refusal_is_the_same_code_everywhere(): void {
		$b       = $this->secondConnection();
		$default = $this->uuidOf( $b, $this->defaultLocation );
		$missing = wp_generate_uuid4();

		wp_set_current_user( $this->createUser( 'administrator' ) );

		$cases = array(
			array(
				'stock.location_missing',
				404,
				LocationOperations::updateLocation(),
				array(
					'location_uuid' => $missing,
					'name'          => 'Nowhere',
				),
			),
			array( 'stock.location_missing', 404, LocationOperations::deactivateLocation(), array( 'location_uuid' => $missing ) ),
			array( 'stock.location_missing', 404, LocationOperations::deleteLocation(), array( 'location_uuid' => $missing ) ),
			array( 'stock.location_is_default', 409, LocationOperations::deactivateLocation(), array( 'location_uuid' => $default ) ),
			array( 'stock.location_in_use', 409, LocationOperations::deleteLocation(), array( 'location_uuid' => $default ) ),
			array( 'stock.location_invalid', 400, LocationOperations::createLocation(), array( 'name' => ' ' ) ),
			array( 'stock.variant_ids_invalid', 400, LocationOperations::listStock(), array( 'variant_ids' => '12,,15' ) ),
			array( 'stock.variant_ids_invalid', 400, LocationOperations::listStock(), array( 'variant_ids' => implode( ',', range( 1, LocationOperations::MOST_LISTED_VARIANTS + 1 ) ) ) ),
		);

		foreach ( $cases as list( $code, $status, $definition, $input ) ) {
			$outcomes = $this->surfaces->everywhere( $definition, $input );

			$this->assertSame( $status, $outcomes['rest']['result']->get_status(), "{$code}: " . $outcomes['rest']['text'] );
			$this->assertSame( $code, $outcomes['rest']['result']->as_error()->get_error_code() );
			$this->assertInstanceOf( WP_Error::class, $outcomes['ability']['result'], $outcomes['ability']['text'] );
			$this->assertSame( $code, $outcomes['ability']['result']->get_error_code() );
			$this->assertStringStartsWith( $code . ': ', (string) $outcomes['cli']['result']['failure'] );
		}

		$inUse = $this->surfaces->rest( 'DELETE', '/locations/' . $default )->get_data();

		$this->assertSame( 'default', $inUse['data']['details']->reason ?? null, 'The refused deletion says why.' );
		$this->assertSame( '1', $b->fetchValue( sprintf( 'SELECT COUNT(*) FROM `%s`', $this->table( InventoryTables::LOCATIONS ) ) ) );
		$this->assertSame( array(), $this->surfaces->reported, 'A refusal the client caused was reported as an unexpected failure.' );
	}

	/**
	 * Tests that the adjustment without a location adjusts the default on every surface, and that one at an unknown location is refused on each with 404.
	 *
	 * @since 0.2.0
	 */
	public function test_the_adjustment_names_its_location_or_takes_the_default(): void {
		$variant = self::variant();
		$b       = $this->secondConnection();

		$this->stockItem( $variant, 10 );

		wp_set_current_user( $this->createUser( 'seocart_catalog_editor' ) );

		$input   = array(
			'variant_id' => $variant,
			'delta'      => 2,
			'reason'     => 'received',
		);
		$answers = self::answers( $this->surfaces->everywhere( InventoryOperations::adjustStock(), $input ) );

		$this->assertSame( array_fill_keys( array( 'rest', 'ability', 'cli' ), $this->uuidOf( $b, $this->defaultLocation ) ), array_map( static fn( array $answer ): string => (string) $answer['location_uuid'], $answers ), 'With no location named, every surface adjusts the default location and says so.' );
		$this->assertSame( 16, $this->committedItem( $b, $variant )['on_hand'] ?? null );

		$unknown = $this->surfaces->everywhere( InventoryOperations::adjustStock(), $input + array( 'location_uuid' => wp_generate_uuid4() ) );

		$this->assertSame( 404, $unknown['rest']['result']->get_status() );
		$this->assertSame( 'stock.location_missing', $unknown['ability']['result'] instanceof WP_Error ? $unknown['ability']['result']->get_error_code() : null );
		$this->assertStringStartsWith( 'stock.location_missing: ', (string) $unknown['cli']['result']['failure'] );
		$this->assertSame( 16, $this->committedItem( $b, $variant )['on_hand'] ?? null );
	}

	/**
	 * Tests that, of the six, only the stock list is exposed to agents, and that the production registry holds all six.
	 *
	 * @since 0.2.0
	 */
	public function test_only_the_stock_list_is_exposed_to_agents(): void {
		$exposed = array();

		foreach ( self::locationOperations() as $id => $factory ) {
			$definition = LocationOperations::$factory();

			$this->assertNotNull( $definition->abilityName(), "{$id} has no ability." );

			$exposed[ $id ] = $definition->isAgentExposed();
		}

		$this->assertSame( array( LocationOperations::LIST_STOCK ), array_keys( array_filter( $exposed ) ) );
		$this->assertSame( array(), array_diff( array_keys( $exposed ), array_map( static fn( OperationDefinition $definition ): string => $definition->id(), Operations::registry()->all() ) ), 'The production registry lacks one of the six.' );
	}

	/**
	 * Asserts that the current user runs an operation on every surface.
	 *
	 * @since 0.2.0
	 *
	 * @param OperationDefinition  $definition The operation.
	 * @param array<string, mixed> $input      The input.
	 */
	private function assertAllowedEverywhere( OperationDefinition $definition, array $input ): void {
		$outcomes = $this->surfaces->everywhere( $definition, $input );

		$this->assertSame( 200, $outcomes['rest']['result']->get_status(), $definition->id() . ': ' . $outcomes['rest']['text'] );
		$this->assertIsArray( $outcomes['ability']['result'], $definition->id() . ': ' . $outcomes['ability']['text'] );
		$this->assertNull( $outcomes['cli']['result']['failure'], $definition->id() );
	}

	/**
	 * Asserts that the current user is refused an operation on every surface, the command naming the capability.
	 *
	 * @since 0.2.0
	 *
	 * @param OperationDefinition  $definition The operation.
	 * @param array<string, mixed> $input      The input.
	 */
	private function assertRefusedEverywhere( OperationDefinition $definition, array $input ): void {
		$outcomes = $this->surfaces->everywhere( $definition, $input );

		$this->assertSame( 403, $outcomes['rest']['result']->get_status(), $definition->id() . ': ' . $outcomes['rest']['text'] );
		$this->assertSame( 'rest_forbidden', $outcomes['rest']['result']->as_error()->get_error_code() );
		$this->assertInstanceOf( WP_Error::class, $outcomes['ability']['result'], $definition->id() );
		$this->assertSame( 'ability_invalid_permissions', $outcomes['ability']['result']->get_error_code() );
		$this->assertStringStartsWith( 'rest_forbidden: ', (string) $outcomes['cli']['result']['failure'] );
		$this->assertStringContainsString( (string) $definition->capability(), (string) $outcomes['cli']['result']['failure'] );
	}

	/**
	 * Returns what each surface answered, failing the test when one refused.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $outcomes Each surface's outcome: a REST response, an ability's result and a command's, or everywhere()'s.
	 * @return array<string, array<string, mixed>> The answers, keyed by surface.
	 */
	private static function answers( array $outcomes ): array {
		$answers = array();

		foreach ( $outcomes as $surface => $outcome ) {
			$result = is_array( $outcome ) && array_key_exists( 'text', $outcome ) ? $outcome['result'] : $outcome;

			if ( $result instanceof WP_REST_Response ) {
				self::assertSame( 200, $result->get_status(), "{$surface}: " . wp_json_encode( $result->get_data() ) );

				$result = $result->get_data();
			} elseif ( is_array( $result ) && array_key_exists( 'printed', $result ) ) {
				self::assertNull( $result['failure'], "{$surface}: " . (string) $result['failure'] );

				$result = $result['printed']['item'] ?? null;
			}

			self::assertIsArray( $result, "{$surface}: " . ( $result instanceof WP_Error ? $result->get_error_message() : wp_json_encode( $result ) ) );

			$answers[ $surface ] = $result;
		}

		return $answers;
	}

	/**
	 * Returns the location FIELDS create, as every surface answers it, less its uuid.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, mixed> The location.
	 */
	private static function expectedLocation(): array {
		return array(
			'name'                => self::FIELDS['name'],
			'address'             => array(
				'country'  => 'GB',
				'line1'    => '12 Market Street',
				'line2'    => '',
				'city'     => 'Leeds',
				'region'   => '',
				'postcode' => 'LS1 6AB',
			),
			'phone'               => self::FIELDS['phone'],
			'active'              => true,
			'is_default'          => false,
			'fulfils_online'      => true,
			'offers_pickup'       => true,
			'pickup_ready_in'     => '2h',
			'pickup_instructions' => null,
			'priority'            => 4,
			'hours'               => self::FIELDS['hours'],
		);
	}

	/**
	 * Writes an input as a command's options: an object or a list as its JSON, a Boolean as the flag's value, anything else as text.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $input The input.
	 * @return array<string, mixed> The options.
	 */
	private static function options( array $input ): array {
		return array_map( static fn( mixed $value ): mixed => is_array( $value ) ? (string) wp_json_encode( $value ) : ( is_bool( $value ) ? $value : (string) $value ), $input );
	}

	/**
	 * Returns the six location operations: each id with the factory that declares it.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, string> The factories, by operation id.
	 */
	private static function locationOperations(): array {
		return array(
			LocationOperations::LIST_LOCATIONS      => 'listLocations',
			LocationOperations::CREATE_LOCATION     => 'createLocation',
			LocationOperations::UPDATE_LOCATION     => 'updateLocation',
			LocationOperations::DEACTIVATE_LOCATION => 'deactivateLocation',
			LocationOperations::DELETE_LOCATION     => 'deleteLocation',
			LocationOperations::LIST_STOCK          => 'listStock',
		);
	}

	/**
	 * Reads a location's public identifier as connection B sees it.
	 *
	 * @since 0.2.0
	 *
	 * @param SecondConnection $b          Connection B.
	 * @param int              $locationId The location.
	 * @return string The uuid.
	 */
	private function uuidOf( SecondConnection $b, int $locationId ): string {
		return (string) $b->fetchValue( sprintf( 'SELECT uuid FROM `%s` WHERE id = %d', $this->table( InventoryTables::LOCATIONS ), $locationId ) );
	}

	/**
	 * Returns the name of the current site's roles option.
	 *
	 * @since 0.2.0
	 *
	 * @return string For example `wptests_user_roles`.
	 */
	private static function rolesOption(): string {
		global $wpdb;

		return $wpdb->get_blog_prefix() . 'user_roles';
	}
}
