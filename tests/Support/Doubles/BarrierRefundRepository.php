<?php
/**
 * BarrierRefundRepository: the refund statements, with a barrier just before a refund is claimed
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support\Doubles;

use SEOCart\Payment\Domain\Refund\ClaimState;
use SEOCart\Payment\Domain\Refund\Refund;
use SEOCart\Payment\Domain\Refund\RefundableIntent;
use SEOCart\Payment\Domain\Refund\RefundClaim;
use SEOCart\Payment\Domain\Refund\RefundPlan;
use SEOCart\Payment\Domain\Refund\RefundRepository;
use SEOCart\Support\Currency;
use SEOCart\Support\Money;

/**
 * Wraps the refund statements and runs what a test gives it just after a refund's claim took its intent's lock, inside the claim's transaction.
 *
 * Owns one fact, for the test of two refunds of one order claimed at once: the moment one claim
 * holds the intent's lock and another must wait for it. Every statement is the wrapped
 * repository's.
 *
 * @since 0.1.0
 */
final class BarrierRefundRepository implements RefundRepository {

	/**
	 * The statements.
	 *
	 * @since 0.1.0
	 *
	 * @var RefundRepository
	 */
	private RefundRepository $inner;

	/**
	 * What runs just after each claim's lock, inside its transaction; null for nothing.
	 *
	 * @since 0.1.0
	 *
	 * @var (\Closure(): void)|null
	 */
	private ?\Closure $afterLock = null;

	/**
	 * Wraps the statements.
	 *
	 * @since 0.1.0
	 *
	 * @param RefundRepository $inner The statements.
	 */
	public function __construct( RefundRepository $inner ) {
		$this->inner = $inner;
	}

	/**
	 * Gives every later claim something to run just after it took the intent's lock, inside its transaction.
	 *
	 * @since 0.1.0
	 *
	 * @param \Closure $run What runs, such as starting another refund in a process of its own.
	 *
	 * @phpstan-param \Closure(): void $run
	 */
	public function afterLock( \Closure $run ): void {
		$this->afterLock = $run;
	}

	/**
	 * Locks the intent through the wrapped statements, then runs the barrier.
	 *
	 * @since 0.1.0
	 *
	 * @param int $intentId The intent.
	 * @return array{has_unapplied_result: bool, refunded_minor: int, declined_refunds: int, open_claim: string|null} What the wrapped statements read.
	 */
	public function lockForClaim( int $intentId ): array {
		$locked = $this->inner->lockForClaim( $intentId );

		if ( null !== $this->afterLock ) {
			( $this->afterLock )();
		}

		return $locked;
	}

	/**
	 * Reads the intent through the wrapped statements.
	 *
	 * @since 0.1.0
	 *
	 * @param int $orderId The order.
	 * @return RefundableIntent|null The intent.
	 */
	public function refundableIntent( int $orderId ): ?RefundableIntent {
		return $this->inner->refundableIntent( $orderId );
	}

	/**
	 * Claims through the wrapped statements.
	 *
	 * @since 0.1.0
	 *
	 * @param RefundPlan $plan      The refund.
	 * @param string     $actorType `user` or `system`.
	 * @param int|null   $actorId   The user who asks for it, or null.
	 * @return bool Whether this request claimed the refund.
	 */
	public function claim( RefundPlan $plan, string $actorType, ?int $actorId ): bool {
		return $this->inner->claim( $plan, $actorType, $actorId );
	}

	/**
	 * Reads a claim through the wrapped statements.
	 *
	 * @since 0.1.0
	 *
	 * @param string $uuid The refund's uuid.
	 * @return RefundClaim|null The claim.
	 */
	public function findClaim( string $uuid ): ?RefundClaim {
		return $this->inner->findClaim( $uuid );
	}

	/**
	 * Ends a claim through the wrapped statements.
	 *
	 * @since 0.1.0
	 *
	 * @param string     $uuid          The refund's uuid.
	 * @param ClaimState $to            Where it ends.
	 * @param int|null   $transactionId The ledger row, or null.
	 * @return bool Whether it ended here.
	 */
	public function settleClaim( string $uuid, ClaimState $to, ?int $transactionId ): bool {
		return $this->inner->settleClaim( $uuid, $to, $transactionId );
	}

	/**
	 * Adds up what earlier refunds returned of some lines, through the wrapped statements.
	 *
	 * @since 0.1.0
	 *
	 * @param int[]    $lineIds      The order lines.
	 * @param Currency $currency     The order's currency.
	 * @param Currency $baseCurrency The base currency.
	 * @return array<int, \SEOCart\Payment\Domain\Refund\Share> What was returned.
	 *
	 * @phpstan-param list<int> $lineIds
	 */
	public function returnedOfLines( array $lineIds, Currency $currency, Currency $baseCurrency ): array {
		return $this->inner->returnedOfLines( $lineIds, $currency, $baseCurrency );
	}

	/**
	 * Adds up what earlier refunds returned of some components, through the wrapped statements.
	 *
	 * @since 0.1.0
	 *
	 * @param int[]    $componentIds The components.
	 * @param Currency $currency     The order's currency.
	 * @param Currency $baseCurrency The base currency.
	 * @return array<int, \SEOCart\Payment\Domain\Refund\Share> What was returned.
	 *
	 * @phpstan-param list<int> $componentIds
	 */
	public function returnedOfComponents( array $componentIds, Currency $currency, Currency $baseCurrency ): array {
		return $this->inner->returnedOfComponents( $componentIds, $currency, $baseCurrency );
	}

	/**
	 * Adds up the shipping earlier refunds returned, through the wrapped statements.
	 *
	 * @since 0.1.0
	 *
	 * @param int      $orderId      The order.
	 * @param Currency $currency     The order's currency.
	 * @param Currency $baseCurrency The base currency.
	 * @return array{0: Money, 1: Money} The shipping returned, in both currencies.
	 */
	public function returnedShipping( int $orderId, Currency $currency, Currency $baseCurrency ): array {
		return $this->inner->returnedShipping( $orderId, $currency, $baseCurrency );
	}

	/**
	 * Inserts the document through the wrapped statements.
	 *
	 * @since 0.1.0
	 *
	 * @param RefundPlan $plan          The refund.
	 * @param int        $transactionId Its ledger row.
	 * @param string     $actorType     `user` or `system`.
	 * @param int|null   $actorId       The user, or null.
	 * @return int|null The refund's id, or null.
	 */
	public function insertRefund( RefundPlan $plan, int $transactionId, string $actorType, ?int $actorId ): ?int {
		return $this->inner->insertRefund( $plan, $transactionId, $actorType, $actorId );
	}

	/**
	 * Inserts the lines through the wrapped statements.
	 *
	 * @since 0.1.0
	 *
	 * @param int        $refundId The refund.
	 * @param RefundPlan $plan     The refund.
	 */
	public function insertLines( int $refundId, RefundPlan $plan ): void {
		$this->inner->insertLines( $refundId, $plan );
	}

	/**
	 * Inserts the components through the wrapped statements.
	 *
	 * @since 0.1.0
	 *
	 * @param int        $refundId The refund.
	 * @param RefundPlan $plan     The refund.
	 * @return bool Whether every share was written.
	 */
	public function insertComponents( int $refundId, RefundPlan $plan ): bool {
		return $this->inner->insertComponents( $refundId, $plan );
	}

	/**
	 * Reads a document through the wrapped statements.
	 *
	 * @since 0.1.0
	 *
	 * @param int $transactionId The ledger row.
	 * @return Refund|null The document.
	 */
	public function findByTransaction( int $transactionId ): ?Refund {
		return $this->inner->findByTransaction( $transactionId );
	}
}
