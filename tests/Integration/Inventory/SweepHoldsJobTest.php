<?php
/**
 * Tests that the sweep of expired holds is one of the plugin's recurring jobs, scheduled every five minutes and run by the queue
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Inventory;

use SEOCart\Inventory\Application\StockService;
use SEOCart\Inventory\Infrastructure\InventoryTables;
use SEOCart\Inventory\Infrastructure\Jobs\SweepHolds;
use SEOCart\Inventory\Infrastructure\Migrations\CreateStockTablesMigration;
use SEOCart\Inventory\Infrastructure\MysqlStockRepository;
use SEOCart\Platform\Authorization\Authorizer;
use SEOCart\Platform\Authorization\CapabilityDeclaration;
use SEOCart\Platform\Database\Schema\DdlGenerator;
use SEOCart\Platform\Database\Schema\SchemaVerifier;
use SEOCart\Platform\Database\SchemaOperations;
use SEOCart\Platform\Events\EventCatalog;
use SEOCart\Platform\Events\HookBridge;
use SEOCart\Platform\Events\Publisher;
use SEOCart\Platform\Jobs\JobHandler;
use SEOCart\Platform\Jobs\JobHandlers;
use SEOCart\Platform\Kernel\Modules;
use SEOCart\Tests\Support\Doubles\FrozenClock;
use SEOCart\Tests\Support\Doubles\RecordingWake;
use SEOCart\Tests\Support\Doubles\SequentialIdGenerator;
use SEOCart\Tests\Support\Jobs\JobsTestCase;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- The test plants a stock item and an expired hold directly.

/**
 * SweepHolds through the real queue and runner: it is in the production list, it recurs every
 * 300 seconds with one attempt per run, the queue schedules it, and a due run reclaims an
 * expired hold.
 *
 * Planted violation: remove SweepHolds from JobHandlers::PRODUCTION. This test's first
 * assertion fails, and so do the production list's own tests.
 *
 * @since 0.1.0
 */
final class SweepHoldsJobTest extends JobsTestCase {

	/**
	 * Creates the stock tables beside the queue's.
	 *
	 * @since 0.1.0
	 */
	public function set_up(): void {
		parent::set_up();

		( new CreateStockTablesMigration() )->up( new SchemaOperations( $this->db, new DdlGenerator(), new SchemaVerifier( $this->db ) ) );
	}

	/**
	 * Tests that the sweep is a production handler recurring every five minutes, with one attempt per run.
	 *
	 * @since 0.1.0
	 */
	public function test_the_sweep_is_a_production_job_recurring_every_five_minutes(): void {
		$this->assertContains( SweepHolds::class, JobHandlers::PRODUCTION );
		$this->assertSame( 'stock.sweep_holds', SweepHolds::name() );
		$this->assertSame( 300, SweepHolds::recurrence() );
		$this->assertSame( 1, SweepHolds::maxAttempts() );
	}

	/**
	 * Tests that the queue schedules the sweep, and that a due run reclaims an expired hold.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException When the queue asks for a handler other than the sweep, which it must not.
	 */
	public function test_the_queue_schedules_the_sweep_and_a_due_run_reclaims(): void {
		$service = new StockService(
			new MysqlStockRepository( $this->db ),
			$this->db,
			new Publisher( $this->db, $this->outbox, new HookBridge( $this->reporter() ), new EventCatalog( Modules::EVENT_CLASSES ), $this->correlation, new RecordingWake() ),
			new SequentialIdGenerator( 1 ),
			FrozenClock::at( '2026-09-24 12:00:00' ),
			$this->correlation,
			new Authorizer( new CapabilityDeclaration() )
		);

		$this->wire( array( SweepHolds::class ), static fn( string $handlerClass ): JobHandler => SweepHolds::class === $handlerClass ? new SweepHolds( $service ) : throw new \LogicException( $handlerClass . ' is not the sweep.' ) );

		$this->assertSame( array( SweepHolds::name() ), $this->queue->ensureRecurring() );

		$actions = $this->actions();

		$this->assertSame( array( '[{"h":"stock.sweep_holds","r":300}]' ), array_column( $actions, 'args' ) );

		$variant = 4242;

		$this->db->execute( 'INSERT INTO %i ( variant_id, on_hand, held, updated_at ) VALUES ( %d, 1, 1, UTC_TIMESTAMP(6) )', $this->db->table( InventoryTables::ITEMS ), $variant );
		$this->db->execute( "INSERT INTO %i ( variant_id, hold_group, quantity, expires_at, created_at ) VALUES ( %d, '00000000-0000-7000-8000-000000000001', 1, UTC_TIMESTAMP() - INTERVAL 60 SECOND, UTC_TIMESTAMP(6) )", $this->db->table( InventoryTables::HOLDS ), $variant );

		$this->makeDue( $actions[0]['id'] );
		$this->runner->runDue( 30, 'test' );

		$this->assertSame( '0', $this->db->fetchValue( 'SELECT held FROM %i WHERE variant_id = %d', $this->db->table( InventoryTables::ITEMS ), $variant ) );
		$this->assertSame( '0', $this->db->fetchValue( 'SELECT COUNT(*) FROM %i WHERE variant_id = %d', $this->db->table( InventoryTables::HOLDS ), $variant ) );
	}
}
