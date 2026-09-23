<?php
/**
 * Lease: one holder's claim on a named lock
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Database;

use SEOCart\Platform\Database\Exception\LockLost;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages go to logs and the command line, never into HTML; the REST layer answers with the translated message of the error code.

/**
 * A lock held by this runner, until it is released or lost.
 *
 * Owns one fact: whether this runner still holds the lock, checked in the way the lock's mode
 * allows. renew() proves it before each step of long work and throws LockLost when it no
 * longer holds, so the holder stops instead of racing whoever reclaimed the lock. release()
 * gives the lock up and can only give up this holder's own claim.
 *
 * LockService creates leases; nothing else should.
 *
 * @since 0.1.0
 */
final class Lease {

	/**
	 * The connection.
	 *
	 * @since 0.1.0
	 *
	 * @var Database
	 */
	private Database $db;

	/**
	 * How the lock is held.
	 *
	 * @since 0.1.0
	 *
	 * @var LockMode
	 */
	private LockMode $mode;

	/**
	 * The lock's name, as the caller gave it.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private string $name;

	/**
	 * Where the lock lives: the server lock name (GetLock) or the full `locks` table name (Table).
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private string $where;

	/**
	 * This holder's owner token.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private string $token;

	/**
	 * How long a renewal extends a table-mode lease, in seconds.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private int $ttlSeconds;

	/**
	 * The thread id of the connection that took the lock.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private int $threadId;

	/**
	 * Whether release() has run.
	 *
	 * @since 0.1.0
	 *
	 * @var bool
	 */
	private bool $released = false;

	/**
	 * Describes a lock just taken. Called by LockService only.
	 *
	 * @since 0.1.0
	 *
	 * @param Database $db         The connection.
	 * @param LockMode $mode       How the lock is held.
	 * @param string   $name       The lock's name, as the caller gave it.
	 * @param string   $where      The server lock name (GetLock) or the full `locks` table name (Table).
	 * @param string   $token      The owner token.
	 * @param int      $ttlSeconds How long a renewal extends a table-mode lease.
	 * @param int      $threadId   The thread id of the connection that took the lock.
	 */
	public function __construct( Database $db, LockMode $mode, string $name, string $where, string $token, int $ttlSeconds, int $threadId ) {
		$this->db         = $db;
		$this->mode       = $mode;
		$this->name       = $name;
		$this->where      = $where;
		$this->token      = $token;
		$this->ttlSeconds = $ttlSeconds;
		$this->threadId   = $threadId;
	}

	/**
	 * Returns the lock's name.
	 *
	 * @since 0.1.0
	 *
	 * @return string The name the caller asked for.
	 */
	public function name(): string {
		return $this->name;
	}

	/**
	 * Returns this holder's owner token.
	 *
	 * @since 0.1.0
	 *
	 * @return string 64 hexadecimal digits.
	 */
	public function token(): string {
		return $this->token;
	}

	/**
	 * Proves the lock is still this runner's and, in table mode, extends the lease by its TTL.
	 *
	 * @since 0.1.0
	 *
	 * @throws LockLost When the lock is no longer held: released, expired and reclaimed, or its
	 *                  connection gone.
	 */
	public function renew(): void {
		if ( $this->released ) {
			throw new LockLost( $this->name, $this->mode, 'the lease was released.' );
		}

		if ( LockMode::GetLock === $this->mode ) {
			// A reconnect drops a server lock with the old connection: the free check first, then the server's word.
			if ( $this->db->threadId() !== $this->threadId ) {
				throw new LockLost( $this->name, $this->mode, 'wpdb reconnected, and the server released the lock with the old connection.' );
			}

			if ( '1' !== (string) $this->db->fetchValue( 'SELECT IS_USED_LOCK( %s ) = CONNECTION_ID()', $this->where ) ) {
				throw new LockLost( $this->name, $this->mode, 'the server says this connection does not hold it.' );
			}

			return;
		}

		$renewed = $this->db->execute(
			'UPDATE %i SET expires_at = UTC_TIMESTAMP(6) + INTERVAL %d SECOND WHERE name = %s AND owner_token = %s',
			$this->where,
			$this->ttlSeconds,
			$this->name,
			$this->token
		);

		if ( 1 !== $renewed ) {
			throw new LockLost( $this->name, $this->mode, 'the lease expired and another runner reclaimed it.' );
		}
	}

	/**
	 * Gives the lock up. Releasing twice does nothing; releasing never affects another holder's lease.
	 *
	 * @since 0.1.0
	 */
	public function release(): void {
		if ( $this->released ) {
			return;
		}

		$this->released = true;

		if ( LockMode::GetLock === $this->mode ) {
			// RELEASE_LOCK() is scoped to the connection by the server: it cannot release another's lock.
			$this->db->fetchValue( 'SELECT RELEASE_LOCK( %s )', $this->where );

			return;
		}

		$this->db->execute(
			'UPDATE %i SET owner_token = NULL WHERE name = %s AND owner_token = %s',
			$this->where,
			$this->name,
			$this->token
		);
	}
}
