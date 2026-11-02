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
use SEOCart\Catalog\Application\Projection\CompleteProductPager;
use SEOCart\Catalog\Application\Projection\PriceProjector;
use SEOCart\Catalog\Application\Projection\SearchDocumentProjector;
use SEOCart\Catalog\Application\Projection\StockSummaryProjector;
use SEOCart\Catalog\Domain\Product;
use SEOCart\Catalog\Infrastructure\CatalogTables;
use SEOCart\Catalog\Interfaces\Cli\CatalogCommand;
use SEOCart\Inventory\Application\InventoryOperations;
use SEOCart\Inventory\Application\LocationDirectory;
use SEOCart\Inventory\Application\LocationOperations;
use SEOCart\Inventory\Application\Locations;
use SEOCart\Inventory\Application\SetStockLevels;
use SEOCart\Inventory\Application\StockService;
use SEOCart\Inventory\Application\Transfers;
use SEOCart\Inventory\Domain\Allocation;
use SEOCart\Inventory\Domain\Event\StockReservationReleased;
use SEOCart\Inventory\Domain\Event\StockReserved;
use SEOCart\Inventory\Domain\Event\StockTransferred;
use SEOCart\Inventory\Domain\HoldLine;
use SEOCart\Inventory\Domain\LedgerReason;
use SEOCart\Inventory\Domain\StockTarget;
use SEOCart\Inventory\Infrastructure\InventoryTables;
use SEOCart\Inventory\Infrastructure\Jobs\MoveLocationStock;
use SEOCart\Inventory\Infrastructure\MysqlLocationRepository;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Platform\Database\Database;
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
use SEOCart\Platform\Events\OutboxTable;
use SEOCart\Platform\Events\Publisher;
use SEOCart\Platform\Jobs\JobQueue;
use SEOCart\Platform\Kernel\Container;
use SEOCart\Platform\Kernel\Modules;
use SEOCart\Platform\Logging\CorrelationId;
use SEOCart\Platform\Logging\LogsTable;
use SEOCart\Platform\Logging\Migrations\CreateLogsMigration;
use SEOCart\Tests\Support\Catalog\CatalogTestCase;
use SEOCart\Tests\Support\CreatesUsers;
use SEOCart\Tests\Support\Doubles\RecordingJobQueue;
use SEOCart\Tests\Support\Doubles\RecordingWake;
use SEOCart\Tests\Support\GrantsCapabilities;
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
 * A checkout's hold, the hold given back and units sent or received between locations are outbox
 * events too: nothing runs in the request that stores them, and a drain in another process, over a
 * connection of its own, delivers them (drainElsewhere()), as the job runner or the end of the
 * request does. The statements one delivery of each sends are printed, not pinned.
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
 * - in StockReserved::deliveryMode(), answer AfterCommit: the projector runs in the request that
 *   took the hold, and the summary moves at the hold's commit;
 * - leave StockReservationReleased out of the projector's events in Modules::EVENT_LISTENERS: the
 *   release drained leaves the product out of stock with its units back;
 * - in StockSummaryProjector::variantsOf(), remove the StockReserved case: the drain reports
 *   `events.listener_failed`;
 * - leave StockTransferred out of the projector's events in Modules::EVENT_LISTENERS: a send of
 *   the last units drained leaves the product in stock;
 * - in CompleteProductPager::walk(), do the page's work without the lock: the drain in another
 *   process delivers the receipt during the page, and the page's stale summary stays.
 *
 * @since 0.2.0
 */
final class StockSummaryProjectionTest extends CatalogTestCase {

	use CreatesUsers;
	use GrantsCapabilities;

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
	 * Deletes the users the test created.
	 *
	 * @since 0.2.0
	 */
	public function tear_down(): void {
		$this->deleteCreatedUsers();

		parent::tear_down();
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
		$this->assertSame( 'in_stock', $this->summary( $product ), 'The order\'s hold is delivered by the drain, after the commit.' );

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
	 * Tests that a checkout's hold of a product's last units leaves its summary as it was at the hold's commit, nothing having run in the request that took it, and that a drain in another process then takes it out of stock; the hold given back, drained by another process again, puts it back in stock.
	 *
	 * @since 0.2.0
	 */
	public function test_a_hold_and_its_release_move_the_summary_from_a_drain_in_another_process(): void {
		list( $product, $variant ) = $this->product( 'SUM-8' );
		$stock                     = $this->stock();

		$stock->adjust( $variant, $this->location, 3, LedgerReason::Received, Actor::user( 1 ) );
		$this->drainElsewhere();
		$this->assertSame( 'in_stock', $this->summary( $product ) );

		$hold = $stock->hold( array( new HoldLine( $variant, 3 ) ), 900 );

		$this->assertSame( 'in_stock', $this->summary( $product ), 'Nothing ran in the request that took the hold.' );
		$this->assertSame( array( Outbox::PENDING ), $this->states( StockReserved::eventName() ), 'The reservation waits in the outbox.' );

		$this->drainElsewhere();
		$this->assertNoListenerFailed();
		$this->assertSame( 'out_of_stock', $this->summary( $product ), 'The drain in another process delivered the hold of the last units.' );

		$stock->release( $hold->holdGroup, 'payment_declined' );

		$this->assertSame( 'out_of_stock', $this->summary( $product ), 'Nothing ran in the request that gave the hold back.' );
		$this->assertSame( array( Outbox::PENDING ), $this->states( StockReservationReleased::eventName() ), 'The release waits in the outbox.' );

		$this->drainElsewhere();
		$this->assertNoListenerFailed();
		$this->assertSame( 'in_stock', $this->summary( $product ), 'The release drained by another process puts the units back on the listing.' );
	}

	/**
	 * Tests that a summary recomputed during a hold, out of stock, is put back in stock by the hold's release once drained.
	 *
	 * @since 0.2.0
	 */
	public function test_a_save_during_a_hold_then_its_release_ends_in_stock(): void {
		list( $product, $variant ) = $this->product( 'SUM-10' );
		$stock                     = $this->stock();

		$stock->adjust( $variant, $this->location, 3, LedgerReason::Received, Actor::user( 1 ) );

		$held = $stock->hold( array( new HoldLine( $variant, 3 ) ), 900 );

		$this->saved( $product );
		$this->drainElsewhere();
		$this->assertSame( 'out_of_stock', $this->summary( $product ), 'A save during the hold finds nothing for sale.' );

		$stock->release( $held->holdGroup, 'payment_declined' );
		$this->drainElsewhere();
		$this->assertSame( 'in_stock', $this->summary( $product ), 'The release is heard: the units are back on the listing.' );
		$this->assertNoListenerFailed();
	}

	/**
	 * Tests that the summary ends right whatever order a hold and its release are delivered in, and that a release delivered again writes no summary.
	 *
	 * The hold's row is held back, so a save during the hold and then the release are delivered
	 * first; the hold's, delivered last, reads the units as they are then. The release's row is
	 * then made pending again, as a delivery that never reported back would leave it, and the
	 * drain delivers it again.
	 *
	 * @since 0.2.0
	 */
	public function test_a_late_hold_and_a_repeated_release_leave_the_summary_right(): void {
		list( $product, $variant ) = $this->product( 'SUM-11' );
		$stock                     = $this->stock();
		$outbox                    = $this->db->table( OutboxTable::NAME );

		$stock->adjust( $variant, $this->location, 3, LedgerReason::Received, Actor::user( 1 ) );
		$this->drainElsewhere();

		$hold = $stock->hold( array( new HoldLine( $variant, 3 ) ), 900 );

		$this->db->execute( 'UPDATE %i SET available_at = UTC_TIMESTAMP(6) + INTERVAL 1 HOUR WHERE event_name = %s', $outbox, StockReserved::eventName() );
		$this->saved( $product );
		$this->drainElsewhere();
		$this->assertSame( 'out_of_stock', $this->summary( $product ), 'A save during the hold finds nothing for sale.' );

		$stock->release( $hold->holdGroup, 'payment_declined' );
		$this->drainElsewhere();

		$this->assertSame( array( Outbox::PENDING ), $this->states( StockReserved::eventName() ), 'The hold\'s row was held back.' );
		$this->assertSame( 'in_stock', $this->summary( $product ), 'The release, delivered before its hold, reads the units back.' );

		$this->db->execute( 'UPDATE %i SET available_at = UTC_TIMESTAMP(6) WHERE event_name = %s', $outbox, StockReserved::eventName() );
		$this->drainElsewhere();

		$this->assertSame( array( Outbox::DISPATCHED ), $this->states( StockReserved::eventName() ) );
		$this->assertSame( 'in_stock', $this->summary( $product ), 'The hold, delivered last, reads the units as they are now: back.' );

		$this->db->execute( "UPDATE %i SET state = 'pending', available_at = UTC_TIMESTAMP(6), dispatched_at = NULL, claim_token = NULL, claimed_until = NULL WHERE event_name = %s", $outbox, StockReservationReleased::eventName() );

		$sent = $this->drainElsewhere();

		$this->assertSame( '2', $this->db->fetchValue( 'SELECT attempts FROM %i WHERE event_name = %s', $outbox, StockReservationReleased::eventName() ), 'The release was delivered again.' );
		$this->assertSame( array(), array_values( preg_grep( '/^UPDATE \S*' . CatalogTables::PRODUCTS . '\S* SET stock_summary/', $sent ) ), 'The repeated delivery wrote no summary.' );
		$this->assertSame( 'in_stock', $this->summary( $product ) );
		$this->assertNoListenerFailed();
	}

	/**
	 * Tests that a transfer moves the summary: sending a product's last units takes it out of stock once drained, since units on their way are counted by no location, and their receipt at an active location that ships online orders puts it back.
	 *
	 * @since 0.2.0
	 */
	public function test_a_transfer_sent_and_received_moves_the_summary(): void {
		list( $product, $variant ) = $this->product( 'SUM-12' );

		$second    = $this->secondLocation();
		$manager   = $this->userGranted( InventoryOperations::CAPABILITY );
		$transfers = $this->kernel->get( Transfers::class );

		$this->stock()->adjust( $variant, $this->location, 3, LedgerReason::Received, Actor::user( 1 ) );
		$this->drainElsewhere();
		$this->assertSame( 'in_stock', $this->summary( $product ) );

		$draft = $transfers->createTransfer(
			array(
				'source_location_uuid'      => $this->uuidOf( $this->location ),
				'destination_location_uuid' => $this->uuidOf( $second ),
				'lines'                     => array(
					array(
						'variant_id' => $variant,
						'quantity'   => 3,
					),
				),
			),
			$manager
		);
		$sent  = $transfers->sendTransfer( array( 'transfer_uuid' => $draft['transfer_uuid'] ), $manager );

		$this->drainElsewhere();
		$this->assertSame( 'out_of_stock', $this->summary( $product ), 'Units on their way are counted by no location.' );

		$transfers->receiveTransfer(
			array(
				'transfer_uuid' => $sent['transfer_uuid'],
				'lines'         => array(
					array(
						'line_id'                    => $sent['lines'][0]['line_id'],
						'received'                   => 3,
						'lost'                       => 0,
						'expected_received_quantity' => 0,
						'expected_lost_quantity'     => 0,
					),
				),
			),
			$manager
		);
		$this->drainElsewhere();
		$this->assertSame( 'in_stock', $this->summary( $product ), 'Received at an active location that ships online orders.' );
		$this->assertNoListenerFailed();
	}

	/**
	 * Tests that a deactivation's move brings a product whose units are all at the deactivated location back in stock once its page is drained.
	 *
	 * @since 0.2.0
	 */
	public function test_a_deactivation_move_puts_the_units_back_in_stock(): void {
		list( $product, $variant ) = $this->product( 'SUM-13' );

		$gone    = $this->secondLocation();
		$manager = $this->userGranted( LocationOperations::CAPABILITY, InventoryOperations::CAPABILITY );
		$queue   = new RecordingJobQueue( $this->db );
		$kernel  = $this->kernel( null, array( JobQueue::class => static fn(): JobQueue => $queue ) );

		$this->stock()->adjust( $variant, $gone, 4, LedgerReason::Received, Actor::user( 1 ) );
		$this->drainElsewhere();
		$this->assertSame( 'in_stock', $this->summary( $product ), 'Units at a second active location are for sale.' );

		$kernel->get( Locations::class )->deactivateLocation(
			array(
				'location_uuid' => $this->uuidOf( $gone ),
				'move_stock_to' => $this->uuidOf( $this->location ),
			),
			$manager
		);
		wp_cache_delete( MysqlLocationRepository::CACHE_KEY, MysqlLocationRepository::CACHE_GROUP );

		$this->saved( $product );
		$this->drainElsewhere();
		$this->assertSame( 'out_of_stock', $this->summary( $product ), 'Units at a deactivated location are not for sale.' );
		$this->assertCount( 1, $queue->queued, 'The move\'s job is queued.' );

		$kernel->get( MoveLocationStock::class )->handle( $queue->queued[0]->payload );
		$this->drainElsewhere();
		$this->assertSame( 'in_stock', $this->summary( $product ), 'The move\'s page brought the units to an active location.' );
		$this->assertNoListenerFailed();
	}

	/**
	 * Prints the statements one delivery of a hold, of its release and of a transfer's send sends for one product, in a drain in another process that starts cold: the projector's, and the drain's own around them.
	 *
	 * @since 0.2.0
	 */
	public function test_the_statements_of_one_delivery_are_printed(): void {
		list( $product, $variant ) = $this->product( 'SUM-14' );

		$second    = $this->secondLocation();
		$manager   = $this->userGranted( InventoryOperations::CAPABILITY );
		$transfers = $this->kernel->get( Transfers::class );
		$stock     = $this->stock();

		$stock->adjust( $variant, $this->location, 3, LedgerReason::Received, Actor::user( 1 ) );
		$this->drainElsewhere();

		$hold = $stock->hold( array( new HoldLine( $variant, 3 ) ), 900 );

		$this->printDelivery( StockReserved::class, $this->drainElsewhere() );
		$this->assertSame( 'out_of_stock', $this->summary( $product ) );

		$stock->release( $hold->holdGroup, 'payment_declined' );

		$this->printDelivery( StockReservationReleased::class, $this->drainElsewhere() );
		$this->assertSame( 'in_stock', $this->summary( $product ) );

		$draft = $transfers->createTransfer(
			array(
				'source_location_uuid'      => $this->uuidOf( $this->location ),
				'destination_location_uuid' => $this->uuidOf( $second ),
				'lines'                     => array(
					array(
						'variant_id' => $variant,
						'quantity'   => 3,
					),
				),
			),
			$manager
		);

		$transfers->sendTransfer( array( 'transfer_uuid' => $draft['transfer_uuid'] ), $manager );

		$this->printDelivery( StockTransferred::class, $this->drainElsewhere() );
		$this->assertSame( 'out_of_stock', $this->summary( $product ) );
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
	 * Tests that a variant whose stock is not counted is in stock with nothing on hand, and one that may be backordered is not: it is sold from its units only, as a hold claims them.
	 *
	 * @since 0.2.0
	 */
	public function test_an_untracked_variant_is_in_stock_and_a_backorderable_one_only_with_units(): void {
		list( $untracked, $notCounted ) = $this->product( 'SUM-3' );
		list( $backordered, $allowed )  = $this->product( 'SUM-4' );
		$policies                       = $this->db->table( InventoryTables::POLICIES );

		$this->db->execute( 'UPDATE %i SET track = 0 WHERE variant_id = %d', $policies, $notCounted );
		$this->db->execute( 'UPDATE %i SET backorder_policy = %s WHERE variant_id = %d', $policies, 'allow', $allowed );

		$this->saved( $untracked );
		$this->saved( $backordered );
		$this->drain();

		$this->assertSame( 'in_stock', $this->summary( $untracked ) );
		$this->assertSame( 'out_of_stock', $this->summary( $backordered ), 'A variant that may be backordered was in stock with no unit a hold could claim.' );
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
			$this->kernel->get( CompleteProductPager::class ),
			$this->kernel->get( StockSummaryProjector::class ),
			$this->kernel->get( PriceProjector::class ),
			$this->kernel->get( SearchDocumentProjector::class ),
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
	 * @param Database|null $db        Optional. The connection, for a container that plays another process. Default the test's.
	 * @param array         $overrides Optional. Further services the test replaces. Default none.
	 * @return Container The container.
	 *
	 * @phpstan-param array<string, callable(Container): object> $overrides
	 */
	private function kernel( ?Database $db = null, array $overrides = array() ): Container {
		return KernelContainer::build(
			$db ?? $this->db,
			$this->reporter(),
			$overrides + array(
				TransactionManager::class => static fn( Container $c ): TransactionManager => $c->get( Database::class ),
				EventPublisher::class     => static fn( Container $c ): EventPublisher => new Publisher( $c->get( TransactionManager::class ), $c->get( Outbox::class ), $c->get( HookBridge::class ), $c->get( EventCatalog::class ), $c->get( CorrelationId::class ), new RecordingWake() ),
			)
		);
	}

	/**
	 * Delivers what the outbox holds from another process, as the job runner or the end of a request does: the request that stored the events ends, its listeners with it, and a kernel of its own, over a connection of its own and with no cache but the options WordPress loads at boot, drains through `wp seocart outbox drain`.
	 *
	 * @since 0.2.0
	 *
	 * @return list<string> The statements the other process sent, in order.
	 */
	private function drainElsewhere(): array {
		self::forgetListeners();
		wp_cache_flush();
		wp_load_alloptions();

		$second = new SecondDatabase( $this->reporter() );
		$sent   = array();
		$lines  = array();
		$record = static function ( string $sql ) use ( &$sent ): string {
			$sent[] = $sql;

			return $sql;
		};

		try {
			$draining = $this->kernel( $second->db() );
			$command  = new OutboxCommand(
				$draining->get( OutboxDrainer::class ),
				$draining->get( Outbox::class ),
				static function ( string $line ) use ( &$lines ): void {
					$lines[] = $line;
				}
			);

			add_filter( 'query', $record );

			$exit = $command->run( array( 'drain' ), array() );
		} finally {
			remove_filter( 'query', $record );
			self::forgetListeners();
			$second->close();
		}

		$this->assertSame( OutboxCommand::EXIT_OK, $exit, implode( "\n", $lines ) );

		return $sent;
	}

	/**
	 * Prints the statements a drain sent to deliver one event: the projector's, listed, and the drain's own around them, counted.
	 *
	 * @since 0.2.0
	 *
	 * @param string   $event The event's class.
	 * @param string[] $sent  Every statement of the drain.
	 *
	 * @phpstan-param list<string> $sent
	 */
	private function printDelivery( string $event, array $sent ): void {
		$drains   = '/outbox|_locks\b|_logs\b|GET_LOCK|RELEASE_LOCK|IS_USED_LOCK|IS_FREE_LOCK|^(SET|START|COMMIT|ROLLBACK|SAVEPOINT|RELEASE)\b/';
		$projects = array_values( array_filter( $sent, static fn( string $sql ): bool => 1 !== preg_match( $drains, $sql ) ) );

		fwrite(
			STDOUT,
			sprintf(
				"\nOne delivery of %s for one product, drained cold in another process: %d statements, %d of them the projector's:\n  %s\n",
				substr( (string) strrchr( $event, '\\' ), 1 ),
				count( $sent ),
				count( $projects ),
				implode( "\n  ", array_map( static fn( string $sql ): string => substr( (string) preg_replace( '/\s+/', ' ', $sql ), 0, 140 ), $projects ) )
			)
		);
	}

	/**
	 * Returns the states of an event's outbox rows, in id order.
	 *
	 * @since 0.2.0
	 *
	 * @param string $eventName The event's name.
	 * @return list<string> The states.
	 */
	private function states( string $eventName ): array {
		return array_map( static fn( array $row ): string => (string) $row['state'], $this->db->fetchAll( 'SELECT state FROM %i WHERE event_name = %s ORDER BY id', $this->db->table( OutboxTable::NAME ), $eventName ) );
	}

	/**
	 * Returns a location's public identifier.
	 *
	 * @since 0.2.0
	 *
	 * @param int $locationId The location.
	 * @return string Its uuid.
	 */
	private function uuidOf( int $locationId ): string {
		return (string) $this->db->fetchValue( 'SELECT uuid FROM %i WHERE id = %d', $this->db->table( InventoryTables::LOCATIONS ), $locationId );
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

	/**
	 * Tests that stock cells set at two locations make the product `in_stock` after the drain, the summary leaving `updated_at` and the marker alone.
	 *
	 * Planted violation: in SetStockLevels, store no StockAdjusted; the drain then has nothing to
	 * project, the summary stays `out_of_stock`, and the test fails.
	 *
	 * @since 0.2.0
	 */
	public function test_cells_set_at_two_locations_make_the_product_in_stock_after_the_drain(): void {
		list( $product, $variant ) = $this->product( 'SKU-CELLS' );

		$second = $this->secondLocation();

		$this->saved( $product );
		$this->drain();

		$this->assertSame( 'out_of_stock', $this->summary( $product ) );

		$before = $this->db->fetchRow( 'SELECT generation_state, updated_at FROM %i WHERE id = %d', $this->db->table( CatalogTables::PRODUCTS ), (int) $product->id() );

		$this->kernel->get( SetStockLevels::class )->apply(
			array(
				new StockTarget( $variant, (int) $this->kernel->get( LocationDirectory::class )->default()?->id, 3, 0 ),
				new StockTarget( $variant, $second, 4, 0 ),
			),
			Actor::user( 1 )
		);
		$this->drain();

		$this->assertSame( 'in_stock', $this->summary( $product ) );
		$this->assertSame( $before, $this->db->fetchRow( 'SELECT generation_state, updated_at FROM %i WHERE id = %d', $this->db->table( CatalogTables::PRODUCTS ), (int) $product->id() ), 'The summary leaves the marker and updated_at alone.' );
		$this->assertNoListenerFailed();
	}
}
