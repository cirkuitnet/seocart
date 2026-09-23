<?php
/**
 * LockService: named, bounded-wait locks that at most one runner holds at a time
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Database;

use SEOCart\Platform\Database\Exception\DatabaseException;
use SEOCart\Platform\Database\Exception\ForbiddenInsideTransaction;
use SEOCart\Platform\Database\Exception\LockNotAcquired;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages go to logs and the command line, never into HTML; the REST layer answers with the translated message of the error code.

/**
 * Takes a named lock for one runner, waits a bounded time for it, and hands out a Lease.
 *
 * Owns one fact: how a lock is taken in each LockMode.
 *
 * - GetLock: `GET_LOCK( name, wait )`. The server does the waiting, so PHP never sleeps. The
 *   server lock name is namespaced by database and table prefix, so each site of a network
 *   has its own locks.
 * - Table: one conditional UPDATE of the `locks` row whose WHERE clause carries the invariant
 *   "free or expired". One affected row means the lock is held. Losers try again every
 *   POLL_MILLISECONDS through the injected sleeper until the wait is spent. Times come from
 *   the database's UTC_TIMESTAMP(), the one clock every web node shares.
 *
 * A loser never proceeds: it gets LockNotAcquired. A lock is never taken inside a transaction,
 * where the table-mode row would stay invisible to other runners until COMMIT.
 *
 * @since 0.1.0
 */
final class LockService {

	/**
	 * How long a table-mode loser pauses between attempts, in milliseconds.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const POLL_MILLISECONDS = 250;

	/**
	 * The longest lock name MySQL accepts for GET_LOCK().
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private const SERVER_NAME_LIMIT = 64;

	/**
	 * How many hexadecimal digits of a hash replace the end of a name that is too long.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private const HASH_DIGITS = 16;

	/**
	 * The connection.
	 *
	 * @since 0.1.0
	 *
	 * @var Database
	 */
	private Database $db;

	/**
	 * How locks are held on this host.
	 *
	 * @since 0.1.0
	 *
	 * @var LockMode
	 */
	private LockMode $mode;

	/**
	 * Pauses for a number of milliseconds between table-mode attempts.
	 *
	 * @since 0.1.0
	 *
	 * @var callable(int): void
	 */
	private $sleep;

	/**
	 * Creates the service. Sends nothing.
	 *
	 * @since 0.1.0
	 *
	 * @param Database                   $db    The connection.
	 * @param LockMode                   $mode  How locks are held on this host, as LockProbe decided.
	 * @param (callable(int): void)|null $sleep Optional. Pauses for a number of milliseconds.
	 *                                          Default null, which uses usleep().
	 */
	public function __construct( Database $db, LockMode $mode, ?callable $sleep = null ) {
		$this->db    = $db;
		$this->mode  = $mode;
		$this->sleep = $sleep ?? static function ( int $milliseconds ): void {
			usleep( $milliseconds * 1000 );
		};
	}

	/**
	 * Returns how locks are held on this host.
	 *
	 * @since 0.1.0
	 *
	 * @return LockMode The mode.
	 */
	public function mode(): LockMode {
		return $this->mode;
	}

	/**
	 * Takes a lock, waiting at most the given time for another runner to let go of it.
	 *
	 * A caller that gets LockNotAcquired must not proceed.
	 *
	 * @since 0.1.0
	 *
	 * @throws LockNotAcquired|ForbiddenInsideTransaction When another runner held the lock for the whole
	 *                                                    wait; or when called inside a transaction.
	 * @throws \InvalidArgumentException                  When the TTL is below one second or the wait is negative.
	 *
	 * @param string $name        The lock's name, for example 'schema'.
	 * @param int    $ttlSeconds  How long a table-mode lease lasts without renew(). At least 1.
	 * @param int    $waitSeconds How long to wait for another holder. 0 tries once.
	 * @return Lease The lease. Renew it before every step of long work; release it when done.
	 */
	public function acquire( string $name, int $ttlSeconds, int $waitSeconds ): Lease {
		if ( 0 !== $this->db->depth() ) {
			throw new ForbiddenInsideTransaction( ForbiddenInsideTransaction::KIND_LOCK, $name );
		}

		if ( $ttlSeconds < 1 || $waitSeconds < 0 ) {
			throw new \InvalidArgumentException( 'A lock needs a TTL of at least one second and a wait of zero seconds or more.' );
		}

		return LockMode::GetLock === $this->mode
			? $this->acquireServerLock( $name, $ttlSeconds, $waitSeconds )
			: $this->acquireTableLock( $name, $ttlSeconds, $waitSeconds );
	}

	/**
	 * Takes a lock, runs work while holding it, and releases it however the work ends.
	 *
	 * @since 0.1.0
	 *
	 * @throws LockNotAcquired When another runner held the lock for the whole wait. The work did not run.
	 * @throws \Throwable      Whatever the work threw, once the lease is released.
	 *
	 * @param string   $name        The lock's name.
	 * @param int      $ttlSeconds  How long a table-mode lease lasts without renew().
	 * @param int      $waitSeconds How long to wait for another holder.
	 * @param callable $work        The work. It receives the Lease, to renew it between steps.
	 * @return mixed What the work returned.
	 */
	public function withLock( string $name, int $ttlSeconds, int $waitSeconds, callable $work ): mixed {
		$lease = $this->acquire( $name, $ttlSeconds, $waitSeconds );

		try {
			$result = $work( $lease );
		} catch ( \Throwable $failure ) {
			try {
				$lease->release();
			} catch ( DatabaseException $releaseFailed ) {
				// The work's failure is the one to report. A table lease that could not be released expires with its TTL.
				unset( $releaseFailed );
			}

			throw $failure;
		}

		$lease->release();

		return $result;
	}

	/**
	 * Builds the name a lock has on the database server, where GET_LOCK names are global.
	 *
	 * @since 0.1.0
	 *
	 * @param string $database The database name.
	 * @param string $prefix   The site's table prefix.
	 * @param string $name     The lock's name, as the caller gave it.
	 * @return string `seocart:{database}:{prefix}:{name}`, cut to 64 characters with a hash of the
	 *                whole name at the end when it is longer.
	 */
	public static function serverLockName( string $database, string $prefix, string $name ): string {
		$full = sprintf( 'seocart:%s:%s:%s', $database, $prefix, $name );

		if ( strlen( $full ) <= self::SERVER_NAME_LIMIT ) {
			return $full;
		}

		$keep = self::SERVER_NAME_LIMIT - self::HASH_DIGITS - 1;

		return substr( $full, 0, $keep ) . '#' . substr( hash( 'sha256', $full ), 0, self::HASH_DIGITS );
	}

	/**
	 * Takes a server lock with GET_LOCK(); the server waits.
	 *
	 * @since 0.1.0
	 *
	 * @throws LockNotAcquired When the server's wait ran out.
	 *
	 * @param string $name        The lock's name.
	 * @param int    $ttlSeconds  Kept on the lease; a server lock lives as long as its connection.
	 * @param int    $waitSeconds How long the server may wait.
	 * @return Lease The lease.
	 */
	private function acquireServerLock( string $name, int $ttlSeconds, int $waitSeconds ): Lease {
		$serverName = self::serverLockName( $this->db->databaseName(), $this->db->prefix(), $name );

		$acquired = $this->db->fetchValue( 'SELECT GET_LOCK( %s, %d )', $serverName, $waitSeconds );

		if ( '1' !== (string) $acquired ) {
			throw new LockNotAcquired( $name, $waitSeconds * 1000 );
		}

		return new Lease( $this->db, LockMode::GetLock, $name, $serverName, self::token(), $ttlSeconds, $this->db->threadId() );
	}

	/**
	 * Takes a lease on a row of the `locks` table, polling until the wait is spent.
	 *
	 * @since 0.1.0
	 *
	 * @throws LockNotAcquired When the wait is spent.
	 *
	 * @param string $name        The lock's name.
	 * @param int    $ttlSeconds  How long the lease lasts without renew().
	 * @param int    $waitSeconds How long to keep trying.
	 * @return Lease The lease.
	 */
	private function acquireTableLock( string $name, int $ttlSeconds, int $waitSeconds ): Lease {
		$table  = $this->db->table( 'locks' );
		$token  = self::token();
		$holder = PHP_SAPI . ':' . getmypid();
		$waited = 0;

		// Rows are permanent; creating one that exists is a no-op.
		$this->db->execute( 'INSERT IGNORE INTO %i ( name, created_at ) VALUES ( %s, UTC_TIMESTAMP() )', $table, $name );

		while ( true ) {
			$taken = $this->db->execute(
				'UPDATE %i SET owner_token = %s, acquired_at = UTC_TIMESTAMP(6), expires_at = UTC_TIMESTAMP(6) + INTERVAL %d SECOND, holder = %s WHERE name = %s AND ( owner_token IS NULL OR expires_at < UTC_TIMESTAMP(6) )',
				$table,
				$token,
				$ttlSeconds,
				$holder,
				$name
			);

			if ( 1 === $taken ) {
				return new Lease( $this->db, LockMode::Table, $name, $table, $token, $ttlSeconds, $this->db->threadId() );
			}

			if ( $waited + self::POLL_MILLISECONDS > $waitSeconds * 1000 ) {
				throw new LockNotAcquired( $name, $waited );
			}

			( $this->sleep )( self::POLL_MILLISECONDS );

			$waited += self::POLL_MILLISECONDS;
		}
	}

	/**
	 * Mints an owner token: 64 hexadecimal digits, the shape of every token column.
	 *
	 * @since 0.1.0
	 *
	 * @return string The token.
	 */
	private static function token(): string {
		return bin2hex( random_bytes( 32 ) );
	}
}
