<?php
/**
 * TransferRepository: the port through which the transfer service reads and changes transfers and their lines
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Inventory\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Every statement group of the transfer documents, named by what it does.
 *
 * Owns one fact: how a transfer's row and its lines are written, and the rule each write keeps in
 * its WHERE clause. A transfer's row is its lock: every change of the document, and every write
 * of an allocation travelling with it, happens after a statement that holds the row's exclusive
 * lock, so its lines are written only under it. The statements that lock the row move it forward
 * in time by at least a microsecond, as the stock item lock does, so one affected row means the
 * state matched and none means it did not. A line has no time column, so a line statement whose
 * values do not change reports no row: the callers decide from the rows they read under the
 * transfer's lock, never from such a count, and the receipt's line statement always changes its
 * row. Methods that write, or that must read under a lock, refuse to run outside a transaction.
 *
 * @since 0.2.0
 */
interface TransferRepository {

	/**
	 * Creates a transfer's row, and returns its id.
	 *
	 * @since 0.2.0
	 *
	 * @throws \LogicException When called outside a transaction.
	 *
	 * @param string        $uuid                  Its public identifier.
	 * @param int           $sourceLocationId      The location the units leave.
	 * @param int           $destinationLocationId The location they arrive at.
	 * @param TransferKind  $kind                  Who starts it.
	 * @param TransferState $state                 Draft, requested, or in transit for a move sent as it is made.
	 * @param int|null      $orderId               The order that waits for it, or null.
	 * @param string|null   $note                  The merchant's note, or null.
	 * @param int|null      $createdBy             The user who creates it, or null for the system.
	 * @return int The id.
	 *
	 * @phpstan-impure
	 */
	public function insert( string $uuid, int $sourceLocationId, int $destinationLocationId, TransferKind $kind, TransferState $state, ?int $orderId, ?string $note, ?int $createdBy ): int;

	/**
	 * Adds lines to a transfer, in the order given, in as few statements as a bounded statement allows.
	 *
	 * @since 0.2.0
	 *
	 * @throws \LogicException When called outside a transaction.
	 *
	 * @param int   $transferId The transfer, locked or written in this unit of work.
	 * @param array $lines      Each line's variant, units, units received already and allocation; none sends nothing.
	 *
	 * @phpstan-param list<array{variant_id: int, quantity: int, received_quantity: int, allocation_id: int|null}> $lines
	 */
	public function insertLines( int $transferId, array $lines ): void;

	/**
	 * Reads a transfer with its lines, by its public identifier. A plain read: under the transfer's lock it is the latest committed.
	 *
	 * @since 0.2.0
	 *
	 * @param string $uuid The transfer's uuid.
	 * @return Transfer|null The transfer, its lines ascending by variant, or null when no transfer has the uuid.
	 */
	public function document( string $uuid ): ?Transfer;

	/**
	 * Locks a draft or requested transfer: an edit's first statement.
	 *
	 * @since 0.2.0
	 *
	 * @throws \LogicException When called outside a transaction.
	 *
	 * @param string $uuid The transfer's uuid.
	 * @return bool True when it is a draft or requested, and now locked.
	 *
	 * @phpstan-impure
	 */
	public function lockEditable( string $uuid ): bool;

	/**
	 * Moves a draft or requested transfer to in transit, with the time it was sent: a send's first statement, which locks it.
	 *
	 * @since 0.2.0
	 *
	 * @throws \LogicException When called outside a transaction.
	 *
	 * @param string $uuid The transfer's uuid.
	 * @return bool True when it was a draft or requested, and is now in transit and locked.
	 *
	 * @phpstan-impure
	 */
	public function markSent( string $uuid ): bool;

	/**
	 * Locks a transfer in transit: a receipt's first statement.
	 *
	 * @since 0.2.0
	 *
	 * @throws \LogicException When called outside a transaction.
	 *
	 * @param string $uuid The transfer's uuid.
	 * @return bool True when it is in transit, and now locked.
	 *
	 * @phpstan-impure
	 */
	public function lockReceivable( string $uuid ): bool;

	/**
	 * Locks a deactivation's move in transit by its id: the first statement of a page of the move.
	 *
	 * @since 0.2.0
	 *
	 * @throws \LogicException When called outside a transaction.
	 *
	 * @param int $transferId The transfer.
	 * @return bool True when it is a deactivation's move in transit, and now locked.
	 *
	 * @phpstan-impure
	 */
	public function lockMoving( int $transferId ): bool;

	/**
	 * Reads what a page of a deactivation's move needs of its locked transfer: its two locations, who made it, and whether its source is active, the source's row locked in share mode.
	 *
	 * A source active again keeps its stock: its reactivation, which takes the location's row first,
	 * either waits for this page or is seen by it.
	 *
	 * @since 0.2.0
	 *
	 * @throws \LogicException When called outside a transaction.
	 *
	 * @param int $transferId The transfer, locked.
	 * @return array{source_location_id: int, destination_location_id: int, created_by: int|null, source_active: bool}|null The four, or null when there is no such transfer or its source is gone.
	 */
	public function moveOf( int $transferId ): ?array;

	/**
	 * Replaces a locked transfer's note.
	 *
	 * @since 0.2.0
	 *
	 * @throws \LogicException When called outside a transaction.
	 *
	 * @param string      $uuid The transfer's uuid, locked.
	 * @param string|null $note The note, or null for none.
	 */
	public function setNote( string $uuid, ?string $note ): void;

	/**
	 * Deletes a locked transfer's lines that carry no allocation.
	 *
	 * @since 0.2.0
	 *
	 * @throws \LogicException When called outside a transaction.
	 *
	 * @param int $transferId The transfer, locked.
	 */
	public function deleteUnallocatedLines( int $transferId ): void;

	/**
	 * Records units received and lost on a line, when the line has received and lost exactly the units the caller expects and has that many on the way.
	 *
	 * Both figures guard the receipt: a receipt that only writes units off leaves the received
	 * figure as it was, so the lost one is what makes its repeat miss.
	 *
	 * @since 0.2.0
	 *
	 * @throws \LogicException When called outside a transaction.
	 *
	 * @param int $lineId           The line.
	 * @param int $transferId       The transfer it must belong to, locked.
	 * @param int $expectedReceived The units the caller read as received so far: with $expectedLost, the receipt's idempotency guard.
	 * @param int $expectedLost     The units the caller read as lost so far.
	 * @param int $received         The units received now.
	 * @param int $lost             The units written off now; with $received, at least 1.
	 * @return bool True when the line matched and changed; false when it is not the transfer's, has received or lost another figure, or has fewer units on the way.
	 *
	 * @phpstan-impure
	 */
	public function receiveLine( int $lineId, int $transferId, int $expectedReceived, int $expectedLost, int $received, int $lost ): bool;

	/**
	 * Moves a locked transfer in transit to received, when every line's units are received or lost.
	 *
	 * @since 0.2.0
	 *
	 * @throws \LogicException When called outside a transaction.
	 *
	 * @param int $transferId The transfer, locked.
	 * @return bool True when it is now received; false while a line still has units on the way.
	 *
	 * @phpstan-impure
	 */
	public function markReceived( int $transferId ): bool;

	/**
	 * Cancels a draft or requested transfer none of whose lines carries an allocation.
	 *
	 * @since 0.2.0
	 *
	 * @throws \LogicException When called outside a transaction.
	 *
	 * @param int $transferId The transfer.
	 * @return bool True when it is now cancelled and locked; false when it is in another state or carries an order's units.
	 *
	 * @phpstan-impure
	 */
	public function cancel( int $transferId ): bool;

	/**
	 * Locks every transfer an order waits for that is requested or in transit, ascending by id: the first statement of the order's cancel.
	 *
	 * @since 0.2.0
	 *
	 * @throws \LogicException When called outside a transaction.
	 *
	 * @param int $orderId The order.
	 * @return list<array{id: int, state: TransferState}> The transfers, ascending.
	 *
	 * @phpstan-impure
	 */
	public function lockOrderTransfers( int $orderId ): array;

	/**
	 * Lists the lines of some transfers that carry an allocation. A plain read: call it under the transfers' locks.
	 *
	 * @since 0.2.0
	 *
	 * @param int[] $transferIds The transfers; none reads nothing.
	 * @return array<int, int> Each line's id, by the id of the allocation it carries.
	 */
	public function carriedLines( array $transferIds ): array;

	/**
	 * Detaches lines from the allocations they carry: their units go on as units no order waits for.
	 *
	 * @since 0.2.0
	 *
	 * @throws \LogicException When called outside a transaction.
	 *
	 * @param int[] $lineIds The lines, of transfers locked; none sends nothing.
	 */
	public function detachLines( array $lineIds ): void;

	/**
	 * Reads the variant of a transfer's last line: how far a deactivation's move has come. A plain read: call it under the transfer's lock.
	 *
	 * @since 0.2.0
	 *
	 * @param int $transferId The transfer.
	 * @return int The variant, or 0 when it has no line.
	 */
	public function lastLineVariant( int $transferId ): int;

	/**
	 * Reads a location's deactivation move in transit, if it has one. A plain read: call it under the location's lock.
	 *
	 * @since 0.2.0
	 *
	 * @param int $sourceLocationId The location the move takes units from.
	 * @return array{id: int, uuid: string, destination_location_id: int}|null The move, or null.
	 */
	public function movingFrom( int $sourceLocationId ): ?array;

	/**
	 * Reads the newest deactivation move from one location to another. A plain read: call it under the source's lock.
	 *
	 * @since 0.2.0
	 *
	 * @param int $sourceLocationId      The location the move took units from.
	 * @param int $destinationLocationId The location it took them to.
	 * @return array{id: int, uuid: string}|null The move, or null when there was none.
	 */
	public function latestMove( int $sourceLocationId, int $destinationLocationId ): ?array;

	/**
	 * Reads a page of transfers, newest first, without their lines. A plain read.
	 *
	 * @since 0.2.0
	 *
	 * @param TransferState|null $state      Only transfers in this state, or null for any.
	 * @param int|null           $locationId Only transfers from or to this location, or null for any.
	 * @param int|null           $orderId    Only transfers this order waits for, or null for any.
	 * @param int                $limit      The most transfers to read.
	 * @param int                $offset     How many to pass over first.
	 * @return list<Transfer> The transfers.
	 */
	public function page( ?TransferState $state, ?int $locationId, ?int $orderId, int $limit, int $offset ): array;

	/**
	 * Reads each of some transfers' line totals: the lines, and the units sent, received and lost. A plain read.
	 *
	 * @since 0.2.0
	 *
	 * @param int[] $transferIds The transfers; none reads nothing.
	 * @return array<int, array{lines: int, sent: int, received: int, lost: int}> The totals of each transfer that has lines, by id.
	 */
	public function lineTotals( array $transferIds ): array;
}
