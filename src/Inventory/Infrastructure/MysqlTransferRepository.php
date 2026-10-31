<?php
/**
 * MysqlTransferRepository: every statement of the transfer documents, over the plugin's Database
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Inventory\Infrastructure;

use SEOCart\Inventory\Domain\Transfer;
use SEOCart\Inventory\Domain\TransferKind;
use SEOCart\Inventory\Domain\TransferLine;
use SEOCart\Inventory\Domain\TransferRepository;
use SEOCart\Inventory\Domain\TransferState;
use SEOCart\Platform\Database\Database;
use SEOCart\Platform\Database\ModuleStatements;

defined( 'ABSPATH' ) || exit;

/**
 * The transfer repository on MySQL: the one class that sends SQL to `stock_transfers` and `stock_transfer_lines`.
 *
 * Owns one fact: the text of every transfer statement, each a public constant naming its tables
 * as `{stock_transfers}` and `{stock_transfer_lines}`, so a concurrency test sends exactly the
 * statement this class sends. The statements that lock a transfer's row set `updated_at` as the
 * stock item lock does, `GREATEST( UTC_TIMESTAMP(6), updated_at + INTERVAL 1 MICROSECOND )`, so
 * their affected-row count says whether the state they carry matched. The stock movements of a
 * transfer, its items, allocations and ledger entries, are MysqlStockRepository's.
 *
 * The reads for `doctor` live here too, because they are transfer SQL; they are not part of the
 * port the service sees.
 *
 * @since 0.2.0
 */
final class MysqlTransferRepository implements TransferRepository {

	/**
	 * A transfer's row; 0 stands for no order and no user, an empty string for no note, and 1 in the fourth-last value for a transfer sent as it is made.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const INSERT_TRANSFER = "INSERT INTO {stock_transfers} ( uuid, source_location_id, destination_location_id, kind, order_id, state, note, created_by, created_at, sent_at, updated_at ) VALUES ( %s, %d, %d, %s, NULLIF( %d, 0 ), %s, NULLIF( %s, '' ), NULLIF( %d, 0 ), UTC_TIMESTAMP(6), IF( %d = 1, UTC_TIMESTAMP(6), NULL ), UTC_TIMESTAMP(6) )";

	/**
	 * A line; 0 stands for no allocation. The lines of one transfer go in together.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const INSERT_LINE = 'INSERT INTO {stock_transfer_lines} ( transfer_id, variant_id, quantity, received_quantity, lost_quantity, allocation_id ) VALUES ( %d, %d, %d, %d, 0, NULLIF( %d, 0 ) )';

	/**
	 * A transfer with its lines, by its public identifier, the lines ascending by variant.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const DOCUMENT = 'SELECT t.id, t.uuid, t.source_location_id, t.destination_location_id, t.kind, t.order_id, t.state, t.note, t.created_at, t.sent_at, t.received_at, l.id AS line_id, l.variant_id, l.quantity, l.received_quantity, l.lost_quantity, l.allocation_id FROM {stock_transfers} t LEFT JOIN {stock_transfer_lines} l ON l.transfer_id = t.id WHERE t.uuid = %s ORDER BY l.variant_id, l.id';

	/**
	 * Locks a draft or requested transfer.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const LOCK_EDITABLE = "UPDATE {stock_transfers} SET updated_at = GREATEST( UTC_TIMESTAMP(6), updated_at + INTERVAL 1 MICROSECOND ) WHERE uuid = %s AND state IN ('draft','requested')";

	/**
	 * Sends a draft or requested transfer: in transit from now on.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const MARK_SENT = "UPDATE {stock_transfers} SET state = 'in_transit', sent_at = UTC_TIMESTAMP(6), updated_at = GREATEST( UTC_TIMESTAMP(6), updated_at + INTERVAL 1 MICROSECOND ) WHERE uuid = %s AND state IN ('draft','requested')";

	/**
	 * Locks a transfer in transit, by its public identifier.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const LOCK_RECEIVABLE = "UPDATE {stock_transfers} SET updated_at = GREATEST( UTC_TIMESTAMP(6), updated_at + INTERVAL 1 MICROSECOND ) WHERE uuid = %s AND state = 'in_transit'";

	/**
	 * Locks a deactivation's move in transit, by its id.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const LOCK_MOVING = "UPDATE {stock_transfers} SET updated_at = GREATEST( UTC_TIMESTAMP(6), updated_at + INTERVAL 1 MICROSECOND ) WHERE id = %d AND state = 'in_transit' AND kind = 'deactivation'";

	/**
	 * A transfer's two locations, who made it, and whether its source is active, the source's row locked in share mode.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const MOVE_OF = 'SELECT t.source_location_id, t.destination_location_id, t.created_by, s.active AS source_active FROM {stock_transfers} t JOIN {locations} s ON s.id = t.source_location_id WHERE t.id = %d LOCK IN SHARE MODE';

	/**
	 * Replaces a transfer's note; an empty string removes it.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const SET_NOTE = "UPDATE {stock_transfers} SET note = NULLIF( %s, '' ), updated_at = GREATEST( UTC_TIMESTAMP(6), updated_at + INTERVAL 1 MICROSECOND ) WHERE uuid = %s";

	/**
	 * Deletes a transfer's lines that carry no allocation.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const DELETE_UNALLOCATED_LINES = 'DELETE FROM {stock_transfer_lines} WHERE transfer_id = %d AND allocation_id IS NULL';

	/**
	 * Records units received and lost on a line: only when it is the transfer's, has received and lost the figures the caller expects, and has that many units on the way.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const RECEIVE_LINE = 'UPDATE {stock_transfer_lines} SET received_quantity = received_quantity + %d, lost_quantity = lost_quantity + %d WHERE id = %d AND transfer_id = %d AND received_quantity = %d AND lost_quantity = %d AND received_quantity + lost_quantity + %d + %d <= quantity';

	/**
	 * Receives a transfer in transit once no line has units on the way.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const MARK_RECEIVED = "UPDATE {stock_transfers} t SET t.state = 'received', t.received_at = UTC_TIMESTAMP(6), t.updated_at = GREATEST( UTC_TIMESTAMP(6), t.updated_at + INTERVAL 1 MICROSECOND ) WHERE t.id = %d AND t.state = 'in_transit' AND NOT EXISTS ( SELECT 1 FROM {stock_transfer_lines} l WHERE l.transfer_id = t.id AND l.received_quantity + l.lost_quantity < l.quantity )";

	/**
	 * Cancels a draft or requested transfer none of whose lines carries an allocation.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const CANCEL = "UPDATE {stock_transfers} t SET t.state = 'cancelled', t.updated_at = GREATEST( UTC_TIMESTAMP(6), t.updated_at + INTERVAL 1 MICROSECOND ) WHERE t.id = %d AND t.state IN ('draft','requested') AND NOT EXISTS ( SELECT 1 FROM {stock_transfer_lines} l WHERE l.transfer_id = t.id AND l.allocation_id IS NOT NULL )";

	/**
	 * The transfers an order waits for that are requested or in transit, locked ascending: the first lock of the order's cancel.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const ORDER_TRANSFERS = "SELECT id, state FROM {stock_transfers} WHERE order_id = %d AND state IN ('requested','in_transit') ORDER BY id FOR UPDATE";

	/**
	 * The lines of some transfers that carry an allocation.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const CARRIED_LINES = 'SELECT id, allocation_id FROM {stock_transfer_lines} WHERE transfer_id IN ({list}) AND allocation_id IS NOT NULL ORDER BY id';

	/**
	 * Detaches lines from the allocations they carry.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const DETACH_LINES = 'UPDATE {stock_transfer_lines} SET allocation_id = NULL WHERE id IN ({list})';

	/**
	 * The variant of a transfer's last line: where a deactivation's move goes on from.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const LAST_LINE = 'SELECT variant_id FROM {stock_transfer_lines} WHERE transfer_id = %d ORDER BY id DESC LIMIT 1';

	/**
	 * A location's deactivation move in transit.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const MOVING_FROM = "SELECT id, uuid, destination_location_id FROM {stock_transfers} WHERE source_location_id = %d AND state = 'in_transit' AND kind = 'deactivation' ORDER BY id DESC LIMIT 1";

	/**
	 * The newest deactivation move from one location to another.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const LATEST_MOVE = "SELECT id, uuid FROM {stock_transfers} WHERE source_location_id = %d AND destination_location_id = %d AND kind = 'deactivation' ORDER BY id DESC LIMIT 1";

	/**
	 * A page of every transfer, newest first.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const PAGE = 'SELECT ' . self::LISTED . ' FROM {stock_transfers} t ORDER BY t.id DESC LIMIT %d OFFSET %d';

	/**
	 * A page of the transfers in one state, newest first: the server reads back from the newest transfer, or, for a state few transfers are in, takes them from the state_created key.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const PAGE_IN_STATE = 'SELECT ' . self::LISTED . ' FROM {stock_transfers} t WHERE t.state = %s ORDER BY t.id DESC LIMIT %d OFFSET %d';

	/**
	 * A page of the transfers from or to one location, newest first.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const PAGE_AT = 'SELECT ' . self::LISTED . ' FROM {stock_transfers} t WHERE ( t.source_location_id = %d OR t.destination_location_id = %d ) ORDER BY t.id DESC LIMIT %d OFFSET %d';

	/**
	 * A page of the transfers in one state from or to one location, newest first.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const PAGE_IN_STATE_AT = 'SELECT ' . self::LISTED . ' FROM {stock_transfers} t WHERE t.state = %s AND ( t.source_location_id = %d OR t.destination_location_id = %d ) ORDER BY t.id DESC LIMIT %d OFFSET %d';

	/**
	 * A page of the transfers one order waits for, newest first, from the order_id key; an empty state and a location of 0 filter nothing.
	 *
	 * An order waits for a handful of transfers, so its key finds every one at once and the other
	 * two filters only pass over some of them.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const PAGE_OF_ORDER = 'SELECT ' . self::LISTED . " FROM {stock_transfers} t WHERE t.order_id = %d AND ( %s = '' OR t.state = %s ) AND ( %d = 0 OR t.source_location_id = %d OR t.destination_location_id = %d ) ORDER BY t.id DESC LIMIT %d OFFSET %d";

	/**
	 * Each of some transfers' lines, and the units they send, receive and lose.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const LINE_TOTALS = 'SELECT transfer_id, COUNT(*) AS line_count, SUM( quantity ) AS sent, SUM( received_quantity ) AS received, SUM( lost_quantity ) AS lost FROM {stock_transfer_lines} WHERE transfer_id IN ({list}) GROUP BY transfer_id ORDER BY transfer_id';

	/**
	 * Lines that received and lost more than they sent, or, on a received transfer, less: doctor's first transfer line.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const LINES_OUT_OF_BOUNDS = "SELECT l.id, l.transfer_id, l.quantity, l.received_quantity, l.lost_quantity FROM {stock_transfer_lines} l JOIN {stock_transfers} t ON t.id = l.transfer_id WHERE l.received_quantity < 0 OR l.lost_quantity < 0 OR l.received_quantity + l.lost_quantity > l.quantity OR ( t.state = 'received' AND l.received_quantity + l.lost_quantity <> l.quantity ) ORDER BY l.id LIMIT %d";

	/**
	 * Sent transfers whose units on the way, by their lines, are not what their ledger entries moved less what was lost: doctor's second transfer line.
	 *
	 * The ledger entries that name a transfer are totalled once, by the ledger's transfer_id index,
	 * rather than looked up per transfer.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const TRANSIT_DRIFT = "SELECT t.id, ( SELECT COALESCE( SUM( l.quantity - l.received_quantity - l.lost_quantity ), 0 ) FROM {stock_transfer_lines} l WHERE l.transfer_id = t.id ) AS on_the_way, ( SELECT COALESCE( SUM( m.lost_quantity ), 0 ) FROM {stock_transfer_lines} m WHERE m.transfer_id = t.id ) AS lost, COALESCE( g.total, 0 ) AS moved FROM {stock_transfers} t LEFT JOIN ( SELECT transfer_id, SUM( - delta ) AS total FROM {stock_ledger} WHERE transfer_id IS NOT NULL AND reason IN ('transfer_out','transfer_in') GROUP BY transfer_id ) g ON g.transfer_id = t.id WHERE t.state IN ('in_transit','received') HAVING on_the_way <> moved - lost ORDER BY t.id LIMIT %d";

	/**
	 * Allocations in transit that the lines of transfers in transit do not carry with the same units on the way: doctor's third transfer line, one way.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const UNCARRIED_ALLOCATIONS = "SELECT a.id, a.order_id, a.quantity, COALESCE( SUM( CASE WHEN t.id IS NULL THEN 0 ELSE l.quantity - l.received_quantity - l.lost_quantity END ), 0 ) AS on_the_way FROM {stock_allocations} a LEFT JOIN {stock_transfer_lines} l ON l.allocation_id = a.id LEFT JOIN {stock_transfers} t ON t.id = l.transfer_id AND t.state = 'in_transit' WHERE a.state = 'in_transit' GROUP BY a.id, a.order_id, a.quantity HAVING on_the_way <> a.quantity ORDER BY a.id LIMIT %d";

	/**
	 * Lines of transfers in transit with units on the way for an allocation that is not in transit: doctor's third transfer line, the other way.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const STRAY_CARRIED_LINES = "SELECT l.id, l.transfer_id, l.allocation_id FROM {stock_transfers} t JOIN {stock_transfer_lines} l ON l.transfer_id = t.id LEFT JOIN {stock_allocations} a ON a.id = l.allocation_id AND a.state = 'in_transit' WHERE t.state = 'in_transit' AND l.allocation_id IS NOT NULL AND l.quantity - l.received_quantity - l.lost_quantity > 0 AND a.id IS NULL ORDER BY l.id LIMIT %d";

	/**
	 * Transfers requested longer ago than some days, or in transit since longer ago than that: doctor's fourth transfer line. A transfer is sent after it is created, so a send that old was created that long ago too.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const OVERDUE = "SELECT id, kind, state FROM {stock_transfers} WHERE ( state = 'requested' AND created_at < UTC_TIMESTAMP() - INTERVAL %d DAY ) OR ( state = 'in_transit' AND created_at < UTC_TIMESTAMP() - INTERVAL %d DAY AND sent_at < UTC_TIMESTAMP() - INTERVAL %d DAY ) ORDER BY id LIMIT %d";

	/**
	 * The sources transfers name that no longer exist, one per location from the source_state key: doctor's sixth transfer line.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const FROM_LOST_LOCATIONS = 'SELECT d.location_id FROM ( SELECT DISTINCT source_location_id AS location_id FROM {stock_transfers} ) d LEFT JOIN {locations} l ON l.id = d.location_id WHERE l.id IS NULL ORDER BY d.location_id LIMIT %d';

	/**
	 * The destinations transfers name that no longer exist, one per location from the destination_state key: doctor's sixth transfer line.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const TO_LOST_LOCATIONS = 'SELECT d.location_id FROM ( SELECT DISTINCT destination_location_id AS location_id FROM {stock_transfers} ) d LEFT JOIN {locations} l ON l.id = d.location_id WHERE l.id IS NULL ORDER BY d.location_id LIMIT %d';

	/**
	 * The newest received transfers of kind order with a line that lost units of the allocation it still names: doctor's seventh transfer line, before each order's status is read.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const SHORT_ORDER_TRANSFERS = "SELECT t.id, t.order_id FROM {stock_transfers} t WHERE t.state = 'received' AND t.kind = 'order' AND EXISTS ( SELECT 1 FROM {stock_transfer_lines} l WHERE l.transfer_id = t.id AND l.lost_quantity > 0 AND l.allocation_id IS NOT NULL ) ORDER BY t.id DESC LIMIT %d";

	/**
	 * The columns a list reads of each transfer.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const LISTED = 't.id, t.uuid, t.source_location_id, t.destination_location_id, t.kind, t.order_id, t.state, t.note, t.created_at, t.sent_at, t.received_at';

	/**
	 * Sends the statements.
	 *
	 * @since 0.2.0
	 *
	 * @var ModuleStatements
	 */
	private ModuleStatements $statements;

	/**
	 * Creates the repository. Sends nothing.
	 *
	 * @since 0.2.0
	 *
	 * @param Database $db The connection.
	 */
	public function __construct( Database $db ) {
		$this->statements = new ModuleStatements( $db, 'inventory', InventoryTables::names() );
	}

	/**
	 * Creates a transfer's row, and returns its id.
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
		$this->statements->requireTransaction( __METHOD__ );
		$this->statements->execute( self::INSERT_TRANSFER, $uuid, $sourceLocationId, $destinationLocationId, $kind->value, $orderId ?? 0, $state->value, $note ?? '', $createdBy ?? 0, TransferState::InTransit === $state ? 1 : 0 );

		return $this->statements->lastInsertId();
	}

	/**
	 * Adds lines to a transfer: one statement for every MysqlStockRepository::ROWS_PER_INSERT lines.
	 *
	 * @since 0.2.0
	 *
	 * @param int   $transferId The transfer.
	 * @param array $lines      The lines; none sends nothing.
	 *
	 * @phpstan-param list<array{variant_id: int, quantity: int, received_quantity: int, allocation_id: int|null}> $lines
	 */
	public function insertLines( int $transferId, array $lines ): void {
		$this->statements->requireTransaction( __METHOD__ );

		$rows = array_map( static fn( array $line ): array => array( $transferId, $line['variant_id'], $line['quantity'], $line['received_quantity'], $line['allocation_id'] ?? 0 ), $lines );

		foreach ( array_chunk( $rows, MysqlStockRepository::ROWS_PER_INSERT ) as $chunk ) {
			$this->statements->execute( ModuleStatements::forRows( self::INSERT_LINE, count( $chunk ) ), ...array_merge( ...$chunk ) );
		}
	}

	/**
	 * Reads a transfer with its lines: one read.
	 *
	 * @since 0.2.0
	 *
	 * @param string $uuid The transfer's uuid.
	 * @return Transfer|null The transfer, or null.
	 */
	public function document( string $uuid ): ?Transfer {
		$rows = $this->statements->rows( self::DOCUMENT, strtolower( $uuid ) );

		if ( array() === $rows ) {
			return null;
		}

		$lines = array();

		foreach ( $rows as $row ) {
			if ( null !== $row['line_id'] ) {
				$lines[] = new TransferLine( (int) $row['line_id'], (int) $row['variant_id'], (int) $row['quantity'], (int) $row['received_quantity'], (int) $row['lost_quantity'], null === $row['allocation_id'] ? null : (int) $row['allocation_id'] );
			}
		}

		return self::transfer( $rows[0], $lines );
	}

	/**
	 * Locks a draft or requested transfer.
	 *
	 * @since 0.2.0
	 *
	 * @param string $uuid The transfer's uuid.
	 * @return bool True when it matched.
	 */
	public function lockEditable( string $uuid ): bool {
		return $this->lock( self::LOCK_EDITABLE, strtolower( $uuid ) );
	}

	/**
	 * Sends a draft or requested transfer.
	 *
	 * @since 0.2.0
	 *
	 * @param string $uuid The transfer's uuid.
	 * @return bool True when it matched.
	 */
	public function markSent( string $uuid ): bool {
		return $this->lock( self::MARK_SENT, strtolower( $uuid ) );
	}

	/**
	 * Locks a transfer in transit, by its uuid.
	 *
	 * @since 0.2.0
	 *
	 * @param string $uuid The transfer's uuid.
	 * @return bool True when it matched.
	 */
	public function lockReceivable( string $uuid ): bool {
		return $this->lock( self::LOCK_RECEIVABLE, strtolower( $uuid ) );
	}

	/**
	 * Locks a transfer in transit, by its id.
	 *
	 * @since 0.2.0
	 *
	 * @param int $transferId The transfer.
	 * @return bool True when it matched.
	 */
	public function lockMoving( int $transferId ): bool {
		return $this->lock( self::LOCK_MOVING, $transferId );
	}

	/**
	 * Reads a transfer's two locations, who made it, and whether its source is active, holding the source's row in share mode.
	 *
	 * @since 0.2.0
	 *
	 * @param int $transferId The transfer, locked.
	 * @return array{source_location_id: int, destination_location_id: int, created_by: int|null, source_active: bool}|null The four, or null when no transfer has the id or its source is gone.
	 */
	public function moveOf( int $transferId ): ?array {
		$this->statements->requireTransaction( __METHOD__ );

		$row = $this->statements->rows( self::MOVE_OF, $transferId )[0] ?? null;

		return null === $row ? null : array(
			'source_location_id'      => (int) $row['source_location_id'],
			'destination_location_id' => (int) $row['destination_location_id'],
			'created_by'              => null === $row['created_by'] ? null : (int) $row['created_by'],
			'source_active'           => 1 === (int) $row['source_active'],
		);
	}

	/**
	 * Replaces a transfer's note.
	 *
	 * @since 0.2.0
	 *
	 * @param string      $uuid The transfer's uuid, locked.
	 * @param string|null $note The note, or null.
	 */
	public function setNote( string $uuid, ?string $note ): void {
		$this->statements->requireTransaction( __METHOD__ );
		$this->statements->execute( self::SET_NOTE, $note ?? '', strtolower( $uuid ) );
	}

	/**
	 * Deletes a transfer's lines that carry no allocation.
	 *
	 * @since 0.2.0
	 *
	 * @param int $transferId The transfer, locked.
	 */
	public function deleteUnallocatedLines( int $transferId ): void {
		$this->statements->requireTransaction( __METHOD__ );
		$this->statements->execute( self::DELETE_UNALLOCATED_LINES, $transferId );
	}

	/**
	 * Records units received and lost on a line.
	 *
	 * @since 0.2.0
	 *
	 * @param int $lineId           The line.
	 * @param int $transferId       The transfer.
	 * @param int $expectedReceived The units received so far, as the caller read them.
	 * @param int $expectedLost     The units lost so far, as the caller read them.
	 * @param int $received         The units received now.
	 * @param int $lost             The units lost now.
	 * @return bool True when it matched.
	 */
	public function receiveLine( int $lineId, int $transferId, int $expectedReceived, int $expectedLost, int $received, int $lost ): bool {
		$this->statements->requireTransaction( __METHOD__ );

		return 1 === $this->statements->execute( self::RECEIVE_LINE, $received, $lost, $lineId, $transferId, $expectedReceived, $expectedLost, $received, $lost );
	}

	/**
	 * Receives a transfer once no line has units on the way.
	 *
	 * @since 0.2.0
	 *
	 * @param int $transferId The transfer, locked.
	 * @return bool True when it is now received.
	 */
	public function markReceived( int $transferId ): bool {
		return $this->lock( self::MARK_RECEIVED, $transferId );
	}

	/**
	 * Cancels a draft or requested transfer that carries no order's units.
	 *
	 * @since 0.2.0
	 *
	 * @param int $transferId The transfer.
	 * @return bool True when it is now cancelled.
	 */
	public function cancel( int $transferId ): bool {
		return $this->lock( self::CANCEL, $transferId );
	}

	/**
	 * Locks an order's open transfers, ascending.
	 *
	 * @since 0.2.0
	 *
	 * @param int $orderId The order.
	 * @return list<array{id: int, state: TransferState}> The transfers.
	 */
	public function lockOrderTransfers( int $orderId ): array {
		$this->statements->requireTransaction( __METHOD__ );

		return array_map(
			static fn( array $row ): array => array(
				'id'    => (int) $row['id'],
				'state' => TransferState::from( (string) $row['state'] ),
			),
			$this->statements->rows( self::ORDER_TRANSFERS, $orderId )
		);
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
		$lines = array();

		if ( array() === $transferIds ) {
			return $lines;
		}

		foreach ( $this->statements->rows( self::CARRIED_LINES, array_values( $transferIds ) ) as $row ) {
			$lines[ (int) $row['allocation_id'] ] = (int) $row['id'];
		}

		return $lines;
	}

	/**
	 * Detaches lines from their allocations.
	 *
	 * @since 0.2.0
	 *
	 * @param int[] $lineIds The lines; none sends nothing.
	 */
	public function detachLines( array $lineIds ): void {
		$this->statements->requireTransaction( __METHOD__ );

		if ( array() !== $lineIds ) {
			$this->statements->execute( self::DETACH_LINES, array_values( $lineIds ) );
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
		return (int) ( $this->statements->rows( self::LAST_LINE, $transferId )[0]['variant_id'] ?? 0 );
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
		$row = $this->statements->rows( self::MOVING_FROM, $sourceLocationId )[0] ?? null;

		return null === $row ? null : array(
			'id'                      => (int) $row['id'],
			'uuid'                    => (string) $row['uuid'],
			'destination_location_id' => (int) $row['destination_location_id'],
		);
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
		$row = $this->statements->rows( self::LATEST_MOVE, $sourceLocationId, $destinationLocationId )[0] ?? null;

		return null === $row ? null : array(
			'id'   => (int) $row['id'],
			'uuid' => (string) $row['uuid'],
		);
	}

	/**
	 * Reads a page of transfers, newest first, with the statement its filters need.
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
		$rows = match ( true ) {
			null !== $orderId                         => $this->statements->rows( self::PAGE_OF_ORDER, $orderId, $state->value ?? '', $state->value ?? '', $locationId ?? 0, $locationId ?? 0, $locationId ?? 0, $limit, $offset ),
			null !== $state && null !== $locationId => $this->statements->rows( self::PAGE_IN_STATE_AT, $state->value, $locationId, $locationId, $limit, $offset ),
			null !== $state                           => $this->statements->rows( self::PAGE_IN_STATE, $state->value, $limit, $offset ),
			null !== $locationId                      => $this->statements->rows( self::PAGE_AT, $locationId, $locationId, $limit, $offset ),
			default                                   => $this->statements->rows( self::PAGE, $limit, $offset ),
		};

		return array_map( static fn( array $row ): Transfer => self::transfer( $row, array() ), $rows );
	}

	/**
	 * Reads some transfers' line totals: one grouped read.
	 *
	 * @since 0.2.0
	 *
	 * @param int[] $transferIds The transfers.
	 * @return array<int, array{lines: int, sent: int, received: int, lost: int}> The totals, by transfer id.
	 */
	public function lineTotals( array $transferIds ): array {
		$totals = array();

		if ( array() === $transferIds ) {
			return $totals;
		}

		foreach ( $this->statements->rows( self::LINE_TOTALS, array_values( $transferIds ) ) as $row ) {
			$totals[ (int) $row['transfer_id'] ] = array(
				'lines'    => (int) $row['line_count'],
				'sent'     => (int) $row['sent'],
				'received' => (int) $row['received'],
				'lost'     => (int) $row['lost'],
			);
		}

		return $totals;
	}

	/**
	 * Lists lines that received and lost more than they sent, or, on a received transfer, other than they sent.
	 *
	 * @since 0.2.0
	 *
	 * @param int $limit The most lines to list.
	 * @return list<array{id: int, transfer_id: int, quantity: int, received_quantity: int, lost_quantity: int}> The lines, by id.
	 */
	public function linesOutOfBounds( int $limit ): array {
		return array_map(
			static fn( array $row ): array => array(
				'id'                => (int) $row['id'],
				'transfer_id'       => (int) $row['transfer_id'],
				'quantity'          => (int) $row['quantity'],
				'received_quantity' => (int) $row['received_quantity'],
				'lost_quantity'     => (int) $row['lost_quantity'],
			),
			$this->statements->rows( self::LINES_OUT_OF_BOUNDS, $limit )
		);
	}

	/**
	 * Lists sent transfers whose units on the way are not what their ledger moved less what was lost.
	 *
	 * @since 0.2.0
	 *
	 * @param int $limit The most transfers to list.
	 * @return list<array{id: int, on_the_way: int, moved: int, lost: int}> The transfers, by id.
	 */
	public function transitDrift( int $limit ): array {
		return array_map(
			static fn( array $row ): array => array(
				'id'         => (int) $row['id'],
				'on_the_way' => (int) $row['on_the_way'],
				'moved'      => (int) $row['moved'],
				'lost'       => (int) $row['lost'],
			),
			$this->statements->rows( self::TRANSIT_DRIFT, $limit )
		);
	}

	/**
	 * Lists allocations in transit that no line of a transfer in transit carries with the same units on the way.
	 *
	 * @since 0.2.0
	 *
	 * @param int $limit The most allocations to list.
	 * @return list<array{id: int, order_id: int, quantity: int, on_the_way: int}> The allocations, by id.
	 */
	public function uncarriedAllocations( int $limit ): array {
		return array_map(
			static fn( array $row ): array => array(
				'id'         => (int) $row['id'],
				'order_id'   => (int) $row['order_id'],
				'quantity'   => (int) $row['quantity'],
				'on_the_way' => (int) $row['on_the_way'],
			),
			$this->statements->rows( self::UNCARRIED_ALLOCATIONS, $limit )
		);
	}

	/**
	 * Lists lines of transfers in transit with units on the way for an allocation that is not in transit.
	 *
	 * @since 0.2.0
	 *
	 * @param int $limit The most lines to list.
	 * @return list<array{id: int, transfer_id: int, allocation_id: int}> The lines, by id.
	 */
	public function strayCarriedLines( int $limit ): array {
		return array_map(
			static fn( array $row ): array => array(
				'id'            => (int) $row['id'],
				'transfer_id'   => (int) $row['transfer_id'],
				'allocation_id' => (int) $row['allocation_id'],
			),
			$this->statements->rows( self::STRAY_CARRIED_LINES, $limit )
		);
	}

	/**
	 * Lists transfers requested, or in transit, for longer than some days.
	 *
	 * @since 0.2.0
	 *
	 * @param int $days  The days.
	 * @param int $limit The most transfers to list.
	 * @return list<array{id: int, kind: string, state: string}> The transfers, by id.
	 */
	public function overdue( int $days, int $limit ): array {
		return array_map(
			static fn( array $row ): array => array(
				'id'    => (int) $row['id'],
				'kind'  => (string) $row['kind'],
				'state' => (string) $row['state'],
			),
			$this->statements->rows( self::OVERDUE, $days, $days, $days, $limit )
		);
	}

	/**
	 * Lists the locations transfers leave or arrive at that no longer exist.
	 *
	 * @since 0.2.0
	 *
	 * @param int $limit The most locations to list of each end.
	 * @return array{from: list<int>, to: list<int>} The sources and the destinations gone, ascending.
	 */
	public function lostLocations( int $limit ): array {
		$ids = static fn( array $rows ): array => array_map( static fn( array $row ): int => (int) $row['location_id'], $rows );

		return array(
			'from' => $ids( $this->statements->rows( self::FROM_LOST_LOCATIONS, $limit ) ),
			'to'   => $ids( $this->statements->rows( self::TO_LOST_LOCATIONS, $limit ) ),
		);
	}

	/**
	 * Lists the newest received transfers of an order that lost units of its allocation.
	 *
	 * @since 0.2.0
	 *
	 * @param int $limit The most transfers to list.
	 * @return list<array{id: int, order_id: int}> The transfers, newest first.
	 */
	public function shortOrderTransfers( int $limit ): array {
		return array_map(
			static fn( array $row ): array => array(
				'id'       => (int) $row['id'],
				'order_id' => (int) $row['order_id'],
			),
			$this->statements->rows( self::SHORT_ORDER_TRANSFERS, $limit )
		);
	}

	/**
	 * Sends a statement that locks one transfer's row, and tells whether it matched.
	 *
	 * @since 0.2.0
	 *
	 * @param string $statement The statement: it sets updated_at forward, so one affected row means its WHERE clause matched.
	 * @param mixed  ...$values Its values.
	 * @return bool True when it matched.
	 */
	private function lock( string $statement, mixed ...$values ): bool {
		$this->statements->requireTransaction( __METHOD__ );

		return 1 === $this->statements->execute( $statement, ...$values );
	}

	/**
	 * Builds a transfer from its row and its lines.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $row   The row, with the listed columns.
	 * @param TransferLine[]       $lines The lines.
	 * @return Transfer The transfer.
	 *
	 * @phpstan-param list<TransferLine> $lines
	 */
	private static function transfer( array $row, array $lines ): Transfer {
		return new Transfer(
			(int) $row['id'],
			(string) $row['uuid'],
			(int) $row['source_location_id'],
			(int) $row['destination_location_id'],
			TransferKind::from( (string) $row['kind'] ),
			null === $row['order_id'] ? null : (int) $row['order_id'],
			TransferState::from( (string) $row['state'] ),
			null === $row['note'] ? null : (string) $row['note'],
			(string) $row['created_at'],
			null === $row['sent_at'] ? null : (string) $row['sent_at'],
			null === $row['received_at'] ? null : (string) $row['received_at'],
			$lines
		);
	}
}
