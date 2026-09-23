<?php
/**
 * LockLost: a lease the caller believed it held is no longer its own
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Database\Exception;

use SEOCart\Platform\Database\DatabaseError;

defined( 'ABSPATH' ) || exit;

/**
 * The table lease expired, or the connection that held the server lock is gone.
 *
 * Owns one fact: that the holder must stop at once, because another runner may already be
 * doing the same work. Lease::renew() raises it before every step of long work, with the lock
 * `name`, the `mode` it was held in, and the `reason`, one of the constants below.
 *
 * @since 0.1.0
 */
final class LockLost extends DatabaseException {

	/**
	 * The catalog case this class raises.
	 *
	 * @since 0.1.0
	 *
	 * @var DatabaseError
	 */
	public const CODE = DatabaseError::LockLost;

	/**
	 * Reason: the lease was already released.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const RELEASED = 'released';

	/**
	 * Reason: wpdb reconnected, and the server released the lock with the old connection.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const CONNECTION_CHANGED = 'connection_changed';

	/**
	 * Reason: the server says this connection does not hold the lock.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const NOT_HELD = 'not_held';

	/**
	 * Reason: the table lease expired; another runner may already have reclaimed it.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const EXPIRED = 'expired';
}
