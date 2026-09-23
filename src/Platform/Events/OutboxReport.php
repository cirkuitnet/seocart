<?php
/**
 * OutboxReport: how many stored events are waiting, in flight, failed and delivered
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Events;

defined( 'ABSPATH' ) || exit;

/**
 * The outbox's health in five numbers, for doctor, Site Health and `wp seocart outbox status`.
 *
 * Owns one fact: what the outbox's state is reported as. A delivery that stalled shows as a
 * growing oldest pending age; a delivery that gave up shows as failed rows, which nothing
 * deletes until their retention ends.
 *
 * @since 0.1.0
 */
final readonly class OutboxReport {

	/**
	 * How many rows wait to be delivered, leased or not.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public int $pending;

	/**
	 * How long ago the oldest waiting row was stored, in seconds of the database clock, or null when none waits.
	 *
	 * @since 0.1.0
	 *
	 * @var int|null
	 */
	public ?int $oldestPendingSeconds;

	/**
	 * How many waiting rows a drainer holds a live lease on.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public int $inFlight;

	/**
	 * How many rows delivery gave up on.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public int $failed;

	/**
	 * How many rows were delivered in the last 24 hours.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public int $dispatchedLastDay;

	/**
	 * Records the counts.
	 *
	 * @since 0.1.0
	 *
	 * @param int      $pending              Waiting rows.
	 * @param int|null $oldestPendingSeconds Age of the oldest waiting row, or null.
	 * @param int      $inFlight             Waiting rows under a live lease.
	 * @param int      $failed               Failed rows.
	 * @param int      $dispatchedLastDay    Rows delivered in the last 24 hours.
	 */
	public function __construct( int $pending, ?int $oldestPendingSeconds, int $inFlight, int $failed, int $dispatchedLastDay ) {
		$this->pending              = $pending;
		$this->oldestPendingSeconds = $oldestPendingSeconds;
		$this->inFlight             = $inFlight;
		$this->failed               = $failed;
		$this->dispatchedLastDay    = $dispatchedLastDay;
	}
}
