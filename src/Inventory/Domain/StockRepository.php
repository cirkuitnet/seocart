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
 * lock, refuse to run outside a transaction. An item is one variant at one location: the
 * `stock_items` row of that pair.
 *
 * The one-lock rule. Every statement that writes `stock_holds`, `stock_allocations` or
 * `stock_ledger` rows of an item runs after a statement, in the same transaction, that holds the
 * exclusive lock on the item's row: a hold's claim and an allocation's claim for their holder;
 * the upsert createItemAt(), which takes the lock whether it inserts the row or finds it, before
 * an adjustment; and the item lock (lockItem(), and the first statement of every reclaim) for
 * everyone else. So for one item, holders, reclaimers, the sweep, releases, adjustments and the
 * delete serialise on one row lock, always taking the item before its holds; across items every
 * path takes them in ascending order of the variant, then the location; the ledger is appended
 * after the item's row, and allocations are written after it too. The reclaim takes the item's
 * lock explicitly rather than relying on the lock a refused claim leaves: under READ COMMITTED a
 * row that did not match is unlocked again. A variant's policy row, where a path locks it, is
 * locked before any of its items.
 *
 * One bounded exception to the order: an allocation whose hold is gone claims a variant's units
 * afresh where routing puts them, and that location may sort before one whose row the same
 * allocation has locked already. It can happen only when a hold expired and was reclaimed while
 * the order was being paid for, every claim still carries its invariant, and a deadlock it causes
 * is rolled back whole and run again by the deadlock retry policy, as any other is.
 *
 * The rule guarantees correctness, not freedom from deadlocks, so the service runs every unit of
 * work that writes these tables at READ COMMITTED. Under REPEATABLE READ, InnoDB also takes gap
 * locks on the `stock_holds` indexes: the give-back's subqueries and the delete lock the gaps
 * around the claimed token in `reclaim_token`, where every new hold row and every later token is
 * written, and the claim of expired rows locks the range of the item's `item_expires` entries up
 * to the next item's. Those gaps reach other items, so a hold that reclaimed one item made a
 * holder of another item wait, and the two could deadlock. At READ COMMITTED only the matched
 * rows are locked. Should a deadlock still come, from elsewhere, InnoDB rolls one unit of work
 * back whole and the deadlock retry policy runs it again: no hold is lost or doubled, and `held`
 * still equals its rows.
 *
 * The ledger is append-only: no method updates or deletes a ledger entry.
 *
 * @since 0.1.0
 */
interface StockRepository {

	/**
	 * Reads each variant's policy and the store's default location, and computes the expiry of a hold taken now. Locks nothing.
	 *
	 * This read decides only whether a line takes part in a hold, and where a line that names no
	 * location is held. Whether there is stock is decided by claim().
	 *
	 * @since 0.1.0
	 * @since 0.2.0 The policies and the default location.
	 *
	 * @param int[] $variantIds The variants.
	 * @param int   $ttlSeconds How long the hold lasts.
	 * @return array{policies: array<int, StockPolicy>, default_location_id: int|null, expires_at: string|null} The policy of
	 *         each variant that has one, by variant id; the default location, or null when the store has none; and the
	 *         database's UTC time plus the TTL, `Y-m-d H:i:s`. The last two are null when no variant has a policy.
	 */
	public function configuration( array $variantIds, int $ttlSeconds ): array;

	/**
	 * Adds a quantity to an item's `held`, if that many units are available there.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 The location.
	 *
	 * @throws \LogicException When called outside a transaction.
	 *
	 * @param int $variantId  The item's variant.
	 * @param int $locationId The item's location.
	 * @param int $quantity   The units, 1 or more.
	 * @return bool True when the claim matched: the units are held and the item is locked. False when too few are
	 *              available, or the item has no row at the location.
	 *
	 * @phpstan-impure
	 */
	public function claim( int $variantId, int $locationId, int $quantity ): bool;

	/**
	 * Inserts a hold's rows, in the order given, after every one's claim added its units to its item's `held`.
	 *
	 * The rows go in together, in as few statements as a bounded statement allows. Each row's item
	 * is locked already, by its claim, so the one-lock rule holds.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException When called outside a transaction.
	 *
	 * @param HoldLine[] $lines     The lines claimed, one per item, each naming its location; none sends nothing.
	 * @param string     $holdGroup The hold's id.
	 * @param string     $expiresAt When the hold expires, UTC, `Y-m-d H:i:s`.
	 * @param int|null   $cartId    The cart, when there is one.
	 * @param int|null   $orderId   The order, when there is one.
	 *
	 * @phpstan-param list<HoldLine> $lines
	 */
	public function insertHolds( array $lines, string $holdGroup, string $expiresAt, ?int $cartId, ?int $orderId ): void;

	/**
	 * Reads one hold's rows, item by item. Locks nothing: it chooses what to lock.
	 *
	 * A plain read before the locks, inside a unit of work at READ COMMITTED: each statement reads
	 * the rows committed when it is sent, so there is no older snapshot for the locking statements
	 * that follow to contradict, and what it saw is decided again by each of them, under its
	 * item's lock.
	 *
	 * @since 0.2.0
	 *
	 * @param string $holdGroup The hold.
	 * @return list<array{variant_id: int, location_id: int, quantity: int}> The units of each item it holds, ascending by variant then location.
	 */
	public function holdRows( string $holdGroup ): array;

	/**
	 * Turns some or all of a hold's units of one item into allocated units, when the hold still has its row of the item.
	 *
	 * The conversion moves the units from `held` to `allocated` and takes the item's lock; then,
	 * under that lock, the hold's row of the item, holding exactly the units the caller read, is
	 * deleted when every unit converted, or reduced by the units converted when some stay held.
	 * Either proves the units were the hold's. When the hold has no such row, because it expired
	 * and was reclaimed or was released, the conversion is undone in the same transaction and
	 * nothing has changed. Two statements, or three when the row is gone; one when `held` cannot
	 * cover the units, which the hold's row alone would.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 The location, and the part of a row.
	 *
	 * @throws \LogicException When called outside a transaction.
	 *
	 * @param string $holdGroup   The hold.
	 * @param int    $variantId   The item's variant.
	 * @param int    $locationId  The item's location.
	 * @param int    $quantity    The units to convert, 1 or more and at most $rowQuantity.
	 * @param int    $rowQuantity The units the hold's row holds, as read.
	 * @return bool True when the units are allocated and the hold's row is gone or holds only the rest; false when nothing changed.
	 *
	 * @phpstan-impure
	 */
	public function convertHold( string $holdGroup, int $variantId, int $locationId, int $quantity, int $rowQuantity ): bool;

	/**
	 * Adds a quantity to an item's `allocated`, if that many units are available there: an allocation claimed without a hold.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 The location.
	 *
	 * @throws \LogicException When called outside a transaction.
	 *
	 * @param int $variantId  The item's variant.
	 * @param int $locationId The item's location.
	 * @param int $quantity   The units, 1 or more.
	 * @return bool True when the claim matched: the units are allocated and the item is locked.
	 *
	 * @phpstan-impure
	 */
	public function claimAllocation( int $variantId, int $locationId, int $quantity ): bool;

	/**
	 * Inserts an order's open allocation rows, in the order given, after every one's units were added to its item's `allocated`.
	 *
	 * The rows go in together, in as few statements as a bounded statement allows. An order line
	 * that already has its row of the variant at the location is refused by the table's unique
	 * key, and the statement then writes none of its rows.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException When called outside a transaction.
	 *
	 * @param int          $orderId     The order the units are promised to.
	 * @param Allocation[] $allocations The order lines, each with its variant, location and units; none sends nothing.
	 *
	 * @phpstan-param list<Allocation> $allocations
	 */
	public function insertAllocations( int $orderId, array $allocations ): void;

	/**
	 * Takes the item's lock.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 The location.
	 *
	 * @throws \LogicException When called outside a transaction.
	 *
	 * @param int $variantId  The item's variant.
	 * @param int $locationId The item's location.
	 * @return bool True when the item exists and is now locked.
	 *
	 * @phpstan-impure
	 */
	public function lockItem( int $variantId, int $locationId ): bool;

	/**
	 * Gives back the item's expired hold rows that no other reclaim has claimed.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 The location.
	 *
	 * @throws \LogicException When called outside a transaction.
	 *
	 * @param int    $variantId  The item's variant.
	 * @param int    $locationId The item's location.
	 * @param string $token      A fresh token for this reclaim.
	 * @return ReclaimedRows What was given back.
	 *
	 * @phpstan-impure
	 */
	public function reclaimExpired( int $variantId, int $locationId, string $token ): ReclaimedRows;

	/**
	 * Gives back the item's rows of one hold, expired or not.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 The location.
	 *
	 * @throws \LogicException When called outside a transaction.
	 *
	 * @param int    $variantId  The item's variant.
	 * @param int    $locationId The item's location.
	 * @param string $holdGroup  The hold.
	 * @param string $token      A fresh token for this reclaim.
	 * @return ReclaimedRows What was given back.
	 *
	 * @phpstan-impure
	 */
	public function reclaimGroup( int $variantId, int $locationId, string $holdGroup, string $token ): ReclaimedRows;

	/**
	 * Gives back every hold row of the item, expired or not.
	 *
	 * @since 0.2.0
	 *
	 * @throws \LogicException When called outside a transaction.
	 *
	 * @param int    $variantId  The item's variant.
	 * @param int    $locationId The item's location.
	 * @param string $token      A fresh token for this reclaim.
	 * @return ReclaimedRows What was given back.
	 *
	 * @phpstan-impure
	 */
	public function reclaimItem( int $variantId, int $locationId, string $token ): ReclaimedRows;

	/**
	 * Lists the locations each of some variants has an item at. Locks nothing: it chooses what to lock.
	 *
	 * @since 0.2.0
	 *
	 * @param int[] $variantIds The variants.
	 * @return array<int, list<int>> The locations of each variant that has an item, ascending, by variant id, ascending.
	 */
	public function itemLocations( array $variantIds ): array;

	/**
	 * Locks every item of a variant, in ascending location order, and lists their locations.
	 *
	 * @since 0.2.0
	 *
	 * @throws \LogicException When called outside a transaction.
	 *
	 * @param int $variantId The variant.
	 * @return list<int> The locations of its items, ascending.
	 *
	 * @phpstan-impure
	 */
	public function lockItemsOf( int $variantId ): array;

	/**
	 * Lists items with expired, unclaimed hold rows. Locks nothing: it chooses work.
	 *
	 * @since 0.2.0
	 *
	 * @param int $afterVariantId  Only items after this variant and location: where the previous page ended, or 0 and 0.
	 * @param int $afterLocationId See $afterVariantId.
	 * @param int $limit           The most items to list.
	 * @return list<array{0: int, 1: int}> Each item's variant and location, ascending.
	 */
	public function expiredItems( int $afterVariantId, int $afterLocationId, int $limit ): array;

	/**
	 * Lists the variant ids that have a stock policy (doctor's reverse cross-module line: stock
	 * whose variant is gone, read by Catalog through StockService). Locks nothing: it chooses work.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 The variants with a policy: one row per variant, wherever its items are.
	 *
	 * @param int $after Only variants with a higher id: where the previous page ended, or 0.
	 * @param int $limit The most variants to list.
	 * @return list<int> The variant ids, ascending.
	 */
	public function itemVariantIds( int $after, int $limit ): array;

	/**
	 * Tells whether a variant has a policy and a location exists, with a shared lock on both that keeps them until the commit.
	 *
	 * The lock keeps the policy and the location from being deleted while an adjustment creates
	 * and changes the variant's item there.
	 *
	 * @since 0.2.0
	 *
	 * @throws \LogicException When called outside a transaction.
	 *
	 * @param int $variantId  The variant.
	 * @param int $locationId The location.
	 * @return array{policy: bool, location: bool} Whether each exists.
	 *
	 * @phpstan-impure
	 */
	public function lockAdjustable( int $variantId, int $locationId ): array;

	/**
	 * Creates an item at zero when it does not exist, and takes its lock whether it was created or found.
	 *
	 * @since 0.2.0
	 *
	 * @throws \LogicException When called outside a transaction.
	 *
	 * @param int $variantId  The item's variant, which has a policy.
	 * @param int $locationId The item's location, which exists.
	 *
	 * @phpstan-impure
	 */
	public function createItemAt( int $variantId, int $locationId ): void;

	/**
	 * Recreates an item its hold rows outlived, with nothing on hand or allocated and `held` the sum of its rows, when it does not exist.
	 *
	 * The item is created at zero by the upsert that takes its lock, and only when that upsert
	 * created it is `held` set from the hold rows, under the lock: an item found there is left as
	 * it is. No path writes an item's hold rows without its lock, so none can change while it is
	 * recreated.
	 *
	 * @since 0.2.0
	 *
	 * @throws \LogicException When called outside a transaction.
	 *
	 * @param int $variantId  The item's variant.
	 * @param int $locationId The item's location.
	 * @return bool True when the item was created and is locked; false when it existed.
	 *
	 * @phpstan-impure
	 */
	public function restoreItem( int $variantId, int $locationId ): bool;

	/**
	 * Adds a delta to an item's on_hand, if the result is not negative and, when given, on_hand is what the caller read.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 The location.
	 *
	 * @throws \LogicException When called outside a transaction.
	 *
	 * @param int      $variantId      The item's variant.
	 * @param int      $locationId     The item's location.
	 * @param int      $delta          The change, not 0.
	 * @param int|null $expectedOnHand The on_hand the caller read, or null to apply the delta to whatever it is.
	 * @return bool True when the update matched; the item is then locked.
	 *
	 * @phpstan-impure
	 */
	public function adjust( int $variantId, int $locationId, int $delta, ?int $expectedOnHand ): bool;

	/**
	 * Appends a ledger entry.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 The location.
	 *
	 * @throws \LogicException When called outside a transaction.
	 *
	 * @param int          $variantId     The item's variant.
	 * @param int          $locationId    The item's location.
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
	public function appendLedger( int $variantId, int $locationId, int $delta, int $onHandAfter, LedgerReason $reason, string $actorType, ?int $actorId, ?string $correlationId ): int;

	/**
	 * Reads variants' levels at the default location, with their policies. A plain read: it locks nothing, and inside a transaction it may see that transaction's snapshot.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 At the default location.
	 *
	 * @param int[] $variantIds The variants.
	 * @return array<int, StockLevel> The levels of the variants that have a policy and an item at the default location, by variant id.
	 */
	public function levels( array $variantIds ): array;

	/**
	 * Reads variants' levels at every location they have an item at, with their policies. A plain read.
	 *
	 * @since 0.2.0
	 *
	 * @param int[] $variantIds The variants.
	 * @return array<int, list<StockLevel>> The levels of each variant that has a policy, ascending by location, by variant id.
	 */
	public function levelsAt( array $variantIds ): array;

	/**
	 * Reads what each of some locations has of some variants, with each variant's policy. A plain read, which decides nothing: routing plans from it, and every claim re-decides under its row's lock.
	 *
	 * @since 0.2.0
	 *
	 * @param int[] $variantIds  The variants.
	 * @param int[] $locationIds The locations; none reads the policies alone.
	 * @return array{policies: array<int, StockPolicy>, available: array<int, array<int, int>>} The policy of each variant
	 *         that has one, by variant id; and the units available of each variant at each location that has a row of
	 *         it, by location id then variant id, negative when an adjustment counted fewer units than are promised.
	 */
	public function availability( array $variantIds, array $locationIds ): array;

	/**
	 * Reads each variant's units available, summed over some locations and at the one that has most, each location at no fewer than zero, with its policy. A plain read.
	 *
	 * @since 0.2.0
	 *
	 * @param int[] $variantIds  The variants.
	 * @param int[] $locationIds The locations; none counts nothing.
	 * @return array<int, array{policy: StockPolicy, total: int, largest: int}> The figures of each variant that has a policy, by variant id.
	 */
	public function saleFigures( array $variantIds, array $locationIds ): array;

	/**
	 * Reads one item's level with a locking read: its latest committed values, with the item locked.
	 *
	 * A plain read inside a transaction can return the transaction's snapshot, older than a change
	 * another request committed since. Every read that feeds a ledger entry, an event or an error
	 * after a statement on the item uses this one. The item's policy is read with it, and not
	 * locked.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 The location.
	 *
	 * @throws \LogicException When called outside a transaction.
	 *
	 * @param int $variantId  The item's variant.
	 * @param int $locationId The item's location.
	 * @return StockLevel|null The level, or null when the item does not exist.
	 *
	 * @phpstan-impure
	 */
	public function lockedLevel( int $variantId, int $locationId ): ?StockLevel;

	/**
	 * Gives variants their policy at the defaults and their item at a location, at zero, leaving any that exist unchanged.
	 *
	 * A variant is reported when this call inserted its policy row or its row at the location: a
	 * repair that recreates a missing row under a policy that is still there reports it. A variant
	 * whose two rows were both there already, whoever wrote them, is not reported.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 The policy, the location, and the variants created.
	 *
	 * @throws \LogicException When called outside a transaction.
	 *
	 * @param int[] $variantIds The variants, ascending.
	 * @param int   $locationId The location of the items: the store's default location.
	 * @return list<int> The variants whose policy row or row at the location this call inserted, ascending.
	 *
	 * @phpstan-impure
	 */
	public function createItems( array $variantIds, int $locationId ): array;

	/**
	 * Tells whether a variant has an open allocation at any location, with a locking read.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 At any location.
	 *
	 * @throws \LogicException When called outside a transaction.
	 *
	 * @param int $variantId The variant.
	 * @return bool True when one is open.
	 *
	 * @phpstan-impure
	 */
	public function hasOpenAllocation( int $variantId ): bool;

	/**
	 * Takes the lock of a variant's policy.
	 *
	 * @since 0.2.0
	 *
	 * @throws \LogicException When called outside a transaction.
	 *
	 * @param int $variantId The variant.
	 * @return bool True when the variant has a policy, now locked.
	 *
	 * @phpstan-impure
	 */
	public function lockPolicy( int $variantId ): bool;

	/**
	 * Deletes an item. Call it under the item's lock, once its holds are given back.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 The location.
	 *
	 * @throws \LogicException When called outside a transaction.
	 *
	 * @param int $variantId  The item's variant.
	 * @param int $locationId The item's location.
	 * @return bool True when the row was deleted.
	 *
	 * @phpstan-impure
	 */
	public function deleteItem( int $variantId, int $locationId ): bool;

	/**
	 * Deletes a variant's policy. Call it under the policy's lock, once its items are gone.
	 *
	 * @since 0.2.0
	 *
	 * @throws \LogicException When called outside a transaction.
	 *
	 * @param int $variantId The variant.
	 * @return bool True when the row was deleted.
	 *
	 * @phpstan-impure
	 */
	public function deletePolicy( int $variantId ): bool;
}
