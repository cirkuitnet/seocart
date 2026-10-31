<?php
/**
 * MoveLocationStock: works a deactivated location's move of its available units to another, page by page
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Inventory\Infrastructure\Jobs;

use SEOCart\Inventory\Application\Transfers;
use SEOCart\Platform\Jobs\JobHandler;

defined( 'ABSPATH' ) || exit;

/**
 * Sends and receives a deactivation's move, one page of the source's variants per transaction, until it is received or its time is spent.
 *
 * Owns one fact: the bounds of a move's run. A deactivation that moves its location's stock
 * writes one transfer in transit and queues this job with the transfer's id; each page is
 * Transfers::movePage(), a unit of work of its own, and the transfer's lines say how far the move
 * came, so a run killed in the middle of a page loses that page only, and the next run, or the
 * same job run again, goes on from the lines. A run stops when the move is received, and is then
 * done, or when BUDGET_SECONDS is spent, and asks to run again a moment later. The job is keyed by
 * its transfer, so it is queued once while it waits or runs.
 *
 * @since 0.2.0
 */
final class MoveLocationStock implements JobHandler {

	/**
	 * How long one run may keep moving, in seconds: the hold sweep's budget.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	public const BUDGET_SECONDS = SweepHolds::BUDGET_SECONDS;

	/**
	 * How soon a run that spent its budget runs again, in seconds.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	public const AGAIN_AFTER_SECONDS = 1;

	/**
	 * Nanoseconds in a second.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	private const NANOSECONDS = 1000000000;

	/**
	 * The transfer service.
	 *
	 * @since 0.2.0
	 *
	 * @var Transfers
	 */
	private Transfers $transfers;

	/**
	 * How long one run may keep moving, in seconds.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	private int $budgetSeconds;

	/**
	 * How many variants one page moves at most.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	private int $page;

	/**
	 * Returns a monotonic time in nanoseconds.
	 *
	 * @since 0.2.0
	 *
	 * @var \Closure(): int
	 */
	private \Closure $clock;

	/**
	 * Creates the handler. Sends nothing.
	 *
	 * @since 0.2.0
	 *
	 * @param Transfers     $transfers     The transfer service.
	 * @param int           $budgetSeconds Optional. How long one run may keep moving. Default BUDGET_SECONDS. At 0 a run moves one page.
	 * @param int           $page          Optional. How many variants one page moves at most. Default Transfers::MOVE_PAGE.
	 * @param callable|null $clock         Optional. Returns a monotonic time in nanoseconds (int). Default null, which uses hrtime().
	 *
	 * @phpstan-param (callable(): int)|null $clock
	 */
	public function __construct( Transfers $transfers, int $budgetSeconds = self::BUDGET_SECONDS, int $page = Transfers::MOVE_PAGE, ?callable $clock = null ) {
		$this->transfers     = $transfers;
		$this->budgetSeconds = max( 0, $budgetSeconds );
		$this->page          = max( 1, $page );
		$this->clock         = null === $clock ? static fn(): int => (int) hrtime( true ) : \Closure::fromCallable( $clock );
	}

	/**
	 * Returns the handler's name.
	 *
	 * @since 0.2.0
	 *
	 * @return string `stock.move_location_stock`.
	 */
	public static function name(): string {
		return Transfers::MOVE_JOB;
	}

	/**
	 * Returns how often the handler runs on its own: never, it runs when a move is queued.
	 *
	 * @since 0.2.0
	 *
	 * @return int|null Null.
	 */
	public static function recurrence(): ?int {
		return null;
	}

	/**
	 * Returns the attempts per queued move.
	 *
	 * @since 0.2.0
	 *
	 * @return int 3.
	 */
	public static function maxAttempts(): int {
		return 3;
	}

	/**
	 * Moves pages of a deactivation's transfer until it is received or the budget is spent.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, int|string|bool|null> $payload The job's payload: transfer_id.
	 * @return int|null Null once the move is received, or when the payload names no transfer; AGAIN_AFTER_SECONDS while units remain.
	 */
	public function handle( array $payload ): ?int {
		$transferId = is_int( $payload['transfer_id'] ?? null ) ? $payload['transfer_id'] : 0;

		if ( $transferId < 1 ) {
			return null;
		}

		$deadline = ( $this->clock )() + $this->budgetSeconds * self::NANOSECONDS;
		$after    = 0;

		do {
			$page  = $this->transfers->movePage( $transferId, $after, $this->page );
			$after = $page['after'];

			if ( $page['done'] ) {
				return null;
			}
		} while ( ( $this->clock )() < $deadline );

		return self::AGAIN_AFTER_SECONDS;
	}
}
