<?php
/**
 * StockTestCase: the base of the tests that hold, adjust and reclaim stock against real tables
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support\Inventory;

use SEOCart\Inventory\Application\StockService;
use SEOCart\Inventory\Application\Transfers;
use SEOCart\Inventory\Domain\Event\StockAllocated;
use SEOCart\Inventory\Domain\Event\StockReservationReleased;
use SEOCart\Inventory\Domain\Event\StockReserved;
use SEOCart\Inventory\Domain\LedgerReason;
use SEOCart\Inventory\Domain\Routing\FulfilmentMode;
use SEOCart\Inventory\Infrastructure\Doctor\StockProjectionCheck;
use SEOCart\Inventory\Infrastructure\InventoryTables;
use SEOCart\Inventory\Infrastructure\MysqlLocationRepository;
use SEOCart\Inventory\Infrastructure\MysqlStockRepository;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Platform\Cli\Doctor\CheckResult;
use SEOCart\Platform\Database\Database;
use SEOCart\Platform\Database\Schema\DdlGenerator;
use SEOCart\Platform\Database\Schema\SchemaVerifier;
use SEOCart\Platform\Database\SchemaOperations;
use SEOCart\Platform\Events\EventCatalog;
use SEOCart\Platform\Events\EventEnvelope;
use SEOCart\Platform\Events\HookBridge;
use SEOCart\Platform\Events\Migrations\CreateOutboxMigration;
use SEOCart\Platform\Events\Outbox;
use SEOCart\Platform\Events\OutboxTable;
use SEOCart\Platform\Events\Publisher;
use SEOCart\Platform\Kernel\Modules;
use SEOCart\Platform\Logging\CorrelationId;
use SEOCart\Support\Events\DomainEvent;
use SEOCart\Support\IdGenerator;
use SEOCart\Tests\Support\DatabaseTestCase;
use SEOCart\Tests\Support\Doubles\FrozenClock;
use SEOCart\Tests\Support\Doubles\RecordingJobQueue;
use SEOCart\Tests\Support\Doubles\RecordingWake;
use SEOCart\Tests\Support\Doubles\SequentialIdGenerator;
use SEOCart\Tests\Support\SecondConnection;
use SEOCart\Tests\Support\SecondDatabase;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- The base plants stock rows directly and reads them back through a second connection.

/**
 * A DatabaseTestCase with the four stock tables and the outbox, and the stock service wired as the kernel wires it.
 *
 * Owns one fact: how a stock test gets real tables, a service over them, and a second runner.
 * The tables are created by their own migrations in set_up() and dropped by the base
 * tear_down(), and every test takes fresh variant ids from a counter, so a test passes alone,
 * in any order and any number of times. The service publishes through the real publisher: an
 * outbox event is a row, and an after-commit event reaches `$this->afterCommit` through the
 * hook bridge. The wake never drains.
 *
 * The second runner is either connection B sending the repository's own statements, prepared
 * from its public constants (raw()), so a change to a statement changes B's copy; or a second
 * service over a SecondDatabase, when B must run the plugin's code. Interleavings are set by
 * barriers, never pauses: beforeStatement() runs B's side at the moment connection A is about to
 * send a statement of a given shape, and waitsOrAnswers() asks the server whether B is waiting.
 *
 * @since 0.1.0
 */
abstract class StockTestCase extends DatabaseTestCase {

	/**
	 * The instant the service's clock shows.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	protected const NOW = '2026-09-24 12:00:00';

	/**
	 * The last variant id handed out, across every test of the run.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private static int $lastVariant = 100;

	/**
	 * The last location number handed out, for the names of planted locations.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	private static int $lastLocation = 0;

	/**
	 * The store's choice for orders no one location can ship whole, which the routing of every service of the test reads.
	 *
	 * @since 0.2.0
	 *
	 * @var FulfilmentMode
	 */
	protected FulfilmentMode $mode = FulfilmentMode::Split;

	/**
	 * The store's default location, which set_up() creates as the upgrade does.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	protected int $defaultLocation;

	/**
	 * The repository over `$this->db`.
	 *
	 * @since 0.1.0
	 *
	 * @var MysqlStockRepository
	 */
	protected MysqlStockRepository $repository;

	/**
	 * The service over `$this->db`.
	 *
	 * @since 0.1.0
	 *
	 * @var StockService
	 */
	protected StockService $service;

	/**
	 * The correlation id every service of the test shares.
	 *
	 * @since 0.1.0
	 *
	 * @var CorrelationId
	 */
	protected CorrelationId $correlation;

	/**
	 * The ids the service over `$this->db` mints.
	 *
	 * @since 0.1.0
	 *
	 * @var SequentialIdGenerator
	 */
	protected SequentialIdGenerator $ids;

	/**
	 * Returns an order's status for doctor, from the order's id, or null when there is no such order: the order module's answer, which a stock test plays.
	 *
	 * @since 0.2.0
	 *
	 * @var (\Closure(int): ?string)|null
	 */
	protected ?\Closure $orderStatus = null;

	/**
	 * What the transfer services of the test queued.
	 *
	 * @since 0.2.0
	 *
	 * @var RecordingJobQueue
	 */
	protected RecordingJobQueue $queue;

	/**
	 * Every after-commit stock event delivered, in order.
	 *
	 * @since 0.1.0
	 *
	 * @var list<DomainEvent>
	 */
	protected array $afterCommit = array();

	/**
	 * The second databases this test opened.
	 *
	 * @since 0.1.0
	 *
	 * @var list<SecondDatabase>
	 */
	private array $secondDatabases = array();

	/**
	 * The bridge every publisher of the test shares.
	 *
	 * @since 0.1.0
	 *
	 * @var HookBridge
	 */
	private HookBridge $bridge;

	/**
	 * Creates the tables and the service, and listens for the after-commit events.
	 *
	 * @since 0.1.0
	 */
	public function set_up(): void {
		parent::set_up();

		$operations = new SchemaOperations( $this->db, new DdlGenerator(), new SchemaVerifier( $this->db ) );

		( new CreateOutboxMigration() )->up( $operations );

		$this->defaultLocation = StockTables::create( $this->db );

		$this->afterCommit = array();
		$this->correlation = new CorrelationId( new SequentialIdGenerator( 900000 ) );
		$this->bridge      = new HookBridge( $this->reporter() );
		$this->ids         = new SequentialIdGenerator( 1 );
		$this->repository  = new MysqlStockRepository( $this->db );
		$this->service     = $this->serviceOver( $this->db, $this->ids );
		$this->queue       = new RecordingJobQueue( $this->db );

		foreach ( array( StockReserved::eventName(), StockReservationReleased::eventName() ) as $name ) {
			add_action(
				EventEnvelope::hookFor( $name ),
				function ( DomainEvent $event ): void {
					$this->afterCommit[] = $event;
				}
			);
		}
	}

	/**
	 * Closes the second databases.
	 *
	 * @since 0.1.0
	 */
	public function tear_down(): void {
		foreach ( $this->secondDatabases as $second ) {
			$second->close();
		}

		$this->secondDatabases = array();

		parent::tear_down();
	}

	/**
	 * Builds a stock service over a connection, publishing through the real publisher.
	 *
	 * @since 0.1.0
	 *
	 * @param Database    $db  The connection.
	 * @param IdGenerator $ids The ids it mints: a second runner needs its own range.
	 * @return StockService The service.
	 */
	protected function serviceOver( Database $db, IdGenerator $ids ): StockService {
		return StockServices::over( $db, $this->publisherOver( $db ), $ids, FrozenClock::at( self::NOW ), $this->correlation, fn(): FulfilmentMode => $this->mode );
	}

	/**
	 * Builds the transfer service over a connection, publishing through the real publisher and queueing through the test's queue.
	 *
	 * @since 0.2.0
	 *
	 * @param Database    $db  The connection.
	 * @param IdGenerator $ids The ids it mints: a second runner needs its own range.
	 * @return Transfers The service.
	 */
	protected function transfersOver( Database $db, IdGenerator $ids ): Transfers {
		return StockServices::transfers( $db, $this->publisherOver( $db ), $ids, FrozenClock::at( self::NOW ), $this->correlation, $this->queue );
	}

	/**
	 * Builds the real publisher over a connection, delivering through the bridge every publisher of the test shares.
	 *
	 * @since 0.2.0
	 *
	 * @param Database $db The connection.
	 * @return Publisher The publisher.
	 */
	protected function publisherOver( Database $db ): Publisher {
		return new Publisher( $db, new Outbox( $db ), $this->bridge, new EventCatalog( Modules::EVENT_CLASSES ), $this->correlation, new RecordingWake() );
	}

	/**
	 * Opens a second runner of the plugin's code: a service over its own connection. It is closed in tear_down().
	 *
	 * @since 0.1.0
	 *
	 * @return StockService The service.
	 */
	protected function secondService(): StockService {
		$second                  = new SecondDatabase( $this->reporter() );
		$this->secondDatabases[] = $second;

		return $this->serviceOver( $second->db(), new SequentialIdGenerator( 500000 ) );
	}

	/**
	 * Returns a variant id no earlier test of the run used.
	 *
	 * @since 0.1.0
	 *
	 * @return int The id.
	 */
	protected static function variant(): int {
		return ++self::$lastVariant;
	}

	/**
	 * Plants a location beside the default one, and returns its id.
	 *
	 * @since 0.2.0
	 *
	 * @param int  $priority      Optional. Its routing priority. Default 1, after the default location.
	 * @param bool $active        Optional. Whether it is active. Default true.
	 * @param bool $fulfilsOnline Optional. Whether it ships online orders. Default true.
	 * @param bool $offersPickup  Optional. Whether it offers pickup. Default false.
	 * @return int The location's id.
	 */
	protected function plantLocation( int $priority = 1, bool $active = true, bool $fulfilsOnline = true, bool $offersPickup = false ): int {
		$this->db->execute(
			'INSERT INTO %i ( uuid, name, active, fulfils_online, offers_pickup, priority, created_at, updated_at ) VALUES ( %s, %s, %d, %d, %d, %d, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6) )',
			$this->table( InventoryTables::LOCATIONS ),
			wp_generate_uuid4(),
			'Location ' . ( ++self::$lastLocation ),
			$active ? 1 : 0,
			$fulfilsOnline ? 1 : 0,
			$offersPickup ? 1 : 0,
			$priority
		);

		wp_cache_delete( MysqlLocationRepository::CACHE_KEY, MysqlLocationRepository::CACHE_GROUP );

		return $this->db->lastInsertId();
	}

	/**
	 * Plants an item row directly, with its variant's policy row when the variant has none yet, and no ledger entry.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 The location, and the policy row.
	 *
	 * @param int      $variantId  The variant.
	 * @param int      $onHand     Optional. Units on hand. Default 0.
	 * @param int      $allocated  Optional. Units allocated. Default 0.
	 * @param int      $held       Optional. Units held, without rows. Default 0.
	 * @param bool     $track      Optional. Whether stock is counted, when the policy row is planted. Default true.
	 * @param int|null $locationId Optional. The location. Default null, the default location.
	 */
	protected function plantItem( int $variantId, int $onHand = 0, int $allocated = 0, int $held = 0, bool $track = true, ?int $locationId = null ): void {
		$this->plantPolicy( $variantId, $track );
		$this->db->execute(
			'INSERT INTO %i ( variant_id, location_id, on_hand, allocated, held, updated_at ) VALUES ( %d, %d, %d, %d, %d, UTC_TIMESTAMP(6) )',
			$this->table( InventoryTables::ITEMS ),
			$variantId,
			$locationId ?? $this->defaultLocation,
			$onHand,
			$allocated,
			$held
		);
	}

	/**
	 * Plants a variant's policy row, unless it has one.
	 *
	 * @since 0.2.0
	 *
	 * @param int    $variantId The variant.
	 * @param bool   $track     Optional. Whether stock is counted. Default true.
	 * @param string $backorder Optional. The backorder policy. Default `no`.
	 */
	protected function plantPolicy( int $variantId, bool $track = true, string $backorder = 'no' ): void {
		$this->db->execute(
			'INSERT INTO %i ( variant_id, track, backorder_policy, updated_at ) VALUES ( %d, %d, %s, UTC_TIMESTAMP(6) ) ON DUPLICATE KEY UPDATE variant_id = variant_id',
			$this->table( InventoryTables::POLICIES ),
			$variantId,
			$track ? 1 : 0,
			$backorder
		);
	}

	/**
	 * Creates an item through the service and gives it units through an adjustment, so its ledger agrees.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 The location.
	 *
	 * @param int      $variantId  The variant.
	 * @param int      $onHand     The units on hand.
	 * @param int|null $locationId Optional. The location. Default null, the default location, where createItems() puts it.
	 */
	protected function stockItem( int $variantId, int $onHand, ?int $locationId = null ): void {
		$this->db->transaction(
			function () use ( $variantId ): void {
				$this->service->createItems( array( $variantId ) );
			}
		);

		if ( 0 !== $onHand ) {
			$this->service->adjust( $variantId, $locationId ?? $this->defaultLocation, $onHand, LedgerReason::Received, Actor::user( 1 ) );
		}
	}

	/**
	 * Plants a hold row and adds its units to the item's `held`, as a hold would have.
	 *
	 * @since 0.1.0
	 *
	 * @param int         $variantId        The variant, planted first.
	 * @param int         $quantity         The units.
	 * @param int         $expiresInSeconds Seconds from now on the database clock; negative is expired.
	 * @param string|null $holdGroup        Optional. The hold's id. Default a fresh one.
	 * @param int|null    $locationId       Optional. The location of the item it holds. Default null, the default location.
	 * @return string The hold's id.
	 */
	protected function plantHold( int $variantId, int $quantity, int $expiresInSeconds, ?string $holdGroup = null, ?int $locationId = null ): string {
		$holdGroup  ??= SequentialIdGenerator::nth( 700000 + self::variant() );
		$locationId ??= $this->defaultLocation;

		$this->db->execute(
			'INSERT INTO %i ( variant_id, location_id, hold_group, quantity, expires_at, created_at ) VALUES ( %d, %d, %s, %d, UTC_TIMESTAMP() + INTERVAL %d SECOND, UTC_TIMESTAMP(6) )',
			$this->table( InventoryTables::HOLDS ),
			$variantId,
			$locationId,
			$holdGroup,
			$quantity,
			$expiresInSeconds
		);
		$this->db->execute( 'UPDATE %i SET held = held + %d WHERE variant_id = %d AND location_id = %d', $this->table( InventoryTables::ITEMS ), $quantity, $variantId, $locationId );

		return $holdGroup;
	}

	/**
	 * Reads an item as connection B sees it, which is what is committed.
	 *
	 * @since 0.1.0
	 *
	 * @param SecondConnection $b          Connection B.
	 * @param int              $variantId  The variant.
	 * @param int|null         $locationId Optional. The location. Default null, the default location.
	 * @return array{on_hand: int, allocated: int, held: int}|null The counters, or null when there is no item.
	 *
	 * @phpstan-impure
	 */
	protected function committedItem( SecondConnection $b, int $variantId, ?int $locationId = null ): ?array {
		$row = $b->fetchRow( sprintf( 'SELECT on_hand, allocated, held FROM `%s` WHERE variant_id = %d AND location_id = %d', $this->table( InventoryTables::ITEMS ), $variantId, $locationId ?? $this->defaultLocation ) );

		return null === $row ? null : array(
			'on_hand'   => (int) $row['on_hand'],
			'allocated' => (int) $row['allocated'],
			'held'      => (int) $row['held'],
		);
	}

	/**
	 * Reads an item's hold rows as connection B sees them.
	 *
	 * @since 0.1.0
	 *
	 * @param SecondConnection $b         Connection B.
	 * @param int              $variantId The variant.
	 * @return list<string> Each row as `hold_group:quantity`, by id.
	 *
	 * @phpstan-impure
	 */
	protected function committedHolds( SecondConnection $b, int $variantId ): array {
		$rows = (string) $b->fetchValue( sprintf( "SELECT GROUP_CONCAT( CONCAT( hold_group, ':', quantity ) ORDER BY id SEPARATOR ',' ) FROM `%s` WHERE variant_id = %d", $this->table( InventoryTables::HOLDS ), $variantId ) );

		return '' === $rows ? array() : explode( ',', $rows );
	}

	/**
	 * Counts committed outbox rows of one event, as connection B sees them.
	 *
	 * @since 0.1.0
	 *
	 * @param SecondConnection $b         Connection B.
	 * @param string           $eventName The event's name.
	 * @return int The count.
	 *
	 * @phpstan-impure
	 */
	protected function committedEvents( SecondConnection $b, string $eventName ): int {
		return (int) $b->fetchValue( sprintf( "SELECT COUNT(*) FROM `%s` WHERE event_name = '%s'", $this->table( OutboxTable::NAME ), $eventName ) );
	}

	/**
	 * Reads the newest committed outbox row of one event, as connection B sees it, and returns its payload's fields.
	 *
	 * @since 0.2.0
	 *
	 * @param SecondConnection $b         Connection B.
	 * @param string           $eventName The event's name.
	 * @return array<string, mixed> The fields, as a listener's event would be rebuilt from them.
	 *
	 * @phpstan-impure
	 */
	protected function committedPayload( SecondConnection $b, string $eventName ): array {
		return (array) Outbox::decode( (string) $b->fetchValue( sprintf( "SELECT payload_json FROM `%s` WHERE event_name = '%s' ORDER BY id DESC LIMIT 1", $this->table( OutboxTable::NAME ), $eventName ) ) )['p'];
	}

	/**
	 * Returns the lines of a stock event that names several items: each variant once with its units, then each item's variant, location and units.
	 *
	 * @since 0.2.0
	 *
	 * @param DomainEvent $event The event: a reservation, a release or an allocation; any other fails the test.
	 * @return array{0: list<int>, 1: list<int>, 2: list<int>, 3: list<int>, 4: list<int>} The five lists, in that order.
	 */
	protected static function lines( DomainEvent $event ): array {
		if ( ! $event instanceof StockReserved && ! $event instanceof StockReservationReleased && ! $event instanceof StockAllocated ) {
			self::fail( sprintf( 'A %s names no items.', get_class( $event ) ) );
		}

		return array( $event->variantIds, $event->quantities, $event->locationVariantIds, $event->locationIds, $event->locationQuantities );
	}

	/**
	 * Reads an item's ledger as connection B sees it.
	 *
	 * @since 0.1.0
	 *
	 * @param SecondConnection $b         Connection B.
	 * @param int              $variantId The variant.
	 * @return list<array<string, string|null>> The entries, oldest first.
	 *
	 * @phpstan-impure
	 */
	protected function committedLedger( SecondConnection $b, int $variantId ): array {
		$entries = array();
		$count   = (int) $b->fetchValue( sprintf( 'SELECT COUNT(*) FROM `%s` WHERE variant_id = %d', $this->table( InventoryTables::LEDGER ), $variantId ) );

		for ( $index = 0; $index < $count; ++$index ) {
			$entries[] = (array) $b->fetchRow( sprintf( 'SELECT id, delta, on_hand_after, reason, actor_type, actor_id, correlation_id FROM `%s` WHERE variant_id = %d ORDER BY id LIMIT 1 OFFSET %d', $this->table( InventoryTables::LEDGER ), $variantId, $index ) );
		}

		return $entries;
	}

	/**
	 * Returns a repository statement prepared for connection B, from the repository's own constant.
	 *
	 * @since 0.1.0
	 *
	 * @param string $statement A MysqlStockRepository constant.
	 * @param mixed  ...$values Its values.
	 * @return string The statement, ready to send.
	 */
	protected function raw( string $statement, mixed ...$values ): string {
		global $wpdb;

		list( $sql, $arguments ) = MysqlStockRepository::expand( $statement, $values, fn( string $name ): string => $this->table( $name ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The statement is the repository's constant, expanded; this is its prepare step.
		return (string) $wpdb->prepare( $sql, ...$arguments );
	}

	/**
	 * Returns a pattern matching exactly the statements a repository constant produces.
	 *
	 * @since 0.1.0
	 *
	 * @param string $statement A MysqlStockRepository constant.
	 * @return string A regular expression over the whole statement.
	 */
	protected static function shapeOf( string $statement ): string {
		$pattern = (string) preg_replace_callback(
			'/\{[a-z_]+\}|%[dsi]|[^{%]+|[{%]/',
			static function ( array $part ): string {
				return match ( true ) {
					'{list}' === $part[0]                      => '-?\d+(?:, -?\d+)*',
					str_starts_with( $part[0], '{' ) && strlen( $part[0] ) > 1 => '`[^`]+`',
					'%d' === $part[0]                          => '-?\d+',
					'%s' === $part[0]                          => "'[^']*'",
					'%i' === $part[0]                          => '`[^`]+`',
					default                                    => preg_quote( $part[0], '/' ),
				};
			},
			$statement
		);

		return '/^' . $pattern . '$/';
	}

	/**
	 * Tells whether B's asynchronous statement is waiting in the server, or has answered.
	 *
	 * Asks the server's process list, as awaitWaiting() does, and watches B's socket between two
	 * looks. It returns as soon as either is certain; the deadline fails the test and never decides.
	 *
	 * @since 0.1.0
	 *
	 * @param SecondConnection $b     Connection B, with an asynchronous statement in flight.
	 * @param string           $sql   The statement B sent, exactly.
	 * @param string           $state The process-list state of the wait, for a row lock `updating`.
	 * @return bool True when B is waiting; false when B has answered.
	 */
	protected function waitsOrAnswers( SecondConnection $b, string $sql, string $state ): bool {
		$observer = $this->secondConnection();
		$deadline = hrtime( true ) + 5000 * 1000000;
		$query    = sprintf( 'SELECT COMMAND, STATE, INFO FROM information_schema.PROCESSLIST WHERE ID = %d', $b->threadId() );

		while ( true ) {
			$row     = $observer->fetchRow( $query );
			$waiting = null !== $row && 'Query' === $row['COMMAND'] && $sql === $row['INFO'] && 0 === strcasecmp( $state, (string) $row['STATE'] );

			// B's socket is watched after the look at the process list: an answer since means B was not waiting after all.
			if ( $b->isReady( 5 ) ) {
				return false;
			}

			if ( $waiting ) {
				return true;
			}

			if ( hrtime( true ) >= $deadline ) {
				$this->fail( 'The server showed B neither waiting nor answered within 5 seconds.' );
			}
		}
	}

	/**
	 * Arranges a deadlock for a hold of two items: B holds the second, and asks for the first when A is about to claim the second.
	 *
	 * B locks the second item, and makes itself the heavier transaction by changing three fixture
	 * rows. When A, holding the first item, is about to claim the second, B asks for the first and
	 * is shown waiting by the server. A's claim then closes the cycle, InnoDB rolls back the
	 * lighter transaction, A, and the sleeper is the barrier: it lets B finish and commit, so A's
	 * second attempt meets no lock. If InnoDB picks B instead, no pause happens, and the caller's
	 * assertion on the pauses fails.
	 *
	 * @since 0.1.0
	 *
	 * @param int $first  The lower variant id, which A claims first.
	 * @param int $second The higher variant id.
	 * @return SecondConnection Connection B.
	 */
	protected function deadlockBeforeClaimOf( int $first, int $second ): SecondConnection {
		foreach ( array( 1, 2, 3 ) as $id ) {
			$this->insertRow( $id, 'seed' );
		}

		$b     = $this->secondConnection();
		$waits = $this->raw( MysqlStockRepository::LOCK_ITEM, $first, $this->defaultLocation );

		$b->query( 'START TRANSACTION' );
		$b->query( sprintf( "UPDATE `%s` SET value = 'b' WHERE id IN (1, 2, 3)", $this->rowsTable() ) );
		$b->query( $this->raw( MysqlStockRepository::LOCK_ITEM, $second, $this->defaultLocation ) );

		$this->beforeStatement(
			'/^' . preg_quote( $this->raw( MysqlStockRepository::CLAIM, 1, $second, $this->defaultLocation, 1 ), '/' ) . '$/',
			function () use ( $b, $waits ): void {
				$b->queryAsync( $waits );
				$this->awaitWaiting( $b, $waits, 'updating' );
			}
		);

		$this->onSleep = function () use ( $b ): void {
			$this->assertTrue( $b->isReady( 5000 ), 'B\'s lock of the first item must go through once A is rolled back.' );
			$this->assertSame( 1, $b->reap() );

			$b->query( 'COMMIT' );
		};

		return $b;
	}

	/**
	 * Runs the stock check of doctor.
	 *
	 * @since 0.1.0
	 *
	 * @return CheckResult Its result.
	 */
	protected function projectionCheck(): CheckResult {
		return $this->stockCheck()->run();
	}

	/**
	 * Builds the stock check of doctor over the test's connection.
	 *
	 * @since 0.2.0
	 *
	 * @return StockProjectionCheck The check.
	 */
	protected function stockCheck(): StockProjectionCheck {
		return StockServices::check( $this->db, $this->service, $this->orderStatus );
	}

	/**
	 * Returns a plugin table's full name.
	 *
	 * @since 0.1.0
	 *
	 * @param string $name The unprefixed name.
	 * @return string The full name.
	 */
	protected function table( string $name ): string {
		return $this->db->table( $name );
	}
}
