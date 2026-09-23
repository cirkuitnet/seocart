<?php
/**
 * DrainReport: what one drain of the outbox did
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Events;

defined( 'ABSPATH' ) || exit;

/**
 * The outcome of one call to OutboxDrainer::drain().
 *
 * Owns one fact: how a drain's outcome is told to its trigger. A drain that did not run says
 * why in `skipped`: another drainer held the lock, or delivery is paused. A claimed row that
 * was neither dispatched, retried nor parked was handed back unattempted, or was taken over by
 * another drainer after this one's lease lapsed.
 *
 * @since 0.1.0
 */
final readonly class DrainReport {

	/**
	 * Skipped: another drainer holds the drain lock.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const SKIPPED_LOCKED = 'locked';

	/**
	 * Skipped: delivery is paused, for example by Safe Mode.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const SKIPPED_PAUSED = 'paused';

	/**
	 * How many rows the drain claimed.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public int $claimed;

	/**
	 * How many rows it delivered to every listener and marked dispatched.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public int $dispatched;

	/**
	 * How many rows it put back to be tried again later.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public int $retried;

	/**
	 * How many rows it parked as failed.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public int $failed;

	/**
	 * Why the drain did not run, SKIPPED_LOCKED or SKIPPED_PAUSED, or null when it ran.
	 *
	 * @since 0.1.0
	 *
	 * @var string|null
	 */
	public ?string $skipped;

	/**
	 * Whether the drain stopped because its time budget ran out; rows may still wait.
	 *
	 * @since 0.1.0
	 *
	 * @var bool
	 */
	public bool $budgetExhausted;

	/**
	 * How many rows past retention it deleted.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public int $pruned;

	/**
	 * Records the outcome.
	 *
	 * @since 0.1.0
	 *
	 * @param int         $claimed         Rows claimed.
	 * @param int         $dispatched      Rows dispatched.
	 * @param int         $retried         Rows put back for a later attempt.
	 * @param int         $failed          Rows parked as failed.
	 * @param string|null $skipped         Why the drain did not run, or null.
	 * @param bool        $budgetExhausted Whether the time budget ran out.
	 * @param int         $pruned          Rows deleted past retention.
	 */
	public function __construct( int $claimed, int $dispatched, int $retried, int $failed, ?string $skipped, bool $budgetExhausted, int $pruned ) {
		$this->claimed         = $claimed;
		$this->dispatched      = $dispatched;
		$this->retried         = $retried;
		$this->failed          = $failed;
		$this->skipped         = $skipped;
		$this->budgetExhausted = $budgetExhausted;
		$this->pruned          = $pruned;
	}

	/**
	 * Returns the report of a drain that did not run.
	 *
	 * @since 0.1.0
	 *
	 * @param string $reason SKIPPED_LOCKED or SKIPPED_PAUSED.
	 * @return self The report.
	 */
	public static function skipped( string $reason ): self {
		return new self( 0, 0, 0, 0, $reason, false, 0 );
	}
}
