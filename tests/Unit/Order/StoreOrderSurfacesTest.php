<?php
/**
 * Tests that every storefront operation that shows an order is on the five-case list, or is the cart's with its reason
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Order;

use PHPUnit\Framework\TestCase;
use SEOCart\Application\Operations\Operations;
use SEOCart\Tests\Support\Order\StoreOrderSurfaces;

/**
 * The Store API operations that take or answer an order's uuid, read from the operation registry, are exactly the five-case list and the cart's own surfaces.
 *
 * A storefront operation that shows an order and is on neither list fails here, so it cannot ship
 * without the five cases run on it, or a reason it is the cart's. One that left the registry fails
 * too, so the lists never name what no longer exists.
 *
 * Planted violation: remove `order.list_own` from StoreOrderSurfaces::FIVE_CASES. The list, which
 * answers orders' uuids, is then on neither list, and the test names it.
 *
 * @since 0.2.0
 */
final class StoreOrderSurfacesTest extends TestCase {

	/**
	 * Tests that the operations naming an order are the five-case list and the cart's surfaces, both ways.
	 *
	 * @since 0.2.0
	 */
	public function test_every_storefront_operation_that_shows_an_order_is_listed(): void {
		$found  = StoreOrderSurfaces::inRegistry( Operations::registry() );
		$listed = array_merge( StoreOrderSurfaces::FIVE_CASES, array_keys( StoreOrderSurfaces::CART_SURFACES ) );

		$this->assertSame( array(), array_values( array_diff( $found, $listed ) ), 'A Store API operation shows an order and is on neither the five-case list nor the cart\'s: run the five cases on it, or say why the cart\'s token guards it.' );
		$this->assertSame( array(), array_values( array_diff( $listed, $found ) ), 'A listed operation no longer shows an order on the Store API.' );
		$this->assertSame( array(), array_intersect( StoreOrderSurfaces::FIVE_CASES, array_keys( StoreOrderSurfaces::CART_SURFACES ) ), 'An operation is the policy\'s or the cart\'s, not both.' );
	}

	/**
	 * Tests that the reading finds what it looks for: the order module's operations and an order's uuid in an answer.
	 *
	 * @since 0.2.0
	 */
	public function test_the_reading_finds_the_order_reads_and_the_placement(): void {
		$found = StoreOrderSurfaces::inRegistry( Operations::registry() );

		$this->assertContains( 'order.get_status', $found );
		$this->assertContains( 'checkout.place_order', $found, 'The placement answers an order_uuid.' );
		$this->assertNotContains( 'catalog.list_products', $found );
	}
}
