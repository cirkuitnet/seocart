<?php
/**
 * Tests that routing brings an order's units only to an active location
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Inventory;

use SEOCart\Inventory\Application\FulfilmentRouting;
use SEOCart\Inventory\Application\InventoryError;
use SEOCart\Inventory\Application\LocationDirectory;
use SEOCart\Inventory\Application\RoutingContext;
use SEOCart\Inventory\Domain\Allocation;
use SEOCart\Inventory\Domain\Routing\FulfilmentMode;
use SEOCart\Inventory\Infrastructure\MysqlLocationRepository;
use SEOCart\Support\Error\CodedException;
use SEOCart\Tests\Support\Inventory\StockTestCase;

/**
 * A destination the context names is checked as a pickup location is: one that does not exist, or
 * is deactivated, is refused before any plan, and an allocation whose units are claimed afresh for
 * such a destination is refused as when nothing fits, naming it.
 *
 * Planted violation, shown red and removed: in FulfilmentRouting::propose(), check only the pickup
 * location (drop the destination's requireActive()): a plan brings the units to the deactivated
 * location, and the allocation is made there.
 *
 * @since 0.2.0
 */
final class FulfilmentRoutingTest extends StockTestCase {

	/**
	 * Tests that a destination that is deactivated, or does not exist, is refused, and an active one is planned to.
	 *
	 * @since 0.2.0
	 */
	public function test_a_named_destination_must_be_an_active_location(): void {
		$variant  = self::variant();
		$inactive = $this->plantLocation( 2, false );
		$active   = $this->plantLocation( 3 );

		$this->stockItem( $variant, 2 );

		foreach ( array(
			$inactive      => InventoryError::LocationInactive,
			$active + 1000 => InventoryError::LocationMissing,
		) as $destination => $code ) {
			try {
				$this->routing()->propose( array( $variant => 1 ), new RoutingContext( null, null, $destination ), FulfilmentMode::Transfer );
				$this->fail( sprintf( 'A plan brought the units to location %d.', $destination ) );
			} catch ( CodedException $refused ) {
				$this->assertSame( array( $code, array( 'location_id' => $destination ) ), array( $refused->errorCode(), $refused->context() ) );
			}
		}

		$plan = $this->routing()->propose( array( $variant => 1 ), new RoutingContext( null, null, $active ), FulfilmentMode::Transfer );

		$this->assertSame( array( $active, true ), array( $plan->destinationLocationId, $plan->requiresTransfer ), 'The units are brought to the active location named.' );
	}

	/**
	 * Tests that an allocation whose hold is gone, for an order whose destination is deactivated, is refused as when nothing fits, naming the destination, and allocates nothing.
	 *
	 * @since 0.2.0
	 */
	public function test_units_claimed_afresh_for_a_deactivated_destination_are_refused(): void {
		$variant  = self::variant();
		$inactive = $this->plantLocation( 2, false );
		$b        = $this->secondConnection();
		$refused  = null;

		$this->stockItem( $variant, 2 );

		$this->db->transaction(
			function () use ( $variant, $inactive, &$refused ): void {
				try {
					$this->service->allocate( '01928c3e-7b3c-7d1e-9a2b-000000000002', 4242, array( new Allocation( 10, $variant, 1 ) ), new RoutingContext( null, null, $inactive ) );
				} catch ( CodedException $insufficient ) {
					$refused = $insufficient;
				}
			}
		);

		$this->assertInstanceOf( CodedException::class, $refused, 'The units were brought to a deactivated location.' );
		$this->assertSame( array( InventoryError::Insufficient, $inactive ), array( $refused->errorCode(), $refused->details()['location_id'] ?? null ) );
		$this->assertSame( array( 2, 0, 0 ), array_values( (array) $this->committedItem( $b, $variant ) ), 'Nothing was allocated.' );
		$this->assertTrue( $this->projectionCheck()->passed, implode( "\n", $this->projectionCheck()->findings ) );
	}

	/**
	 * Builds routing over the test's connection, under the test's mode.
	 *
	 * @since 0.2.0
	 *
	 * @return FulfilmentRouting The routing.
	 */
	private function routing(): FulfilmentRouting {
		return new FulfilmentRouting( new LocationDirectory( new MysqlLocationRepository( $this->db ) ), $this->repository, fn(): FulfilmentMode => $this->mode );
	}
}
