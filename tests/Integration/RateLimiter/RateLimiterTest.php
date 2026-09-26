<?php
/**
 * Tests the rate limiter's two adapters against real storage: they count, restart in the next window, and never write a transient
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\RateLimiter;

use SEOCart\Platform\Database\Schema\DdlGenerator;
use SEOCart\Platform\Database\Schema\SchemaVerifier;
use SEOCart\Platform\Database\SchemaOperations;
use SEOCart\Platform\RateLimiter\ClientIdentity;
use SEOCart\Platform\RateLimiter\Migrations\CreateRateCountersMigration;
use SEOCart\Platform\RateLimiter\ObjectCacheRateLimiter;
use SEOCart\Platform\RateLimiter\RateCountersTable;
use SEOCart\Platform\RateLimiter\RateLimiter;
use SEOCart\Platform\RateLimiter\SweepRateCounters;
use SEOCart\Platform\RateLimiter\TableRateLimiter;
use SEOCart\Tests\Support\DatabaseTestCase;
use SEOCart\Tests\Support\Doubles\FrozenClock;
use SEOCart\Tests\Support\KernelContainer;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- The test moves counter rows into the past and counts option rows directly.

/**
 * The table adapter over MySQL, with every time from the database clock; the object-cache adapter
 * over WordPress's in-memory object cache, which increments like a persistent one, on a frozen clock.
 * No test mixes the two clocks.
 *
 * Planted violations, each named on its test.
 *
 * @since 0.1.0
 */
final class RateLimiterTest extends DatabaseTestCase {

	/**
	 * The window of the tests, in seconds: a day, so no window ends while a test runs but at midnight UTC.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private const WINDOW = 86400;

	/**
	 * The window of the object-cache tests, in seconds, whose clock is frozen.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private const MINUTE = 60;

	/**
	 * Creates the counter table with its migration.
	 *
	 * @since 0.1.0
	 */
	public function set_up(): void {
		parent::set_up();

		( new CreateRateCountersMigration() )->up( new SchemaOperations( $this->db, new DdlGenerator(), new SchemaVerifier( $this->db ) ) );
	}

	/**
	 * Tests that the table counts per bucket and client, peeks without counting, and starts again in the next window.
	 *
	 * @since 0.1.0
	 */
	public function test_the_table_counts_and_starts_again_in_the_next_window(): void {
		$limiter = new TableRateLimiter( $this->db );
		$client  = self::client( '192.0.2.1' );

		$this->assertSame( array( 1, 2, 3 ), array( $limiter->hit( 'cart.write', $client, self::WINDOW ), $limiter->hit( 'cart.write', $client, self::WINDOW ), $limiter->hit( 'cart.write', $client, self::WINDOW ) ) );
		$this->assertSame( 3, $limiter->peek( 'cart.write', $client, self::WINDOW ) );
		$this->assertSame( 3, $limiter->peek( 'cart.write', $client, self::WINDOW ), 'Peeking does not count.' );
		$this->assertSame( 1, $limiter->hit( 'cart.write', self::client( '192.0.2.2' ), self::WINDOW ), 'Another client has its own counter.' );
		$this->assertSame( 1, $limiter->hit( 'coupon.redeem', $client, self::WINDOW ), 'Another bucket has its own counter.' );
		$this->assertSame( 0, $limiter->peek( 'cart.write', self::client( '192.0.2.3' ), self::WINDOW ) );

		$this->moveWindowsBack( 1 );

		$this->assertSame( 0, $limiter->peek( 'cart.write', $client, self::WINDOW ), 'The counter of an ended window is not the current one.' );
		$this->assertSame( 1, $limiter->hit( 'cart.write', $client, self::WINDOW ), 'The next window starts at 1.' );
		$this->assertSame( 1, (int) $this->db->fetchValue( 'SELECT COUNT(*) FROM %i WHERE key_hash = %s AND expires_at > UTC_TIMESTAMP()', $this->table(), $client->key() ), 'One current row per bucket and client.' );
	}

	/**
	 * Tests that a hit is one statement, which also returns the count, on the database clock alone.
	 *
	 * @since 0.1.0
	 */
	public function test_a_hit_is_one_statement(): void {
		$limiter = new TableRateLimiter( $this->db );
		$client  = self::client( '192.0.2.1' );
		$log     = $this->captureQueries( static fn(): int => $limiter->hit( 'cart.write', $client, self::WINDOW ) + $limiter->hit( 'cart.write', $client, self::WINDOW ) );

		$this->assertQueryCount( 2, $log, 'Two hits, one statement each: the count is read back as the insert id' );
		$this->assertSame( 2, $limiter->peek( 'cart.write', $client, self::WINDOW ) );

		$row = $this->db->fetchRow( 'SELECT TIMESTAMPDIFF( SECOND, window_start, expires_at ) AS length, TIMESTAMPDIFF( SECOND, %s, window_start ) MOD %d AS offset, window_start <= UTC_TIMESTAMP() AND expires_at > UTC_TIMESTAMP() AS current FROM %i', '1970-01-01 00:00:00', self::WINDOW, $this->table() );

		$this->assertSame( array( (string) self::WINDOW, '0', '1' ), array( $row['length'] ?? null, $row['offset'] ?? null, $row['current'] ?? null ), 'The row is the current window, aligned to the epoch, and expires when it ends.' );
	}

	/**
	 * Tests that the sweep deletes the rows of ended windows, in batches, and keeps the current ones.
	 *
	 * Planted violation: in SweepRateCounters::DELETE_ENDED, compare `expires_at > UTC_TIMESTAMP()`.
	 * The current rows are then deleted and the ended ones kept.
	 *
	 * @since 0.1.0
	 */
	public function test_the_sweep_deletes_ended_windows_only(): void {
		$limiter = new TableRateLimiter( $this->db );

		foreach ( array( '192.0.2.1', '192.0.2.2', '192.0.2.3' ) as $address ) {
			$limiter->hit( 'cart.write', self::client( $address ), self::WINDOW );
		}

		$this->moveWindowsBack( 2 );

		$limiter->hit( 'cart.write', self::client( '192.0.2.1' ), self::WINDOW );

		( new SweepRateCounters( $this->db, 1 ) )->handle( array() );

		$this->assertSame(
			array( self::client( '192.0.2.1' )->key() ),
			array_column( $this->db->fetchAll( 'SELECT key_hash FROM %i', $this->table() ), 'key_hash' ),
			'Every ended row went, one batch at a time; the current one stayed.'
		);
	}

	/**
	 * Tests that the object cache counts per bucket and client, peeks without counting, and starts again in the next window.
	 *
	 * @since 0.1.0
	 */
	public function test_the_object_cache_counts_and_starts_again_in_the_next_window(): void {
		$clock   = new FrozenClock( new \DateTimeImmutable( '2026-09-25 10:00:30', new \DateTimeZone( 'UTC' ) ) );
		$limiter = new ObjectCacheRateLimiter( $clock, $this->noFallback() );
		$client  = self::client( '192.0.2.1' );

		$this->assertSame( array( 1, 2, 3 ), array( $limiter->hit( 'cart.write', $client, self::MINUTE ), $limiter->hit( 'cart.write', $client, self::MINUTE ), $limiter->hit( 'cart.write', $client, self::MINUTE ) ) );
		$this->assertSame( 3, $limiter->peek( 'cart.write', $client, self::MINUTE ) );
		$this->assertSame( 1, $limiter->hit( 'cart.write', self::client( '192.0.2.2' ), self::MINUTE ) );
		$this->assertSame( 1, $limiter->hit( 'coupon.redeem', $client, self::MINUTE ) );

		$clock->advance( new \DateInterval( 'PT29S' ) );

		$this->assertSame( 4, $limiter->hit( 'cart.write', $client, self::MINUTE ), 'Still the same window: it runs from 10:00:00 to 10:01:00.' );

		$clock->advance( new \DateInterval( 'PT1S' ) );

		$this->assertSame( 0, $limiter->peek( 'cart.write', $client, self::MINUTE ) );
		$this->assertSame( 1, $limiter->hit( 'cart.write', $client, self::MINUTE ), 'The next window starts at 1.' );
	}

	/**
	 * Tests that an evicted counter starts again at 1: a looser limit, never a wrong refusal.
	 *
	 * @since 0.1.0
	 */
	public function test_an_evicted_counter_starts_again(): void {
		$limiter = new ObjectCacheRateLimiter( new FrozenClock( new \DateTimeImmutable( '2026-09-25 10:00:30', new \DateTimeZone( 'UTC' ) ) ), $this->noFallback() );
		$client  = self::client( '192.0.2.1' );

		$limiter->hit( 'cart.write', $client, self::WINDOW );
		$limiter->hit( 'cart.write', $client, self::WINDOW );

		wp_cache_flush();

		$this->assertSame( 1, $limiter->hit( 'cart.write', $client, self::WINDOW ) );
	}

	/**
	 * Tests that a cache that neither adds nor increments hands the count to the counter table, for the rest of the request.
	 *
	 * WordPress's own cache refuses every add while additions are suspended, and cannot increment
	 * an entry it does not hold, so it fails the way a cache that is down does.
	 *
	 * Planted violation: in ObjectCacheRateLimiter::hit(), return 1 when the cache fails, as before.
	 * Every hit then counts 1, and the limit is never reached.
	 *
	 * @since 0.1.0
	 */
	public function test_a_failing_cache_counts_in_the_table(): void {
		$limiter = new ObjectCacheRateLimiter( new FrozenClock( new \DateTimeImmutable( '2026-09-25 10:00:30', new \DateTimeZone( 'UTC' ) ) ), fn(): RateLimiter => new TableRateLimiter( $this->db ) );
		$client  = self::client( '192.0.2.1' );

		wp_suspend_cache_addition( true );

		try {
			$counts = array( $limiter->hit( 'cart.write', $client, self::WINDOW ), $limiter->hit( 'cart.write', $client, self::WINDOW ), $limiter->hit( 'cart.write', $client, self::WINDOW ) );
		} finally {
			wp_suspend_cache_addition( false );
		}

		$this->assertSame( array( 1, 2, 3 ), $counts, 'A failing cache must not count every hit as the first.' );
		$this->assertSame( 3, ( new TableRateLimiter( $this->db ) )->peek( 'cart.write', $client, self::WINDOW ), 'The hits were counted in the table.' );
		$this->assertSame( 3, $limiter->peek( 'cart.write', $client, self::WINDOW ), 'Once the cache failed, the request peeks at the table too.' );
	}

	/**
	 * Tests that neither adapter ever writes a transient.
	 *
	 * Planted violation: in ObjectCacheRateLimiter::hit(), keep the count with
	 * `set_transient( 'seocart_rate_' . md5( $key ), (int) $count, $ttl );` after the increment.
	 *
	 * @since 0.1.0
	 */
	public function test_no_transient_is_ever_written(): void {
		global $wpdb;

		$written = array();

		foreach ( array( 'setted_transient', 'setted_site_transient' ) as $hook ) {
			add_action(
				$hook,
				static function ( $name ) use ( &$written ): void {
					$written[] = $name;
				}
			);
		}

		$transients = static fn(): int => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE option_name LIKE %s OR option_name LIKE %s', $wpdb->options, '\_transient\_%', '\_site\_transient\_%' ) );
		$before     = $transients();

		foreach ( array( new TableRateLimiter( $this->db ), new ObjectCacheRateLimiter( new FrozenClock( new \DateTimeImmutable( '2026-09-25 10:00:00' ) ), $this->noFallback() ) ) as $limiter ) {
			for ( $i = 0; $i < 5; $i++ ) {
				$limiter->hit( 'cart.write', self::client( '192.0.2.' . $i ), self::WINDOW );
				$limiter->peek( 'cart.write', self::client( '192.0.2.' . $i ), self::WINDOW );
			}
		}

		$this->assertSame( array(), $written, 'A transient was set.' );
		$this->assertSame( $before, $transients(), 'An options row of a transient was written.' );
	}

	/**
	 * Tests that the kernel counts in the object cache exactly when a persistent one is in use.
	 *
	 * @since 0.1.0
	 */
	public function test_the_kernel_counts_in_the_object_cache_only_when_it_is_persistent(): void {
		$was = wp_using_ext_object_cache();

		try {
			wp_using_ext_object_cache( true );
			$this->assertInstanceOf( ObjectCacheRateLimiter::class, KernelContainer::build( $this->db, $this->reporter() )->get( RateLimiter::class ) );

			wp_using_ext_object_cache( false );
			$this->assertInstanceOf( TableRateLimiter::class, KernelContainer::build( $this->db, $this->reporter() )->get( RateLimiter::class ) );
		} finally {
			wp_using_ext_object_cache( $was );
		}
	}

	/**
	 * Returns a fallback for an object-cache test whose cache never fails.
	 *
	 * @since 0.1.0
	 *
	 * @return \Closure(): RateLimiter A fallback that fails the test if it is ever asked for.
	 */
	private function noFallback(): \Closure {
		return static function (): RateLimiter {
			throw new \LogicException( 'The in-memory object cache failed to count, so the adapter fell back to the table.' );
		};
	}

	/**
	 * Moves every counter row back by a number of windows, on the database clock.
	 *
	 * @since 0.1.0
	 *
	 * @param int $windows How many windows.
	 */
	private function moveWindowsBack( int $windows ): void {
		$this->db->execute(
			'UPDATE %i SET window_start = window_start - INTERVAL %d SECOND, expires_at = expires_at - INTERVAL %d SECOND',
			$this->table(),
			$windows * self::WINDOW,
			$windows * self::WINDOW
		);
	}

	/**
	 * Returns the counter table's full name.
	 *
	 * @since 0.1.0
	 *
	 * @return string The name.
	 */
	private function table(): string {
		return $this->db->table( RateCountersTable::NAME );
	}

	/**
	 * Returns a guest client without a cart token, at an address.
	 *
	 * @since 0.1.0
	 *
	 * @param string $address The address.
	 * @return ClientIdentity The identity.
	 */
	private static function client( string $address ): ClientIdentity {
		return ClientIdentity::ofClient( $address, 0, 'test secret' );
	}
}
