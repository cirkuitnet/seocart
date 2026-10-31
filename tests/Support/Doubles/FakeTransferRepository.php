<?php
/**
 * FakeTransferRepository: an in-memory transfer repository that keeps the documents' rules
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support\Doubles;

use SEOCart\Inventory\Domain\Transfer;
use SEOCart\Inventory\Domain\TransferKind;
use SEOCart\Inventory\Domain\TransferLine;
use SEOCart\Inventory\Domain\TransferRepository;
use SEOCart\Inventory\Domain\TransferState;
use SEOCart\Platform\Database\TransactionManager;

/**
 * An in-memory TransferRepository with the same contract as the MySQL one, for the transfer service's unit tests.
 *
 * It keeps transfers and lines in arrays and answers each conditional statement the way its WHERE
 * clause would: a lock or a move matches only from the states it names, a line's receipt only
 * with the figure expected and the units on the way, a cancel only with no line carrying an
 * allocation. Every method that must run inside a transaction refuses at depth 0, as the real one
 * does. It does not undo anything when a transaction rolls back, and it takes no lock: a test of
 * a rollback or of a race belongs in the integration suite, against the real tables. Its times
 * are fixed.
 *
 * @since 0.2.0
 */
final class FakeTransferRepository implements TransferRepository {

	/**
	 * The time every row is written at.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const NOW = '2026-10-25 12:00:00.000000';

	/**
	 * The unit of work, whose depth the writes check.
	 *
	 * @since 0.2.0
	 *
	 * @var TransactionManager
	 */
	private TransactionManager $tx;

	/**
	 * The transfers' rows, by id.
	 *
	 * @since 0.2.0
	 *
	 * @var array<int, array{uuid: string, source: int, destination: int, kind: TransferKind, order_id: int|null, state: TransferState, note: string|null, created_by: int|null, sent_at: string|null, received_at: string|null}>
	 */
	private array $transfers = array();

	/**
	 * The lines, by id.
	 *
	 * @since 0.2.0
	 *
	 * @var array<int, array{transfer_id: int, variant_id: int, quantity: int, received_quantity: int, lost_quantity: int, allocation_id: int|null}>
	 */
	private array $lines = array();

	/**
	 * The last line id handed out.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	private int $lastLineId = 0;

	/**
	 * Creates the double.
	 *
	 * @since 0.2.0
	 *
	 * @param TransactionManager $tx The unit of work the service runs in.
	 */
	public function __construct( TransactionManager $tx ) {
		$this->tx = $tx;
	}

	/**
	 * Returns who created each transfer, by id: what the double keeps and the answers never show.
	 *
	 * @since 0.2.0
	 *
	 * @return array<int, int|null> The users, by transfer id.
	 */
	public function creators(): array {
		return array_map( static fn( array $row ): ?int => $row['created_by'], $this->transfers );
	}

	/**
	 * Creates a transfer's row.
	 *
	 * @since 0.2.0
	 *
	 * @param string        $uuid                  Its public identifier.
	 * @param int           $sourceLocationId      The source.
	 * @param int           $destinationLocationId The destination.
	 * @param TransferKind  $kind                  Who starts it.
	 * @param TransferState $state                 Its first state.
	 * @param int|null      $orderId               The order, or null.
	 * @param string|null   $note                  The note, or null.
	 * @param int|null      $createdBy             The user, or null.
	 * @return int The id.
	 */
	public function insert( string $uuid, int $sourceLocationId, int $destinationLocationId, TransferKind $kind, TransferState $state, ?int $orderId, ?string $note, ?int $createdBy ): int {
		$this->requireTransaction();

		$id = count( $this->transfers ) + 1;

		$this->transfers[ $id ] = array(
			'uuid'        => strtolower( $uuid ),
			'source'      => $sourceLocationId,
			'destination' => $destinationLocationId,
			'kind'        => $kind,
			'order_id'    => $orderId,
			'state'       => $state,
			'note'        => $note,
			'created_by'  => $createdBy,
			'sent_at'     => TransferState::InTransit === $state ? self::NOW : null,
			'received_at' => null,
		);

		return $id;
	}

	/**
	 * Adds lines to a transfer.
	 *
	 * @since 0.2.0
	 *
	 * @param int   $transferId The transfer.
	 * @param array $lines      The lines.
	 *
	 * @phpstan-param list<array{variant_id: int, quantity: int, received_quantity: int, allocation_id: int|null}> $lines
	 */
	public function insertLines( int $transferId, array $lines ): void {
		$this->requireTransaction();

		foreach ( $lines as $line ) {
			$this->lines[ ++$this->lastLineId ] = array(
				'transfer_id'       => $transferId,
				'variant_id'        => $line['variant_id'],
				'quantity'          => $line['quantity'],
				'received_quantity' => $line['received_quantity'],
				'lost_quantity'     => 0,
				'allocation_id'     => $line['allocation_id'],
			);
		}
	}

	/**
	 * Reads a transfer with its lines.
	 *
	 * @since 0.2.0
	 *
	 * @param string $uuid The transfer's uuid.
	 * @return Transfer|null The transfer, or null.
	 */
	public function document( string $uuid ): ?Transfer {
		$id = $this->idOf( $uuid );

		return null === $id ? null : $this->transfer( $id, true );
	}

	/**
	 * Locks a draft or requested transfer.
	 *
	 * @since 0.2.0
	 *
	 * @param string $uuid The transfer's uuid.
	 * @return bool Whether it matched.
	 */
	public function lockEditable( string $uuid ): bool {
		return $this->in( $this->idOf( $uuid ), TransferState::Draft, TransferState::Requested );
	}

	/**
	 * Sends a draft or requested transfer.
	 *
	 * @since 0.2.0
	 *
	 * @param string $uuid The transfer's uuid.
	 * @return bool Whether it matched.
	 */
	public function markSent( string $uuid ): bool {
		$id = $this->idOf( $uuid );

		if ( ! $this->in( $id, TransferState::Draft, TransferState::Requested ) || null === $id ) {
			return false;
		}

		$this->transfers[ $id ]['state']   = TransferState::InTransit;
		$this->transfers[ $id ]['sent_at'] = self::NOW;

		return true;
	}

	/**
	 * Locks a transfer in transit, by its uuid.
	 *
	 * @since 0.2.0
	 *
	 * @param string $uuid The transfer's uuid.
	 * @return bool Whether it matched.
	 */
	public function lockReceivable( string $uuid ): bool {
		return $this->in( $this->idOf( $uuid ), TransferState::InTransit );
	}

	/**
	 * Locks a deactivation's move in transit.
	 *
	 * @since 0.2.0
	 *
	 * @param int $transferId The transfer.
	 * @return bool Whether it matched.
	 */
	public function lockMoving( int $transferId ): bool {
		return $this->in( $transferId, TransferState::InTransit ) && TransferKind::Deactivation === $this->transfers[ $transferId ]['kind'];
	}

	/**
	 * Reads a transfer's two locations, who made it, and whether its source is active.
	 *
	 * The fake keeps no location rows: a move's source reads as its deactivation left it, inactive.
	 *
	 * @since 0.2.0
	 *
	 * @param int $transferId The transfer.
	 * @return array{source_location_id: int, destination_location_id: int, created_by: int|null, source_active: bool}|null The four, or null.
	 */
	public function moveOf( int $transferId ): ?array {
		$row = $this->transfers[ $transferId ] ?? null;

		return null === $row ? null : array(
			'source_location_id'      => $row['source'],
			'destination_location_id' => $row['destination'],
			'created_by'              => $row['created_by'],
			'source_active'           => false,
		);
	}

	/**
	 * Replaces a transfer's note.
	 *
	 * @since 0.2.0
	 *
	 * @param string      $uuid The transfer's uuid.
	 * @param string|null $note The note.
	 */
	public function setNote( string $uuid, ?string $note ): void {
		$this->requireTransaction();

		$id = $this->idOf( $uuid );

		if ( null !== $id ) {
			$this->transfers[ $id ]['note'] = $note;
		}
	}

	/**
	 * Deletes a transfer's lines that carry no allocation.
	 *
	 * @since 0.2.0
	 *
	 * @param int $transferId The transfer.
	 */
	public function deleteUnallocatedLines( int $transferId ): void {
		$this->requireTransaction();

		$this->lines = array_filter( $this->lines, static fn( array $line ): bool => $line['transfer_id'] !== $transferId || null !== $line['allocation_id'] );
	}

	/**
	 * Records units received and lost on a line.
	 *
	 * @since 0.2.0
	 *
	 * @param int $lineId           The line.
	 * @param int $transferId       The transfer.
	 * @param int $expectedReceived The units received so far, as read.
	 * @param int $expectedLost     The units lost so far, as read.
	 * @param int $received         The units received.
	 * @param int $lost             The units lost.
	 * @return bool Whether it matched.
	 */
	public function receiveLine( int $lineId, int $transferId, int $expectedReceived, int $expectedLost, int $received, int $lost ): bool {
		$this->requireTransaction();

		$line = $this->lines[ $lineId ] ?? null;

		if ( null === $line || $line['transfer_id'] !== $transferId || $line['received_quantity'] !== $expectedReceived || $line['lost_quantity'] !== $expectedLost || $line['received_quantity'] + $line['lost_quantity'] + $received + $lost > $line['quantity'] ) {
			return false;
		}

		$this->lines[ $lineId ]['received_quantity'] += $received;
		$this->lines[ $lineId ]['lost_quantity']     += $lost;

		return true;
	}

	/**
	 * Receives a transfer once no line has units on the way.
	 *
	 * @since 0.2.0
	 *
	 * @param int $transferId The transfer.
	 * @return bool Whether it is now received.
	 */
	public function markReceived( int $transferId ): bool {
		if ( ! $this->in( $transferId, TransferState::InTransit ) ) {
			return false;
		}

		foreach ( $this->linesOf( $transferId ) as $line ) {
			if ( $line->remaining() > 0 ) {
				return false;
			}
		}

		$this->transfers[ $transferId ]['state']       = TransferState::Received;
		$this->transfers[ $transferId ]['received_at'] = self::NOW;

		return true;
	}

	/**
	 * Cancels a draft or requested transfer that carries no order's units.
	 *
	 * @since 0.2.0
	 *
	 * @param int $transferId The transfer.
	 * @return bool Whether it is now cancelled.
	 */
	public function cancel( int $transferId ): bool {
		if ( ! $this->in( $transferId, TransferState::Draft, TransferState::Requested ) ) {
			return false;
		}

		foreach ( $this->linesOf( $transferId ) as $line ) {
			if ( $line->isAllocated() ) {
				return false;
			}
		}

		$this->transfers[ $transferId ]['state'] = TransferState::Cancelled;

		return true;
	}

	/**
	 * Lists an order's transfers requested or in transit.
	 *
	 * @since 0.2.0
	 *
	 * @param int $orderId The order.
	 * @return list<array{id: int, state: TransferState}> The transfers.
	 */
	public function lockOrderTransfers( int $orderId ): array {
		$this->requireTransaction();

		$found = array();

		foreach ( $this->transfers as $id => $row ) {
			if ( $orderId === $row['order_id'] && in_array( $row['state'], array( TransferState::Requested, TransferState::InTransit ), true ) ) {
				$found[] = array(
					'id'    => $id,
					'state' => $row['state'],
				);
			}
		}

		return $found;
	}

	/**
	 * Lists the lines of some transfers that carry an allocation.
	 *
	 * @since 0.2.0
	 *
	 * @param int[] $transferIds The transfers.
	 * @return array<int, int> Each line's id, by its allocation's id.
	 */
	public function carriedLines( array $transferIds ): array {
		$carried = array();

		foreach ( $this->lines as $id => $line ) {
			if ( null !== $line['allocation_id'] && in_array( $line['transfer_id'], $transferIds, true ) ) {
				$carried[ $line['allocation_id'] ] = $id;
			}
		}

		return $carried;
	}

	/**
	 * Detaches lines from their allocations.
	 *
	 * @since 0.2.0
	 *
	 * @param int[] $lineIds The lines.
	 */
	public function detachLines( array $lineIds ): void {
		$this->requireTransaction();

		foreach ( $lineIds as $id ) {
			$this->lines[ $id ]['allocation_id'] = null;
		}
	}

	/**
	 * Reads the variant of a transfer's last line.
	 *
	 * @since 0.2.0
	 *
	 * @param int $transferId The transfer.
	 * @return int The variant, or 0.
	 */
	public function lastLineVariant( int $transferId ): int {
		$last = 0;

		foreach ( $this->lines as $line ) {
			$last = $transferId === $line['transfer_id'] ? $line['variant_id'] : $last;
		}

		return $last;
	}

	/**
	 * Reads a location's deactivation move in transit.
	 *
	 * @since 0.2.0
	 *
	 * @param int $sourceLocationId The location.
	 * @return array{id: int, uuid: string, destination_location_id: int}|null The move, or null.
	 */
	public function movingFrom( int $sourceLocationId ): ?array {
		foreach ( array_reverse( $this->transfers, true ) as $id => $row ) {
			if ( $sourceLocationId === $row['source'] && TransferKind::Deactivation === $row['kind'] && TransferState::InTransit === $row['state'] ) {
				return array(
					'id'                      => $id,
					'uuid'                    => $row['uuid'],
					'destination_location_id' => $row['destination'],
				);
			}
		}

		return null;
	}

	/**
	 * Reads the newest deactivation move from one location to another.
	 *
	 * @since 0.2.0
	 *
	 * @param int $sourceLocationId      The source.
	 * @param int $destinationLocationId The destination.
	 * @return array{id: int, uuid: string}|null The move, or null.
	 */
	public function latestMove( int $sourceLocationId, int $destinationLocationId ): ?array {
		foreach ( array_reverse( $this->transfers, true ) as $id => $row ) {
			if ( $sourceLocationId === $row['source'] && $destinationLocationId === $row['destination'] && TransferKind::Deactivation === $row['kind'] ) {
				return array(
					'id'   => $id,
					'uuid' => $row['uuid'],
				);
			}
		}

		return null;
	}

	/**
	 * Reads a page of transfers, newest first.
	 *
	 * @since 0.2.0
	 *
	 * @param TransferState|null $state      The state, or null.
	 * @param int|null           $locationId The location, or null.
	 * @param int|null           $orderId    The order, or null.
	 * @param int                $limit      The most transfers.
	 * @param int                $offset     How many to pass over.
	 * @return list<Transfer> The transfers.
	 */
	public function page( ?TransferState $state, ?int $locationId, ?int $orderId, int $limit, int $offset ): array {
		$found = array();

		foreach ( array_reverse( $this->transfers, true ) as $id => $row ) {
			if ( ( null === $state || $state === $row['state'] ) && ( null === $locationId || in_array( $locationId, array( $row['source'], $row['destination'] ), true ) ) && ( null === $orderId || $orderId === $row['order_id'] ) ) {
				$found[] = $this->transfer( $id, false );
			}
		}

		return array_slice( $found, $offset, $limit );
	}

	/**
	 * Reads some transfers' line totals.
	 *
	 * @since 0.2.0
	 *
	 * @param int[] $transferIds The transfers.
	 * @return array<int, array{lines: int, sent: int, received: int, lost: int}> The totals, by transfer id.
	 */
	public function lineTotals( array $transferIds ): array {
		$totals = array();

		foreach ( $transferIds as $id ) {
			foreach ( $this->linesOf( $id ) as $line ) {
				$totals[ $id ] ??= array(
					'lines'    => 0,
					'sent'     => 0,
					'received' => 0,
					'lost'     => 0,
				);

				++$totals[ $id ]['lines'];
				$totals[ $id ]['sent']     += $line->quantity;
				$totals[ $id ]['received'] += $line->receivedQuantity;
				$totals[ $id ]['lost']     += $line->lostQuantity;
			}
		}

		return $totals;
	}

	/**
	 * Tells whether a transfer is in one of some states, refusing outside a transaction as a locking statement does.
	 *
	 * @since 0.2.0
	 *
	 * @param int|null      $transferId The transfer, or null for none.
	 * @param TransferState ...$states  The states.
	 * @return bool True when it is.
	 */
	private function in( ?int $transferId, TransferState ...$states ): bool {
		$this->requireTransaction();

		return null !== $transferId && isset( $this->transfers[ $transferId ] ) && in_array( $this->transfers[ $transferId ]['state'], $states, true );
	}

	/**
	 * Finds a transfer's id by its uuid.
	 *
	 * @since 0.2.0
	 *
	 * @param string $uuid The uuid.
	 * @return int|null The id, or null.
	 */
	private function idOf( string $uuid ): ?int {
		foreach ( $this->transfers as $id => $row ) {
			if ( strtolower( $uuid ) === $row['uuid'] ) {
				return $id;
			}
		}

		return null;
	}

	/**
	 * Lists a transfer's lines, ascending by variant then id.
	 *
	 * @since 0.2.0
	 *
	 * @param int $transferId The transfer.
	 * @return list<TransferLine> The lines.
	 */
	private function linesOf( int $transferId ): array {
		$lines = array();

		foreach ( $this->lines as $id => $line ) {
			if ( $transferId === $line['transfer_id'] ) {
				$lines[] = new TransferLine( $id, $line['variant_id'], $line['quantity'], $line['received_quantity'], $line['lost_quantity'], $line['allocation_id'] );
			}
		}

		usort( $lines, static fn( TransferLine $a, TransferLine $b ): int => array( $a->variantId, $a->id ) <=> array( $b->variantId, $b->id ) );

		return $lines;
	}

	/**
	 * Builds a transfer from its row.
	 *
	 * @since 0.2.0
	 *
	 * @param int  $id        The transfer.
	 * @param bool $withLines Whether to read its lines too.
	 * @return Transfer The transfer.
	 */
	private function transfer( int $id, bool $withLines ): Transfer {
		$row = $this->transfers[ $id ];

		return new Transfer( $id, $row['uuid'], $row['source'], $row['destination'], $row['kind'], $row['order_id'], $row['state'], $row['note'], self::NOW, $row['sent_at'], $row['received_at'], $withLines ? $this->linesOf( $id ) : array() );
	}

	/**
	 * Refuses a write outside a transaction.
	 *
	 * @since 0.2.0
	 *
	 * @throws \LogicException At depth 0.
	 */
	private function requireTransaction(): void {
		if ( 0 === $this->tx->depth() ) {
			throw new \LogicException( 'FakeTransferRepository: a write runs only inside a transaction.' );
		}
	}
}
