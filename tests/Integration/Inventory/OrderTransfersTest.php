<?php
/**
 * Tests the transfers an order waits for against real tables: their request, the order's cancel, its races with a receipt, and a variant's delete
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Inventory;

use SEOCart\Inventory\Application\InventoryError;
use SEOCart\Inventory\Domain\Event\StockTransferRequested;
use SEOCart\Inventory\Domain\Transfer;
use SEOCart\Inventory\Domain\TransferKind;
use SEOCart\Inventory\Domain\TransferState;
use SEOCart\Inventory\Infrastructure\MysqlStockRepository;
use SEOCart\Inventory\Infrastructure\MysqlTransferRepository;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Platform\Database\Isolation;
use SEOCart\Platform\Database\ServerKind;
use SEOCart\Tests\Support\Inventory\TransferTestCase;
use SEOCart\Tests\Support\QueryCounter;

/**
 * The transfers an order waits for, held to what is committed: one per source, carrying the order's allocations, and detached when the order is cancelled.
 *
 * The order's units are planted as the allocations a placement makes; the request and the cancel
 * run inside a unit of work at READ COMMITTED, as the placement's settlement and the order's
 * cancel call them.
 *
 * Planted violations, each named on its test.
 *
 * @since 0.2.0
 */
final class OrderTransfersTest extends TransferTestCase {

	use QueryCounter;

	/**
	 * Tests that one requested transfer is written per location the order's units are at, other than its destination, each line carrying its allocation, with one event per transfer; and counts the statements for one source and for two.
	 *
	 * @since 0.2.0
	 */
	public function test_one_transfer_is_requested_per_source_carrying_the_allocations(): void {
		$b      = $this->secondConnection();
		$first  = $this->plantLocation();
		$second = $this->plantLocation();
		$order  = self::order();
		$here   = self::variant();
		$a      = self::variant();
		$c      = self::variant();

		$this->stockItem( $here, 2 );
		$this->stockItem( $a, 5, $first );
		$this->stockItem( $c, 5, $first );
		$this->stockItem( $c, 5, $second );

		$this->plantAllocation( $here, $this->defaultLocation, $order, 1, 2 );

		$fromFirst  = array(
			$this->plantAllocation( $a, $first, $order, 2, 1 ),
			$this->plantAllocation( $c, $first, $order, 3, 2 ),
		);
		$fromSecond = $this->plantAllocation( $c, $second, $order, 4, 3 );

		$statements = 0;
		$requested  = $this->db->transaction(
			function () use ( $order, &$statements ): array {
				$made = array();
				$log  = $this->captureQueries(
					function () use ( $order, &$made ): void {
						$made = $this->transfers->requestForOrder( $order, $this->defaultLocation );
					}
				);

				$statements = $log->count();

				return $made;
			},
			null,
			Isolation::ReadCommitted
		);

		$this->assertSame( array( $first, $second ), array_map( static fn( Transfer $transfer ): int => $transfer->sourceLocationId, $requested ) );
		$this->assertSame( 6, $statements, 'Two sources: the read of the allocations, then per source the transfer and its lines, and the two events in one outbox insert.' );

		foreach ( $requested as $transfer ) {
			$this->assertSame( array( TransferKind::Order, TransferState::Requested, $order, $this->defaultLocation ), array( $transfer->kind, $transfer->state, $transfer->orderId, $transfer->destinationLocationId ) );
			$this->assertSame(
				array(
					'kind'       => 'order',
					'state'      => 'requested',
					'order_id'   => (string) $order,
					'created_by' => null,
				),
				array_intersect_key( $this->committedTransfer( $b, $transfer->uuid ), array_flip( array( 'kind', 'state', 'order_id', 'created_by' ) ) )
			);
		}

		$this->assertSame( array( $a . ':1:0:0:' . $fromFirst[0], $c . ':2:0:0:' . $fromFirst[1] ), $this->committedLines( $b, $requested[0]->uuid ) );
		$this->assertSame( array( $c . ':3:0:0:' . $fromSecond ), $this->committedLines( $b, $requested[1]->uuid ) );
		$this->assertSame( 2, $this->committedEvents( $b, StockTransferRequested::eventName() ) );
		$this->assertSame( array( $c ), $this->committedPayload( $b, StockTransferRequested::eventName() )['variant_ids'] );

		$single = self::order();

		$this->plantAllocation( $a, $first, $single, 5, 1 );

		$this->db->transaction(
			function () use ( $single, &$statements ): void {
				$statements = $this->captureQueries( fn() => $this->transfers->requestForOrder( $single, $this->defaultLocation ) )->count();
			},
			null,
			Isolation::ReadCommitted
		);

		$this->assertSame( 4, $statements, 'One source: the read of the allocations, the transfer, its lines and its event.' );
	}

	/**
	 * Tests that a request runs only inside the caller's unit of work, and that the caller's rollback leaves nothing of it.
	 *
	 * @since 0.2.0
	 */
	public function test_a_request_belongs_to_the_callers_unit_of_work(): void {
		$b      = $this->secondConnection();
		$source = $this->plantLocation();
		$order  = self::order();
		$v      = self::variant();

		$this->stockItem( $v, 3, $source );
		$this->plantAllocation( $v, $source, $order, 1, 3 );

		try {
			$this->transfers->requestForOrder( $order, $this->defaultLocation );
			$this->fail( 'A request outside a transaction was made.' );
		} catch ( \LogicException $refused ) {
			$this->assertStringContainsString( 'requestForOrder', $refused->getMessage() );
		}

		try {
			$this->db->transaction(
				function () use ( $order ): void {
					$this->transfers->requestForOrder( $order, $this->defaultLocation );

					throw new \RuntimeException( 'The placement fails after the request.' );
				},
				null,
				Isolation::ReadCommitted
			);
		} catch ( \RuntimeException $failed ) {
			$this->assertSame( 'The placement fails after the request.', $failed->getMessage() );
		}

		$this->assertSame( '0', $b->fetchValue( sprintf( 'SELECT COUNT(*) FROM `%s` WHERE order_id = %d', $this->table( 'stock_transfers' ), $order ) ) );
		$this->assertSame( 0, $this->committedEvents( $b, StockTransferRequested::eventName() ) );
	}

	/**
	 * Tests that an order cancelled while its transfer is requested gives its units back where they are, detaches the lines, and cancels the transfer in the same unit of work.
	 *
	 * @since 0.2.0
	 */
	public function test_an_order_cancelled_while_its_transfer_is_requested_cancels_the_transfer(): void {
		$b      = $this->secondConnection();
		$source = $this->plantLocation();
		$order  = self::order();
		$v      = self::variant();

		$this->stockItem( $v, 3, $source );

		$this->plantAllocation( $v, $source, $order, 1, 3 );

		$uuid = $this->requestFor( $order, $this->defaultLocation )[0]->uuid;

		$this->detach( $order );

		$this->assertSame( 'cancelled', $this->committedTransfer( $b, $uuid )['state'] );
		$this->assertSame( array( $v . ':3:0:0:-' ), $this->committedLines( $b, $uuid ) );
		$this->assertSame( array( $v . '@' . $source . ':1:3:cancelled' ), $this->committedAllocations( $b, $order ) );
		$this->assertSame( array( 3, 0, 0 ), array_values( (array) $this->committedItem( $b, $v, $source ) ), 'The units are the source\'s again, available.' );

		$doctor = $this->projectionCheck();

		$this->assertTrue( $doctor->passed, 'Doctor: ' . implode( "\n", $doctor->findings ) );
	}

	/**
	 * Tests that an order cancelled while its units are on the way leaves the transfer in transit, carrying nothing, and that its receipt then completes it as a plain move: the units arrive available.
	 *
	 * Planted violation, shown red and removed: in Transfers::detachOrder(), cancel every transfer
	 * locked, whatever its state, and in MysqlTransferRepository::CANCEL accept `in_transit`: the
	 * transfer in transit is cancelled, its units vanish from every sum, and its receipt is refused.
	 *
	 * @since 0.2.0
	 */
	public function test_an_order_cancelled_while_its_units_are_on_the_way_lets_the_transfer_complete_as_a_move(): void {
		$b      = $this->secondConnection();
		$source = $this->plantLocation();
		$order  = self::order();
		$v      = self::variant();

		$this->stockItem( $v, 3, $source );
		$this->plantAllocation( $v, $source, $order, 1, 3 );

		$uuid = $this->requestFor( $order, $this->defaultLocation )[0]->uuid;
		$sent = $this->send( $uuid );

		$this->detach( $order );

		$this->assertSame( 'in_transit', $this->committedTransfer( $b, $uuid )['state'] );
		$this->assertSame( array( $v . ':3:0:0:-' ), $this->committedLines( $b, $uuid ), 'The line goes on carrying no order\'s units.' );
		$this->assertSame( array( $v . '@' . $source . ':1:3:cancelled' ), $this->committedAllocations( $b, $order ) );

		$received = $this->receive( $uuid, array( array( self::lineOf( $sent, $v ), 3, 0, 0 ) ) );

		$this->assertSame( 'received', $received['state'] );
		$this->assertSame( array( 3, 0, 0 ), array_values( (array) $this->committedItem( $b, $v ) ), 'The units arrive available, promised to no one.' );
		$this->assertSame( array( $v . '@' . $source . ':1:3:cancelled' ), $this->committedAllocations( $b, $order ), 'No allocation was made for the cancelled order.' );

		$doctor = $this->projectionCheck();

		$this->assertTrue( $doctor->passed, 'Doctor: ' . implode( "\n", $doctor->findings ) );
	}

	/**
	 * Tests the order's cancel coming first, racing a receipt of its transfer: the receipt waits for the transfer's lock, then finds the line detached, and the units arrive available. No retry on either side.
	 *
	 * A cancels the order. When A is about to detach the line, holding the transfer's lock, B
	 * sends the receipt's first statement and the server shows it waiting. A commits; B's lock
	 * goes through. Then the receipt runs whole on a second connection.
	 *
	 * Planted violations, shown red and removed: (1) in MysqlTransferRepository::ORDER_TRANSFERS,
	 * drop `FOR UPDATE`: the cancel no longer holds the transfer, and the receipt does not wait for
	 * it. (2) In Transfers::detachOrder(), leave the lines attached, and in
	 * MysqlStockRepository::IN_TRANSIT_ALLOCATION drop `AND state = 'in_transit'`: the receipt finds
	 * the cancelled allocation and allocates the units to the dead order at the destination.
	 *
	 * @since 0.2.0
	 *
	 * @group concurrency
	 */
	public function test_a_cancel_that_comes_first_leaves_the_receipt_nothing_to_allocate(): void {
		$race = $this->orderOnTheWay();
		$b    = $this->secondConnection();
		$lock = $this->raw( MysqlTransferRepository::LOCK_RECEIVABLE, $race['uuid'] );

		$raced = $this->beforeStatement(
			self::shapeOf( MysqlTransferRepository::DETACH_LINES ),
			function () use ( $b, $lock ): void {
				$b->query( 'SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED' );
				$b->query( 'START TRANSACTION' );
				$b->queryAsync( $lock );
				$this->awaitWaiting( $b, $lock, 'updating' );
			}
		);

		$this->detach( $race['order'] );

		$this->assertTrue( $raced->fired, 'B never raced A.' );
		$this->assertTrue( $b->isReady( 5000 ), 'B\'s lock of the transfer must go through once A commits.' );
		$this->assertSame( 1, $b->reap() );

		$b->query( 'ROLLBACK' );

		$received = $this->receive( $race['uuid'], array( array( $race['line'], 3, 0, 0 ) ), $this->secondTransfers() );

		$this->assertSame( 'received', $received['state'] );
		$this->assertSame( array( 3, 0, 0 ), array_values( (array) $this->committedItem( $b, $race['variant'] ) ), 'The units arrive available.' );
		$this->assertSame( array( $race['variant'] . '@' . $race['source'] . ':1:3:cancelled' ), $this->committedAllocations( $b, $race['order'] ) );
		$this->assertSame( array(), $this->sleeps, 'Neither side was run again.' );

		$doctor = $this->projectionCheck();

		$this->assertTrue( $doctor->passed, 'Doctor: ' . implode( "\n", $doctor->findings ) );
	}

	/**
	 * Tests a receipt coming first, racing the order's cancel: the cancel waits for the transfer's lock, then finds the received units as the order's open allocation at the destination and gives them back there. No retry on either side.
	 *
	 * A receives two of the three units. When A is about to write the order line's allocation at
	 * the destination, holding the transfer's lock and the destination's item, B sends the
	 * cancel's first statement, the lock of the order's transfers, and the server shows it
	 * waiting. A commits; B's lock goes through. Then the cancel runs whole on a second connection.
	 *
	 * Planted violation, shown red and removed: in MysqlTransferRepository::ORDER_TRANSFERS, drop
	 * `FOR UPDATE`: the cancel no longer waits for the receipt's lock of the transfer, and could
	 * read the order's allocation in transit while the receipt moves it.
	 *
	 * @since 0.2.0
	 *
	 * @group concurrency
	 */
	public function test_a_receipt_that_comes_first_leaves_the_cancel_the_units_to_give_back(): void {
		$race  = $this->orderOnTheWay();
		$b     = $this->secondConnection();
		$locks = $this->raw( MysqlTransferRepository::ORDER_TRANSFERS, $race['order'] );

		$raced = $this->beforeStatement(
			self::shapeOf( MysqlStockRepository::ALLOCATE_RECEIVED ),
			function () use ( $b, $locks ): void {
				$b->query( 'SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED' );
				$b->query( 'START TRANSACTION' );
				$b->queryAsync( $locks );
				$this->awaitWaiting(
					$b,
					$locks,
					array(
						ServerKind::MySQL->value   => 'executing',
						ServerKind::MariaDB->value => 'Sending data',
					)
				);
			}
		);

		$this->receive( $race['uuid'], array( array( $race['line'], 2, 0, 0 ) ) );

		$this->assertTrue( $raced->fired, 'B never raced A.' );
		$this->assertTrue( $b->isReady( 5000 ), 'B\'s lock of the order\'s transfers must go through once A commits.' );

		$b->reap();
		$b->query( 'ROLLBACK' );

		list( $second, $secondDb ) = $this->secondRunner();

		$secondDb->transaction( fn() => $second->detachOrder( $race['order'] ), null, Isolation::ReadCommitted );

		$this->assertSame( array( 2, 0, 0 ), array_values( (array) $this->committedItem( $b, $race['variant'] ) ), 'The two received are the destination\'s again, available.' );
		$this->assertSame(
			array(
				$race['variant'] . '@' . $this->defaultLocation . ':1:2:cancelled',
				$race['variant'] . '@' . $race['source'] . ':1:1:cancelled',
			),
			$this->committedAllocations( $b, $race['order'] )
		);
		$this->assertSame( array( $race['variant'] . ':3:2:0:-' ), $this->committedLines( $b, $race['uuid'] ), 'The last unit goes on as no order\'s.' );
		$this->assertSame( array(), $this->sleeps, 'Neither side was run again.' );

		$doctor = $this->projectionCheck();

		$this->assertTrue( $doctor->passed, 'Doctor: ' . implode( "\n", $doctor->findings ) );
	}

	/**
	 * Tests that a variant whose units are on a transfer in transit cannot be deleted, and that once they are received it can.
	 *
	 * Planted violation, shown red and removed: in StockService::isPromised(), leave out
	 * hasTransferInFlight(): the variant is deleted while its units are on the way, and the line
	 * names a variant with no stock.
	 *
	 * @since 0.2.0
	 */
	public function test_a_variant_on_the_way_cannot_be_deleted_until_received(): void {
		$b      = $this->secondConnection();
		$source = $this->plantLocation();
		$v      = self::variant();

		$this->stockItem( $v, 4, $source );

		$draft = $this->draft( $source, $this->defaultLocation, array( $v => 4 ) );
		$uuid  = (string) $draft['transfer_uuid'];

		$this->send( $uuid );

		$refused = $this->assertRefused( InventoryError::DeleteBlocked, fn() => $this->db->transaction( fn() => $this->service->deleteVariants( array( $v ), Actor::user( 1 ) ) ) );

		$this->assertSame( array( 'variant_id' => $v ), $refused->context() );
		$this->assertSame( array( $v ), $this->db->transaction( fn(): array => $this->service->openAllocationVariants( array( $v ) ) ), 'The product\'s delete check answers the same.' );

		$this->receive( $uuid, array( array( self::lineOf( $draft, $v ), 4, 0, 0 ) ) );

		$this->assertSame( array(), $this->db->transaction( fn(): array => $this->service->openAllocationVariants( array( $v ) ) ) );

		$this->db->transaction( fn() => $this->service->deleteVariants( array( $v ), Actor::user( 1 ) ) );

		$this->assertNull( $this->committedItem( $b, $v ), 'Received, the variant is deleted with its stock.' );
	}

	/**
	 * Plants an order's three units at a second location, requests their transfer to the default location and sends it.
	 *
	 * @since 0.2.0
	 *
	 * @return array{order: int, variant: int, source: int, uuid: string, line: int} The order, its variant, the source, the transfer and its line.
	 */
	private function orderOnTheWay(): array {
		$source = $this->plantLocation();
		$order  = self::order();
		$v      = self::variant();

		$this->stockItem( $v, 3, $source );
		$this->plantAllocation( $v, $source, $order, 1, 3 );

		$uuid = $this->requestFor( $order, $this->defaultLocation )[0]->uuid;
		$sent = $this->send( $uuid );

		return array(
			'order'   => $order,
			'variant' => $v,
			'source'  => $source,
			'uuid'    => $uuid,
			'line'    => self::lineOf( $sent, $v ),
		);
	}
}
