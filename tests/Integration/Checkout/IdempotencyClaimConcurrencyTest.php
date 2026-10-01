<?php
/**
 * Tests the claim of an idempotency key under two real connections
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Checkout;

use SEOCart\Checkout\Domain\CheckoutError;
use SEOCart\Checkout\Domain\IdempotencyClaim;
use SEOCart\Checkout\Infrastructure\CheckoutTables;
use SEOCart\Checkout\Infrastructure\MysqlIdempotencyKeys;
use SEOCart\Platform\Database\RetryPolicy;
use SEOCart\Support\Error\CodedException;
use SEOCart\Tests\Support\Checkout\CheckoutTestCase;
use SEOCart\Tests\Support\SecondConnection;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- One race sets the isolation level of wpdb's own connection, which no plugin statement does yet.

/**
 * A claim racing another claim of the same key: a new key's second claim waits on the key's unique index for the first one's transaction, then answers from what it committed; an expired key's second claim waits too, or, at READ COMMITTED, deadlocks and is retried.
 *
 * A is MysqlIdempotencyKeys over wpdb, in a transaction the test holds open. B is connection B
 * sending the repository's own statements, built from its constants. B's statement is sent
 * asynchronously once A has claimed, and the server is asked to show it waiting; only then does A
 * commit or roll back. Barriers, never pauses.
 *
 * Planted violations, each named on its test.
 *
 * @since 0.1.0
 *
 * @group concurrency
 */
final class IdempotencyClaimConcurrencyTest extends CheckoutTestCase {

	/**
	 * The answer A sends.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const RESPONSE = '{"status":201,"body":{"order_uuid":"00000000-0000-4000-8000-000000000001","access_key":"k3y"}}';

	/**
	 * Tests that a claim waits for an uncommitted claim of its key, and answers with A's answer once A commits the key placed.
	 *
	 * Planted violation (must be shown red): drop the unique key `scope_key` from
	 * CheckoutTables::idempotencyKeys(). B's INSERT then goes in at once, beside A's row: B never
	 * waits, and both requests own the key.
	 *
	 * @since 0.1.0
	 */
	public function test_a_claim_waits_for_an_uncommitted_claim_and_replays_its_answer_once_it_commits(): void {
		$hash   = self::keyHash( 'attempt-1' );
		$b      = $this->secondConnection();
		$insert = $this->raw( MysqlIdempotencyKeys::CLAIM, $this->keyTable(), self::SCOPE, $hash, self::digest( 'request 1' ), self::KEY_TTL );

		$this->db->transaction(
			function () use ( $hash, $b, $insert ): void {
				$claim = $this->keys->claim( self::SCOPE, $hash, self::digest( 'request 1' ), self::KEY_TTL );

				$this->assertTrue( $claim->owned );

				$b->query( 'START TRANSACTION' );
				$b->queryAsync( $insert );
				$this->awaitWaiting( $b, $insert, 'update' );

				$this->keys->complete( $claim->id, 42, self::RESPONSE );
			}
		);

		$this->assertSame( 1062, $this->reapError( $b ), 'B\'s claim did not fail as a duplicate once A committed the key.' );

		$taken = $b->fetchRow( $this->raw( MysqlIdempotencyKeys::TAKEN, $this->keyTable(), self::SCOPE, $hash ) );

		$this->assertSame( array( 'placed', '42', self::RESPONSE, '0' ), array( $taken['state'] ?? null, $taken['order_id'] ?? null, $taken['response_json'] ?? null, $taken['expired'] ?? null ), 'B did not read the key A placed.' );

		$b->query( 'ROLLBACK' );

		$replay = $this->claimCommitted( $hash, self::digest( 'request 1' ) );

		$this->assertFalse( $replay->owned );
		$this->assertSame( array( 42, self::RESPONSE ), array( $replay->orderId, $replay->responseJson ) );
		$this->assertSame( 1, $this->checkoutRows( CheckoutTables::IDEMPOTENCY_KEYS ) );
	}

	/**
	 * Tests that a claim waits for an uncommitted claim of its key, and owns the key once A rolls back.
	 *
	 * Planted violation: drop the unique key `scope_key` from CheckoutTables::idempotencyKeys():
	 * B's INSERT then never waits for A, so nothing orders B's claim after A's rollback.
	 *
	 * @since 0.1.0
	 */
	public function test_a_claim_waits_for_an_uncommitted_claim_and_owns_the_key_once_it_rolls_back(): void {
		$hash     = self::keyHash( 'attempt-1' );
		$b        = $this->secondConnection();
		$insert   = $this->raw( MysqlIdempotencyKeys::CLAIM, $this->keyTable(), self::SCOPE, $hash, self::digest( 'request 2' ), self::KEY_TTL );
		$rollBack = new \RuntimeException( 'A fails after its claim, as a placement refused by its stock would.' );

		try {
			$this->db->transaction(
				function () use ( $hash, $b, $insert, $rollBack ): void {
					$this->assertTrue( $this->keys->claim( self::SCOPE, $hash, self::digest( 'request 1' ), self::KEY_TTL )->owned );

					$b->query( 'START TRANSACTION' );
					$b->queryAsync( $insert );
					$this->awaitWaiting( $b, $insert, 'update' );

					throw $rollBack;
				}
			);
		} catch ( \RuntimeException $thrown ) {
			$this->assertSame( $rollBack, $thrown );
		}

		$this->assertSame( 1, $b->reap(), 'B did not get the key A rolled back.' );

		$b->query( 'COMMIT' );

		$row = $this->committedKey( $b, $hash );

		$this->assertSame( array( 'claimed', self::digest( 'request 2' ) ), array( $row['state'] ?? null, $row['request_fingerprint'] ?? null ), 'The key is not B\'s.' );

		try {
			$this->claimCommitted( $hash, self::digest( 'request 1' ) );
			$this->fail( 'A\'s retry owned the key B holds.' );
		} catch ( CodedException $held ) {
			$this->assertSame( CheckoutError::PlacementInProgress, $held->errorCode() );
		}
	}

	/**
	 * Tests that a claim of an expired key that another request is claiming waits for it, then finds the key live: one owner, at the test database's isolation level.
	 *
	 * Both requests send the claim's real sequence on the expired key: the INSERT, which fails as
	 * a duplicate, the locking read, and the conditional UPDATE. At REPEATABLE READ, the level the
	 * test database runs, A's failed INSERT keeps a lock on the key that B's INSERT must wait for:
	 * just before A's UPDATE, B's INSERT is shown waiting. A claims the key again and commits. B's
	 * INSERT then fails as a duplicate, B's locking read finds the key live and claimed by A, and
	 * B's claim, as the plugin answers it, is "placement in progress". Nothing deadlocks and
	 * nothing retries.
	 *
	 * Planted violation: in MysqlIdempotencyKeys::answerTaken(), treat every taken key as expired,
	 * and drop `AND expires_at <= UTC_TIMESTAMP()` from RECLAIM_EXPIRED: B's claim then claims the
	 * key A has just claimed, and both own it.
	 *
	 * @since 0.1.0
	 */
	public function test_a_claim_of_an_expired_key_waits_for_another_claim_and_finds_it_live(): void {
		$hash   = self::keyHash( 'attempt-1' );
		$old    = $this->placedKey( $hash );
		$b      = $this->secondConnection();
		$insert = $this->raw( MysqlIdempotencyKeys::CLAIM, $this->keyTable(), self::SCOPE, $hash, self::digest( 'request 3' ), self::KEY_TTL );

		$this->keyExpiresIn( $old->id, -1 );

		$raced = $this->beforeStatement(
			self::shapeOf( MysqlIdempotencyKeys::RECLAIM_EXPIRED ),
			function () use ( $b, $insert ): void {
				$b->query( 'START TRANSACTION' );
				$b->queryAsync( $insert );
				$this->awaitWaiting( $b, $insert, 'update' );
			}
		);

		$owner = $this->db->transaction( fn(): IdempotencyClaim => $this->keys->claim( self::SCOPE, $hash, self::digest( 'request 2' ), self::KEY_TTL ), RetryPolicy::deadlocks() );

		$this->assertTrue( $raced->fired, 'B never raced A.' );
		$this->assertSame( array( true, $old->id ), array( $owner->owned, $owner->id ), 'A did not claim the expired key again.' );
		$this->assertSame( 1062, $this->reapError( $b ), 'B\'s INSERT did not fail as a duplicate once A committed.' );

		$read = (array) $b->fetchRow( $this->raw( MysqlIdempotencyKeys::TAKEN, $this->keyTable(), self::SCOPE, $hash ) );

		$this->assertSame( array( 'claimed', '0', self::digest( 'request 2' ) ), array( $read['state'] ?? null, $read['expired'] ?? null, $read['request_fingerprint'] ?? null ), 'B did not read the key live and claimed by A.' );

		$b->query( 'ROLLBACK' );

		try {
			$this->claimCommitted( $hash, self::digest( 'request 3' ) );
			$this->fail( 'B owned the key A claimed: two owners.' );
		} catch ( CodedException $held ) {
			$this->assertSame( CheckoutError::PlacementInProgress, $held->errorCode() );
		}

		$this->assertSame( array(), $this->sleeps, 'A was retried, so something deadlocked.' );
		$this->assertSame( self::digest( 'request 2' ), $this->committedKey( $b, $hash )['request_fingerprint'] ?? null );
	}

	/**
	 * Tests that two claims of one expired key at READ COMMITTED deadlock on their re-claims, and the one rolled back is retried and finds the key live: one owner.
	 *
	 * At READ COMMITTED, the level the order placement runs at, a failed INSERT keeps no lock
	 * that a second INSERT waits for. Both requests then read the key expired, each leaving the
	 * row shared, and both send the conditional UPDATE. B goes first up to its UPDATE, which waits
	 * for A's shared lock; A's UPDATE closes the cycle. InnoDB rolls back the lighter transaction,
	 * A (B has changed three fixture rows), with a deadlock, which the transaction manager
	 * classifies as retryable. While A pauses before its second attempt, B's UPDATE goes through
	 * and B commits; A's second attempt reads the key live and claimed by B, and answers "placement
	 * in progress". So a claim runs only in a unit of work that retries on a deadlock.
	 *
	 * Planted violation: in MysqlIdempotencyKeys::TAKEN, drop `FOR SHARE`: A's read then leaves no
	 * lock on the row, nothing orders B's re-claim after A's read, and B's UPDATE goes through
	 * without waiting.
	 *
	 * @since 0.1.0
	 */
	public function test_two_claims_of_an_expired_key_at_read_committed_deadlock_and_one_retries(): void {
		global $wpdb;

		$hash = self::keyHash( 'attempt-1' );
		$old  = $this->placedKey( $hash );

		$this->keyExpiresIn( $old->id, -1 );

		foreach ( array( 1, 2, 3 ) as $id ) {
			$this->insertRow( $id, 'seed' );
		}

		$b       = $this->secondConnection();
		$table   = $this->keyTable();
		$reclaim = $this->raw( MysqlIdempotencyKeys::RECLAIM_EXPIRED, $table, self::digest( 'request 3' ), self::KEY_TTL, $old->id );
		$read    = array();

		$b->query( 'SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED' );
		$b->query( 'START TRANSACTION' );
		$b->query( sprintf( "UPDATE `%s` SET value = 'b' WHERE id IN (1, 2, 3)", $this->rowsTable() ) );

		$raced = $this->beforeStatement(
			self::shapeOf( MysqlIdempotencyKeys::RECLAIM_EXPIRED ),
			function () use ( $b, $table, $hash, $reclaim, &$read ): void {
				$this->assertSame( 1062, $this->queryError( $b, $this->raw( MysqlIdempotencyKeys::CLAIM, $table, self::SCOPE, $hash, self::digest( 'request 3' ), self::KEY_TTL ) ), 'B\'s INSERT did not fail as a duplicate of the expired key.' );

				$read = (array) $b->fetchRow( $this->raw( MysqlIdempotencyKeys::TAKEN, $table, self::SCOPE, $hash ) );

				$b->queryAsync( $reclaim );
				$this->awaitWaiting( $b, $reclaim, 'updating' );
			}
		);

		$this->onSleep = function () use ( $b ): void {
			$this->assertTrue( $b->isReady( 5000 ), 'B\'s re-claim did not go through once A was rolled back.' );
			$this->assertSame( 1, $b->reap(), 'B\'s re-claim did not claim the expired key.' );

			$b->query( 'COMMIT' );
		};

		$wpdb->query( 'SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED' );

		try {
			$this->db->transaction( fn(): IdempotencyClaim => $this->keys->claim( self::SCOPE, $hash, self::digest( 'request 2' ), self::KEY_TTL ), RetryPolicy::deadlocks() );
			$this->fail( 'A owned the key B claimed: two owners.' );
		} catch ( CodedException $held ) {
			$this->assertSame( CheckoutError::PlacementInProgress, $held->errorCode(), 'A\'s second attempt did not find the key live.' );
		} finally {
			$wpdb->query( 'SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ' );
		}

		$this->assertTrue( $raced->fired, 'B never raced A.' );
		$this->assertSame( '1', (string) ( $read['expired'] ?? '' ), 'B did not read the key expired, so the two never raced for it.' );
		$this->assertCount( 1, $this->sleeps, 'A was not rolled back by a deadlock and retried exactly once.' );
		$this->assertSame( self::digest( 'request 3' ), $this->committedKey( $b, $hash )['request_fingerprint'] ?? null, 'The key is not B\'s.' );
		$this->assertSame( 1, $this->checkoutRows( CheckoutTables::IDEMPOTENCY_KEYS ) );
	}

	/**
	 * Plants a placed key, committed.
	 *
	 * @since 0.1.0
	 *
	 * @param string $hash The key's hash.
	 * @return IdempotencyClaim The claim the key was placed with.
	 */
	private function placedKey( string $hash ): IdempotencyClaim {
		return $this->db->transaction(
			function () use ( $hash ): IdempotencyClaim {
				$claim = $this->keys->claim( self::SCOPE, $hash, self::digest( 'request 1' ), self::KEY_TTL );

				$this->keys->complete( $claim->id, 42, self::RESPONSE );

				return $claim;
			}
		);
	}

	/**
	 * Sends a statement over connection B that must fail, and returns its MySQL error number.
	 *
	 * @since 0.1.0
	 *
	 * @param SecondConnection $b   Connection B.
	 * @param string           $sql The statement.
	 * @return int The error number.
	 */
	private function queryError( SecondConnection $b, string $sql ): int {
		try {
			$b->query( $sql );
		} catch ( \RuntimeException $failed ) {
			return (int) $failed->getCode();
		}

		$this->fail( 'B\'s statement succeeded.' );
	}

	/**
	 * Collects B's asynchronous statement, which must have failed, and returns its MySQL error number.
	 *
	 * @since 0.1.0
	 *
	 * @param SecondConnection $b Connection B.
	 * @return int The error number.
	 */
	private function reapError( SecondConnection $b ): int {
		try {
			$b->reap();
		} catch ( \RuntimeException $failed ) {
			return (int) $failed->getCode();
		}

		$this->fail( 'B\'s statement succeeded.' );
	}

	/**
	 * Returns the key table's full name.
	 *
	 * @since 0.1.0
	 *
	 * @return string The name.
	 */
	private function keyTable(): string {
		return $this->table( CheckoutTables::IDEMPOTENCY_KEYS );
	}
}
