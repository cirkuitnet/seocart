<?php
/**
 * Tests that the sweep of expired carts is one of the plugin's recurring jobs, scheduled every hour and run by the queue
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Cart;

use SEOCart\Cart\Infrastructure\CartTables;
use SEOCart\Cart\Infrastructure\Jobs\SweepExpiredCarts;
use SEOCart\Cart\Infrastructure\Migrations\CreateCartTables;
use SEOCart\Cart\Infrastructure\MysqlCartRepository;
use SEOCart\Platform\Database\Schema\DdlGenerator;
use SEOCart\Platform\Database\Schema\SchemaVerifier;
use SEOCart\Platform\Database\SchemaOperations;
use SEOCart\Platform\Jobs\JobHandler;
use SEOCart\Platform\Jobs\JobHandlers;
use SEOCart\Tests\Support\Jobs\JobsTestCase;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- The test plants an expired cart and its line directly.

/**
 * SweepExpiredCarts through the real queue and runner: it is in the production list, it recurs
 * every 3,600 seconds with one attempt per run, the queue schedules it, and a due run deletes an
 * expired cart and its line.
 *
 * Planted violation: remove SweepExpiredCarts from JobHandlers::PRODUCTION. This test's first
 * assertion fails, and so do the production list's own tests.
 *
 * @since 0.1.0
 */
final class SweepExpiredCartsJobTest extends JobsTestCase {

	/**
	 * Creates the cart tables beside the queue's.
	 *
	 * @since 0.1.0
	 */
	public function set_up(): void {
		parent::set_up();

		( new CreateCartTables() )->up( new SchemaOperations( $this->db, new DdlGenerator(), new SchemaVerifier( $this->db ) ) );
	}

	/**
	 * Tests that the sweep is a production handler recurring every hour, with one attempt per run.
	 *
	 * @since 0.1.0
	 */
	public function test_the_sweep_is_a_production_job_recurring_every_hour(): void {
		$this->assertContains( SweepExpiredCarts::class, JobHandlers::PRODUCTION );
		$this->assertSame( 'carts.sweep_expired', SweepExpiredCarts::name() );
		$this->assertSame( 3600, SweepExpiredCarts::recurrence() );
		$this->assertSame( 1, SweepExpiredCarts::maxAttempts() );
	}

	/**
	 * Tests that the queue schedules the sweep, and that a due run deletes an expired cart and its line.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException When the queue asks for a handler other than the sweep, which it must not.
	 */
	public function test_the_queue_schedules_the_sweep_and_a_due_run_deletes(): void {
		$sweep = new SweepExpiredCarts( new MysqlCartRepository( $this->db ) );

		$this->wire( array( SweepExpiredCarts::class ), static fn( string $handlerClass ): JobHandler => SweepExpiredCarts::class === $handlerClass ? $sweep : throw new \LogicException( $handlerClass . ' is not the sweep.' ) );

		$this->assertSame( array( SweepExpiredCarts::name() ), $this->queue->ensureRecurring() );

		$actions = $this->actions();

		$this->assertSame( array( '[{"h":"carts.sweep_expired","r":3600}]' ), array_column( $actions, 'args' ) );

		$carts = $this->db->table( CartTables::CARTS );
		$lines = $this->db->table( CartTables::LINES );

		$this->db->execute( "INSERT INTO %i ( token_hash, currency, locale, promotion_codes, expires_at, created_at, updated_at ) VALUES ( %s, 'USD', 'en_US', '[]', UTC_TIMESTAMP() - INTERVAL 60 SECOND, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6) )", $carts, hash( 'sha256', 'an expired cart' ) );
		$this->db->execute( 'INSERT INTO %i ( cart_id, line_identity, variant_id, quantity, created_at, updated_at ) VALUES ( %d, %s, 7, 1, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6) )', $lines, $this->db->lastInsertId(), hash( 'sha256', 'variant:7' ) );

		$this->makeDue( $actions[0]['id'] );
		$this->runner->runDue( 30, 'test' );

		$this->assertSame( '0', $this->db->fetchValue( 'SELECT COUNT(*) FROM %i', $carts ) );
		$this->assertSame( '0', $this->db->fetchValue( 'SELECT COUNT(*) FROM %i', $lines ) );
	}
}
