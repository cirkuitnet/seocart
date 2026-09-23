<?php
/**
 * OutboxCatchUp: the recurring job that delivers stored events no request delivered
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Jobs\Handlers;

use SEOCart\Platform\Events\DrainOptions;
use SEOCart\Platform\Events\OutboxDrainer;
use SEOCart\Platform\Jobs\JobHandler;

defined( 'ABSPATH' ) || exit;

/**
 * Drains the outbox every five minutes, and at once for a request that could not drain at its end.
 *
 * Owns one fact: the bounds of the catch-up drain. A request that publishes events drains
 * them at its own end, once its response has ended, so the recurring run delivers what that
 * missed: rows put back for a retry, rows whose request died, rows published while delivery
 * was paused. Where the server cannot end a response early, the request queues a one-off run
 * instead of draining (EventWake). It never prunes: the outbox retention job does, daily.
 * Another drainer holding the lock is not a failure; the next run catches up.
 *
 * @since 0.1.0
 */
final class OutboxCatchUp implements JobHandler {

	/**
	 * How long one catch-up may keep delivering, in seconds.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const BUDGET_SECONDS = 20;

	/**
	 * The drainer.
	 *
	 * @since 0.1.0
	 *
	 * @var OutboxDrainer
	 */
	private OutboxDrainer $drainer;

	/**
	 * Creates the handler.
	 *
	 * @since 0.1.0
	 *
	 * @param OutboxDrainer $drainer The drainer.
	 */
	public function __construct( OutboxDrainer $drainer ) {
		$this->drainer = $drainer;
	}

	/**
	 * Returns the handler's name.
	 *
	 * @since 0.1.0
	 *
	 * @return string `outbox.catch_up`.
	 */
	public static function name(): string {
		return 'outbox.catch_up';
	}

	/**
	 * Returns how often the handler runs.
	 *
	 * @since 0.1.0
	 *
	 * @return int Every 300 seconds.
	 */
	public static function recurrence(): int {
		return 300;
	}

	/**
	 * Returns the attempts per run.
	 *
	 * @since 0.1.0
	 *
	 * @return int 1: the next run is the retry.
	 */
	public static function maxAttempts(): int {
		return 1;
	}

	/**
	 * Drains the outbox for up to BUDGET_SECONDS, without pruning.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, int|string|bool|null> $payload Unused.
	 * @return int|null Null: the next run follows on its schedule.
	 */
	public function handle( array $payload ): ?int {
		$this->drainer->drain( new DrainOptions( self::BUDGET_SECONDS, prune: false ) );

		return null;
	}
}
