<?php
/**
 * Tests the transfer service against real tables: a send, a receipt in parts with loss, and a receipt's idempotency
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Inventory;

use SEOCart\Inventory\Application\InventoryError;
use SEOCart\Inventory\Application\Transfers;
use SEOCart\Inventory\Domain\Event\StockTransferred;
use SEOCart\Inventory\Domain\Event\StockTransferShort;
use SEOCart\Inventory\Domain\LedgerReason;
use SEOCart\Inventory\Infrastructure\MysqlTransferRepository;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Tests\Support\Inventory\TransferTestCase;

/**
 * A transfer's send and receipt, held to what is committed: the items at both ends, the order's allocations, the ledger and the events.
 *
 * Each test plants a second location as the source and receives at the default location, whose
 * id is lower. An order's units are planted as the allocation a placement makes, and the
 * transfer is requested for it as a settlement does.
 *
 * Planted violations, each named on its test.
 *
 * @since 0.2.0
 */
final class TransfersTest extends TransferTestCase {

	/**
	 * The order line the planted allocations promise units to.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	private const ORDER_LINE = 7001;

	/**
	 * Tests that a send takes an order's allocated units off on_hand and allocated together and sets the allocation out with them, takes other units from what is available, and writes one ledger entry per line naming the transfer.
	 *
	 * The source holds exactly the order's three units of the first variant, all allocated, so
	 * nothing of it is available: they leave because they are the order's own.
	 *
	 * Planted violations, shown red and removed: (1) in MysqlStockRepository::TRANSFER_OUT_ALLOCATED,
	 * write the available predicate, `( on_hand - allocated - held ) >= %d`, in place of the
	 * allocated one: the fully allocated source refuses the order's own units. (2) In
	 * Transfers::sendLine(), leave out allocationInTransit(): the allocation stays open at the
	 * source while its units left `allocated`, and doctor finds the source's `allocated` below its
	 * open allocations.
	 *
	 * @since 0.2.0
	 */
	public function test_a_send_moves_the_orders_units_and_available_units_out_with_their_ledger(): void {
		$sent = $this->sentOrderTransfer();
		$b    = $this->secondConnection();
		$uuid = (string) $sent['answer']['transfer_uuid'];

		$this->assertSame( 'in_transit', $sent['answer']['state'] );
		$this->assertSame( array( 0, 0, 0 ), array_values( (array) $this->committedItem( $b, $sent['ordered'], $sent['source'] ) ), 'The order\'s units left on_hand and allocated together.' );
		$this->assertSame( array( 2, 0, 0 ), array_values( (array) $this->committedItem( $b, $sent['spare'], $sent['source'] ) ), 'Two of the four spare units left.' );
		$this->assertSame( array( $sent['ordered'] . '@' . $sent['source'] . ':' . self::ORDER_LINE . ':3:in_transit' ), $this->committedAllocations( $b, $sent['order'] ), 'The allocation travels with its units, counting at no item.' );
		$this->assertSame(
			array(
				$sent['ordered'] . '@' . $sent['source'] . ':-3:0:transfer_out',
				$sent['spare'] . '@' . $sent['source'] . ':-2:2:transfer_out',
			),
			$this->transferLedger( $b, $uuid )
		);
		$this->assertSame(
			array(
				array( 3, 3, true ),
				array( 2, 2, false ),
			),
			array_map( static fn( array $line ): array => array( $line['quantity'], $line['in_transit'], $line['allocated'] ), $sent['answer']['lines'] )
		);
		$this->assertSame( 1, $this->committedEvents( $b, StockTransferred::eventName() ) );
		$this->assertSame( array( $sent['ordered'], $sent['spare'] ), $this->committedPayload( $b, StockTransferred::eventName() )['variant_ids'] );
		$this->assertSame( array( 3, 2 ), $this->committedPayload( $b, StockTransferred::eventName() )['quantities'] );
		$this->assertSame( StockTransferred::SENT, $this->committedPayload( $b, StockTransferred::eventName() )['phase'] );

		$doctor = $this->projectionCheck();

		$this->assertTrue( $doctor->passed, 'Doctor: ' . implode( "\n", $doctor->findings ) );
	}

	/**
	 * Tests that a draft may name more units than its source has, that its send then refuses whole, naming the line, and that nothing moves.
	 *
	 * @since 0.2.0
	 */
	public function test_a_short_line_refuses_the_whole_send(): void {
		$enough = self::variant();
		$short  = self::variant();
		$source = $this->plantLocation();
		$b      = $this->secondConnection();

		$this->stockItem( $enough, 4, $source );
		$this->stockItem( $short, 3, $source );

		$draft = $this->draft(
			$source,
			$this->defaultLocation,
			array(
				$enough => 2,
				$short  => 5,
			)
		);

		$this->assertSame( 'draft', $draft['state'], 'A draft promises nothing, so it may name more than the source has.' );

		$refused = $this->assertRefused( InventoryError::TransferRefused, fn(): array => $this->send( (string) $draft['transfer_uuid'] ) );

		$this->assertSame(
			array(
				'variant_id' => $short,
				'requested'  => 5,
				'available'  => 3,
			),
			$refused->context()
		);
		$this->assertSame( array( 'location_id' => $this->uuidOf( $b, $source ) ), $refused->details() );
		$this->assertSame( 'draft', $this->committedTransfer( $b, (string) $draft['transfer_uuid'] )['state'] );
		$this->assertSame( array( 4, 0, 0 ), array_values( (array) $this->committedItem( $b, $enough, $source ) ), 'The line that had its units did not leave alone.' );
		$this->assertSame( array(), $this->transferLedger( $b, (string) $draft['transfer_uuid'] ) );
	}

	/**
	 * Tests that a transfer is sent once: a second send is refused with its state, and moves nothing more.
	 *
	 * @since 0.2.0
	 */
	public function test_a_second_send_is_refused_with_the_state(): void {
		$sent = $this->sentOrderTransfer();
		$b    = $this->secondConnection();
		$uuid = (string) $sent['answer']['transfer_uuid'];

		$refused = $this->assertRefused( InventoryError::TransferState, fn(): array => $this->send( $uuid ) );

		$this->assertSame(
			array(
				'transfer_id' => $uuid,
				'state'       => 'in_transit',
			),
			$refused->context()
		);
		$this->assertSame( array( 'reason' => Transfers::NOT_SENDABLE ), $refused->details() );
		$this->assertCount( 2, $this->transferLedger( $b, $uuid ) );
		$this->assertSame( array( 2, 0, 0 ), array_values( (array) $this->committedItem( $b, $sent['spare'], $sent['source'] ) ) );
	}

	/**
	 * Tests a receipt in parts with loss: received units of the order's line join its allocation at the destination, which it already held one unit of, lost ones leave the order short, and the transfer is received once nothing is on the way.
	 *
	 * Planted violations, shown red and removed: (1) in Transfers::receiveLine(), leave out
	 * allocateReceived(): the destination's `allocated` grows with no allocation row behind it,
	 * and doctor finds it. (2) In Transfers::receiveLine(), leave out the shrink of the allocation
	 * in transit: the order's units count twice, in transit and at the destination.
	 *
	 * @since 0.2.0
	 */
	public function test_a_receipt_in_parts_with_loss_moves_the_orders_allocation(): void {
		$sent        = $this->sentOrderTransfer( 1 );
		$b           = $this->secondConnection();
		$uuid        = (string) $sent['answer']['transfer_uuid'];
		$ordered     = self::lineOf( $sent['answer'], $sent['ordered'] );
		$spare       = self::lineOf( $sent['answer'], $sent['spare'] );
		$destination = $this->defaultLocation;

		$first = $this->receive(
			$uuid,
			array(
				array( $ordered, 2, 0, 0 ),
				array( $spare, 2, 0, 0 ),
			)
		);

		$this->assertSame( 'in_transit', $first['state'], 'One unit of the order\'s line is still on the way.' );
		$this->assertSame( array( 3, 3, 0 ), array_values( (array) $this->committedItem( $b, $sent['ordered'], $destination ) ), 'Two received join the one the order line held there, on hand and allocated.' );
		$this->assertSame( array( 2, 0, 0 ), array_values( (array) $this->committedItem( $b, $sent['spare'], $destination ) ), 'Units no order waits for arrive available.' );
		$this->assertSame(
			array(
				$sent['ordered'] . '@' . $destination . ':' . self::ORDER_LINE . ':3:open',
				$sent['ordered'] . '@' . $sent['source'] . ':' . self::ORDER_LINE . ':1:in_transit',
			),
			$this->committedAllocations( $b, $sent['order'] )
		);

		$doctor = $this->projectionCheck();

		$this->assertTrue( $doctor->passed, 'Doctor after the first receipt: ' . implode( "\n", $doctor->findings ) );

		$second = $this->receive( $uuid, array( array( $ordered, 0, 1, 2 ) ) );

		$this->assertSame( 'received', $second['state'] );
		$this->assertNotNull( $second['received_at'] );
		$this->assertSame( array( $sent['ordered'] . '@' . $destination . ':' . self::ORDER_LINE . ':3:open' ), $this->committedAllocations( $b, $sent['order'] ), 'The allocation in transit is gone once its last unit is lost.' );
		$this->assertSame( array( $sent['ordered'] . ':3:2:1:' . $sent['allocation'], $sent['spare'] . ':2:2:0:-' ), $this->committedLines( $b, $uuid ), 'The line keeps naming the allocation it carried.' );
		$this->assertSame(
			array(
				'order_id'    => $sent['order'],
				'transfer_id' => (int) $this->committedTransfer( $b, $uuid )['id'],
				'line_id'     => $ordered,
				'variant_id'  => $sent['ordered'],
				'quantity'    => 1,
			),
			$this->committedPayload( $b, StockTransferShort::eventName() )
		);
		$this->assertSame( 2, $this->committedEvents( $b, StockTransferred::eventName() ), 'The send, and the one receipt that received units.' );
		$this->assertSame( StockTransferred::RECEIVED, $this->committedPayload( $b, StockTransferred::eventName() )['phase'] );
		$this->assertSame(
			array(
				$sent['ordered'] . '@' . $sent['source'] . ':-3:0:transfer_out',
				$sent['spare'] . '@' . $sent['source'] . ':-2:2:transfer_out',
				$sent['ordered'] . '@' . $destination . ':2:3:transfer_in',
				$sent['spare'] . '@' . $destination . ':2:2:transfer_in',
			),
			$this->transferLedger( $b, $uuid )
		);
		$this->assertNotSame( array(), preg_grep( '/^Critical: transfer \d+ lost units order ' . $sent['order'] . ' waited for, but the order is gone, not on hold/', $this->projectionCheck()->findings ), 'Until the order is on hold, doctor names the shortage.' );

		// The kernel's listener of the shortage puts the order on hold; the order module answers so.
		$this->orderStatus = static fn(): string => 'on_hold';

		$doctor = $this->projectionCheck();

		$this->assertTrue( $doctor->passed, 'Doctor after the loss, the order on hold: ' . implode( "\n", $doctor->findings ) );
	}

	/**
	 * Tests that a receipt is refused when the transfer is not in transit, when a line is not the transfer's, and when it would receive more than is on the way, with nothing changed.
	 *
	 * @since 0.2.0
	 */
	public function test_a_receipt_names_the_transfers_lines_and_what_is_on_the_way(): void {
		$variant = self::variant();
		$source  = $this->plantLocation();
		$b       = $this->secondConnection();

		$this->stockItem( $variant, 5, $source );

		$draft = $this->draft( $source, $this->defaultLocation, array( $variant => 3 ) );
		$uuid  = (string) $draft['transfer_uuid'];
		$line  = self::lineOf( $draft, $variant );

		$this->assertSame( array( 'reason' => Transfers::NOT_RECEIVABLE ), $this->assertRefused( InventoryError::TransferState, fn(): array => $this->receive( $uuid, array( array( $line, 1, 0, 0 ) ) ) )->details() );

		$this->send( $uuid );

		$this->assertSame( array( 'line_id' => $line + 1000 ), $this->assertRefused( InventoryError::TransferNotFound, fn(): array => $this->receive( $uuid, array( array( $line + 1000, 1, 0, 0 ) ) ) )->details() );
		$this->assertSame( array( 'field' => 'lines' ), $this->assertRefused( InventoryError::TransferInvalid, fn(): array => $this->receive( $uuid, array( array( $line, 3, 1, 0 ) ) ) )->context(), 'Four units of a line of three.' );
		$this->assertSame( array( 'field' => 'lines' ), $this->assertRefused( InventoryError::TransferInvalid, fn(): array => $this->receive( $uuid, array( array( $line, 0, 0, 0 ) ) ) )->context(), 'Nothing received or lost.' );
		$this->assertSame(
			array( 'field' => 'lines' ),
			$this->assertRefused(
				InventoryError::TransferInvalid,
				fn(): array => $this->receive(
					$uuid,
					array(
						array( $line, 1, 0, 0 ),
						array( $line, 1, 0, 0 ),
					)
				)
			)->context(),
			'A line named twice.'
		);
		$this->assertSame( array( $variant . ':3:0:0:-' ), $this->committedLines( $b, $uuid ) );
		$this->assertSame( array( 0, 0, 0 ), array_values( (array) $this->committedItem( $b, $variant ) ), 'Nothing arrived.' );
	}

	/**
	 * Tests that the same receipt sent twice is recorded once: the second expects a figure the line no longer has, and is refused with the figure it has.
	 *
	 * Planted violation, shown red and removed: in MysqlTransferRepository::RECEIVE_LINE, drop
	 * `received_quantity = %d` from the WHERE clause. The repeated receipt then adds its units again.
	 *
	 * @since 0.2.0
	 */
	public function test_the_same_receipt_twice_is_recorded_once(): void {
		$moved = $this->sentDraft();
		$b     = $this->secondConnection();

		$this->receive( $moved['uuid'], array( array( $moved['line'], 1, 0, 0 ) ) );

		$refused = $this->assertRefused( InventoryError::TransferReceiptConflict, fn(): array => $this->receive( $moved['uuid'], array( array( $moved['line'], 1, 0, 0 ) ) ) );

		$this->assertSame(
			array(
				'line_id'           => $moved['line'],
				'expected'          => 0,
				'expected_lost'     => 0,
				'received_quantity' => 1,
				'lost_quantity'     => 0,
			),
			$refused->context()
		);
		$this->assertSame( 1, $this->committedItem( $b, $moved['variant'] )['on_hand'] ?? null );
		$this->assertSame( array( $moved['variant'] . ':3:1:0:-' ), $this->committedLines( $b, $moved['uuid'] ) );
	}

	/**
	 * Tests that a receipt that only writes units off, sent twice, writes them off once: it leaves the received figure as it was, so the second is refused on the lost figure it expects.
	 *
	 * Planted violation, shown red and removed: in MysqlTransferRepository::RECEIVE_LINE, make the
	 * lost figure match whatever it is, `( lost_quantity = %d OR 1 = 1 )`: the repeat writes the
	 * unit off again, leaving the line two lost and one on the way.
	 *
	 * @since 0.2.0
	 */
	public function test_a_receipt_that_only_writes_units_off_sent_twice_writes_them_off_once(): void {
		$moved = $this->sentDraft();
		$b     = $this->secondConnection();

		$this->receive( $moved['uuid'], array( array( $moved['line'], 0, 1, 0, 0 ) ) );

		$refused = $this->assertRefused( InventoryError::TransferReceiptConflict, fn(): array => $this->receive( $moved['uuid'], array( array( $moved['line'], 0, 1, 0, 0 ) ) ) );

		$this->assertSame(
			array(
				'line_id'           => $moved['line'],
				'expected'          => 0,
				'expected_lost'     => 0,
				'received_quantity' => 0,
				'lost_quantity'     => 1,
			),
			$refused->context()
		);
		$this->assertSame( array( $moved['variant'] . ':3:0:1:-' ), $this->committedLines( $b, $moved['uuid'] ), 'One unit lost, two on the way.' );
		$this->assertSame( 'in_transit', $this->committedTransfer( $b, $moved['uuid'] )['state'] );
		$this->assertSame( array( 0, 0, 0 ), array_values( (array) $this->committedItem( $b, $moved['variant'] ) ), 'Nothing arrived.' );

		$doctor = $this->projectionCheck();

		$this->assertTrue( $doctor->passed, 'Doctor: ' . implode( "\n", $doctor->findings ) );
	}

	/**
	 * Tests that a receipt reading the line from before a loss is refused, though the received figure it expects still holds, and that one reading both figures applies.
	 *
	 * Planted violation, shown red and removed: the one of the test above: the stale receipt
	 * receives two units on a line that has one lost, as if the loss had not been written off.
	 *
	 * @since 0.2.0
	 */
	public function test_a_receipt_expecting_a_stale_lost_figure_is_refused(): void {
		$moved = $this->sentDraft();
		$b     = $this->secondConnection();

		$this->receive( $moved['uuid'], array( array( $moved['line'], 0, 1, 0, 0 ) ) );

		$refused = $this->assertRefused( InventoryError::TransferReceiptConflict, fn(): array => $this->receive( $moved['uuid'], array( array( $moved['line'], 2, 0, 0, 0 ) ) ) );

		$this->assertSame( array( 0, 0, 0, 1 ), array( $refused->context()['expected'], $refused->context()['expected_lost'], $refused->context()['received_quantity'], $refused->context()['lost_quantity'] ) );
		$this->assertSame( array( $moved['variant'] . ':3:0:1:-' ), $this->committedLines( $b, $moved['uuid'] ), 'The stale receipt changed nothing.' );

		$received = $this->receive( $moved['uuid'], array( array( $moved['line'], 2, 0, 0, 1 ) ) );

		$this->assertSame( 'received', $received['state'], 'Two received and one lost: nothing is on the way.' );
		$this->assertSame( array( $moved['variant'] . ':3:2:1:-' ), $this->committedLines( $b, $moved['uuid'] ) );
		$this->assertSame( 2, $this->committedItem( $b, $moved['variant'] )['on_hand'] ?? null );
	}

	/**
	 * Tests that a loss of an order's units reported twice leaves the order short once: the allocation in transit shrinks by the unit lost once, and one StockTransferShort says so.
	 *
	 * Planted violation, shown red and removed: the one of the tests above: the repeat shrinks the
	 * allocation in transit again and announces a second shortage of the same unit.
	 *
	 * @since 0.2.0
	 */
	public function test_a_loss_of_an_orders_units_reported_twice_leaves_it_short_once(): void {
		$sent    = $this->sentOrderTransfer();
		$b       = $this->secondConnection();
		$uuid    = (string) $sent['answer']['transfer_uuid'];
		$ordered = self::lineOf( $sent['answer'], $sent['ordered'] );

		$this->receive( $uuid, array( array( $ordered, 0, 1, 0, 0 ) ) );
		$this->assertRefused( InventoryError::TransferReceiptConflict, fn(): array => $this->receive( $uuid, array( array( $ordered, 0, 1, 0, 0 ) ) ) );

		$this->assertSame( array( $sent['ordered'] . ':3:0:1:' . $sent['allocation'], $sent['spare'] . ':2:0:0:-' ), $this->committedLines( $b, $uuid ), 'One unit lost, two of the order\'s and two spare on the way.' );
		$this->assertSame( array( $sent['ordered'] . '@' . $sent['source'] . ':' . self::ORDER_LINE . ':2:in_transit' ), $this->committedAllocations( $b, $sent['order'] ), 'The allocation in transit shrank once, by the unit lost.' );
		$this->assertSame( 1, $this->committedEvents( $b, StockTransferShort::eventName() ), 'One shortage, announced once.' );
		$this->assertSame( 'in_transit', $this->committedTransfer( $b, $uuid )['state'] );

		$doctor = $this->projectionCheck();

		$this->assertTrue( $doctor->passed, 'Doctor: ' . implode( "\n", $doctor->findings ) );
	}

	/**
	 * Tests that two receipts of one line at once record it once: the second waits for the first's lock of the transfer, then finds the line received.
	 *
	 * A receives one unit. When A is about to send the line's statement, holding the transfer's
	 * lock, B sends the receipt's first statement and the server shows it waiting. A commits; B's
	 * lock goes through, the transfer still in transit, and B's line statement, expecting nothing
	 * received, matches no row. Then B runs the whole service and is told the figure.
	 *
	 * Planted violation, shown red and removed: in MysqlTransferRepository::RECEIVE_LINE, drop
	 * `received_quantity = %d` from the WHERE clause: B's line statement records the unit again.
	 *
	 * @since 0.2.0
	 *
	 * @group concurrency
	 */
	public function test_two_receipts_of_one_line_at_once_record_it_once(): void {
		$moved = $this->sentDraft();
		$b     = $this->secondConnection();
		$lock  = $this->raw( MysqlTransferRepository::LOCK_RECEIVABLE, $moved['uuid'] );

		$raced = $this->beforeStatement(
			self::shapeOf( MysqlTransferRepository::RECEIVE_LINE ),
			function () use ( $b, $lock ): void {
				$b->query( 'SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED' );
				$b->query( 'START TRANSACTION' );
				$b->queryAsync( $lock );
				$this->awaitWaiting( $b, $lock, 'updating' );
			}
		);

		$this->receive( $moved['uuid'], array( array( $moved['line'], 1, 0, 0 ) ) );

		$this->assertTrue( $raced->fired, 'B never raced A.' );
		$this->assertTrue( $b->isReady( 5000 ), 'B\'s lock of the transfer must go through once A commits.' );
		$this->assertSame( 1, $b->reap(), 'The transfer is still in transit: two units are on the way.' );

		$b->query( $this->raw( MysqlTransferRepository::RECEIVE_LINE, 1, 0, $moved['line'], $moved['id'], 0, 0, 1, 0 ) );

		$applied = $b->affectedRows();

		$b->query( 'ROLLBACK' );

		$this->assertSame( 0, $applied, 'B\'s receipt expecting nothing received recorded the unit A received.' );
		$this->assertSame( InventoryError::TransferReceiptConflict, $this->assertRefused( InventoryError::TransferReceiptConflict, fn(): array => $this->receive( $moved['uuid'], array( array( $moved['line'], 1, 0, 0 ) ), $this->secondTransfers() ) )->errorCode() );
		$this->assertSame( array( $moved['variant'] . ':3:1:0:-' ), $this->committedLines( $b, $moved['uuid'] ) );
		$this->assertSame( 1, $this->committedItem( $b, $moved['variant'] )['on_hand'] ?? null );
	}

	/**
	 * Plants an order's three units at a second location and four spare units of another variant, requests the order's transfer to the default location, adds two spare units to it, and sends it.
	 *
	 * @since 0.2.0
	 *
	 * @param int $alreadyThere Optional. Units of the order's line it already holds at the default location. Default 0.
	 * @return array{answer: array<string, mixed>, order: int, ordered: int, spare: int, source: int, allocation: int} The send's answer and what it moved.
	 */
	private function sentOrderTransfer( int $alreadyThere = 0 ): array {
		$ordered = self::variant();
		$spare   = self::variant();
		$source  = $this->plantLocation();
		$order   = self::order();

		$this->stockItem( $ordered, 3, $source );
		$this->stockItem( $spare, 4, $source );

		$allocation = $this->plantAllocation( $ordered, $source, $order, self::ORDER_LINE, 3 );

		if ( $alreadyThere > 0 ) {
			$this->service->adjust( $ordered, $this->defaultLocation, $alreadyThere, LedgerReason::Received, Actor::user( 1 ) );
			$this->plantAllocation( $ordered, $this->defaultLocation, $order, self::ORDER_LINE, $alreadyThere );
		}

		$requested = $this->requestFor( $order, $this->defaultLocation );

		$this->assertCount( 1, $requested, 'One source, one transfer.' );

		$this->transfers->updateTransfer(
			array(
				'transfer_uuid' => $requested[0]->uuid,
				'lines'         => array(
					array(
						'variant_id' => $spare,
						'quantity'   => 2,
					),
				),
			),
			$this->manager
		);

		return array(
			'answer'     => $this->send( $requested[0]->uuid ),
			'order'      => $order,
			'ordered'    => $ordered,
			'spare'      => $spare,
			'source'     => $source,
			'allocation' => $allocation,
		);
	}

	/**
	 * Plants five units of a variant at a second location and sends three of them to the default location on a draft.
	 *
	 * @since 0.2.0
	 *
	 * @return array{uuid: string, id: int, line: int, variant: int} The transfer, its id, its line and the variant.
	 */
	private function sentDraft(): array {
		$variant = self::variant();
		$source  = $this->plantLocation();

		$this->stockItem( $variant, 5, $source );

		$draft = $this->draft( $source, $this->defaultLocation, array( $variant => 3 ) );
		$uuid  = (string) $draft['transfer_uuid'];

		$this->send( $uuid );

		return array(
			'uuid'    => $uuid,
			'id'      => (int) $this->committedTransfer( $this->secondConnection(), $uuid )['id'],
			'line'    => self::lineOf( $draft, $variant ),
			'variant' => $variant,
		);
	}
}
