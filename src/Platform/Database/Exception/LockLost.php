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

use SEOCart\Platform\Database\LockMode;

defined( 'ABSPATH' ) || exit;

/**
 * The lease expired and was reclaimed, or the connection that held the server lock is gone.
 *
 * Owns one fact: that the holder must stop at once, because another runner may already be
 * doing the same work. Lease::renew() throws it before every step of long work.
 *
 * @since 0.1.0
 */
final class LockLost extends DatabaseException {

	/**
	 * The machine code.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const CODE = 'database.lock_lost';

	/**
	 * Describes the lost lease.
	 *
	 * @since 0.1.0
	 *
	 * @param string   $name The lock's name, as the caller gave it.
	 * @param LockMode $mode How the lock was held.
	 * @param string   $why  What showed that it is lost.
	 */
	public function __construct( string $name, LockMode $mode, string $why ) {
		parent::__construct(
			sprintf( 'The lock "%s" is no longer held by this runner: %s', $name, $why ),
			array(
				'name' => $name,
				'mode' => $mode->value,
			)
		);
	}
}
