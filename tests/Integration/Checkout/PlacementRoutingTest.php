<?php
/**
 * Tests that a placement holds each unit where its plan found it and records the location the order is fulfilled from
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Checkout;

use SEOCart\Inventory\Application\StockService;
use SEOCart\Inventory\Domain\HoldLine;
use SEOCart\Inventory\Domain\Routing\FulfilmentMode;
use SEOCart\Inventory\Infrastructure\InventoryTables;
use SEOCart\Order\Infrastructure\OrderTables;
use SEOCart\Tests\Support\Checkout\PlacementTestCase;
use SEOCart\Tests\Support\Checkout\RoutedPlacements;

defined( 'ABSPATH' ) || exit;

/**
 * The plan is what the placement holds, records and settles: a whole order at its one location, a split at each, with the `routed` event naming the location.
 *
 * Planted violations: in PlaceOrder::placeInside(), build each HoldLine without its location (every
 * unit is then held at the default location); and record the `routed` event under the payment
 * machine. In SettlePlacement::allocate(), request transfers whatever the store's mode (a split
 * order then requests one for the part at its second location).
 *
 * @since 0.2.0
 */
final class PlacementRoutingTest extends PlacementTestCase {

	use RoutedPlacements;

	/**
	 * Tests that an order the higher-priority location can ship whole is held and allocated there only, and records it with no transfer.
	 *
	 * @since 0.2.0
	 */
	public function test_a_whole_order_is_held_and_allocated_at_the_location_it_ships_from(): void {
		$variant = $this->sellable( 5 );
		$second  = $this->plantLocation( -1 );

		$this->stockAt( $variant, $second, 5 );
		$this->routeIn( FulfilmentMode::Split );
		$this->readyCart( array( $variant => 2 ) );

		$answer = $this->placement->place( $this->placeInput(), self::guest() );
		$order  = $this->routedOrder( (string) $answer['order_uuid'] );

		$this->assertSame( 'approved', $answer['outcome'] );
		$this->assertArrayHasKey( 'transfer_days', $answer );
		$this->assertNull( $answer['transfer_days'], 'An order that needs no transfer is told of no extra days.' );
		$this->assertSame( array( $variant . '@' . $second . ':2:open' ), $this->allocationsOf( $order['id'] ), 'The units were not held and allocated where the plan found them.' );
		$this->assertSame( array( $second, null ), array( $order['fulfilment_location_id'], $order['transfer_lead_days'] ) );
		$this->assertSame( array( 'routed:unfulfilled>unfulfilled:' . $this->uuidOfLocation( $second ) ), $this->fulfillmentEventsOf( $order['id'] ), 'The order\'s fulfillment trail does not record where it ships from.' );
		$this->assertSame( 0, (int) $this->db->fetchValue( "SELECT COUNT(*) FROM %i WHERE order_id = %d AND machine = 'payment' AND reason = 'routed'", $this->table( OrderTables::EVENTS ), $order['id'] ), 'The routed event is the fulfillment machine\'s, not the payment\'s.' );
		$this->assertSame( 0, (int) $this->db->fetchValue( 'SELECT allocated FROM %i WHERE variant_id = %d AND location_id = %d', $this->table( InventoryTables::ITEMS ), $variant, $this->defaultLocation() ) );
	}

	/**
	 * Tests that a split order is held and allocated at each location the plan names, with no transfer and no transfer's event.
	 *
	 * @since 0.2.0
	 */
	public function test_a_split_order_is_held_and_allocated_at_each_location_without_a_transfer(): void {
		$variant = $this->sellable( 1 );
		$second  = $this->plantLocation( 5 );

		$this->stockAt( $variant, $second, 1 );
		$this->routeIn( FulfilmentMode::Split );
		$this->readyCart( array( $variant => 2 ) );

		$answer = $this->placement->place( $this->placeInput(), self::guest() );
		$order  = $this->routedOrder( (string) $answer['order_uuid'] );
		$first  = $this->defaultLocation();

		$this->assertSame( 'approved', $answer['outcome'] );
		$this->assertSame( array( $variant . '@' . $first . ':1:open', $variant . '@' . $second . ':1:open' ), $this->allocationsOf( $order['id'] ) );
		$this->assertSame( array( $first, null ), array( $order['fulfilment_location_id'], $order['transfer_lead_days'] ), 'A split ships from the first location that gave units, with no transfer.' );
		$this->assertSame( array(), $this->transfersOf( $order['id'] ) );
		$this->assertSame( array( 'routed:unfulfilled>unfulfilled:' . $this->uuidOfLocation( $first ) ), $this->fulfillmentEventsOf( $order['id'] ), 'A split requests no transfer, so it records none.' );
		$this->assertNull( $answer['transfer_days'] );
	}

	/**
	 * Tests that a hold whose line names no location is still held at the store's default location, for a caller that knows no plan.
	 *
	 * @since 0.2.0
	 */
	public function test_a_line_without_a_location_is_still_held_at_the_default_location(): void {
		$variant = $this->sellable( 3 );

		$this->plantLocation( -1 );

		$hold = $this->kernel->get( StockService::class )->hold( array( new HoldLine( $variant, 2 ) ), 60 );

		$this->assertSame( array( array( $variant, $this->defaultLocation(), 2 ) ), array_map( static fn( HoldLine $line ): array => array( $line->variantId, $line->locationId, $line->quantity ), $hold->lines ) );
	}
}
