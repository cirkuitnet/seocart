<?php
/**
 * RefundRepository: the statements of a refund, named by what they do
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Payment\Domain\Refund;

use SEOCart\Contracts\Payment\GatewayResult;
use SEOCart\Support\Currency;
use SEOCart\Support\Money;

defined( 'ABSPATH' ) || exit;

/**
 * The refund service's port to the payment tables: what earlier refunds returned, the refund's claim, and the refund document.
 *
 * Owns one fact: which statements a refund sends. The reads are plain and batched, one per kind
 * of row whatever the number of lines, and decide nothing for good. Every write runs only inside
 * the caller's transaction: the claim inside a short one of its own, under the intent's lock and,
 * for a user whose refunds are capped by the day, the user's lock row, committed before the
 * gateway is asked; every other write inside the one that records the answer, each carrying its
 * condition in its WHERE clause: the document is written only while what it was worked out from
 * still holds, and a claim ends only while it is still claimed.
 *
 * @since 0.1.0
 * @since 0.2.0 The claim keeps its request and the caller's idempotency key; the user's lock row and what the user asked in the last 24 hours.
 */
interface RefundRepository {

	/**
	 * Reads an order's captured intent, which a refund gives money back through, whether the ledger holds a result of it applied to nothing since a person last cleared the order's unreconciled money, how many of its refunds were declined, and its oldest refund still claimed, without a lock.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 The order's clearance.
	 *
	 * @param int         $orderId      The order.
	 * @param string|null $reconciledAt When a person last cleared the order's unreconciled money, as the order was read; null for never.
	 * @return RefundableIntent|null The first captured or partly refunded intent, or null when the order has none.
	 */
	public function refundableIntent( int $orderId, ?string $reconciledAt ): ?RefundableIntent;

	/**
	 * Locks an intent for a refund's claim, inside the caller's transaction, and reads, as they now stand, whether it has money a person must reconcile, what refund uuids are named by, and its oldest refund still claimed.
	 *
	 * The lock is held until the caller's transaction ends, so no other refund of the intent is
	 * claimed, and nothing a refund's uuid is named by moves, while the caller decides.
	 *
	 * A result applied to nothing counts only when it was written after the clearance the refund
	 * was worked out with: one that landed since is newer than any clearance the plain reads saw.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 The order's clearance.
	 *
	 * @throws \LogicException Outside a transaction, or when the intent does not exist.
	 *
	 * @param int         $intentId     The intent.
	 * @param string|null $reconciledAt When a person last cleared the order's unreconciled money, as the refund's plain reads found it; null for never.
	 * @return array{has_unapplied_result: bool, refunded_minor: int, base_refunded_minor: int, declined_refunds: int, open_claim: string|null} Whether the ledger holds a result of it applied to nothing since that clearance; what it refunded, in minor units, and the same in the base currency; how many of its refunds were declined; its oldest refund claim still claimed.
	 */
	public function lockForClaim( int $intentId, ?string $reconciledAt ): array;

	/**
	 * Locks a user's row for the refunds capped by the day, inside the caller's transaction: inserted the first time, found every later time, and held until the transaction ends.
	 *
	 * Two capped refunds of one user, of any orders, are claimed one after the other: the second
	 * waits here until the first's claim has committed.
	 *
	 * @since 0.2.0
	 *
	 * @throws \LogicException Outside a transaction.
	 *
	 * @param int $userId The user.
	 */
	public function lockActor( int $userId ): void;

	/**
	 * Adds up what a user asked of the gateway in the last 24 hours, by the database clock, in the base currency: the base share of every claim of theirs but a declined one.
	 *
	 * Read inside the caller's transaction, after the user's lock row: every claim of the user's
	 * that committed before the lock was taken is counted.
	 *
	 * @since 0.2.0
	 *
	 * @throws \LogicException Outside a transaction.
	 *
	 * @param int      $userId The user.
	 * @param Currency $base   The base currency.
	 * @return Money The sum, in the base currency; zero when the user asked nothing.
	 */
	public function askedToday( int $userId, Currency $base ): Money;

	/**
	 * Claims a refund before the gateway is asked for it, with what was asked: the claim, then its lines, inside the caller's transaction, which holds the intent's lock and commits before the gateway is asked.
	 *
	 * The claim's unique uuid refuses a second claim of the same refund, and its unique key hash a
	 * second claim with the same idempotency key.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 The request and the caller's key.
	 *
	 * @throws \LogicException Outside a transaction.
	 *
	 * @param RefundPlan      $plan      The refund.
	 * @param RefundRequest   $request   What was asked: the lines, the shipping, the reason and the note.
	 * @param string          $actorType `user` or `system`.
	 * @param int|null        $actorId   The user who asks for it, or null.
	 * @param RequestKey|null $key       The caller's key; null for a refund asked without one.
	 * @return bool True when this request claimed the refund; false when the refund, or the key, was claimed before.
	 */
	public function claim( RefundPlan $plan, RefundRequest $request, string $actorType, ?int $actorId, ?RequestKey $key ): bool;

	/**
	 * Reads the claim a caller's idempotency key names, without a lock.
	 *
	 * @since 0.2.0
	 *
	 * @param string $keyHash The key's hash, scoped to the user who sent it.
	 * @return RefundClaim|null The claim, with the fingerprint of the request it was made for; null when the key names none.
	 */
	public function findClaimByKey( string $keyHash ): ?RefundClaim;

	/**
	 * Reads a refund's claim, without a lock.
	 *
	 * @since 0.1.0
	 *
	 * @param string $uuid The refund's uuid.
	 * @return RefundClaim|null The claim, or null when the refund was never claimed.
	 */
	public function findClaim( string $uuid ): ?RefundClaim;

	/**
	 * Reads a refund's claim with what it asked, so the refund can be worked out again: the intent, the order, the units of each line, the shipping, the reason and the base share; without a lock.
	 *
	 * @since 0.2.0
	 *
	 * @param string $uuid The refund's uuid.
	 * @return ClaimRequest|null The claim, with its lines in the order of their identifiers; null when the refund was never claimed.
	 */
	public function claimRequest( string $uuid ): ?ClaimRequest;

	/**
	 * Tells whether the ledger holds a result of the provider object's operation, whatever its outcome, without a lock.
	 *
	 * @since 0.2.0
	 *
	 * @param GatewayResult $result The result, naming its provider object.
	 * @return bool True when a row holds a result of the provider object's operation, in any outcome; false when none
	 *              does, or the result names no object.
	 */
	public function holdsResult( GatewayResult $result ): bool;

	/**
	 * Notes how a person settled a refund's claim, inside the caller's transaction, once the claim has ended and only the first time: what they stated, what the gateway said of the refund when it was asked once more, who they are and why.
	 *
	 * @since 0.2.0
	 *
	 * @throws \LogicException Outside a transaction.
	 *
	 * @param string   $uuid           The refund's uuid.
	 * @param string   $statement      What the person stated: refunded or not_refunded.
	 * @param string   $gatewayReading What the gateway said of the refund.
	 * @param int|null $settledBy      The user who settled it, or null.
	 * @param string   $note           Why, as the person wrote it.
	 * @return bool True when it was noted here; false when the claim is still claimed, or a person settled it before.
	 */
	public function noteSettlement( string $uuid, string $statement, string $gatewayReading, ?int $settledBy, string $note ): bool;

	/**
	 * Ends a refund's claim with the ledger row that ended it, inside the caller's transaction, only while it is still claimed.
	 *
	 * A claim names only a row its own refund's answer wrote, never one the gateway answered it with
	 * that another refund's answer wrote: such a claim ends with none.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException Outside a transaction, or for a state that ends nothing.
	 *
	 * @param string     $uuid          The refund's uuid.
	 * @param ClaimState $to            Recorded, declined or unreconciled.
	 * @param int|null   $transactionId The ledger row this refund's answer wrote, or null for none.
	 * @return bool True when the claim ended here; false when it had ended already.
	 */
	public function settleClaim( string $uuid, ClaimState $to, ?int $transactionId ): bool;

	/**
	 * Adds up what earlier refunds returned of some order lines.
	 *
	 * @since 0.1.0
	 *
	 * @param int[]    $lineIds      The order lines.
	 * @param Currency $currency     The order's currency.
	 * @param Currency $baseCurrency The base currency.
	 * @return array<int, Share> What was returned, by order line id; a line nothing was returned of is absent.
	 *
	 * @phpstan-param list<int> $lineIds
	 */
	public function returnedOfLines( array $lineIds, Currency $currency, Currency $baseCurrency ): array;

	/**
	 * Adds up what earlier refunds returned of some tax components.
	 *
	 * @since 0.1.0
	 *
	 * @param int[]    $componentIds The order's tax components.
	 * @param Currency $currency     The order's currency.
	 * @param Currency $baseCurrency The base currency.
	 * @return array<int, Share> What was returned, by component id; a component nothing was returned of is absent.
	 *
	 * @phpstan-param list<int> $componentIds
	 */
	public function returnedOfComponents( array $componentIds, Currency $currency, Currency $baseCurrency ): array;

	/**
	 * Adds up the shipping earlier refunds of an order returned, before tax.
	 *
	 * @since 0.1.0
	 *
	 * @param int      $orderId      The order.
	 * @param Currency $currency     The order's currency.
	 * @param Currency $baseCurrency The base currency.
	 * @return array{0: Money, 1: Money} The shipping returned, and the same in the base currency.
	 */
	public function returnedShipping( int $orderId, Currency $currency, Currency $baseCurrency ): array;

	/**
	 * Inserts the refund's document, while the shipping the order's refunds returned, with this one's, stays within the stored shipping.
	 *
	 * @since 0.1.0
	 *
	 * @param RefundPlan $plan          The refund.
	 * @param int        $transactionId The ledger row of the gateway's refund.
	 * @param string     $actorType     `user` or `system`.
	 * @param int|null   $actorId       The user who refunds, or null.
	 * @return int|null The refund's id; null when the shipping's cap refused it.
	 */
	public function insertRefund( RefundPlan $plan, int $transactionId, string $actorType, ?int $actorId ): ?int;

	/**
	 * Inserts the refund's lines, in one statement; sends nothing when it returns no line.
	 *
	 * @since 0.1.0
	 *
	 * @param int        $refundId The refund.
	 * @param RefundPlan $plan     The refund.
	 */
	public function insertLines( int $refundId, RefundPlan $plan ): void;

	/**
	 * Inserts the shares of the tax components the refund returns, in one statement, each while it fits what is left of its component; sends nothing when there is none.
	 *
	 * @since 0.1.0
	 *
	 * @param int        $refundId The refund, whose lines are written.
	 * @param RefundPlan $plan     The refund.
	 * @return bool True when every share was written; false when a component's cap refused any.
	 */
	public function insertComponents( int $refundId, RefundPlan $plan ): bool;

	/**
	 * Reads the refund document that states a ledger row.
	 *
	 * @since 0.1.0
	 *
	 * @param int $transactionId The ledger row.
	 * @return Refund|null The refund, or null when the row has no document.
	 */
	public function findByTransaction( int $transactionId ): ?Refund;
}
