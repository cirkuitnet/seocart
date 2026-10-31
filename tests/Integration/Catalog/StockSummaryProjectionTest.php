<?php
/**
 * Tests the stock summary projection: every event that moves it, through the production wiring
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Catalog;

use SEOCart\Catalog\Application\Doctor\ProductSettler;
use SEOCart\Catalog\Application\Projection\StockSummaries;
use SEOCart\Catalog\Application\Projection\StockSummaryProjector;
use SEOCart\Catalog\Domain\Product;
use SEOCart\Catalog\Infrastructure\CatalogTables;
use SEOCart\Catalog\Interfaces\Cli\CatalogCommand;
use SEOCart\Inventory\Application\StockService;
use SEOCart\Inventory\Domain\Allocation;
use SEOCart\Inventory\Domain\HoldLine;
use SEOCart\Inventory\Domain\LedgerReason;
use SEOCart\Inventory\Infrastructure\InventoryTables;
use SEOCart\Inventory\Infrastructure\MysqlLocationRepository;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Platform\Database\Database;
use SEOCart\Platform\Database\LockService;
use SEOCart\Platform\Database\Schema\DdlGenerator;
use SEOCart\Platform\Database\Schema\SchemaVerifier;
use SEOCart\Platform\Database\SchemaOperations;
use SEOCart\Platform\Database\TransactionManager;
use SEOCart\Platform\Events\Cli\OutboxCommand;
use SEOCart\Platform\Events\DrainOptions;
use SEOCart\Platform\Events\DrainReport;
use SEOCart\Platform\Events\EventCatalog;
use SEOCart\Platform\Events\EventEnvelope;
use SEOCart\Platform\Events\EventPublisher;
use SEOCart\Platform\Events\HookBridge;
use SEOCart\Platform\Events\Migrations\CreateOutboxMigration;
use SEOCart\Platform\Events\Outbox;
use SEOCart\Platform\Events\OutboxDrainer;
use SEOCart\Platform\Events\Publisher;
use SEOCart\Platform\Kernel\Container;
use SEOCart\Platform\Kernel\Modules;
use SEOCart\Platform\Logging\CorrelationId;
use SEOCart\Platform\Logging\LogsTable;
use SEOCart\Platform\Logging\Migrations\CreateLogsMigration;
use SEOCart\Tests\Support\Catalog\CatalogTestCase;
use SEOCart\Tests\Support\Doubles\RecordingWake;
use SEOCart\Tests\Support\Inventory\StockTables;
use SEOCart\Tests\Support\KernelContainer;
use SEOCart\Tests\Support\SecondDatabase;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Plants stock rows and markers directly, and reads the summaries back as the database holds them.

/**
 * `products.stock_summary` follows the events the outbox delivers that move what is for sale, read
 * when the event is delivered, through the kernel's own wiring: the bridge attaches the projector
 * before its first dispatch, and a drain delivers the events. The kernel's reporter writes what the
 * bridge and the drainer report to the plugin's log, created here, which assertNoListenerFailed()
 * reads. `wp seocart catalog summarize-stock` holds the drainer's lock for each page, so a drain in
 * another process never recomputes a summary during a page.
 *
 * The known limitation, in a merchant's words: a product's availability on the listing follows
 * stock received or adjusted, units a paid order takes, an expired checkout hold giving units back,
 * and every save of the product. It does not follow a shopper's checkout holding stock or giving
 * it back. So a product whose last units are held at checkout is still listed in stock while they
 * are held; and if anything else refreshes its availability while they are held, it is listed out
 * of stock and stays so after a declined or abandoned checkout gives them back, until its stock
 * next changes, it is saved, or `wp seocart catalog summarize-stock` runs. The listing's
 * availability is a guide: checkout checks the stock again.
 *
 * Planted violations, each shown red and removed:
 *
 * - in MysqlStockSummaryProjection::WRITE_SUMMARY, also set `updated_at = UTC_TIMESTAMP(6)`: the
 *   settle of the product stuck updating, which requires the instant doctor read, finds it moved
 *   and changes nothing;
 * - attach the listeners where an event is published instead of before a dispatch (in
 *   HookBridge::dispatch(), drop the call to attachListeners(); in Publisher::publish(), call
 *   `$this->bridge->attachListeners()` first): the drain in a process that published nothing
 *   delivers to no listener, and the summary stays unknown;
 * - in ProductSettler::settle(), publish nothing: the settled product's summary stays unknown;
 * - hear a checkout's hold again (list StockReserved among the projector's events in
 *   Modules::EVENT_LISTENERS, with its case in StockSummaryProjector::variantsOf()): the hold of the
 *   last units takes the product out of stock at its commit, and the hold test fails;
 * - in CatalogCommand::summarizePage(), project the page without the lock: the drain in another
 *   process delivers the receipt during the page, and the page's stale summary stays.
 *
 * @since 0.2.0
 */
final class StockSummaryProjectionTest extends CatalogTestCase {

	/**
	 * The kernel's container, as a request that publishes builds it.
	 *
	 * @since 0.2.0
	 *
	 * @var Container
	 */
	private Container $kernel;

	/**
	 * The store's default location.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	private int $location;

	/**
	 * Creates the outbox, the log and the stock tables beside the catalog's, and wires the kernel.
	 *
	 * @since 0.2.0
	 */
	public function set_up(): void {
		parent::set_up();

		$schema = new SchemaOperations( $this->db, new DdlGenerator(), new SchemaVerifier( $this->db ) );

		( new CreateOutboxMigration() )->up( $schema );
		( new CreateLogsMigration() )->up( $schema );

		$this->location = StockTables::create( $this->db );
		$this->kernel   = $this->kernel();

		wp_cache_delete( MysqlLocationRepository::CACHE_KEY, MysqlLocationRepository::CACHE_GROUP );
	}

	/**
	 * Tests that a save, an adjustment, an expired hold's reclaim and an allocation each move the summary once the drain delivers them.
	 *
	 * @since 0.2.0
	 */
	public function test_every_stock_event_the_outbox_delivers_moves_the_summary(): void {
		list( $product, $variant ) = $this->product( 'SUM-1' );
		$stock                     = $this->stock();

		$this->assertSame( 'unknown', $this->summary( $product ), 'A product saved before the projection has no summary.' );

		$this->saved( $product );
		$this->drain();
		$this->assertSame( 'out_of_stock', $this->summary( $product ), 'A saved product whose item holds nothing is out of stock.' );

		$stock->adjust( $variant, $this->location, 3, LedgerReason::Received, Actor::user( 1 ) );
		$this->assertSame( 'out_of_stock', $this->summary( $product ), 'An adjustment is delivered by the drain, after the commit.' );
		$this->drain();
		$this->assertSame( 'in_stock', $this->summary( $product ), 'Received units put it in stock.' );

		// A save while a hold has the last units reads them as not for sale; the hold's expiry then gives them back.
		$expired = $stock->hold( array( new HoldLine( $variant, 3 ) ), 900 );
		$this->saved( $product );
		$this->drain();
		$this->assertSame( 'out_of_stock', $this->summary( $product ), 'A save during the hold finds nothing for sale.' );

		$this->db->execute( 'UPDATE %i SET expires_at = UTC_TIMESTAMP() - INTERVAL 60 SECOND WHERE hold_group = %s', $this->db->table( InventoryTables::HOLDS ), $expired->holdGroup );
		$stock->reclaimExpired( $variant, $this->location );
		$this->assertSame( 'out_of_stock', $this->summary( $product ), 'The reclaim is delivered by the drain, after the commit.' );
		$this->drain();
		$this->assertSame( 'in_stock', $this->summary( $product ), 'The reclaim of an expired hold gives the units back.' );

		$placed = $stock->hold( array( new HoldLine( $variant, 3 ) ), 900 );
		$this->assertSame( 'in_stock', $this->summary( $product ), 'The order\'s hold alone leaves the summary as it was.' );

		$this->db->transaction(
			static function () use ( $stock, $placed, $variant ): void {
				$stock->allocate( $placed->holdGroup, 77, array( new Allocation( 1, $variant, 3 ) ) );
			}
		);
		$this->drain();
		$this->assertSame( 'out_of_stock', $this->summary( $product ), 'Allocated units are not for sale.' );

		$this->assertNoListenerFailed();
	}

	/**
	 * Tests that a checkout's hold and its release leave the summary as it was, so a summary refreshed during a hold stays out of stock after the release until the product's next event.
	 *
	 * @since 0.2.0
	 */
	public function test_a_hold_and_its_release_leave_the_summary_as_it_was(): void {
		list( $product, $variant ) = $this->product( 'SUM-8' );
		$stock                     = $this->stock();

		$stock->adjust( $variant, $this->location, 3, LedgerReason::Received, Actor::user( 1 ) );
		$this->drain();
		$this->assertSame( 'in_stock', $this->summary( $product ) );

		$hold = $stock->hold( array( new HoldLine( $variant, 3 ) ), 900 );
		$this->drain();
		$this->assertSame( 'in_stock', $this->summary( $product ), 'A checkout holding the last units leaves the product summarized in stock while they are held.' );

		$stock->release( $hold->holdGroup, 'payment_declined' );
		$this->drain();
		$this->assertSame( 'in_stock', $this->summary( $product ), 'Its release leaves it as it was.' );

		// The known limitation: a summary refreshed during a hold is not refreshed again by the hold's release.
		$held = $stock->hold( array( new HoldLine( $variant, 3 ) ), 900 );
		$this->saved( $product );
		$this->drain();
		$this->assertSame( 'out_of_stock', $this->summary( $product ), 'A save during the hold finds nothing for sale.' );

		$stock->release( $held->holdGroup, 'payment_declined' );
		$this->drain();
		$this->assertSame( 'out_of_stock', $this->summary( $product ), 'The release is not heard, so the summary stays out of stock with the units back.' );

		$this->saved( $product );
		$this->drain();
		$this->assertSame( 'in_stock', $this->summary( $product ), 'The product\'s next event puts the summary right.' );

		$this->assertNoListenerFailed();
	}

	/**
	 * Tests that units at a second active location count, and units at a deactivated one do not.
	 *
	 * @since 0.2.0
	 */
	public function test_units_count_at_every_active_location_that_ships_online_orders(): void {
		list( $product, $variant ) = $this->product( 'SUM-2' );

		$second = $this->secondLocation();

		$this->db->execute( 'INSERT INTO %i ( variant_id, location_id, on_hand, allocated, held, updated_at ) VALUES ( %d, %d, 4, 0, 0, UTC_TIMESTAMP(6) )', $this->db->table( InventoryTables::ITEMS ), $variant, $second );

		$this->saved( $product );
		$this->drain();
		$this->assertSame( 'in_stock', $this->summary( $product ), 'Units at a second active location are for sale.' );

		$this->db->execute( 'UPDATE %i SET active = 0 WHERE id = %d', $this->db->table( InventoryTables::LOCATIONS ), $second );
		wp_cache_delete( MysqlLocationRepository::CACHE_KEY, MysqlLocationRepository::CACHE_GROUP );

		$this->saved( $product );
		$this->drain();
		$this->assertSame( 'out_of_stock', $this->summary( $product ), 'Units at a deactivated location are not.' );
		$this->assertNoListenerFailed();
	}

	/**
	 * Tests that a variant whose stock is not counted, and one that may be backordered, are in stock with nothing on hand.
	 *
	 * @since 0.2.0
	 */
	public function test_an_untracked_or_backorderable_variant_is_in_stock(): void {
		list( $untracked, $notCounted ) = $this->product( 'SUM-3' );
		list( $backordered, $allowed )  = $this->product( 'SUM-4' );
		$policies                       = $this->db->table( InventoryTables::POLICIES );

		$this->db->execute( 'UPDATE %i SET track = 0 WHERE variant_id = %d', $policies, $notCounted );
		$this->db->execute( 'UPDATE %i SET backorder_policy = %s WHERE variant_id = %d', $policies, 'allow', $allowed );

		$this->saved( $untracked );
		$this->saved( $backordered );
		$this->drain();

		$this->assertSame( 'in_stock', $this->summary( $untracked ) );
		$this->assertSame( 'in_stock', $this->summary( $backordered ) );
		$this->assertNoListenerFailed();
	}

	/**
	 * Tests that a summary written while the product is stuck updating moves neither its marker nor its instant, so doctor's repair, which requires the instant it read, still settles it.
	 *
	 * @since 0.2.0
	 */
	public function test_a_summary_write_leaves_the_marker_and_its_instant_alone(): void {
		list( $product, $variant ) = $this->product( 'SUM-5' );
		$productId                 = (int) $product->id();

		$this->db->execute( 'UPDATE %i SET generation_state = %s WHERE id = %d', $this->db->table( CatalogTables::PRODUCTS ), 'updating', $productId );

		$before = (string) $this->productRow( $productId )['updated_at'];

		$this->stock()->adjust( $variant, $this->location, 2, LedgerReason::Received, Actor::user( 1 ) );
		$this->drain();

		$this->assertSame( 'in_stock', $this->summary( $product ) );
		$this->assertSame( sprintf( 'product %d: settled updating to complete', $productId ), $this->kernel->get( ProductSettler::class )->settle( $productId, $before ), 'The repair that requires the instant doctor read still settles the product.' );
		$this->assertSame( 'complete', $this->productRow( $productId )['generation_state'] ?? null );
		$this->assertNoListenerFailed();
	}

	/**
	 * Tests that an event stored by one process is delivered to the projector by a drain in another that published nothing, through `wp seocart outbox drain`.
	 *
	 * @since 0.2.0
	 */
	public function test_a_drain_in_a_process_that_published_nothing_delivers_to_the_projector(): void {
		list( $product, $variant ) = $this->product( 'SUM-6' );

		$this->stock()->adjust( $variant, $this->location, 4, LedgerReason::Received, Actor::user( 1 ) );

		// The publishing request ends here: whatever it put on the plugin's actions ends with it.
		self::forgetListeners();

		$draining = $this->kernel();
		$lines    = array();
		$command  = new OutboxCommand(
			$draining->get( OutboxDrainer::class ),
			$draining->get( Outbox::class ),
			static function ( string $line ) use ( &$lines ): void {
				$lines[] = $line;
			}
		);

		$this->assertSame( 'unknown', $this->summary( $product ) );
		$this->assertSame( OutboxCommand::EXIT_OK, $command->run( array( 'drain' ), array() ), implode( "\n", $lines ) );
		$this->assertSame( 'in_stock', $this->summary( $product ), 'The drain delivered the adjustment to the projector: ' . implode( "\n", $lines ) );
		$this->assertNoListenerFailed();
	}

	/**
	 * Tests that doctor's settle of a product stuck updating publishes its save with the marker as the field changed, and the projector summarizes it.
	 *
	 * @since 0.2.0
	 */
	public function test_the_doctor_settle_publishes_the_save_the_projector_hears(): void {
		list( $product, $variant ) = $this->product( 'SUM-7' );
		$productId                 = (int) $product->id();

		$this->db->execute( 'UPDATE %i SET on_hand = 3 WHERE variant_id = %d', $this->db->table( InventoryTables::ITEMS ), $variant );
		$this->db->execute( 'UPDATE %i SET generation_state = %s WHERE id = %d', $this->db->table( CatalogTables::PRODUCTS ), 'updating', $productId );

		$this->assertSame( sprintf( 'product %d: settled updating to complete', $productId ), $this->kernel->get( ProductSettler::class )->settle( $productId ) );

		$stored = $this->db->fetchAll( 'SELECT payload_json FROM %i WHERE event_name = %s', $this->db->table( 'outbox' ), 'product_saved' );

		$this->assertCount( 1, $stored, 'The settle stored one ProductSaved.' );
		$this->assertSame( array( 'generation_state' ), Outbox::decode( (string) $stored[0]['payload_json'] )['p']['changed_fields'] ?? null );
		$this->assertSame( 'unknown', $this->summary( $product ) );

		$this->drain();

		$this->assertSame( 'in_stock', $this->summary( $product ), 'The settled product is summarized from its event.' );
		$this->assertNoListenerFailed();
	}

	/**
	 * Tests that the command and a drain never recompute at once: a drain during a page finds the lock held, and the next drain reads the stock the page did not see.
	 *
	 * The command's page reads the product's units, none. Before it writes, another process, on a
	 * connection of its own, receives five units and drains at the end of its request. Unlocked,
	 * that drain would write `in_stock` and the page would then overwrite it with the `out_of_stock`
	 * it read. The page holds the drainer's lock, so the drain is skipped and the receipt stays
	 * pending; the next drain delivers it after the page and reads the five units.
	 *
	 * @since 0.2.0
	 */
	public function test_the_command_and_a_drain_never_recompute_at_once(): void {
		list( $product, $variant ) = $this->product( 'SUM-9' );

		$location = $this->location;
		$second   = new SecondDatabase( $this->reporter() );
		$other    = $this->kernel( $second->db() );
		$state    = array(
			'started' => false,
			'drain'   => null,
		);

		// Before the page's first write, the other process receives units and drains them.
		$interleave = static function ( string $sql ) use ( &$state, $other, $variant, $location ): string {
			if ( ! $state['started'] && str_starts_with( $sql, 'UPDATE' ) && str_contains( $sql, 'SET stock_summary' ) ) {
				$state['started'] = true;

				$other->get( StockService::class )->adjust( $variant, $location, 5, LedgerReason::Received, Actor::user( 1 ) );

				$state['drain'] = $other->get( OutboxDrainer::class )->drain( DrainOptions::command( 30 ) );
			}

			return $sql;
		};

		$lines   = array();
		$command = new CatalogCommand(
			$this->kernel->get( StockSummaries::class ),
			$this->kernel->get( StockSummaryProjector::class ),
			array( $this->kernel->get( LockService::class ), 'withLock' ),
			static function ( string $line ) use ( &$lines ): void {
				$lines[] = $line;
			}
		);

		add_filter( 'query', $interleave );

		try {
			$exit = $command->run( array( 'summarize-stock' ), array() );
		} finally {
			remove_filter( 'query', $interleave );
			$second->close();
		}

		$this->assertSame( CatalogCommand::EXIT_OK, $exit, implode( "\n", $lines ) );
		$this->assertInstanceOf( DrainReport::class, $state['drain'], 'The page wrote no summary.' );
		$this->assertSame( DrainReport::SKIPPED_LOCKED, $state['drain']->skipped, 'The drain in the other process ran during the page.' );
		$this->assertSame( 'out_of_stock', $this->summary( $product ), 'The page wrote the summary it read.' );

		$this->drain();

		$this->assertSame( 'in_stock', $this->summary( $product ), 'The next drain delivered the receipt the page did not see.' );
		$this->assertNoListenerFailed();
	}

	/**
	 * Stores a complete product with its stock item, at zero, and no summary.
	 *
	 * @since 0.2.0
	 *
	 * @param string $sku The SKU.
	 * @return array{0: Product, 1: int} The product, and its variant.
	 */
	private function product( string $sku ): array {
		$product = $this->storedProduct( $sku );
		$variant = (int) $product->defaultVariant()?->id();
		$stock   = $this->stock();

		$this->db->transaction(
			static function () use ( $stock, $variant ): void {
				$stock->createItems( array( $variant ) );
			}
		);

		return array( $product, $variant );
	}

	/**
	 * Publishes a product's save, as the product write does in its transaction.
	 *
	 * @since 0.2.0
	 *
	 * @param Product $product The product.
	 */
	private function saved( Product $product ): void {
		$events = $this->kernel->get( EventPublisher::class );

		$this->db->transaction(
			static function () use ( $product, $events ): void {
				$product->markSaved( array( 'post' ), new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) ) );
				$events->publish( ...$product->releaseEvents() );
			}
		);
	}

	/**
	 * Delivers what the outbox holds, as the end of the request does.
	 *
	 * @since 0.2.0
	 */
	private function drain(): void {
		$this->kernel->get( OutboxDrainer::class )->drain( DrainOptions::command( 30 ) );
	}

	/**
	 * Returns a product's summary as stored.
	 *
	 * @since 0.2.0
	 *
	 * @param Product $product The product.
	 * @return string The summary.
	 */
	private function summary( Product $product ): string {
		return (string) $this->db->fetchValue( 'SELECT stock_summary FROM %i WHERE id = %d', $this->db->table( CatalogTables::PRODUCTS ), (int) $product->id() );
	}

	/**
	 * Returns the kernel's stock service.
	 *
	 * @since 0.2.0
	 *
	 * @return StockService The service.
	 */
	private function stock(): StockService {
		return $this->kernel->get( StockService::class );
	}

	/**
	 * Plants a second active location that ships online orders.
	 *
	 * @since 0.2.0
	 *
	 * @return int The location's id.
	 */
	private function secondLocation(): int {
		$this->db->execute(
			'INSERT INTO %i ( uuid, name, active, fulfils_online, offers_pickup, priority, created_at, updated_at ) VALUES ( %s, %s, 1, 1, 0, 1, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6) )',
			$this->db->table( InventoryTables::LOCATIONS ),
			wp_generate_uuid4(),
			'Second location'
		);
		wp_cache_delete( MysqlLocationRepository::CACHE_KEY, MysqlLocationRepository::CACHE_GROUP );

		return $this->db->lastInsertId();
	}

	/**
	 * Asserts that no listener failed: each delivery reached the projector, and it wrote.
	 *
	 * The bridge and the drainer report through the kernel's reporter, which writes to the plugin's
	 * log, so the log is where an event report is found.
	 *
	 * @since 0.2.0
	 */
	private function assertNoListenerFailed(): void {
		$codes = array_column( $this->db->fetchAll( 'SELECT machine_code FROM %i ORDER BY id', $this->db->table( LogsTable::NAME ) ), 'machine_code' );

		$this->assertSame( array(), array_values( array_filter( $codes, static fn( mixed $code ): bool => str_starts_with( (string) $code, 'events.' ) ) ), 'An event report was logged.' );
	}

	/**
	 * Builds the kernel's container as a request builds it, with the plain connection as its unit of work and a wake that records.
	 *
	 * @since 0.2.0
	 *
	 * @param Database|null $db Optional. The connection, for a container that plays another process. Default the test's.
	 * @return Container The container.
	 */
	private function kernel( ?Database $db = null ): Container {
		return KernelContainer::build(
			$db ?? $this->db,
			$this->reporter(),
			array(
				TransactionManager::class => static fn( Container $c ): TransactionManager => $c->get( Database::class ),
				EventPublisher::class     => static fn( Container $c ): EventPublisher => new Publisher( $c->get( TransactionManager::class ), $c->get( Outbox::class ), $c->get( HookBridge::class ), $c->get( EventCatalog::class ), $c->get( CorrelationId::class ), new RecordingWake() ),
			)
		);
	}

	/**
	 * Takes every callback off the plugin's listened actions, as the end of a process does.
	 *
	 * @since 0.2.0
	 */
	private static function forgetListeners(): void {
		foreach ( Modules::EVENT_LISTENERS as $events ) {
			foreach ( $events as $event ) {
				remove_all_actions( EventEnvelope::hookFor( $event::eventName() ) );
			}
		}
	}
}
