<?php
/**
 * Tests the retention job of the idempotency keys
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Checkout;

use SEOCart\Checkout\Infrastructure\CheckoutTables;
use SEOCart\Checkout\Infrastructure\Jobs\IdempotencyKeyRetention;
use SEOCart\Platform\DataRegistry\RetentionCatalog;
use SEOCart\Platform\Logging\LogRetention;
use SEOCart\Tests\Support\Checkout\CheckoutTestCase;

/**
 * The keys' retention: an expired key is deleted, a live one never, however long ago it was claimed; a backlog is deleted in batches.
 *
 * Expiry is set and judged by the database clock.
 *
 * Planted violations, each named on its test.
 *
 * @since 0.1.0
 */
final class IdempotencyKeyRetentionTest extends CheckoutTestCase {

	/**
	 * Tests that the job deletes exactly the expired keys: a live key claimed long ago stays, and so does one that expires in a minute.
	 *
	 * Planted violation: in MysqlIdempotencyKeys::DELETE_EXPIRED, sweep by age instead
	 * (`WHERE created_at <= UTC_TIMESTAMP() - INTERVAL 30 DAY ORDER BY created_at`): a key claimed
	 * 40 days ago that still has 20 to live is then deleted.
	 *
	 * @since 0.1.0
	 */
	public function test_the_job_deletes_the_expired_keys_and_keeps_every_live_one(): void {
		$expired = $this->plantKey( 'expired a second ago', -1 );
		$long    = $this->plantKey( 'expired two months ago', -60 * 86400 );
		$minute  = $this->plantKey( 'expires in a minute', 60 );
		$old     = $this->plantKey( 'claimed 40 days ago, with 20 left', 20 * 86400 );

		$this->keyClaimedAgo( $old, 40 * 86400 );

		( new IdempotencyKeyRetention( $this->keys ) )->handle( array() );

		$this->assertSame( array( $minute, $old ), $this->remaining(), 'The job deleted a live key, or kept an expired one.' );
		$this->assertNotContains( $expired, $this->remaining() );
		$this->assertNotContains( $long, $this->remaining() );
	}

	/**
	 * Tests that a backlog is deleted in batches until a batch comes back short, all in one run.
	 *
	 * @since 0.1.0
	 */
	public function test_a_backlog_is_deleted_in_batches_in_one_run(): void {
		for ( $key = 0; $key < 5; $key++ ) {
			$this->plantKey( 'expired ' . $key, -1 - $key );
		}

		$live = $this->plantKey( 'live', 3600 );
		$log  = $this->captureQueries( fn() => ( new IdempotencyKeyRetention( $this->keys, 2 ) )->handle( array() ) );

		$this->assertSame( array( $live ), $this->remaining() );
		$this->assertQueryCount( 3, $log->ofType( 'DELETE' ), 'Five expired keys in batches of two' );
	}

	/**
	 * Tests the job's schedule, and that a key lives the period of the keys' retention policy.
	 *
	 * @since 0.1.0
	 */
	public function test_the_job_runs_hourly_and_keys_live_their_retention_period(): void {
		$this->assertSame( array( 'idempotency_keys.prune', 3600, 1 ), array( IdempotencyKeyRetention::name(), IdempotencyKeyRetention::recurrence(), IdempotencyKeyRetention::maxAttempts() ) );
		$this->assertSame( self::KEY_TTL, LogRetention::seconds( ( new RetentionCatalog() )->defaults( CheckoutTables::KEYS_RETENTION )['all'] ) );
		$this->assertSame( CheckoutTables::KEYS_RETENTION, CheckoutTables::idempotencyKeys()->retention() );
	}

	/**
	 * Plants a placed key whose expiry is a number of seconds from now, by the database clock.
	 *
	 * @since 0.1.0
	 *
	 * @param string $key     The key.
	 * @param int    $seconds How long it has left; below 0, how long ago it expired.
	 * @return int The key's row.
	 */
	private function plantKey( string $key, int $seconds ): int {
		$hash  = self::keyHash( $key );
		$claim = $this->db->transaction(
			function () use ( $hash ) {
				$claim = $this->keys->claim( self::SCOPE, $hash, self::digest( 'request' ), self::KEY_TTL );

				$this->keys->complete( $claim->id, 42, '{"status":201}' );

				return $claim;
			}
		);

		$this->keyExpiresIn( $claim->id, $seconds );

		return $claim->id;
	}

	/**
	 * Lists the keys left, by row.
	 *
	 * @since 0.1.0
	 *
	 * @return list<int> The rows, in order.
	 */
	private function remaining(): array {
		return array_map( 'intval', array_column( $this->db->fetchAll( 'SELECT id FROM %i ORDER BY id', $this->table( CheckoutTables::IDEMPOTENCY_KEYS ) ), 'id' ) );
	}
}
