<?php
/**
 * ReconcileStalePlacements: asks the gateway about placements still waiting for its answer, and settles them, as a recurring job
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Checkout\Infrastructure\Jobs;

use SEOCart\Checkout\Application\SettlePlacement;
use SEOCart\Payment\Application\PaymentService;
use SEOCart\Payment\Domain\Gateway\GatewayUnavailable;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Platform\Jobs\JobHandler;
use SEOCart\Support\Error\CodedException;

defined( 'ABSPATH' ) || exit;

/**
 * The reconciliation of placements whose payment answer never arrived, as a recurring job.
 *
 * Owns one fact: when a placement that lost its gateway's answer is settled. Every five minutes it
 * takes the intents still waiting for an answer that have not changed for STALE_SECONDS, a page at
 * a time in a cursor's order, asks the gateway about each, outside any transaction, and settles
 * each answer through SettlePlacement, the path every answer takes. An intent the gateway still
 * has no answer for, or cannot be asked about now, is left as it is for the next run: a placement
 * is never released because time has passed. STALE_SECONDS must exceed the longest an
 * authorization call may take, so no intent is asked about while its own call is still on its way.
 * Applying the same answer twice is a no-op, so two
 * runners at once, the schedule and a command, settle each placement once. A failure is reported
 * with the intent and the run goes on; the job is never retried, the next run is the retry.
 *
 * @since 0.1.0
 */
final class ReconcileStalePlacements implements JobHandler {

	/**
	 * How long an intent must have waited unchanged before the gateway is asked about it, in seconds.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const STALE_SECONDS = 600;

	/**
	 * How many intents one page takes.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const PAGE = 50;

	/**
	 * How long one run may keep asking, in seconds.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const BUDGET_SECONDS = 20;

	/**
	 * The code an intent is reported with when it could not be settled this run.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const DEFERRED = 'checkout.reconcile_deferred';

	/**
	 * Nanoseconds in a second.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private const NANOSECONDS = 1000000000;

	/**
	 * Returns a monotonic time in nanoseconds.
	 *
	 * @since 0.1.0
	 *
	 * @var \Closure(): int
	 */
	private \Closure $clock;

	/**
	 * Receives a report code and its context.
	 *
	 * @since 0.1.0
	 *
	 * @var \Closure(string, array<string, mixed>): void
	 */
	private \Closure $report;

	/**
	 * Creates the handler. Sends nothing.
	 *
	 * @since 0.1.0
	 *
	 * @param PaymentService  $payments   The stale intents, and the gateway.
	 * @param SettlePlacement $settlement The settlement every answer goes through.
	 * @param callable        $report     Receives a report code (string) and its context (array).
	 * @param callable|null   $clock      Optional. Returns a monotonic time in nanoseconds (int). Default
	 *                                    null, which uses hrtime().
	 *
	 * @phpstan-param callable(string, array<string, mixed>): void $report
	 * @phpstan-param (callable(): int)|null $clock
	 */
	public function __construct(
		private PaymentService $payments,
		private SettlePlacement $settlement,
		callable $report,
		?callable $clock = null
	) {
		$this->report = \Closure::fromCallable( $report );
		$this->clock  = null === $clock ? static fn(): int => (int) hrtime( true ) : \Closure::fromCallable( $clock );
	}

	/**
	 * Returns the handler's name.
	 *
	 * @since 0.1.0
	 *
	 * @return string `checkout.reconcile_placements`.
	 */
	public static function name(): string {
		return 'checkout.reconcile_placements';
	}

	/**
	 * Returns how often the handler runs.
	 *
	 * @since 0.1.0
	 *
	 * @return int Every five minutes.
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
	 * Asks the gateway about each stale intent, page by page, and settles each answer, until a page comes back short or the budget is spent.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, int|string|bool|null> $payload Unused.
	 * @return int|null Null: the next run follows on its schedule.
	 */
	public function handle( array $payload ): ?int {
		$deadline = ( $this->clock )() + self::BUDGET_SECONDS * self::NANOSECONDS;
		$after    = '';

		do {
			$page = $this->payments->staleIntents( self::STALE_SECONDS, self::PAGE, $after );

			foreach ( $page as $intent ) {
				$after = $intent->uuid;

				try {
					$answer = $this->payments->queryGateway( $intent );

					if ( null !== $answer ) {
						// A job acts on no user's authority: what it applies is recorded without a user.
						$this->settlement->apply( $answer, Actor::user( 0 ) );
					}
				} catch ( GatewayUnavailable | CodedException $deferred ) {
					( $this->report )(
						self::DEFERRED,
						array(
							'intent_uuid' => $intent->uuid,
							'reason'      => $deferred instanceof CodedException ? (string) $deferred->errorCode()->value : 'gateway_unavailable',
						)
					);
				}
			}

			$full = count( $page ) >= self::PAGE;
		} while ( $full && ( $this->clock )() < $deadline );

		return null;
	}
}
