<?php
/**
 * OutboxRetention: the daily job that deletes outbox rows past retention
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Jobs\Handlers;

use SEOCart\Platform\Events\Outbox;
use SEOCart\Platform\Jobs\JobHandler;

defined( 'ABSPATH' ) || exit;

/**
 * Prunes the outbox once a day, in bounded batches.
 *
 * Owns one fact: the bounds of the outbox's retention sweep. Outbox::prune() deletes the
 * dispatched and failed rows past their retention periods, each statement bounded by BATCH
 * rows on its own index; a pending row is never deleted. The sweep
 * repeats until a batch deletes nothing or BUDGET_SECONDS is spent, and a backlog it cannot
 * finish is the next day's. Because this job prunes, the plugin's own drains (the catch-up job
 * and `wp seocart jobs run`) do not.
 *
 * @since 0.1.0
 */
final class OutboxRetention implements JobHandler {

	/**
	 * How many rows of each state one statement deletes at most.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const BATCH = 5000;

	/**
	 * How long one sweep may keep deleting, in seconds.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const BUDGET_SECONDS = 20;

	/**
	 * Nanoseconds in a second.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private const NANOSECONDS = 1000000000;

	/**
	 * The outbox rows.
	 *
	 * @since 0.1.0
	 *
	 * @var Outbox
	 */
	private Outbox $outbox;

	/**
	 * How many rows of each state one statement deletes at most.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private int $batch;

	/**
	 * Returns a monotonic time in nanoseconds.
	 *
	 * @since 0.1.0
	 *
	 * @var callable(): int
	 */
	private $clock;

	/**
	 * Creates the handler.
	 *
	 * @since 0.1.0
	 *
	 * @param Outbox        $outbox The outbox rows.
	 * @param int           $batch  Optional. Rows of each state one statement deletes at most. Default BATCH.
	 * @param callable|null $clock  Optional. Returns a monotonic time in nanoseconds (int). Default
	 *                              null, which uses hrtime().
	 */
	public function __construct( Outbox $outbox, int $batch = self::BATCH, ?callable $clock = null ) {
		$this->outbox = $outbox;
		$this->batch  = max( 1, $batch );
		$this->clock  = $clock ?? static fn(): int => (int) hrtime( true );
	}

	/**
	 * Returns the handler's name.
	 *
	 * @since 0.1.0
	 *
	 * @return string `outbox.prune`.
	 */
	public static function name(): string {
		return 'outbox.prune';
	}

	/**
	 * Returns how often the handler runs.
	 *
	 * @since 0.1.0
	 *
	 * @return int Once a day.
	 */
	public static function recurrence(): int {
		return 86400;
	}

	/**
	 * Returns the attempts per run.
	 *
	 * @since 0.1.0
	 *
	 * @return int 1: the next day's run is the retry.
	 */
	public static function maxAttempts(): int {
		return 1;
	}

	/**
	 * Deletes batches of rows past retention until none is left or the budget is spent.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, int|string|bool|null> $payload Unused.
	 * @return int|null Null: the next run follows on its schedule.
	 */
	public function handle( array $payload ): ?int {
		$deadline = ( $this->clock )() + self::BUDGET_SECONDS * self::NANOSECONDS;

		do {
			$deleted = $this->outbox->prune( $this->batch );
		} while ( $deleted > 0 && ( $this->clock )() < $deadline );

		return null;
	}
}
