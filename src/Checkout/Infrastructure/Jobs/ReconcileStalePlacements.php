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
use SEOCart\Contracts\Payment\GatewayUnavailable;
use SEOCart\Order\Application\Orders;
use SEOCart\Payment\Application\PaymentService;
use SEOCart\Payment\Domain\IntentRef;
use SEOCart\Platform\Jobs\JobHandler;
use SEOCart\Support\Error\CodedException;

defined( 'ABSPATH' ) || exit;

/**
 * The reconciliation of placements whose payment answer never arrived, as a recurring job.
 *
 * Owns one fact: when a placement that lost its gateway's answer is settled. Every five minutes it
 * takes the intents still waiting for an answer that have not changed for STALE_SECONDS, a page at
 * a time in a cursor's order, asks the gateway about each, outside any transaction, and settles
 * each answer through SettlePlacement, the path every answer takes.
 *
 * A shopper asked to act whose time to do so ran out never acted: a real provider keeps such a
 * payment waiting until something cancels it, so the run asks the gateway to void it before
 * anything the order holds is released, and settles the void the same way (the end of the
 * shopper's time to act). A provider that had approved meanwhile answers the void with the
 * approval, which is settled as an approval: the order goes on, never released. A provider that
 * refuses to cancel leaves the placement as it was, reported deferred, for the next run to ask
 * again. A payment the gateway itself is still deciding is never voided because time passed: it is
 * only asked about.
 *
 * An intent the gateway still
 * has no answer for, or cannot be asked about now, is left as it is for the next run: a placement
 * is never released only because time has passed here, nor because its gateway is gone, switched
 * to another mode or kept from live calls by Safe Mode. An intent that could not be asked about is
 * reported deferred, with its gateway, the error's code and its reason (`cause`), such as
 * `not_registered` or `safe_mode`. It is released when the gateway answers,
 * and an intent the provider expired is answered so, as a decline: the query carries the intent's
 * expiry, which stands for a provider that reports none. STALE_SECONDS must exceed the longest an
 * authorization call may take, so no intent is asked about while its own call is still on its way.
 *
 * An order placed with nothing to pay has no intent: its placement settles it without the
 * gateway. One still pending payment STALE_SECONDS after it was placed was left by a placement
 * that stopped between its two units of work, and the run settles it too, through the same
 * path, a page at a time after the intents, within the same budget.
 *
 * Applying the same answer twice, or settling an order with nothing due twice, is a no-op, so two
 * runners at once, the schedule and a command, settle each placement once. A failure is reported
 * with the intent or the order and the run goes on; the job is never retried, the next run is the
 * retry.
 *
 * @since 0.1.0
 */
final class ReconcileStalePlacements implements JobHandler {

	/**
	 * How long an intent must have waited unchanged before the gateway is asked about it, and an order with nothing due waited since it was placed before it is settled, in seconds.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const STALE_SECONDS = PaymentService::STALE_SECONDS;

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
	 * @param Orders          $orders     The orders with nothing due still pending payment.
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
		private Orders $orders,
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
	 * Asks the gateway about each stale intent and settles each answer, then settles each stale order with nothing due, page by page, until a page comes back short or the budget is spent.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, int|string|bool|null> $payload Unused.
	 * @return int|null Null: the next run follows on its schedule.
	 */
	public function handle( array $payload ): ?int {
		$deadline = ( $this->clock )() + self::BUDGET_SECONDS * self::NANOSECONDS;

		$this->settleAnswered( $deadline );
		$this->settleNothingDue( $deadline );

		return null;
	}

	/**
	 * Asks the gateway about each stale intent, page by page, and settles each answer, until a page comes back short or the budget is spent.
	 *
	 * @since 0.1.0
	 *
	 * @param int $deadline When the budget is spent, on the clock's scale.
	 */
	private function settleAnswered( int $deadline ): void {
		$after = '';

		do {
			$page = $this->payments->staleIntents( self::STALE_SECONDS, self::PAGE, $after );

			foreach ( $page as $intent ) {
				$after = $intent->uuid;

				try {
					$this->settle( $intent );
				} catch ( GatewayUnavailable | CodedException $deferred ) {
					( $this->report )(
						self::DEFERRED,
						array(
							'intent_uuid' => $intent->uuid,
							'gateway_id'  => $intent->gatewayId,
							'reason'      => $deferred instanceof CodedException ? (string) $deferred->errorCode()->value : 'gateway_unavailable',
							'cause'       => $deferred instanceof CodedException ? ( $deferred->context()['reason'] ?? null ) : null,
						)
					);
				}
			}

			$full = count( $page ) >= self::PAGE;
		} while ( $full && ( $this->clock )() < $deadline );
	}

	/**
	 * Settles one stale intent: the void of one whose shopper's time to act ran out, or the gateway's answer about any other.
	 *
	 * @since 0.2.0
	 *
	 * Throws GatewayUnavailable when the gateway has no answer, and a CodedException for what asking
	 * the gateway, or settling its answer, refuses: `payment.operation_declined` when the gateway
	 * refuses to void, which changes nothing (SettlePlacement::voidEndedAction()).
	 *
	 * @param IntentRef $intent The intent, as the page read it.
	 */
	private function settle( IntentRef $intent ): void {
		if ( null !== $this->settlement->voidEndedAction( $intent ) ) {
			return;
		}

		$answer = $this->payments->queryGateway( $intent );

		if ( null !== $answer ) {
			$this->settlement->apply( $answer, SettlePlacement::store() );
		}
	}

	/**
	 * Settles each order placed with nothing due that is still pending payment STALE_SECONDS after it was placed, page by page, until a page comes back short or the budget is spent.
	 *
	 * @since 0.1.0
	 *
	 * @param int $deadline When the budget is spent, on the clock's scale.
	 */
	private function settleNothingDue( int $deadline ): void {
		$after = 0;

		do {
			$page = $this->orders->pendingNothingDue( self::STALE_SECONDS, $after, self::PAGE );

			foreach ( $page as $orderId ) {
				$after = $orderId;

				try {
					$this->settlement->settleNothingDue( $orderId, SettlePlacement::store() );
				} catch ( CodedException $deferred ) {
					( $this->report )(
						self::DEFERRED,
						array(
							'order_id' => $orderId,
							'reason'   => (string) $deferred->errorCode()->value,
						)
					);
				}
			}

			$full = count( $page ) >= self::PAGE;
		} while ( $full && ( $this->clock )() < $deadline );
	}
}
