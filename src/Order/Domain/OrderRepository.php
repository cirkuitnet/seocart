<?php
/**
 * OrderRepository: the statements of the order tables, named by what they do
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Order\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * The port the order service reaches the order tables through.
 *
 * Owns one fact: which statements exist against the order tables, and the invariant each one
 * carries. Every write belongs to a group that commits together, so each runs only inside the
 * caller's transaction. A status or payment change is one conditional update whose WHERE clause
 * holds the rule, and its boolean result says whether the rule held.
 *
 * A storefront names an order by its uuid, and nothing here finds an order by an integer id or
 * by its number. lock() takes the internal id because only the payment and order services call
 * it, inside a transaction that already knows the id, and never with a value from a request.
 * The reads for `doctor` decide nothing: the payment amounts and the lines' refunded quantities
 * are read a page at a time by id, after the last id seen, for comparing every row; the flagged
 * orders and the totals drift are read as the first orders they find, in id order.
 *
 * @since 0.1.0
 */
interface OrderRepository {

	/**
	 * Inserts the order row, in its initial status, with its totals copied from the document.
	 *
	 * @since 0.1.0
	 *
	 * @param NewOrder    $order               The document.
	 * @param string      $uuid                The order's public identifier.
	 * @param string      $orderNumber         Its number.
	 * @param OrderStatus $status              The status it starts in.
	 * @param int         $conversionContextId The frozen rate it is placed at.
	 * @param string      $accessKeyHash       The hash of its access key; the key expires AccessKeys::LIFETIME_SECONDS after now, by the database clock.
	 * @param string      $actorType           `user` or `system`.
	 * @param int|null    $actorId             The user who places it, or null for a visitor.
	 * @param string      $correlationId       The request's correlation id.
	 * @return int The order's id.
	 */
	public function insertOrder( NewOrder $order, string $uuid, string $orderNumber, OrderStatus $status, int $conversionContextId, string $accessKeyHash, string $actorType, ?int $actorId, string $correlationId ): int;

	/**
	 * Inserts the order's lines in one statement, then reads their ids back in one more.
	 *
	 * @since 0.1.0
	 *
	 * @param int      $orderId The order.
	 * @param NewOrder $order   The document.
	 * @return array<string, int> Each line's id, by its key in the document.
	 */
	public function insertLines( int $orderId, NewOrder $order ): array;

	/**
	 * Inserts every option of every line in one statement; sends nothing when there is none.
	 *
	 * @since 0.1.0
	 *
	 * @param NewOrder           $order   The document.
	 * @param array<string, int> $lineIds Each line's id, by its key.
	 */
	public function insertLineOptions( NewOrder $order, array $lineIds ): void;

	/**
	 * Inserts the order's adjustments in one statement, then reads their ids back in one more; sends nothing when there is none.
	 *
	 * @since 0.1.0
	 *
	 * @param int                $orderId The order.
	 * @param NewOrder           $order   The document.
	 * @param array<string, int> $lineIds Each line's id, by its key.
	 * @return list<int> Each adjustment's id, by its position in the document.
	 */
	public function insertAdjustments( int $orderId, NewOrder $order, array $lineIds ): array;

	/**
	 * Inserts every tax component of every line and adjustment in one statement; sends nothing when there is none.
	 *
	 * @since 0.1.0
	 *
	 * @param int                $orderId       The order.
	 * @param NewOrder           $order         The document.
	 * @param array<string, int> $lineIds       Each line's id, by its key.
	 * @param int[]              $adjustmentIds Each adjustment's id, by its position.
	 * @param int                $totalsVersion The totals version the components belong to.
	 *
	 * @phpstan-param list<int> $adjustmentIds
	 */
	public function insertTaxComponents( int $orderId, NewOrder $order, array $lineIds, array $adjustmentIds, int $totalsVersion ): void;

	/**
	 * Inserts the billing address, and the shipping address when there is one, in one statement.
	 *
	 * @since 0.1.0
	 *
	 * @param int      $orderId The order.
	 * @param NewOrder $order   The document.
	 */
	public function insertAddresses( int $orderId, NewOrder $order ): void;

	/**
	 * Inserts a totals snapshot as the order's current one.
	 *
	 * @since 0.1.0
	 *
	 * @param int      $orderId             The order.
	 * @param NewOrder $order               The document.
	 * @param int      $conversionContextId The frozen rate the snapshot was calculated at.
	 * @param int      $version             The snapshot's version.
	 * @return int The snapshot's id.
	 */
	public function insertTotals( int $orderId, NewOrder $order, int $conversionContextId, int $version ): int;

	/**
	 * Points the order at the totals snapshot its totals were copied from.
	 *
	 * @since 0.1.0
	 *
	 * @param int $orderId  The order.
	 * @param int $totalsId The snapshot.
	 * @return bool True when the order was found.
	 */
	public function setCurrentTotals( int $orderId, int $totalsId ): bool;

	/**
	 * Appends an order event: one change of one of the order's statuses.
	 *
	 * @since 0.1.0
	 *
	 * @param int      $orderId       The order.
	 * @param Machine  $machine       Which status changed.
	 * @param string   $from          The status before; empty for the order's first event.
	 * @param string   $to            The status after.
	 * @param string   $reason        Why, a lowercase snake_case word.
	 * @param string   $actorType     `user` or `system`.
	 * @param int|null $actorId       The user on whose authority, or null for a visitor.
	 * @param string   $correlationId The request's correlation id.
	 * @return int The event row's id.
	 */
	public function appendEvent( int $orderId, Machine $machine, string $from, string $to, string $reason, string $actorType, ?int $actorId, string $correlationId ): int;

	/**
	 * Appends an event of the order's payment that changes no status, naming what it records by its uuid: its from and to are the payment status the order has.
	 *
	 * Inside the caller's transaction, which has locked the order, so the status it copies is the
	 * order's as the transaction leaves it.
	 *
	 * @since 0.2.0
	 *
	 * @throws \LogicException Outside a transaction, or when no order has the id, which the caller's lock rules out.
	 *
	 * @param int      $orderId       The order.
	 * @param string   $reason        What happened, a lowercase snake_case word such as `refund_recorded`.
	 * @param string   $reference     The uuid of what it records, such as the refund's.
	 * @param string   $actorType     `user` or `system`.
	 * @param int|null $actorId       The user on whose authority, or null.
	 * @param string   $correlationId The request's correlation id.
	 * @return int The event row's id.
	 */
	public function appendAudit( int $orderId, string $reason, string $reference, string $actorType, ?int $actorId, string $correlationId ): int;

	/**
	 * Reads the order with a locking read: the transaction's lock of the order, and the values it decides from.
	 *
	 * @since 0.1.0
	 *
	 * @param int $orderId The order's internal id, never a value from a request.
	 * @return LockedOrder|null The order, or null when there is none.
	 */
	public function lock( int $orderId ): ?LockedOrder;

	/**
	 * Changes the order's status, when the registry allows it from the status the order is in.
	 *
	 * One conditional update: its WHERE clause lists the statuses the order may be in, and, for a
	 * status that claims payment, the settled payment statuses.
	 *
	 * @since 0.1.0
	 *
	 * @param int           $orderId                The order.
	 * @param OrderStatus   $to                     The status entered.
	 * @param OrderStatus[] $allowedFrom            The statuses it may be entered from; empty when none.
	 * @param bool          $requiresSettledPayment Whether the order's payment status must be settled.
	 * @return bool True when the order changed; false when the rule did not hold.
	 *
	 * @phpstan-param list<OrderStatus> $allowedFrom
	 */
	public function transition( int $orderId, OrderStatus $to, array $allowedFrom, bool $requiresSettledPayment ): bool;

	/**
	 * Adds a payment's amounts to the order's payment projection, and writes its payment status.
	 *
	 * One conditional update, which moves every amount by its delta, the amount due included, and
	 * refuses an order in other currencies and any change that would authorize or capture more than
	 * the grand total in either currency, refund more than was captured, or leave less than nothing
	 * due.
	 *
	 * @since 0.1.0
	 *
	 * @param int           $orderId The order.
	 * @param PaymentDelta  $delta   What the payment adds.
	 * @param PaymentStatus $status  The payment status the order has once the amounts are added.
	 * @return bool True when the order changed; false when a condition did not hold.
	 */
	public function recordPayment( int $orderId, PaymentDelta $delta, PaymentStatus $status ): bool;

	/**
	 * Flags the order as holding money a person must reconcile: a payment that did not match it.
	 *
	 * @since 0.1.0
	 *
	 * @param int $orderId The order.
	 * @return bool True when the order was found.
	 */
	public function markUnreconciled( int $orderId ): bool;

	/**
	 * Locks an order's row by its public identifier, inside the caller's transaction, and reads whether it holds money a person must reconcile.
	 *
	 * @since 0.2.0
	 *
	 * @throws \LogicException Outside a transaction.
	 *
	 * @param string $orderUuid The order's public identifier.
	 * @return array{id: int, has_unreconciled_money: bool}|null The order's id and its flag; null when there is no such order.
	 */
	public function lockReconciliation( string $orderUuid ): ?array;

	/**
	 * Lowers the flag of an order holding money a person must reconcile, inside the caller's transaction, only while it is raised, keeping when, by the database clock, and why.
	 *
	 * The clearance is dated after the order's row last changed and after the newest money it
	 * clears, so a payment result the ledger applied to nothing before the clearance no longer
	 * holds the order's refunds back; one written after it does.
	 *
	 * @since 0.2.0
	 *
	 * @throws \LogicException Outside a transaction.
	 *
	 * @param string      $orderUuid   The order's public identifier.
	 * @param string      $note        Why the person says the money is reconciled.
	 * @param string|null $newestMoney When the newest of the order's payment results that moved no money was recorded, by the
	 *                                 database clock, read under the order's lock; null when it has none.
	 * @return bool True when the flag was lowered here; false when the order is not flagged, or there is none.
	 */
	public function clearUnreconciled( string $orderUuid, string $note, ?string $newestMoney ): bool;

	/**
	 * Reads whether an order holds money a person must reconcile, and when a person last cleared it, by its public identifier.
	 *
	 * @since 0.2.0
	 *
	 * @param string $orderUuid The order's public identifier.
	 * @return array{id: int, has_unreconciled_money: bool, money_reconciled_at: string|null}|null The order's id, its flag, and the
	 *         clearance, UTC to the microsecond, as the database clock wrote it, or null for never; null when there is no such order.
	 */
	public function reconciliation( string $orderUuid ): ?array;

	/**
	 * Reads a page of orders' payment amounts, in id order, for comparing them with the payments they were derived from.
	 *
	 * @since 0.1.0
	 *
	 * @param int $afterId The last id of the page before, or 0 for the first page.
	 * @param int $limit   The most orders to read.
	 * @return list<array{id: int, uuid: string, grand_total: int, age_seconds: int, authorized: int, paid: int, refunded: int, base_authorized: int, base_paid: int, base_refunded: int}> The orders, in minor
	 *         units, each with its grand total and how long ago it was placed, by the database's clock.
	 */
	public function paymentAmounts( int $afterId, int $limit ): array;

	/**
	 * Reads the first orders flagged as holding money a person must reconcile, in id order.
	 *
	 * @since 0.1.0
	 *
	 * @param int $limit The most orders to read.
	 * @return list<array{id: int, uuid: string}> The orders.
	 */
	public function unreconciled( int $limit ): array;

	/**
	 * Reads the first orders whose current totals snapshot is not the one they point at, in id order.
	 *
	 * @since 0.1.0
	 *
	 * @param int $limit The most orders to read.
	 * @return list<array{id: int, uuid: string, points_at: int|null, current: int|null}> The orders, with the snapshot each points at and the one marked current; null for none.
	 */
	public function currentTotalsDrift( int $limit ): array;

	/**
	 * Reads what a refund of an order allocates its shares from, by its public identifier: the order, the lines asked for, its shipping when asked for, and their tax components.
	 *
	 * Four reads whatever the number of lines, three without the shipping: the order with the
	 * version of its current totals; the lines asked for; the shipping adjustments added up, a
	 * free-shipping discount included; and the tax components of those lines and of the shipping,
	 * at that totals version. Every figure is the stored row's.
	 *
	 * @since 0.1.0
	 *
	 * @param string   $orderUuid    The order's public identifier.
	 * @param string[] $lineUuids    The public identifiers of the lines asked for.
	 * @param bool     $withShipping Whether the shipping is asked for.
	 * @return RefundableOrder|null The order, or null when there is none; a line uuid the order does not have is left out.
	 *
	 * @phpstan-param list<string> $lineUuids
	 */
	public function findRefundable( string $orderUuid, array $lineUuids, bool $withShipping ): ?RefundableOrder;

	/**
	 * Adds a refund's units to its lines' refunded quantities, in one conditional update.
	 *
	 * Each line moves only while it still has the refunded quantity the refund was worked out from,
	 * and never past the units sold; the caller learns whether every line moved.
	 *
	 * @since 0.1.0
	 *
	 * @param int             $orderId The order the lines belong to.
	 * @param RefundedUnits[] $units   The units returned, one entry per line; at least one.
	 * @return bool True when every line moved; false when any refused, which the caller's savepoint takes back.
	 *
	 * @phpstan-param non-empty-list<RefundedUnits> $units
	 */
	public function addRefundedQuantities( int $orderId, array $units ): bool;

	/**
	 * Reads a page of order lines' refunded quantities, in id order, for comparing them with the refunds' lines.
	 *
	 * @since 0.1.0
	 *
	 * @param int $afterId The last id of the page before, or 0 for the first page.
	 * @param int $limit   The most lines to read.
	 * @return list<array{id: int, uuid: string, refunded: int}> The lines, each with its public identifier.
	 */
	public function refundedQuantities( int $afterId, int $limit ): array;

	/**
	 * Reads what each of an order's lines sells: its id, its variant and its units, in variant order.
	 *
	 * @since 0.1.0
	 *
	 * @param int $orderId The order's internal id.
	 * @return list<array{orderLineId: int, variantId: int, quantity: int}> The lines, by variant and then by id; none for no such order.
	 */
	public function stockLines( int $orderId ): array;

	/**
	 * Reads an order's public identifier, status and payment status, and when a person last cleared its unreconciled money, by its internal id, without a lock.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 Reads the payment status and the clearance too.
	 *
	 * @param int $orderId The order's internal id, never a value from a request.
	 * @return array{uuid: string, status: OrderStatus, payment_status: PaymentStatus, money_reconciled_at: string|null}|null
	 *         The four, the clearance UTC to the microsecond or null for never; null when there is no such order.
	 */
	public function statusOf( int $orderId ): ?array;

	/**
	 * Reads a page of the orders still pending payment whose grand total is zero, placed at least a while ago by the database's clock, in id order: the orders with nothing to pay whose acceptance never came.
	 *
	 * @since 0.1.0
	 *
	 * @param int $olderThanSeconds How long ago they were placed, at least.
	 * @param int $afterId          The last id of the page before, or 0 for the first page.
	 * @param int $limit            The most orders to read.
	 * @return list<int> The orders' internal ids.
	 */
	public function pendingNothingDue( int $olderThanSeconds, int $afterId, int $limit ): array;

	/**
	 * Reads an order for showing it, by its public identifier: the order row, its lines, their options and its addresses.
	 *
	 * @since 0.1.0
	 *
	 * @param string $uuid The public identifier.
	 * @return OrderView|null The order, or null when there is none.
	 */
	public function findByUuid( string $uuid ): ?OrderView;

	/**
	 * Reads what an order's access check needs, by its public identifier.
	 *
	 * @since 0.1.0
	 *
	 * @param string $uuid The public identifier.
	 * @return OrderAccess|null The facts, or null when there is no such order.
	 */
	public function findForAccess( string $uuid ): ?OrderAccess;
}
