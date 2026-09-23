<?php
/**
 * Tests the lock service in both modes against a real MySQL server and a second connection
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Database;

use PHPUnit\Framework\AssertionFailedError;
use SEOCart\Platform\Database\Exception\ForbiddenInsideTransaction;
use SEOCart\Platform\Database\Exception\LockLost;
use SEOCart\Platform\Database\Exception\LockNotAcquired;
use SEOCart\Platform\Database\Exception\QueryFailed;
use SEOCart\Platform\Database\Lease;
use SEOCart\Platform\Database\LockMode;
use SEOCart\Platform\Database\LockProbe;
use SEOCart\Platform\Database\LockService;
use SEOCart\Platform\Database\Schema\DdlGenerator;
use SEOCart\Platform\Database\Schema\PlatformTables;
use SEOCart\Platform\Database\Schema\SchemaVerifier;
use SEOCart\Platform\Database\SchemaOperations;
use SEOCart\Tests\Support\DatabaseTestCase;
use SEOCart\Tests\Support\SecondConnection;

/**
 * At most one holder, bounded waits, stale reclaim, and a
 * lease that knows when it is lost.
 *
 * Connection B plays the other runner. The table-mode sleeper is the barrier: whatever B must
 * do between two attempts, it does inside the sleeper. In GetLock mode B waits inside the
 * server, asynchronously, and the test waits until the server shows it waiting.
 *
 * Each test names its planted violation, in src/Platform/Database/LockService.php or Lease.php.
 *
 * @since 0.1.0
 *
 * @group concurrency
 */
final class LockServiceTest extends DatabaseTestCase {

	/**
	 * The lock name the tests use.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const NAME = 'report';

	/**
	 * Creates the `locks` table from its declaration.
	 *
	 * @since 0.1.0
	 */
	public function set_up(): void {
		parent::set_up();

		( new SchemaOperations( $this->db, new DdlGenerator(), new SchemaVerifier( $this->db ) ) )->createTable( PlatformTables::locks() );
	}

	/**
	 * Acquire writes the lease; a stale lease of another runner cannot release it; release frees it.
	 *
	 * Planted violation: in Lease::release(), drop `AND owner_token = %s` from the table-mode UPDATE.
	 *
	 * @since 0.1.0
	 */
	public function test_a_lease_is_recorded_and_only_its_owner_releases_it(): void {
		$b     = $this->secondConnection();
		$lease = $this->tableLocks()->acquire( self::NAME, 60, 0 );
		$row   = $this->lockRow( $b );

		$this->assertSame( $lease->token(), $row['owner_token'] );
		$this->assertSame( 64, strlen( (string) $row['owner_token'] ) );
		$this->assertSame( PHP_SAPI . ':' . getmypid(), $row['holder'] );
		$this->assertSame( '60', $b->fetchValue( sprintf( "SELECT TIMESTAMPDIFF( SECOND, acquired_at, expires_at ) FROM `%s` WHERE name = '%s'", $this->locksTable(), self::NAME ) ) );

		$stale = new Lease( $this->db, LockMode::Table, self::NAME, $this->locksTable(), str_repeat( 'f', 64 ), 60, $this->db->threadId() );
		$stale->release();

		$this->assertSame( $lease->token(), $this->lockRow( $b )['owner_token'], 'Another runner\'s release must change nothing.' );

		$lease->release();
		$lease->release();

		$this->assertNull( $this->lockRow( $b )['owner_token'], 'Release frees the lock; a second release does nothing.' );
	}

	/**
	 * A held lock is not taken; a loser polls through the sleeper and wins once the holder lets go.
	 *
	 * Planted violation: in LockService::acquireTableLock(), drop
	 * `AND ( owner_token IS NULL OR expires_at < UTC_TIMESTAMP(6) )`. Attempt 1 then "wins".
	 *
	 * @since 0.1.0
	 */
	public function test_a_held_lock_is_waited_for_then_taken(): void {
		$b = $this->secondConnection();

		$this->plantLease( $b, '+ INTERVAL 1 HOUR' );

		try {
			$this->tableLocks()->acquire( self::NAME, 60, 0 );
			$this->fail( 'A lock held by B must not be taken.' );
		} catch ( LockNotAcquired $held ) {
			$this->assertSame( self::NAME, $held->name() );
		}

		$this->assertSame( array(), $this->sleeps, 'A wait of 0 seconds never pauses.' );

		$this->onSleep = function () use ( $b ): void {
			$b->query( sprintf( "UPDATE `%s` SET owner_token = NULL WHERE name = '%s'", $this->locksTable(), self::NAME ) );
		};

		$lease = $this->tableLocks()->acquire( self::NAME, 60, 5 );

		$this->assertSame( array( LockService::POLL_MILLISECONDS ), $this->sleeps, 'One pause of 250 ms, then attempt 2 won.' );
		$this->assertSame( $lease->token(), $this->lockRow( $b )['owner_token'] );
	}

	/**
	 * An expired lease is reclaimed on the first attempt.
	 *
	 * Planted violation: in LockService::acquireTableLock(), drop `OR expires_at < UTC_TIMESTAMP(6)`.
	 *
	 * @since 0.1.0
	 */
	public function test_an_expired_lease_is_reclaimed(): void {
		$b = $this->secondConnection();

		$this->plantLease( $b, '- INTERVAL 1 SECOND' );

		$lease = $this->tableLocks()->acquire( self::NAME, 60, 0 );

		$this->assertSame( $lease->token(), $this->lockRow( $b )['owner_token'], 'The token was replaced.' );
		$this->assertSame( array(), $this->sleeps );
	}

	/**
	 * Renew() extends the lease, and throws LockLost once another runner owns the row.
	 *
	 * Planted violation: in Lease::renew(), ignore the number of affected rows in table mode.
	 *
	 * @since 0.1.0
	 */
	public function test_renew_extends_the_lease_and_notices_a_takeover(): void {
		$b      = $this->secondConnection();
		$lease  = $this->tableLocks()->acquire( self::NAME, 60, 0 );
		$before = (string) $this->lockRow( $b )['expires_at'];

		$lease->renew();

		$this->assertGreaterThan( $before, (string) $this->lockRow( $b )['expires_at'], 'renew() moved the expiry forward.' );

		$b->query( sprintf( "UPDATE `%s` SET owner_token = '%s' WHERE name = '%s'", $this->locksTable(), str_repeat( 'b', 64 ), self::NAME ) );

		$this->expectException( LockLost::class );

		$lease->renew();
	}

	/**
	 * A lease that has expired is not renewed, even while no other runner has reclaimed it yet.
	 *
	 * Planted violation: in Lease::renew(), drop `AND expires_at > UTC_TIMESTAMP(6)`.
	 *
	 * @since 0.1.0
	 */
	public function test_an_expired_lease_is_not_renewed(): void {
		$b     = $this->secondConnection();
		$lease = $this->tableLocks()->acquire( self::NAME, 60, 0 );

		$b->query( sprintf( "UPDATE `%s` SET expires_at = UTC_TIMESTAMP(6) - INTERVAL 1 SECOND WHERE name = '%s'", $this->locksTable(), self::NAME ) );

		$expired = $this->lockRow( $b )['expires_at'];

		try {
			$lease->renew();
			$this->fail( 'An expired lease must not be renewed.' );
		} catch ( LockLost $lost ) {
			$this->assertSame( LockLost::EXPIRED, $lost->context()['reason'] );
		}

		$row = $this->lockRow( $b );

		$this->assertSame( $lease->token(), $row['owner_token'], 'Nobody has reclaimed it yet.' );
		$this->assertSame( $expired, $row['expires_at'], 'The expired lease was not extended.' );
	}

	/**
	 * In GetLock mode the lock belongs to wpdb's connection, and B, waiting inside the server, gets it on release.
	 *
	 * The server itself must show B waiting on the user lock before A releases, so the test cannot
	 * pass on a B whose GET_LOCK simply had not arrived yet.
	 *
	 * Planted violation: in Lease::release(), skip the RELEASE_LOCK statement. B then stays
	 * blocked and isReady( 2000 ) fails; the pass path never waits.
	 *
	 * @since 0.1.0
	 */
	public function test_a_server_lock_passes_to_the_waiting_runner_on_release(): void {
		$b      = $this->secondConnection();
		$lease  = ( new LockService( $this->db, LockMode::GetLock ) )->acquire( self::NAME, 60, 0 );
		$server = $this->serverName();

		$this->assertSame( (string) $this->db->threadId(), $b->fetchValue( "SELECT IS_USED_LOCK( '{$server}' )" ) );

		$waiting = "SELECT GET_LOCK( '{$server}', 10 )";

		$b->queryAsync( $waiting );

		$this->awaitWaiting( $b, $waiting, 'User lock' );

		$lease->release();

		$this->assertTrue( $b->isReady( 2000 ), 'B must get the lock once it is released.' );
		$this->assertSame( '1', $b->reap() );
		$this->assertSame( (string) $b->threadId(), $b->fetchValue( "SELECT IS_USED_LOCK( '{$server}' )" ) );
	}

	/**
	 * In GetLock mode, a reconnect loses the lock, and renew() says so.
	 *
	 * Planted violation: in Lease::renew(), delete both the thread-id comparison and the
	 * IS_USED_LOCK check.
	 *
	 * @since 0.1.0
	 */
	public function test_a_reconnect_loses_a_server_lock(): void {
		global $wpdb;

		$b     = $this->secondConnection();
		$lease = ( new LockService( $this->db, LockMode::GetLock ) )->acquire( self::NAME, 60, 0 );

		$b->kill( $this->db->threadId() );
		$wpdb->check_connection();

		try {
			$lease->renew();
			$this->fail( 'The lease died with the connection; renew() must say so.' );
		} catch ( LockLost $lost ) {
			$this->assertSame( LockMode::GetLock->value, $lost->context()['mode'] );
		}

		$this->assertNull( $b->fetchValue( sprintf( "SELECT IS_USED_LOCK( '%s' )", $this->serverName() ) ), 'The server released the lock with the killed connection.' );
	}

	/**
	 * On this server the probe trusts GET_LOCK and leaves no lock behind.
	 *
	 * @since 0.1.0
	 */
	public function test_the_probe_chooses_get_lock_on_this_server(): void {
		$this->assertSame( LockMode::GetLock, LockProbe::run( $this->db, 'test' ) );

		$probe = LockService::serverLockName( $this->db->databaseName(), $this->db->prefix(), 'lock_probe_test' );

		$this->assertNull( $this->secondConnection()->fetchValue( "SELECT IS_USED_LOCK( '{$probe}' )" ) );
	}

	/**
	 * A lock taken by withLock() is released when the work throws, and no lock is taken inside a transaction.
	 *
	 * Planted violation: in LockService::withLock(), delete the release in the catch block.
	 *
	 * @since 0.1.0
	 */
	public function test_with_lock_releases_on_failure_and_refuses_a_transaction(): void {
		$b     = $this->secondConnection();
		$locks = $this->tableLocks();

		try {
			$locks->withLock(
				self::NAME,
				60,
				0,
				static function (): void {
					throw new \DomainException( 'the work failed' );
				}
			);
		} catch ( \DomainException $expected ) {
			$this->assertSame( 'the work failed', $expected->getMessage() );
		}

		$this->assertNull( $this->lockRow( $b )['owner_token'], 'The failed work\'s lease was released.' );
		$this->assertSame( 'done', $locks->withLock( self::NAME, 60, 0, static fn( Lease $lease ): string => $lease->name() === self::NAME ? 'done' : 'wrong lease' ) );

		$this->expectException( ForbiddenInsideTransaction::class );

		$this->db->transaction( fn() => $locks->acquire( self::NAME, 60, 0 ) );
	}

	/**
	 * A lease is neither renewed nor released inside a transaction, where the table row would stay invisible until COMMIT.
	 *
	 * Planted violation: in Lease::renew() and Lease::release(), delete the depth check.
	 *
	 * @since 0.1.0
	 */
	public function test_a_lease_is_neither_renewed_nor_released_inside_a_transaction(): void {
		$b     = $this->secondConnection();
		$lease = $this->tableLocks()->acquire( self::NAME, 60, 0 );

		foreach ( array(
			'renew'   => static fn() => $lease->renew(),
			'release' => static fn() => $lease->release(),
		) as $method => $call ) {
			try {
				$this->db->transaction( $call );
				$this->fail( $method . '() inside a transaction must be refused.' );
			} catch ( ForbiddenInsideTransaction $refused ) {
				$this->assertSame( ForbiddenInsideTransaction::KIND_LOCK, $refused->kind(), $method );
			}
		}

		$this->assertSame( $lease->token(), $this->lockRow( $b )['owner_token'], 'The lease is still held.' );

		$lease->release();
	}

	/**
	 * The probe does not collide with another probe in progress: its lock name is unique per run.
	 *
	 * Planted violation: in LockProbe::run(), use the fixed name `lock_probe` again.
	 *
	 * @since 0.1.0
	 */
	public function test_the_probe_does_not_collide_with_another_probe_in_progress(): void {
		$b     = $this->secondConnection();
		$fixed = LockService::serverLockName( $this->db->databaseName(), $this->db->prefix(), 'lock_probe' );

		$this->assertSame( '1', $b->fetchValue( "SELECT GET_LOCK( '{$fixed}', 0 )" ) );
		$this->assertSame( LockMode::GetLock, LockProbe::run( $this->db ) );
	}

	/**
	 * The probe releases its lock when a check after GET_LOCK fails, and still answers Table.
	 *
	 * Planted violation: in LockProbe::decide(), delete the release in the finally block.
	 *
	 * @since 0.1.0
	 */
	public function test_the_probe_releases_its_lock_when_a_later_check_fails(): void {
		$b    = $this->secondConnection();
		$name = LockService::serverLockName( $this->db->databaseName(), $this->db->prefix(), 'lock_probe_failing' );

		$mode = LockProbe::decide(
			'localhost',
			$this->db->threadId(),
			function ( string $sql ) use ( $name ): mixed {
				if ( str_starts_with( $sql, 'SELECT IS_USED_LOCK' ) ) {
					throw QueryFailed::fromErrno( 1142, '42000', 'SELECT IS_USED_LOCK', 'the holder check failed', false );
				}

				return $this->db->fetchValue( $sql, $name );
			}
		);

		$this->assertSame( LockMode::Table, $mode );
		$this->assertNull( $b->fetchValue( "SELECT IS_USED_LOCK( '{$name}' )" ), 'The probe lock is free again.' );
	}

	/**
	 * The waiting barrier refuses a B that is not blocked: it fails the test instead of passing it.
	 *
	 * Planted violation: in DatabaseTestCase::awaitWaiting(), return as soon as the process list shows B at all.
	 *
	 * @since 0.1.0
	 */
	public function test_the_waiting_barrier_refuses_a_statement_that_is_not_blocked(): void {
		$b       = $this->secondConnection();
		$free    = "SELECT GET_LOCK( '{$this->serverName()}', 10 )";
		$refused = null;

		$b->queryAsync( $free );

		try {
			$this->awaitWaiting( $b, $free, 'User lock' );
		} catch ( AssertionFailedError $failed ) {
			$refused = $failed->getMessage();
		}

		$this->assertNotNull( $refused, 'Nothing holds the lock, so B never waits, and the barrier must say so.' );
		$this->assertStringContainsString( 'it was never blocked', (string) $refused );
		$this->assertSame( '1', $b->reap() );
	}

	/**
	 * Returns a table-mode lock service with the recording sleeper.
	 *
	 * @since 0.1.0
	 *
	 * @return LockService The service.
	 */
	private function tableLocks(): LockService {
		return new LockService( $this->db, LockMode::Table, $this->sleeper() );
	}

	/**
	 * Returns the full name of the `locks` table.
	 *
	 * @since 0.1.0
	 *
	 * @return string The name.
	 */
	private function locksTable(): string {
		return $this->db->table( 'locks' );
	}

	/**
	 * Returns the server name of the test lock.
	 *
	 * @since 0.1.0
	 *
	 * @return string The GET_LOCK name.
	 */
	private function serverName(): string {
		return LockService::serverLockName( $this->db->databaseName(), $this->db->prefix(), self::NAME );
	}

	/**
	 * Reads the test lock's row as B sees it.
	 *
	 * @since 0.1.0
	 *
	 * @param SecondConnection $b Connection B.
	 * @return array<string, string|null> The row.
	 */
	private function lockRow( SecondConnection $b ): array {
		$row = $b->fetchRow( sprintf( "SELECT owner_token, acquired_at, expires_at, holder FROM `%s` WHERE name = '%s'", $this->locksTable(), self::NAME ) );

		$this->assertIsArray( $row, 'The lock row must exist.' );

		return $row;
	}

	/**
	 * Has B write a lease of its own for the test lock.
	 *
	 * @since 0.1.0
	 *
	 * @param SecondConnection $b      Connection B.
	 * @param string           $expiry The interval that places expires_at relative to now, for example '+ INTERVAL 1 HOUR'.
	 */
	private function plantLease( SecondConnection $b, string $expiry ): void {
		$b->query(
			sprintf(
				"INSERT INTO `%s` ( name, owner_token, acquired_at, expires_at, holder, created_at ) VALUES ( '%s', '%s', UTC_TIMESTAMP(6), UTC_TIMESTAMP(6) %s, 'cli:other-runner', UTC_TIMESTAMP() )",
				$this->locksTable(),
				self::NAME,
				str_repeat( 'b', 64 ),
				$expiry
			)
		);
	}
}
