<?php
/**
 * BarrierRefundRepository: the refund statements, with barriers where a test runs another refund
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support\Doubles;

use SEOCart\Contracts\Payment\GatewayResult;
use SEOCart\Payment\Domain\Refund\ClaimRequest;
use SEOCart\Payment\Domain\Refund\ClaimState;
use SEOCart\Payment\Domain\Refund\Refund;
use SEOCart\Payment\Domain\Refund\RefundableIntent;
use SEOCart\Payment\Domain\Refund\RefundClaim;
use SEOCart\Payment\Domain\Refund\RefundPlan;
use SEOCart\Payment\Domain\Refund\RefundRepository;
use SEOCart\Payment\Domain\Refund\RefundRequest;
use SEOCart\Payment\Domain\Refund\RequestKey;
use SEOCart\Support\Currency;
use SEOCart\Support\Money;

/**
 * Wraps the refund statements and runs what a test gives it just after a refund's claim took its intent's lock, or added up what its user asked, inside the claim's transaction; or once just after a refund looked its idempotency key up.
 *
 * Owns one fact, for the tests of two refunds at once: the moment one claim holds the intent's
 * lock, or its user's lock row, and another must wait for it; and the moment a request has found
 * no claim by its key and has not yet worked its refund out. Every statement is the wrapped
 * repository's.
 *
 * @since 0.1.0
 * @since 0.2.0 The barrier after the key's lookup.
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
	 * What runs once, just after the next lookup of a key; null for nothing.
	 *
	 * @since 0.2.0
	 *
	 * @var (\Closure(): void)|null
	 */
	private ?\Closure $afterKeyLookup = null;

	/**
	 * What runs just after each sum of what a user asked, inside the claim's transaction; null for nothing.
	 *
	 * @since 0.2.0
	 *
	 * @var (\Closure(): void)|null
	 */
	private ?\Closure $afterAskedToday = null;

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
	 * Gives the next lookup of a key something to run just after it, once, outside any transaction.
	 *
	 * @since 0.2.0
	 *
	 * @param \Closure $run What runs, such as another request with the same key, whole.
	 *
	 * @phpstan-param \Closure(): void $run
	 */
	public function onceAfterKeyLookup( \Closure $run ): void {
		$this->afterKeyLookup = $run;
	}

	/**
	 * Looks a key up through the wrapped statements, then runs the barrier once.
	 *
	 * @since 0.2.0
	 *
	 * @param string $keyHash The key's hash.
	 * @return RefundClaim|null What the wrapped statements read, before the barrier ran.
	 */
	public function findClaimByKey( string $keyHash ): ?RefundClaim {
		$claim = $this->inner->findClaimByKey( $keyHash );
		$run   = $this->afterKeyLookup;

		if ( null !== $run ) {
			$this->afterKeyLookup = null;

			$run();
		}

		return $claim;
	}

	/**
	 * Locks the intent through the wrapped statements, then runs the barrier.
	 *
	 * @since 0.1.0
	 *
	 * @param int         $intentId     The intent.
	 * @param string|null $reconciledAt The order's clearance, or null.
	 * @return array{has_unapplied_result: bool, refunded_minor: int, base_refunded_minor: int, declined_refunds: int, open_claim: string|null} What the wrapped statements read.
	 */
	public function lockForClaim( int $intentId, ?string $reconciledAt ): array {
		$locked = $this->inner->lockForClaim( $intentId, $reconciledAt );

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
	 * @param int         $orderId      The order.
	 * @param string|null $reconciledAt The order's clearance, or null.
	 * @return RefundableIntent|null The intent.
	 */
	public function refundableIntent( int $orderId, ?string $reconciledAt ): ?RefundableIntent {
		return $this->inner->refundableIntent( $orderId, $reconciledAt );
	}

	/**
	 * Claims through the wrapped statements.
	 *
	 * @since 0.1.0
	 *
	 * @param RefundPlan      $plan      The refund.
	 * @param RefundRequest   $request   What was asked.
	 * @param string          $actorType `user` or `system`.
	 * @param int|null        $actorId   The user who asks for it, or null.
	 * @param RequestKey|null $key       The caller's key, or null.
	 * @return bool Whether this request claimed the refund.
	 */
	public function claim( RefundPlan $plan, RefundRequest $request, string $actorType, ?int $actorId, ?RequestKey $key ): bool {
		return $this->inner->claim( $plan, $request, $actorType, $actorId, $key );
	}

	/**
	 * Locks a user's row through the wrapped statements.
	 *
	 * @since 0.2.0
	 *
	 * @param int $userId The user.
	 */
	public function lockActor( int $userId ): void {
		$this->inner->lockActor( $userId );
	}

	/**
	 * Adds up what a user asked through the wrapped statements.
	 *
	 * @since 0.2.0
	 *
	 * @param int      $userId The user.
	 * @param Currency $base   The base currency.
	 * @return Money What the wrapped statements read.
	 */
	public function askedToday( int $userId, Currency $base ): Money {
		$asked = $this->inner->askedToday( $userId, $base );

		if ( null !== $this->afterAskedToday ) {
			( $this->afterAskedToday )();
		}

		return $asked;
	}

	/**
	 * Gives every later sum of what a user asked something to run just after it, inside the claim's transaction, before the claim.
	 *
	 * @since 0.2.0
	 *
	 * @param \Closure $run What runs, such as another refund of the same user in a process of its own.
	 *
	 * @phpstan-param \Closure(): void $run
	 */
	public function afterAskedToday( \Closure $run ): void {
		$this->afterAskedToday = $run;
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
	 * Reads a claim with what it asked through the wrapped statements.
	 *
	 * @since 0.2.0
	 *
	 * @param string $uuid The refund's uuid.
	 * @return ClaimRequest|null The claim.
	 */
	public function claimRequest( string $uuid ): ?ClaimRequest {
		return $this->inner->claimRequest( $uuid );
	}

	/**
	 * Tells whether the ledger holds a result through the wrapped statements.
	 *
	 * @since 0.2.0
	 *
	 * @param GatewayResult $result The result.
	 * @return bool Whether a row holds it.
	 */
	public function holdsResult( GatewayResult $result ): bool {
		return $this->inner->holdsResult( $result );
	}

	/**
	 * Notes a person's settlement through the wrapped statements.
	 *
	 * @since 0.2.0
	 *
	 * @param string   $uuid           The refund's uuid.
	 * @param string   $statement      What the person stated.
	 * @param string   $gatewayReading What the gateway said.
	 * @param int|null $settledBy      The user, or null.
	 * @param string   $note           Why.
	 * @return bool Whether it was noted here.
	 */
	public function noteSettlement( string $uuid, string $statement, string $gatewayReading, ?int $settledBy, string $note ): bool {
		return $this->inner->noteSettlement( $uuid, $statement, $gatewayReading, $settledBy, $note );
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
