<?php
/**
 * LockMode: how the lock service holds a lock on this host
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Database;

defined( 'ABSPATH' ) || exit;

/**
 * The two ways a lock can be held.
 *
 * Owns one fact: the choice LockProbe made for this host. The backing values are what the
 * boot option stores.
 *
 * @since 0.1.0
 */
enum LockMode: string {

	/**
	 * MySQL's GET_LOCK(): held by the connection, released by the server when the connection
	 * ends, and waited for by the server, not by PHP.
	 *
	 * @since 0.1.0
	 */
	case GetLock = 'get_lock';

	/**
	 * A row of the `locks` table with an owner token, a TTL and stale reclaim. Used where
	 * GET_LOCK is unreliable: persistent connections, connection poolers, restricted hosts.
	 *
	 * @since 0.1.0
	 */
	case Table = 'table';
}
