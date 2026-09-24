<?php
/**
 * StockRepository: the port through which the stock service reads and changes stock
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Inventory\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Every statement group the stock service sequences, named by what it does.
 *
 * Owns one fact: which statements change stock, and the invariant each one carries in its
 * WHERE clause. There is no load-modify-save: every contended change is one conditional UPDATE,
 * and a method answers whether it matched. Methods that change stock, or that must read under a
 * lock, refuse to run outside a transaction.
 *
 * The one-lock rule. Every statement that writes `stock_holds` rows of variant v runs after a
 * statement, in the same transaction, that holds the exclusive lock on `stock_items` row v: the
 * hold's claim for the holder, and the item lock (lockItem(), and the first statement of every
 * reclaim) for everyone else. So for one item, holders, reclaimers, the sweep, releases and the
 * delete serialise on one row lock, always taking the item before its holds; across items every
 * path takes them in ascending variant order; the ledger is appended after the item's row, and
 * allocations are written after it too. The reclaim takes the item's lock explicitly rather than
 * relying on the lock a refused claim leaves: under READ COMMITTED a row that did not match is
 * unlocked again.
 *
 * The rule guarantees correctness, not freedom from deadlocks. Under REPEATABLE READ, InnoDB also
 * takes gap locks on the `stock_holds` indexes: the claim of expired rows locks the range of the
 * item's `variant_expires` entries up to the next item's, and the give-back's subqueries lock
 * around the claimed token in `reclaim_token`, where a new hold row is inserted. Those gaps reach
 * other items, so a hold that reclaimed one item can make a holder of another item wait, and the
 * two can deadlock. InnoDB then rolls one unit of work back whole, and the deadlock retry policy
 * runs it again: no hold is lost or doubled, and `held` still equals its rows.
 *
 * The ledger is append-only: no method updates or deletes a ledger entry.
 *
 * @since 0.1.0
 */
interface StockRepository {

	/**
	 * Reads whether each item is tracked, and computes the expiry of a hold taken now. Locks nothing.
	 *
	 * This read decides only whether a line takes part in a hold. Whether there is stock is
	 * decided by claim().
	 *
	 * @since 0.1.0
	 *
	 * @param int[] $variantIds The items.
	 * @param int   $ttlSeconds How long the hold lasts.
	 * @return array{track: array<int, bool>, expires_at: string|null} Tracking by variant id, for the items
	 *         that exist; and the database's UTC time plus the TTL, `Y-m-d H:i:s`, or null when no item exists.
	 */
	public function configuration( array $variantIds, int $ttlSeconds ): array;

	/**
	 * Adds a quantity to a tracked item's `held`, if that many units are available.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException When called outside a transaction.
	 *
	 * @param int $variantId The item.
	 * @param int $quantity  The units, 1 or more.
	 * @return bool True when the claim matched: the units are held and the item is locked.
	 *
	 * @phpstan-impure
	 */
	public function claim( int $variantId, int $quantity ): bool;

	/**
	 * Inserts a hold row, after its claim.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException When called outside a transaction.
	 *
	 * @param int      $variantId The item.
	 * @param int      $quantity  The units the claim added to `held`.
	 * @param string   $holdGroup The hold's id.
	 * @param string   $expiresAt When the hold expires, UTC, `Y-m-d H:i:s`.
	 * @param int|null $cartId    The cart, when there is one.
	 * @param int|null $orderId   The order, when there is one.
	 * @return int The row's id.
	 *
	 * @phpstan-impure
	 */
	public function insertHold( int $variantId, int $quantity, string $holdGroup, string $expiresAt, ?int $cartId, ?int $orderId ): int;

	/**
	 * Takes the item's lock.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException When called outside a transaction.
	 *
	 * @param int $variantId The item.
	 * @return bool True when the item exists and is now locked.
	 *
	 * @phpstan-impure
	 */
	public function lockItem( int $variantId ): bool;

	/**
	 * Gives back the item's expired hold rows that no other reclaim has claimed.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException When called outside a transaction.
	 *
	 * @param int    $variantId The item.
	 * @param string $token     A fresh token for this reclaim.
	 * @return ReclaimedRows What was given back.
	 *
	 * @phpstan-impure
	 */
	public function reclaimExpired( int $variantId, string $token ): ReclaimedRows;

	/**
	 * Gives back the item's rows of one hold, expired or not.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException When called outside a transaction.
	 *
	 * @param int    $variantId The item.
	 * @param string $holdGroup The hold.
	 * @param string $token     A fresh token for this reclaim.
	 * @return ReclaimedRows What was given back.
	 *
	 * @phpstan-impure
	 */
	public function reclaimGroup( int $variantId, string $holdGroup, string $token ): ReclaimedRows;

	/**
	 * Gives back every hold row of the item, expired or not.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException When called outside a transaction.
	 *
	 * @param int    $variantId The item.
	 * @param string $token     A fresh token for this reclaim.
	 * @return ReclaimedRows What was given back.
	 *
	 * @phpstan-impure
	 */
	public function reclaimVariant( int $variantId, string $token ): ReclaimedRows;

	/**
	 * Lists the items one hold has rows for. Locks nothing: it chooses what to lock.
	 *
	 * @since 0.1.0
	 *
	 * @param string $holdGroup The hold.
	 * @return list<int> The variant ids, ascending.
	 */
	public function groupVariants( string $holdGroup ): array;

	/**
	 * Lists items with expired, unclaimed hold rows. Locks nothing: it chooses work.
	 *
	 * @since 0.1.0
	 *
	 * @param int $after Only items with a higher variant id: where the previous page ended, or 0.
	 * @param int $limit The most items to list.
	 * @return list<int> The variant ids, ascending.
	 */
	public function expiredVariants( int $after, int $limit ): array;

	/**
	 * Adds a delta to an item's on_hand, if the result is not negative and, when given, on_hand is what the caller read.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException When called outside a transaction.
	 *
	 * @param int      $variantId      The item.
	 * @param int      $delta          The change, not 0.
	 * @param int|null $expectedOnHand The on_hand the caller read, or null to apply the delta to whatever it is.
	 * @return bool True when the update matched; the item is then locked.
	 *
	 * @phpstan-impure
	 */
	public function adjust( int $variantId, int $delta, ?int $expectedOnHand ): bool;

	/**
	 * Appends a ledger entry.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException When called outside a transaction.
	 *
	 * @param int          $variantId     The item.
	 * @param int          $delta         The change of on_hand.
	 * @param int          $onHandAfter   on_hand once the change was applied.
	 * @param LedgerReason $reason        Why.
	 * @param string       $actorType     `user` or `system`.
	 * @param int|null     $actorId       The user on whose authority it happened, or null.
	 * @param string|null  $correlationId The request's correlation id.
	 * @return int The entry's id.
	 *
	 * @phpstan-impure
	 */
	public function appendLedger( int $variantId, int $delta, int $onHandAfter, LedgerReason $reason, string $actorType, ?int $actorId, ?string $correlationId ): int;

	/**
	 * Reads items' levels. A plain read: it locks nothing, and inside a transaction it may see that transaction's snapshot.
	 *
	 * @since 0.1.0
	 *
	 * @param int[] $variantIds The items.
	 * @return array<int, StockLevel> The levels of the items that exist, by variant id.
	 */
	public function levels( array $variantIds ): array;

	/**
	 * Reads one item's level with a locking read: its latest committed values, with the item locked.
	 *
	 * A plain read inside a transaction can return the transaction's snapshot, older than a change
	 * another request committed since. Every read that feeds a ledger entry, an event or an error
	 * after a statement on the item uses this one.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException When called outside a transaction.
	 *
	 * @param int $variantId The item.
	 * @return StockLevel|null The level, or null when the item does not exist.
	 *
	 * @phpstan-impure
	 */
	public function lockedLevel( int $variantId ): ?StockLevel;

	/**
	 * Creates items at zero with the declared defaults, leaving any that exist unchanged.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException When called outside a transaction.
	 *
	 * @param int[] $variantIds The items, ascending.
	 */
	public function createItems( array $variantIds ): void;

	/**
	 * Tells whether an item has an open allocation, with a locking read. Call it under the item's lock.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException When called outside a transaction.
	 *
	 * @param int $variantId The item.
	 * @return bool True when one is open.
	 *
	 * @phpstan-impure
	 */
	public function hasOpenAllocation( int $variantId ): bool;

	/**
	 * Deletes an item. Call it under the item's lock, once its holds are given back.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException When called outside a transaction.
	 *
	 * @param int $variantId The item.
	 * @return bool True when the row was deleted.
	 *
	 * @phpstan-impure
	 */
	public function deleteItem( int $variantId ): bool;
}
