<?php
/**
 * Tests the location operations' service against real tables: the default's rules, deletion, deactivation and partial updates
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Inventory;

use SEOCart\Inventory\Application\FulfilmentRouting;
use SEOCart\Inventory\Application\InventoryError;
use SEOCart\Inventory\Application\LocationDirectory;
use SEOCart\Inventory\Application\Locations;
use SEOCart\Inventory\Application\RoutingContext;
use SEOCart\Inventory\Domain\HoldLine;
use SEOCart\Inventory\Domain\LedgerReason;
use SEOCart\Inventory\Domain\Routing\FulfilmentMode;
use SEOCart\Inventory\Infrastructure\InventoryTables;
use SEOCart\Inventory\Infrastructure\MysqlLocationRepository;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Platform\Authorization\CapabilityDeclaration;
use SEOCart\Platform\Authorization\CapabilityInstaller;
use SEOCart\Support\Error\CodedException;
use SEOCart\Tests\Support\CreatesUsers;
use SEOCart\Tests\Support\Doubles\InMemoryGrantLedger;
use SEOCart\Tests\Support\Doubles\SequentialIdGenerator;
use SEOCart\Tests\Support\Inventory\StockServices;
use SEOCart\Tests\Support\Inventory\StockTestCase;
use SEOCart\Tests\Support\ReloadsRoles;
use SEOCart\Tests\Support\SecondConnection;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- The tests plant rows a store reaches only through other modules, and read the tables back.

/**
 * The rules a location's record changes by, held to what is committed.
 *
 * The service runs as a store manager over the test's connection; connection B reads what is
 * committed. Every location but the default is planted or created by the test.
 *
 * Planted violations, each named on its test.
 *
 * @since 0.2.0
 */
final class LocationOperationsTest extends StockTestCase {

	use CreatesUsers;
	use ReloadsRoles;

	/**
	 * The service under test.
	 *
	 * @since 0.2.0
	 *
	 * @var Locations
	 */
	private Locations $locations;

	/**
	 * Who acts: a store manager.
	 *
	 * @since 0.2.0
	 *
	 * @var Actor
	 */
	private Actor $manager;

	/**
	 * The roles option as it was before the test installed the plugin's roles.
	 *
	 * @since 0.2.0
	 *
	 * @var mixed
	 */
	private mixed $roles = null;

	/**
	 * Installs the plugin's roles and builds the service.
	 *
	 * @since 0.2.0
	 */
	public function set_up(): void {
		parent::set_up();

		$this->roles = get_option( self::rolesOption() );

		( new CapabilityInstaller( new CapabilityDeclaration(), new InMemoryGrantLedger() ) )->install();

		$this->locations = StockServices::locations( $this->db, new SequentialIdGenerator( 300000 ), $this->transfersOver( $this->db, new SequentialIdGenerator( 310000 ) ) );
		$this->manager   = Actor::user( $this->createUser( 'seocart_manager' ) );
	}

	/**
	 * Deletes the users and puts the roles back.
	 *
	 * @since 0.2.0
	 */
	public function tear_down(): void {
		$this->deleteCreatedUsers();

		update_option( self::rolesOption(), $this->roles );

		parent::tear_down();

		self::reloadRoles();
	}

	/**
	 * Tests that the default location is never deactivated, deleted or stripped of its marker, by either operation, and stays as it was.
	 *
	 * Planted violation, shown red and removed: in Locations::update(), drop the deactivate() call
	 * for a location turned off (write `active` some other way): the default is deactivated.
	 *
	 * @since 0.2.0
	 */
	public function test_the_default_location_is_never_deactivated_or_deleted(): void {
		$b       = $this->secondConnection();
		$default = array( 'location_uuid' => $this->uuidOf( $b, $this->defaultLocation ) );

		$this->assertRefused( InventoryError::LocationIsDefault, fn(): array => $this->locations->deactivateLocation( $default, $this->manager ) );
		$this->assertRefused( InventoryError::LocationIsDefault, fn(): array => $this->locations->updateLocation( $default + array( 'active' => false ), $this->manager ) );
		$this->assertRefused( InventoryError::LocationIsDefault, fn(): array => $this->locations->updateLocation( $default + array( 'is_default' => false ), $this->manager ) );

		$deletion = $this->assertRefused( InventoryError::LocationInUse, fn(): array => $this->locations->deleteLocation( $default, $this->manager ) );

		$this->assertSame( array( 'reason' => 'default' ), $deletion->details() );
		$this->assertSame(
			array(
				'id'         => (string) $this->defaultLocation,
				'active'     => '1',
				'is_default' => '1',
			),
			$b->fetchRow( sprintf( 'SELECT id, active, is_default FROM `%s` WHERE is_default = 1', $this->table( InventoryTables::LOCATIONS ) ) )
		);
	}

	/**
	 * Tests that a location with stock history is kept, even when its items count nothing now, and that doctor finds the store whole.
	 *
	 * Planted violation, shown red and removed: in MysqlStockRepository::locationUse(), leave the
	 * ledger out of the uses it reads. The deletion goes through, the ledger's entries name a
	 * location that is gone, and doctor fails on them.
	 *
	 * @since 0.2.0
	 */
	public function test_a_location_with_a_ledger_entry_is_kept(): void {
		$variant  = self::variant();
		$location = $this->plantLocation();
		$b        = $this->secondConnection();

		$this->stockItem( $variant, 5, $location );
		$this->service->adjust( $variant, $location, -5, LedgerReason::Damaged, Actor::user( 1 ) );

		$refusal = null;

		try {
			$this->locations->deleteLocation( array( 'location_uuid' => $this->uuidOf( $b, $location ) ), $this->manager );
		} catch ( CodedException $refused ) {
			$refusal = $refused;
		}

		$doctor = $this->projectionCheck();

		$this->assertTrue( $doctor->passed, 'Doctor: ' . implode( "\n", $doctor->findings ) );
		$this->assertInstanceOf( CodedException::class, $refusal, 'A location with stock history was deleted.' );
		$this->assertSame( array( InventoryError::LocationInUse, array( 'reason' => 'ledger' ) ), array( $refusal->errorCode(), $refusal->details() ) );
		$this->assertSame( array( 0, 0, 0 ), array_values( (array) $this->committedItem( $b, $variant, $location ) ), 'Its item is kept with it.' );
	}

	/**
	 * Tests that every other thing that refers to a location keeps it, each named as the reason.
	 *
	 * @since 0.2.0
	 */
	public function test_each_use_of_a_location_keeps_it(): void {
		$b     = $this->secondConnection();
		$cases = array(
			'stock'       => function ( int $location, int $variant ): void {
				$this->plantItem( $variant, 1, locationId: $location );
			},
			'holds'       => function ( int $location, int $variant ): void {
				$this->plantItem( $variant, locationId: $location );
				$this->db->execute( 'INSERT INTO %i ( variant_id, location_id, hold_group, quantity, expires_at, created_at ) VALUES ( %d, %d, %s, 1, UTC_TIMESTAMP() + INTERVAL 600 SECOND, UTC_TIMESTAMP(6) )', $this->table( InventoryTables::HOLDS ), $variant, $location, SequentialIdGenerator::nth( 710000 + $variant ) );
			},
			'allocations' => function ( int $location, int $variant ): void {
				$this->plantItem( $variant, locationId: $location );
				$this->db->execute( "INSERT INTO %i ( variant_id, location_id, order_id, order_line_id, quantity, posted_quantity, state, created_at ) VALUES ( %d, %d, 1, %d, 1, 1, 'posted', UTC_TIMESTAMP(6) )", $this->table( InventoryTables::ALLOCATIONS ), $variant, $location, $variant );
			},
			'transfers'   => function ( int $location ): void {
				$this->db->execute( "INSERT INTO %i ( uuid, source_location_id, destination_location_id, kind, state, created_at, updated_at ) VALUES ( %s, %d, %d, 'manual', 'draft', UTC_TIMESTAMP(6), UTC_TIMESTAMP(6) )", $this->table( InventoryTables::TRANSFERS ), wp_generate_uuid4(), $this->defaultLocation, $location );
			},
		);

		foreach ( $cases as $reason => $plant ) {
			$location = $this->plantLocation();

			$plant( $location, self::variant() );

			$refused = $this->assertRefused( InventoryError::LocationInUse, fn(): array => $this->locations->deleteLocation( array( 'location_uuid' => $this->uuidOf( $b, $location ) ), $this->manager ) );

			$this->assertSame( array( 'reason' => $reason ), $refused->details(), "A location with {$reason} was deleted or named another reason." );
			$this->assertSame( '1', $b->fetchValue( sprintf( 'SELECT COUNT(*) FROM `%s` WHERE id = %d', $this->table( InventoryTables::LOCATIONS ), $location ) ) );
		}

		// A transfer that leaves the location keeps it as surely as one that arrives there.
		$source = $this->plantLocation();

		$this->db->execute( "INSERT INTO %i ( uuid, source_location_id, destination_location_id, kind, state, created_at, updated_at ) VALUES ( %s, %d, %d, 'manual', 'draft', UTC_TIMESTAMP(6), UTC_TIMESTAMP(6) )", $this->table( InventoryTables::TRANSFERS ), wp_generate_uuid4(), $source, $this->defaultLocation );

		$this->assertSame( array( 'reason' => 'transfers' ), $this->assertRefused( InventoryError::LocationInUse, fn(): array => $this->locations->deleteLocation( array( 'location_uuid' => $this->uuidOf( $b, $source ) ), $this->manager ) )->details(), 'A location a transfer leaves was deleted.' );
	}

	/**
	 * Tests that a move of the default locks the default before it clears it: the clear is never the statement that waits.
	 *
	 * The race tests below send the waiting side's statements themselves; this one holds the order
	 * of moveDefault()'s own statements.
	 *
	 * Planted violation, shown red and removed: in MysqlLocationRepository::moveDefault(), leave
	 * out the LOCK_DEFAULT read: the clear is then sent with no lock taken first.
	 *
	 * @since 0.2.0
	 */
	public function test_a_move_of_the_default_locks_it_before_it_clears_it(): void {
		$b     = $this->secondConnection();
		$other = $this->plantLocation();
		$lock  = $this->raw( MysqlLocationRepository::LOCK_DEFAULT );
		$sent  = array();

		add_filter(
			'query',
			static function ( string $query ) use ( &$sent ): string {
				$sent[] = $query;

				return $query;
			}
		);

		$clear = $this->beforeStatement(
			self::shapeOf( MysqlLocationRepository::CLEAR_DEFAULT ),
			function () use ( &$sent, $lock ): void {
				$this->assertContains( $lock, $sent, 'The default was cleared before it was locked.' );
			}
		);

		$this->locations->updateLocation(
			array(
				'location_uuid' => $this->uuidOf( $b, $other ),
				'is_default'    => true,
			),
			$this->manager
		);

		$this->assertTrue( $clear->fired, 'The move sent no clear of the default.' );
		$this->assertSame( array( (string) $other ), $this->defaults( $b ) );
	}

	/**
	 * Tests that a location nothing refers to is deleted with its items that count nothing, its pickup hours and its markets, in one unit of work.
	 *
	 * Planted violation, shown red and removed: in Locations::deleteLocation(), leave out
	 * deleteEmptyItemsAt(). The location's items stay behind it, and doctor fails on them.
	 *
	 * @since 0.2.0
	 */
	public function test_a_location_nothing_refers_to_is_deleted_with_its_empty_items(): void {
		$b        = $this->secondConnection();
		$created  = $this->locations->createLocation(
			array(
				'name'  => 'Pop-up shop',
				'hours' => array(
					array(
						'weekday'   => 6,
						'opens_at'  => '10:00',
						'closes_at' => '16:00',
					),
				),
			),
			$this->manager
		);
		$location = (int) $b->fetchValue( sprintf( "SELECT id FROM `%s` WHERE uuid = '%s'", $this->table( InventoryTables::LOCATIONS ), $created['location_uuid'] ) );

		foreach ( array( self::variant(), self::variant() ) as $variant ) {
			$this->plantItem( $variant );
			$this->plantItem( $variant, locationId: $location );
		}

		$this->db->execute( 'INSERT INTO %i ( location_id, market_id ) VALUES ( %d, 7 )', $this->table( InventoryTables::LOCATION_MARKETS ), $location );

		$answer = $this->locations->deleteLocation( array( 'location_uuid' => $created['location_uuid'] ), $this->manager );

		$this->assertSame(
			array(
				'location_uuid'       => $created['location_uuid'],
				'stock_items_removed' => 2,
			),
			$answer
		);

		foreach ( array(
			InventoryTables::LOCATIONS        => 'id',
			InventoryTables::ITEMS            => 'location_id',
			InventoryTables::PICKUP_HOURS     => 'location_id',
			InventoryTables::LOCATION_MARKETS => 'location_id',
		) as $table => $column ) {
			$this->assertSame( '0', $b->fetchValue( sprintf( 'SELECT COUNT(*) FROM `%s` WHERE %s = %d', $this->table( $table ), $column, $location ) ), "{$table} still names the location." );
		}

		$doctor = $this->projectionCheck();

		$this->assertTrue( $doctor->passed, 'Doctor: ' . implode( "\n", $doctor->findings ) );
		$this->assertRefused( InventoryError::LocationMissing, fn(): array => $this->locations->deleteLocation( array( 'location_uuid' => $created['location_uuid'] ), $this->manager ) );
	}

	/**
	 * Tests that moving the default leaves exactly one, that an inactive location is refused as the default, and that a refused move changes nothing.
	 *
	 * Planted violation, shown red and removed: in MysqlLocationRepository::moveDefault(), leave out
	 * CLEAR_DEFAULT. The unique key then refuses the mark, and the move fails with a database error.
	 *
	 * @since 0.2.0
	 */
	public function test_moving_the_default_leaves_exactly_one(): void {
		$b        = $this->secondConnection();
		$other    = $this->plantLocation();
		$inactive = $this->plantLocation( 2, false );

		$moved = $this->locations->updateLocation(
			array(
				'location_uuid' => $this->uuidOf( $b, $other ),
				'is_default'    => true,
			),
			$this->manager
		);

		$this->assertTrue( $moved['is_default'] );
		$this->assertSame( array( (string) $other ), $this->defaults( $b ) );

		$this->assertRefused(
			InventoryError::LocationInactive,
			fn(): array => $this->locations->updateLocation(
				array(
					'location_uuid' => $this->uuidOf( $b, $inactive ),
					'is_default'    => true,
				),
				$this->manager
			)
		);
		$this->assertSame( array( (string) $other ), $this->defaults( $b ), 'A refused move changed the default.' );

		$this->locations->updateLocation(
			array(
				'location_uuid' => $this->uuidOf( $b, $inactive ),
				'active'        => true,
				'is_default'    => true,
			),
			$this->manager
		);

		$this->assertSame( array( (string) $inactive ), $this->defaults( $b ), 'A location activated and made the default in one change is the one default.' );

		$again = $this->locations->updateLocation(
			array(
				'location_uuid' => $this->uuidOf( $b, $inactive ),
				'is_default'    => true,
			),
			$this->manager
		);

		$this->assertTrue( $again['is_default'], 'Making the default the default again changes nothing.' );
		$this->assertSame( array( (string) $inactive ), $this->defaults( $b ) );
	}

	/**
	 * Tests that a move of the default that waited for one in flight finds the default that move marked: the clear is its own statement after the wait.
	 *
	 * A moves the default from E to C, and C sorts before E in the marker's index. When A is about
	 * to mark C, B begins the same move to D: B's LOCK_DEFAULT waits on E, whose marker A cleared.
	 * A marks C and commits; B then clears and marks as moveDefault() does. B's clear, sent after
	 * the wait, finds C, so the store ends with one default, D. B runs at READ COMMITTED, as every
	 * move of the default does.
	 *
	 * @since 0.2.0
	 *
	 * @group concurrency
	 */
	public function test_a_move_of_the_default_after_one_in_flight_leaves_one_default(): void {
		$b     = $this->secondConnection();
		$early = $this->plantLocation();
		$late  = $this->plantLocation();
		$lock  = $this->raw( MysqlLocationRepository::LOCK_DEFAULT );

		$this->locations->updateLocation(
			array(
				'location_uuid' => $this->uuidOf( $b, $late ),
				'is_default'    => true,
			),
			$this->manager
		);

		$barrier = $this->beforeStatement(
			'/^' . preg_quote( $this->raw( MysqlLocationRepository::MARK_DEFAULT, $early ), '/' ) . '$/',
			function () use ( $b, $lock ): void {
				$b->query( 'SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED' );
				$b->query( 'START TRANSACTION' );
				$b->queryAsync( $lock );
				$this->awaitWaiting( $b, $lock, 'statistics' );
			}
		);

		$this->locations->updateLocation(
			array(
				'location_uuid' => $this->uuidOf( $b, $early ),
				'is_default'    => true,
			),
			$this->manager
		);

		$this->assertTrue( $barrier->fired );
		$this->assertTrue( $b->isReady( 5000 ), 'B\'s lock of the default must go through once A commits.' );

		$b->reap();
		$b->query( $this->raw( MysqlLocationRepository::CLEAR_DEFAULT, $this->defaultLocation ) );

		$cleared = $b->affectedRows();

		$b->query( $this->raw( MysqlLocationRepository::MARK_DEFAULT, $this->defaultLocation ) );

		$marked = $b->affectedRows();

		$b->query( 'COMMIT' );

		$this->assertSame( array( 1, 1 ), array( $cleared, $marked ), 'B\'s clear found the default A marked while B waited.' );
		$this->assertSame( array( (string) $this->defaultLocation ), $this->defaults( $b ) );
	}

	/**
	 * Tests why moveDefault() locks before it clears: a clear that is itself the statement that waited passes over the default marked meanwhile, and the mark that follows is refused.
	 *
	 * The same interleaving as the test above, with B's clear sent where its lock was.
	 *
	 * @since 0.2.0
	 *
	 * @group concurrency
	 */
	public function test_a_clear_that_waited_passes_over_the_default_marked_meanwhile(): void {
		$b     = $this->secondConnection();
		$early = $this->plantLocation();
		$late  = $this->plantLocation();
		$clear = $this->raw( MysqlLocationRepository::CLEAR_DEFAULT, $this->defaultLocation );

		$this->locations->updateLocation(
			array(
				'location_uuid' => $this->uuidOf( $b, $late ),
				'is_default'    => true,
			),
			$this->manager
		);

		$this->beforeStatement(
			'/^' . preg_quote( $this->raw( MysqlLocationRepository::MARK_DEFAULT, $early ), '/' ) . '$/',
			function () use ( $b, $clear ): void {
				$b->query( 'SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED' );
				$b->query( 'START TRANSACTION' );
				$b->queryAsync( $clear );
				$this->awaitWaiting( $b, $clear, 'updating' );
			}
		);

		$this->locations->updateLocation(
			array(
				'location_uuid' => $this->uuidOf( $b, $early ),
				'is_default'    => true,
			),
			$this->manager
		);

		$this->assertTrue( $b->isReady( 5000 ) );
		$this->assertSame( 0, $b->reap(), 'The clear that waited found the default A marked; if InnoDB no longer passes over it, moveDefault() needs no lock first.' );

		$refused = 0;

		try {
			$b->query( $this->raw( MysqlLocationRepository::MARK_DEFAULT, $this->defaultLocation ) );
		} catch ( \RuntimeException $duplicate ) {
			$refused = $duplicate->getCode();
		}

		$b->query( 'ROLLBACK' );

		$this->assertSame( 1062, $refused, 'The unique key on the marker must refuse a second default.' );
		$this->assertSame( array( (string) $early ), $this->defaults( $b ) );
	}

	/**
	 * Tests that deactivation stops routing on the next plan: the location's units are no longer offered.
	 *
	 * @since 0.2.0
	 */
	public function test_deactivation_stops_routing_on_the_next_plan(): void {
		$variant  = self::variant();
		$location = $this->plantLocation();
		$b        = $this->secondConnection();

		$this->stockItem( $variant, 5, $location );

		$plan = $this->routing()->propose( array( $variant => 3 ), RoutingContext::none() );

		$this->assertSame( array( $location ), array_values( array_unique( array_map( static fn( HoldLine $line ): ?int => $line->locationId, $plan->lines ) ) ), 'The only location with units is planned from.' );

		$answer = $this->locations->deactivateLocation( array( 'location_uuid' => $this->uuidOf( $b, $location ) ), $this->manager );

		$this->assertFalse( $answer['active'] );
		$this->assertRefused( InventoryError::RoutingRefused, fn(): mixed => $this->routing()->propose( array( $variant => 3 ), RoutingContext::none() ) );
		$this->assertSame( array( 5, 0, 0 ), array_values( (array) $this->committedItem( $b, $variant, $location ) ), 'Its stock stays where it is.' );
		$this->assertSame( $answer, $this->locations->deactivateLocation( array( 'location_uuid' => $answer['location_uuid'] ), $this->manager ), 'A second deactivation answers the same.' );
	}

	/**
	 * Tests that an update changes the fields given and keeps the others; an empty phone removes it; hours replace the seven days.
	 *
	 * Planted violation, shown red and removed: in Locations::merged(), take the phone from the
	 * input whether or not it gives one (`$changes['phone'] ?? null`). An update of the name alone
	 * then removes the phone.
	 *
	 * @since 0.2.0
	 */
	public function test_an_update_changes_only_the_fields_it_is_given(): void {
		$b       = $this->secondConnection();
		$created = $this->locations->createLocation(
			array(
				'name'                => 'Leeds shop',
				'address'             => array(
					'country'  => 'GB',
					'line1'    => '12 Market Street',
					'city'     => 'Leeds',
					'postcode' => 'LS1 6AB',
				),
				'phone'               => '+44 113 496 0000',
				'offers_pickup'       => true,
				'pickup_ready_in'     => '2h',
				'pickup_instructions' => "Ring the bell.\nSide door.",
				'priority'            => 3,
				'hours'               => array(
					array(
						'weekday'   => 5,
						'opens_at'  => '09:00',
						'closes_at' => '17:30',
					),
					array(
						'weekday'   => 1,
						'opens_at'  => '09:00',
						'closes_at' => '17:30',
					),
				),
			),
			$this->manager
		);

		$this->assertSame( array( 1, 5 ), array_column( $created['hours'], 'weekday' ), 'The hours are answered by day.' );
		$this->assertFalse( $created['is_default'] );
		$this->assertTrue( $created['active'] );

		$renamed = $this->locations->updateLocation(
			array(
				'location_uuid' => $created['location_uuid'],
				'name'          => 'Leeds Market shop',
			),
			$this->manager
		);

		$this->assertSame( array_merge( $created, array( 'name' => 'Leeds Market shop' ) ), $renamed, 'Only the name changed.' );
		$this->assertSame( $renamed, $this->listed( $created['location_uuid'] ), 'The list reads what the change answered.' );

		$cleared = $this->locations->updateLocation(
			array(
				'location_uuid'       => $created['location_uuid'],
				'phone'               => '',
				'pickup_instructions' => '',
				'hours'               => array(
					array(
						'weekday'   => 6,
						'opens_at'  => '10:00',
						'closes_at' => '14:00',
					),
				),
			),
			$this->manager
		);

		$this->assertSame( array( null, null ), array( $cleared['phone'], $cleared['pickup_instructions'] ) );
		$this->assertSame(
			array(
				array(
					'weekday'   => 6,
					'opens_at'  => '10:00',
					'closes_at' => '14:00',
				),
			),
			$cleared['hours']
		);

		$location = (int) $b->fetchValue( sprintf( "SELECT id FROM `%s` WHERE uuid = '%s'", $this->table( InventoryTables::LOCATIONS ), $created['location_uuid'] ) );

		$this->assertNull( $b->fetchValue( sprintf( 'SELECT phone FROM `%s` WHERE id = %d', $this->table( InventoryTables::LOCATIONS ), $location ) ) );
		$this->assertSame( '7', $b->fetchValue( sprintf( 'SELECT COUNT(*) FROM `%s` WHERE location_id = %d', $this->table( InventoryTables::PICKUP_HOURS ), $location ) ), 'The hours are one row per day.' );
		$this->assertSame( '1', $b->fetchValue( sprintf( 'SELECT COUNT(*) FROM `%s` WHERE location_id = %d AND opens_at IS NOT NULL', $this->table( InventoryTables::PICKUP_HOURS ), $location ) ) );
		$this->assertSame( $cleared, $this->listed( $created['location_uuid'] ) );
	}

	/**
	 * Tests that a field that is not valid is refused, naming it, before any statement.
	 *
	 * @since 0.2.0
	 */
	public function test_a_field_that_is_not_valid_is_refused_naming_it(): void {
		$b     = $this->secondConnection();
		$day   = static fn( int $weekday, string $opens, string $closes ): array => array(
			'weekday'   => $weekday,
			'opens_at'  => $opens,
			'closes_at' => $closes,
		);
		$cases = array(
			'name'                => array( 'name' => '   ' ),
			'address.country'     => array( 'address' => array( 'country' => 'gb' ) ),
			'address.line1'       => array(
				'address' => array(
					'country' => 'GB',
					'line1'   => "12 Market Street\nLeeds",
				),
			),
			'phone'               => array( 'phone' => "0113\t496" ),
			'pickup_instructions' => array( 'pickup_instructions' => "Ring\x07" ),
			'pickup_ready_in'     => array( 'pickup_ready_in' => 'bogus' ),
			'priority'            => array( 'priority' => -5 ),
			'hours'               => array( 'hours' => array( $day( 1, '17:00', '09:00' ) ) ),
			'hours twice'         => array( 'hours' => array( $day( 1, '09:00', '12:00' ), $day( 1, '13:00', '17:00' ) ) ),
			'hours at 24:00'      => array( 'hours' => array( $day( 1, '09:00', '24:00' ) ) ),
		);

		$log = $this->captureQueries(
			function () use ( $cases ): void {
				foreach ( $cases as $field => $input ) {
					$refused = $this->assertRefused( InventoryError::LocationInvalid, fn(): array => $this->locations->createLocation( $input + array( 'name' => 'Shop' ), $this->manager ) );

					$this->assertSame( array( 'field' => explode( ' ', $field )[0] ), $refused->context() );
				}
			}
		);

		$this->assertSame( 0, $log->forTable( $this->table( InventoryTables::LOCATIONS ) )->count(), 'A refused field reached the locations: ' . $log->describe() );
		$this->assertSame( 0, $log->matching( '/^(?:START TRANSACTION|BEGIN)/i' )->count(), 'A refused field opened a unit of work.' );
		$this->assertSame( '1', $b->fetchValue( sprintf( 'SELECT COUNT(*) FROM `%s`', $this->table( InventoryTables::LOCATIONS ) ) ), 'A refused location was written.' );
	}

	/**
	 * Tests that the store's first location becomes its default, and every later one does not.
	 *
	 * @since 0.2.0
	 */
	public function test_the_first_location_of_a_store_becomes_its_default(): void {
		$this->db->execute( 'DELETE FROM %i', $this->table( InventoryTables::LOCATIONS ) );
		wp_cache_delete( MysqlLocationRepository::CACHE_KEY, MysqlLocationRepository::CACHE_GROUP );

		$first  = $this->locations->createLocation( array( 'name' => 'Home' ), $this->manager );
		$second = $this->locations->createLocation( array( 'name' => 'Garage' ), $this->manager );

		$this->assertSame( array( true, false ), array( $first['is_default'], $second['is_default'] ) );
		$this->assertCount( 1, $this->defaults( $this->secondConnection() ) );
	}

	/**
	 * Asserts that a call is refused with a code, and returns the refusal.
	 *
	 * @since 0.2.0
	 *
	 * @param InventoryError $code The code.
	 * @param \Closure       $call The call.
	 * @return CodedException The refusal.
	 */
	private function assertRefused( InventoryError $code, \Closure $call ): CodedException {
		try {
			$call();
		} catch ( CodedException $refused ) {
			$this->assertSame( $code, $refused->errorCode(), $refused->getMessage() );

			return $refused;
		}

		$this->fail( sprintf( 'The call went through instead of being refused with %s.', $code->value ) );
	}

	/**
	 * Returns one location as the list of locations answers it, without its totals.
	 *
	 * @since 0.2.0
	 *
	 * @param string $uuid The location's uuid.
	 * @return array<string, mixed>|null The location, or null when the list does not hold it.
	 */
	private function listed( string $uuid ): ?array {
		foreach ( $this->locations->listLocations( array(), $this->manager )['locations'] as $location ) {
			if ( $uuid === $location['location_uuid'] ) {
				unset( $location['units_on_hand'], $location['open_allocations'] );

				return $location;
			}
		}

		return null;
	}

	/**
	 * Reads the default locations' ids as connection B sees them.
	 *
	 * @since 0.2.0
	 *
	 * @param SecondConnection $b Connection B.
	 * @return list<string> The ids, ascending.
	 */
	private function defaults( SecondConnection $b ): array {
		$ids = (string) $b->fetchValue( sprintf( "SELECT GROUP_CONCAT( id ORDER BY id SEPARATOR ',' ) FROM `%s` WHERE is_default = 1", $this->table( InventoryTables::LOCATIONS ) ) );

		return '' === $ids ? array() : explode( ',', $ids );
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
	 * Builds routing over the test's connection, under the test's mode.
	 *
	 * @since 0.2.0
	 *
	 * @return FulfilmentRouting The routing.
	 */
	private function routing(): FulfilmentRouting {
		return new FulfilmentRouting( new LocationDirectory( new MysqlLocationRepository( $this->db ) ), $this->repository, fn(): FulfilmentMode => $this->mode );
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
