<?php
/**
 * Tests that a checkout's plan is not refused for units another cart's expired, unswept hold still counts as held
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Checkout;

use SEOCart\Inventory\Infrastructure\InventoryTables;
use SEOCart\Payment\Infrastructure\Gateway\StubGateway;
use SEOCart\Tests\Support\Checkout\PlacementTestCase;
use SEOCart\Tests\Support\SecondConnection;

defined( 'ABSPATH' ) || exit;

/**
 * A hold that expired still counts its units as held until the sweep reclaims it, so the router does not offer them: the session write and the placement reclaim the refused variant's expired holds, once, and plan again.
 *
 * Planted violation: in PlannedPricing::price(), reclaim nothing (the refusal stands): the session
 * write and the placement on the last unit are refused `stock.routing_refused` until the sweep.
 *
 * @since 0.2.0
 */
final class ExpiredHoldPlanTest extends PlacementTestCase {

	/**
	 * Tests that a session write for the last unit, which another cart's expired hold still counts as held, reclaims that hold and plans the unit.
	 *
	 * @since 0.2.0
	 */
	public function test_a_session_write_on_the_last_unit_reclaims_an_expired_hold_and_plans_it(): void {
		$mug = $this->sellable( 1 );
		$b   = $this->expiredHoldOn( $mug );

		$this->assertSame( array( 1, 0, 1 ), $this->committedStock( $b, $mug ), 'The expired hold still counts the unit as held.' );

		$cart = $this->startCart( array( $mug => 1 ) );

		$this->writeCheckout( $cart->version, StubGateway::ID );

		$this->assertSame( array( 1, 0, 0 ), $this->committedStock( $b, $mug ), 'The session write reclaimed the expired hold.' );
		$this->assertSame( 0, $this->rowsOf( InventoryTables::HOLDS ), 'The expired hold\'s row is gone.' );
	}

	/**
	 * Tests that a placement of the last unit, which another cart's expired hold took after the session was written, reclaims that hold, plans the unit and places the order.
	 *
	 * @since 0.2.0
	 */
	public function test_a_placement_of_the_last_unit_reclaims_an_expired_hold_and_places(): void {
		$mug = $this->sellable( 1 );

		$this->readyCart( array( $mug => 1 ) );

		$input = $this->placeInput( 'taker' );

		$this->plantExpiredHold( $mug, $this->secondConnection() );

		$answer = $this->placement->place( $input, self::guest() );
		$b      = $this->secondConnection();

		$this->assertSame( 'approved', $answer['outcome'], 'The placement was refused the unit an expired hold counted as held.' );
		$this->assertSame( array( 1, 1, 0 ), $this->committedStock( $b, $mug ) );
	}

	/**
	 * Holds the last unit for another cart's placement that waits on its shopper, then lets the hold expire without the sweep.
	 *
	 * @since 0.2.0
	 *
	 * @param int $variantId The variant, with one unit.
	 * @return SecondConnection Connection B.
	 */
	private function expiredHoldOn( int $variantId ): SecondConnection {
		$this->readyCart( array( $variantId => 1 ) );

		$this->assertSame( 'requires_action', $this->placement->place( $this->placeInput( 'waiting', StubGateway::REQUIRES_ACTION ), self::guest() )['outcome'] );

		$b = $this->secondConnection();

		$b->query( sprintf( 'UPDATE `%s` SET expires_at = UTC_TIMESTAMP() - INTERVAL 1 MINUTE', $this->table( InventoryTables::HOLDS ) ) );

		return $b;
	}

	/**
	 * Plants another cart's hold of the last unit at the default location, expired a minute ago and not swept.
	 *
	 * @since 0.2.0
	 *
	 * @param int              $variantId The variant, with one unit there.
	 * @param SecondConnection $b         Connection B, which commits the hold.
	 */
	private function plantExpiredHold( int $variantId, SecondConnection $b ): void {
		$items    = $this->table( InventoryTables::ITEMS );
		$location = (int) $b->fetchValue( sprintf( 'SELECT location_id FROM `%s` WHERE variant_id = %d', $items, $variantId ) );

		$b->query( sprintf( "INSERT INTO `%s` ( variant_id, location_id, hold_group, quantity, expires_at, created_at ) VALUES ( %d, %d, '%s', 1, UTC_TIMESTAMP() - INTERVAL 1 MINUTE, UTC_TIMESTAMP(6) )", $this->table( InventoryTables::HOLDS ), $variantId, $location, wp_generate_uuid4() ) );
		$b->query( sprintf( 'UPDATE `%s` SET held = held + 1 WHERE variant_id = %d AND location_id = %d', $items, $variantId, $location ) );
	}
}
