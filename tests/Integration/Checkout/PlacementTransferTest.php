<?php
/**
 * Tests that a placement whose units are not at the location it ships from holds them where they are and requests their transfer
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Checkout;

use SEOCart\Checkout\Domain\CheckoutError;
use SEOCart\Inventory\Application\InventoryError;
use SEOCart\Inventory\Application\LocationOperations;
use SEOCart\Inventory\Application\Locations;
use SEOCart\Inventory\Domain\Event\StockTransferRequested;
use SEOCart\Inventory\Domain\Routing\FulfilmentMode;
use SEOCart\Inventory\Infrastructure\InventoryTables;
use SEOCart\Order\Infrastructure\OrderTables;
use SEOCart\Payment\Infrastructure\Gateway\StubGateway;
use SEOCart\Platform\Events\OutboxTable;
use SEOCart\Support\Error\CodedException;
use SEOCart\Tests\Support\Checkout\PlacementTestCase;
use SEOCart\Tests\Support\Checkout\RoutedPlacements;
use SEOCart\Tests\Support\CreatesUsers;
use SEOCart\Tests\Support\GrantsCapabilities;
use SEOCart\Tests\Support\QueryLog;

defined( 'ABSPATH' ) || exit;

/**
 * In `transfer` mode the order ships once, from its destination: the units are held and allocated where they are, and the settlement requests one transfer per source in its own transaction.
 *
 * Planted violations: in PlaceOrder::placeInside(), hold every unit at the destination (the
 * destination has none, so the placement is refused); in SettlePlacement::allocate(), request no
 * transfer (none is written, and the order is stranded), or request one whatever the store's mode
 * (a store that splits orders gets a transfer for a part it ships where it is); in
 * StockService::claimAfresh(), honour the store's mode (under `refuse` an order whose hold lapsed
 * is parked after it was paid for); in Modules::inventoryRegister(), answer false from the order
 * module's closure that a location's deletion asks (the location an order awaiting payment ships
 * from is deleted).
 *
 * @since 0.2.0
 */
final class PlacementTransferTest extends PlacementTestCase {

	use CreatesUsers;
	use GrantsCapabilities;
	use RoutedPlacements;

	/**
	 * Deletes the users this test created, then closes what the parent opened.
	 *
	 * @since 0.2.0
	 */
	public function tear_down(): void {
		$this->deleteCreatedUsers();

		parent::tear_down();
	}

	/**
	 * Tests that units the destination does not have are held and allocated at their source, the destination's row is locked in the first unit, and one transfer is requested and recorded in the second.
	 *
	 * @since 0.2.0
	 */
	public function test_units_away_from_the_destination_are_held_there_and_their_transfer_requested(): void {
		$variant     = $this->sellable( 0 );
		$destination = $this->plantLocation( -1 );
		$source      = $this->plantLocation( 5, true, false );
		$events      = $this->outboxCount();

		// A warehouse that ships no online orders has the units: the order ships from the first location, after a transfer.
		$this->stockAt( $variant, $source, 5 );

		$this->routeIn( FulfilmentMode::Transfer );
		$this->readyCart( array( $variant => 2 ) );

		$input  = $this->placeInput();
		$answer = null;
		$log    = $this->captureQueries(
			function () use ( $input, &$answer ): void {
				$answer = $this->placement->place( $input, self::guest() );
			}
		);
		$order  = $this->routedOrder( (string) $answer['order_uuid'] );
		$units  = $this->units( $log );

		$this->assertSame( array( 'approved', 3 ), array( $answer['outcome'], $answer['transfer_days'] ) );
		$this->assertSame( array( $destination, 3 ), array( $order['fulfilment_location_id'], $order['transfer_lead_days'] ) );
		$this->assertSame( array( $variant . '@' . $source . ':2:open' ), $this->allocationsOf( $order['id'] ), 'The units were not held and allocated where they are.' );
		$this->assertSame( 1, $this->matching( $units[0], '/ FROM `[^`]+locations` WHERE id = \d+ LOCK IN SHARE MODE$/' ), 'The first unit locks the destination\'s row.' );
		$this->assertSame( 0, $this->matching( $units[1], '/LOCK IN SHARE MODE$/' ) );
		$this->assertSame( 1, $this->matching( $units[1], '/^INSERT INTO `[^`]+stock_transfers` /' ), 'The transfer is requested inside the settlement\'s transaction.' );
		$this->assertSame( array( 'requested:' . $source . '>' . $destination ), $this->transfersOf( $order['id'] ) );
		$this->assertSame( $this->allocationIds( $order['id'] ), $this->transferLineAllocations( $order['id'] ), 'The transfer\'s lines carry the order\'s allocations.' );
		$this->assertSame(
			array(
				'routed:unfulfilled>unfulfilled:' . $this->uuidOfLocation( $destination ),
				'transfer_requested:unfulfilled>unfulfilled:' . $this->transferUuids( $order['id'] )[0],
			),
			$this->fulfillmentEventsOf( $order['id'] )
		);
		$this->assertSame( $events + 1, $this->outboxCount(), 'One StockTransferRequested in the outbox.' );
	}

	/**
	 * Tests that units at two sources give two transfers and two events.
	 *
	 * @since 0.2.0
	 */
	public function test_two_sources_request_two_transfers_and_record_each(): void {
		$variant     = $this->sellable( 1 );
		$destination = $this->plantLocation( -1 );
		$second      = $this->plantLocation( 5 );

		$this->stockAt( $variant, $second, 1 );
		$this->routeIn( FulfilmentMode::Transfer );
		$this->readyCart( array( $variant => 2 ) );

		$answer = $this->placement->place( $this->placeInput(), self::guest() );
		$order  = $this->routedOrder( (string) $answer['order_uuid'] );
		$uuids  = $this->transferUuids( $order['id'] );

		$this->assertSame( array( 'requested:' . $this->defaultLocation() . '>' . $destination, 'requested:' . $second . '>' . $destination ), $this->transfersOf( $order['id'] ) );
		$this->assertCount( 2, $uuids );
		$this->assertSame(
			array(
				'routed:unfulfilled>unfulfilled:' . $this->uuidOfLocation( $destination ),
				'transfer_requested:unfulfilled>unfulfilled:' . $uuids[0],
				'transfer_requested:unfulfilled>unfulfilled:' . $uuids[1],
			),
			$this->fulfillmentEventsOf( $order['id'] )
		);
	}

	/**
	 * Tests that an order whose hold lapsed and was taken before its payment settled is still allocated, where the units are, with its transfer requested, under `refuse` too.
	 *
	 * The settlement claims the units afresh with `transfer` semantics whatever the store's mode:
	 * the order is paid for, so it is never refused for a routing preference.
	 *
	 * @since 0.2.0
	 */
	public function test_a_lapsed_hold_is_claimed_afresh_with_a_transfer_whatever_the_mode(): void {
		list( $order, $variant, $first, $second, $third ) = $this->placedAfterItsHoldWasTaken( FulfilmentMode::Refuse );

		$this->assertSame( array( $variant . '@' . $second . ':1:open', $variant . '@' . $third . ':1:open' ), $this->allocationsOf( $order['id'] ), 'The units were not claimed where they are.' );
		$this->assertSame( array( 'requested:' . $second . '>' . $first, 'requested:' . $third . '>' . $first ), $this->transfersOf( $order['id'] ) );
		$this->assertCount( 3, $this->fulfillmentEventsOf( $order['id'] ), 'The routed event and each transfer\'s.' );
	}

	/**
	 * Tests that in a store that splits orders, units claimed afresh at another location ship from there: the order keeps its location, and nothing is requested.
	 *
	 * @since 0.2.0
	 */
	public function test_a_lapsed_hold_claimed_afresh_in_a_store_that_splits_ships_from_where_it_was_claimed(): void {
		list( $order, $variant, $first, $second, $third ) = $this->placedAfterItsHoldWasTaken( FulfilmentMode::Split );

		$this->assertSame( array( $variant . '@' . $second . ':1:open', $variant . '@' . $third . ':1:open' ), $this->allocationsOf( $order['id'] ) );
		$this->assertSame( $first, $order['fulfilment_location_id'], 'The order still names the location its plan ships from.' );
		$this->assertSame( array(), $this->transfersOf( $order['id'] ), 'A store that splits orders ships the part from where it was claimed.' );
		$this->assertCount( 1, $this->fulfillmentEventsOf( $order['id'] ), 'The routed event only.' );
	}

	/**
	 * Tests that the location an order awaiting its payment ships from is kept from deletion by the kernel's wiring, though no stock, hold or transfer refers to it yet.
	 *
	 * The gateway gives no answer, so the order waits as placed: its units are held at their source,
	 * the destination has none, and the transfer is requested only once the payment settles. Only
	 * the order names the destination, so only the order module's answer, through the closure the
	 * kernel hands the location service, keeps it.
	 *
	 * @since 0.2.0
	 */
	public function test_the_location_an_order_awaiting_payment_ships_from_is_kept_from_deletion(): void {
		$variant     = $this->sellable( 0 );
		$destination = $this->plantLocation( -1 );
		$source      = $this->plantLocation( 5, true, false );

		$this->stockAt( $variant, $source, 2 );
		$this->routeIn( FulfilmentMode::Transfer );
		$this->readyCart( array( $variant => 2 ) );

		$input     = $this->placeInput( 'attempt-1', StubGateway::THROW );
		$orderUuid = null;

		try {
			$this->placement->place( $input, self::guest() );
		} catch ( CodedException $unanswered ) {
			$this->assertSame( CheckoutError::GatewayUnavailable, $unanswered->errorCode() );

			$orderUuid = (string) ( $unanswered->details()['order_uuid'] ?? '' );
		}

		$this->assertNotNull( $orderUuid, 'The gateway answered.' );

		$order = $this->routedOrder( $orderUuid );

		$this->assertSame( 'pending_payment', (string) $this->db->fetchValue( 'SELECT status FROM %i WHERE id = %d', $this->table( OrderTables::ORDERS ), $order['id'] ) );
		$this->assertSame( $destination, $order['fulfilment_location_id'] );
		$this->assertSame( array(), $this->transfersOf( $order['id'] ), 'A transfer was requested before the payment settled.' );
		$this->assertSame( '0', (string) $this->db->fetchValue( 'SELECT COUNT(*) FROM %i WHERE location_id = %d', $this->table( InventoryTables::ITEMS ), $destination ), 'The destination has stock.' );

		$refused = null;

		try {
			$this->kernel->get( Locations::class )->deleteLocation( array( 'location_uuid' => $this->uuidOfLocation( $destination ) ), $this->userGranted( LocationOperations::CAPABILITY ) );
		} catch ( CodedException $thrown ) {
			$refused = $thrown;
		}

		$this->assertNotNull( $refused, 'The location an order awaiting payment ships from was deleted.' );
		$this->assertSame( array( InventoryError::LocationInUse, array( 'reason' => 'orders' ) ), array( $refused->errorCode(), $refused->details() ) );
	}

	/**
	 * Places a two-unit order planned whole at the default location, whose hold lapses and is taken by another checkout before the settlement reads the order's lines.
	 *
	 * A second and a third location have one unit each, so no single location can then give the
	 * order its units: under `refuse` a plan for them would be refused.
	 *
	 * @since 0.2.0
	 *
	 * @param FulfilmentMode $mode The store's routing mode.
	 * @return array{0: array{id: int, fulfilment_location_id: int|null, transfer_lead_days: int|null}, 1: int, 2: int, 3: int, 4: int} The order, the variant, the default location, the second and the third.
	 */
	private function placedAfterItsHoldWasTaken( FulfilmentMode $mode ): array {
		$variant = $this->sellable( 2 );
		$first   = $this->defaultLocation();
		$second  = $this->plantLocation( 5 );
		$third   = $this->plantLocation( 6 );
		$b       = $this->secondConnection();

		$this->stockAt( $variant, $second, 1 );
		$this->stockAt( $variant, $third, 1 );
		$this->routeIn( $mode );
		$this->readyCart( array( $variant => 2 ) );

		$input = $this->placeInput();

		// Before the settlement reads the order's lines: the hold lapses, is reclaimed, and another checkout takes both units.
		$this->beforeStatement(
			'/^SELECT l\.id, l\.variant_id, l\.quantity, o\.market_id/',
			function () use ( $b, $variant, $first ): void {
				$items = $this->table( InventoryTables::ITEMS );
				$holds = $this->table( InventoryTables::HOLDS );

				$b->query( sprintf( 'DELETE FROM `%s` WHERE variant_id = %d AND location_id = %d', $holds, $variant, $first ) );
				$b->query( sprintf( "INSERT INTO `%s` ( variant_id, location_id, hold_group, quantity, expires_at, created_at ) VALUES ( %d, %d, '%s', 2, UTC_TIMESTAMP() + INTERVAL 600 SECOND, UTC_TIMESTAMP(6) )", $holds, $variant, $first, wp_generate_uuid4() ) );
				$this->assertSame( '2', $b->fetchValue( sprintf( 'SELECT held FROM `%s` WHERE variant_id = %d AND location_id = %d', $items, $variant, $first ) ) );
			}
		);

		$answer = $this->placement->place( $input, self::guest() );
		$order  = $this->routedOrder( (string) $answer['order_uuid'] );

		$this->assertSame( 'approved', $answer['outcome'], 'An order paid for was refused its units.' );

		return array( $order, $variant, $first, $second, $third );
	}

	/**
	 * Splits a placement's log into its two units of work, each from its START TRANSACTION to its COMMIT.
	 *
	 * @since 0.2.0
	 *
	 * @param QueryLog $log The placement's log.
	 * @return list<list<string>> The statements of each unit, in order.
	 */
	private function units( QueryLog $log ): array {
		$units   = array();
		$current = null;

		foreach ( $log->sqls() as $sql ) {
			if ( 'START TRANSACTION' === $sql ) {
				$current = array();
			} elseif ( 'COMMIT' === $sql && null !== $current ) {
				$units[] = $current;
				$current = null;
			} elseif ( null !== $current ) {
				$current[] = $sql;
			}
		}

		$this->assertCount( 2, $units, 'A placement is two units of work.' );

		return $units;
	}

	/**
	 * Counts the statements matching a pattern.
	 *
	 * @since 0.2.0
	 *
	 * @param string[] $sqls    The statements.
	 * @param string   $pattern The pattern.
	 * @return int How many match.
	 *
	 * @phpstan-param list<string> $sqls
	 */
	private function matching( array $sqls, string $pattern ): int {
		$matched = preg_grep( $pattern, $sqls );

		return false === $matched ? 0 : count( $matched );
	}

	/**
	 * Reads an order's allocation ids, ascending.
	 *
	 * @since 0.2.0
	 *
	 * @param int $orderId The order.
	 * @return list<int> The ids.
	 */
	private function allocationIds( int $orderId ): array {
		return array_map( 'intval', array_column( $this->db->fetchAll( 'SELECT id FROM %i WHERE order_id = %d ORDER BY id', $this->table( InventoryTables::ALLOCATIONS ), $orderId ), 'id' ) );
	}

	/**
	 * Reads the allocations an order's transfers' lines carry, ascending.
	 *
	 * @since 0.2.0
	 *
	 * @param int $orderId The order.
	 * @return list<int> The allocation ids.
	 */
	private function transferLineAllocations( int $orderId ): array {
		return array_map( 'intval', array_column( $this->db->fetchAll( 'SELECT l.allocation_id FROM %i l JOIN %i t ON t.id = l.transfer_id WHERE t.order_id = %d ORDER BY l.allocation_id', $this->table( InventoryTables::TRANSFER_LINES ), $this->table( InventoryTables::TRANSFERS ), $orderId ), 'allocation_id' ) );
	}

	/**
	 * Reads an order's transfers' uuids, by id.
	 *
	 * @since 0.2.0
	 *
	 * @param int $orderId The order.
	 * @return list<string> The uuids.
	 */
	private function transferUuids( int $orderId ): array {
		return array_map( 'strval', array_column( $this->db->fetchAll( 'SELECT uuid FROM %i WHERE order_id = %d ORDER BY id', $this->table( InventoryTables::TRANSFERS ), $orderId ), 'uuid' ) );
	}

	/**
	 * Counts the outbox's StockTransferRequested events.
	 *
	 * @since 0.2.0
	 *
	 * @return int The count.
	 */
	private function outboxCount(): int {
		return (int) $this->db->fetchValue( 'SELECT COUNT(*) FROM %i WHERE event_name = %s', $this->table( OutboxTable::NAME ), StockTransferRequested::eventName() );
	}
}
