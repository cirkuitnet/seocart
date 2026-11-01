<?php
/**
 * Tests that a cart line its answer does not flag is one the router plans, in each of the store's modes
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Checkout;

use SEOCart\Cart\Application\CartService;
use SEOCart\Inventory\Domain\Routing\FulfilmentMode;
use SEOCart\Pricing\Application\RoutingScope;
use SEOCart\Support\Address;
use SEOCart\Support\Error\CodedException;
use SEOCart\Tests\Support\Checkout\PlacementTestCase;
use SEOCart\Tests\Support\Checkout\RoutedPlacements;

defined( 'ABSPATH' ) || exit;

/**
 * The kernel's cart service, its answers judged by the figure and its checkout's calculation planned
 * by the router, over four locations: L1, the store's default, and L2 are active and ship online
 * orders; L3 is inactive; L4 is active and offers pickup only. A single-line cart whose answer does
 * not flag its line is planned for a shipping address, with no refusal; one whose line is flagged is
 * refused, but for the one stated case where the figure counts fewer units than the router can
 * reach: under `transfer`, units at an active location that ships no online order are brought by a
 * transfer, while the figure leaves them out.
 *
 * Planted violation, shown red and removed: in Availability::forSale(), take the sum under `refuse`
 * too. A line of 4 against L1's 3 and L2's 2 is then not flagged, and the router refuses it.
 *
 * @since 0.2.0
 */
final class CartAvailabilityRoutingTest extends PlacementTestCase {

	use RoutedPlacements;

	/**
	 * Tests a single-line cart's flag against what the router does with it.
	 *
	 * @since 0.2.0
	 *
	 * @dataProvider carts
	 *
	 * @param FulfilmentMode $mode     The store's mode.
	 * @param int[]          $units    The units at L1, L2, L3 and L4.
	 * @param int            $quantity The line's units.
	 * @param string[]       $flags    The line's flag, as `reason:for_sale`, or none.
	 * @param string         $routed   What the router does: `planned`, `planned with a transfer`, or
	 *                                 `refused ` and the code.
	 *
	 * @phpstan-param list<int>    $units
	 * @phpstan-param list<string> $flags
	 */
	public function test_a_line_not_flagged_is_planned( FulfilmentMode $mode, array $units, int $quantity, array $flags, string $routed ): void {
		$variant   = $this->sellable( $units[0] );
		$locations = array( $this->plantLocation( 1 ), $this->plantLocation( 2, false ), $this->plantLocation( 3, true, false, true ) );

		foreach ( $locations as $index => $location ) {
			$this->stockAt( $variant, $location, $units[ $index + 1 ] );
		}

		$this->routeIn( $mode );

		$cart    = $this->startCart( array( $variant => $quantity ) );
		$carts   = $this->kernel->get( CartService::class );
		$flagged = array_map( static fn( array $line ): string => $line['reason'] . ':' . $line['for_sale'], $carts->getCart( array(), self::guest() )['unavailable_lines'] );

		try {
			$proposal = $carts->calculation( $cart, self::delivery(), RoutingScope::checkout() )->routingProposal();
			$outcome  = null === $proposal ? 'no plan' : ( $proposal->requiresTransfer ? 'planned with a transfer' : 'planned' );
		} catch ( CodedException $refused ) {
			$outcome = 'refused ' . $refused->errorCode()->value;
		}

		if ( array() === $flagged ) {
			$this->assertStringStartsWith( 'planned', $outcome, 'A line its answer does not flag must be one the router plans.' );
		}

		$this->assertSame( array( $flags, $routed ), array( $flagged, $outcome ) );
	}

	/**
	 * Returns the carts: a mode, the units at L1, L2, L3 and L4, the line's units, its flag and what the router does.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, array{0: FulfilmentMode, 1: list<int>, 2: int, 3: list<string>, 4: string}> The cases.
	 */
	public static function carts(): array {
		$spread  = array( 3, 2, 50, 50 );
		$refused = 'refused stock.routing_refused';

		return array(
			'split, 5 of 3 and 2'                => array( FulfilmentMode::Split, $spread, 5, array(), 'planned' ),
			'split, 6 of 3 and 2'                => array( FulfilmentMode::Split, $spread, 6, array( 'insufficient_stock:5' ), $refused ),
			'transfer, 5 of 3 and 2'             => array( FulfilmentMode::Transfer, $spread, 5, array(), 'planned with a transfer' ),
			'transfer, 6 of 3 and 2'             => array( FulfilmentMode::Transfer, array( 3, 2, 50, 0 ), 6, array( 'insufficient_stock:5' ), $refused ),
			'refuse, 3 of 3 and 2'               => array( FulfilmentMode::Refuse, $spread, 3, array(), 'planned' ),
			'refuse, 4 of 3 and 2'               => array( FulfilmentMode::Refuse, $spread, 4, array( 'insufficient_stock:3' ), $refused ),
			'transfer, 2 only at the pickup one' => array( FulfilmentMode::Transfer, array( 0, 0, 0, 5 ), 2, array( 'out_of_stock:0' ), 'planned with a transfer' ),
		);
	}

	/**
	 * Returns where the checkout ships: an address in the United States, the cheapest method.
	 *
	 * @since 0.2.0
	 *
	 * @return array{destination: Address, shipping_method_key: null} The delivery.
	 */
	private static function delivery(): array {
		return array(
			'destination'         => new Address( 'US', line1: '1 Main Street', city: 'Austin', postcode: '78701' ),
			'shipping_method_key' => null,
		);
	}
}
