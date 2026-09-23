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

defined( 'ABSPATH' ) || exit;

/**
 * The lock was held by another runner for as long as the caller was willing to wait.
 *
 * Owns one fact: that the caller must not proceed. A loser never goes on as if it held the
 * lock; the migrator reports "blocked" and the job runner tries again on its next tick.
 *
 * @since 0.1.0
 */
final class LockNotAcquired extends DatabaseException {

	/**
	 * The machine code.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const CODE = 'database.lock_not_acquired';

	/**
	 * The lock's name, as the caller gave it.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private string $name;

	/**
	 * Describes the lock that could not be taken.
	 *
	 * @since 0.1.0
	 *
	 * @param string $name     The lock's name, as the caller gave it.
	 * @param int    $waitedMs How long the caller waited, in milliseconds.
	 */
	public function __construct( string $name, int $waitedMs ) {
		$this->name = $name;

		parent::__construct(
			sprintf( 'The lock "%s" is held by another runner; gave up after waiting %d ms.', $name, $waitedMs ),
			array(
				'name'   => $name,
				'waited' => $waitedMs,
			)
		);
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
}
