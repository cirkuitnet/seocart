<?php
/**
 * LockNotAcquired: another runner held a lock for the whole bounded wait
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
 * The lock was held by another runner for as long as the caller was willing to wait.
 *
 * Owns one fact: that the caller must not proceed. A loser never goes on as if it held the
 * lock; the migrator reports "blocked" and the job runner tries again on its next tick. The
 * context carries the lock `name` and how long the caller `waited`, in milliseconds.
 *
 * @since 0.1.0
 */
final class LockNotAcquired extends DatabaseException {

	/**
	 * The catalog case this class raises.
	 *
	 * @since 0.1.0
	 *
	 * @var DatabaseError
	 */
	public const CODE = DatabaseError::LockNotAcquired;

	/**
	 * Returns the lock's name.
	 *
	 * @since 0.1.0
	 *
	 * @return string The name the caller asked for.
	 */
	public function name(): string {
		return (string) $this->context()['name'];
	}
}
