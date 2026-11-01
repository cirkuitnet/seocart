<?php
/**
 * Tests what the checkout operations and the order's status read answer of an order's plan over the wire: its delivery, the days a transfer adds, and the router's refusal
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Checkout;

use SEOCart\Application\Operations\RestBinding;
use SEOCart\Cart\Interfaces\StoreApi\CartOperations;
use SEOCart\Cart\Interfaces\StoreApi\CartTokenTransport;
use SEOCart\Cart\Interfaces\StoreApi\StoreRequestPolicy;
use SEOCart\Catalog\Application\Lifecycle\DeleteProduct;
use SEOCart\Checkout\Interfaces\StoreApi\CheckoutOperations;
use SEOCart\Inventory\Domain\Routing\FulfilmentMode;
use SEOCart\Inventory\Infrastructure\InventoryTables;
use SEOCart\Order\Interfaces\StoreApi\OrderStoreOperations;
use SEOCart\Payment\Infrastructure\Gateway\StubGateway;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Tests\Support\Cart\ServesStoreApi;
use SEOCart\Tests\Support\Checkout\PlacementTestCase;
use SEOCart\Tests\Support\Checkout\RoutedPlacements;
use SEOCart\Tests\Support\Doubles\FakeCartTokens;

defined( 'ABSPATH' ) || exit;

/**
 * `PUT` and `POST seocart/store/v1/checkout` and the order's status read, served through the production wiring: what the plan says of delivery is data, and the days a transfer adds are the order's, as told at placement.
 *
 * Planted violations: in MysqlOrderRepository::view(), answer the store's lead days as they are
 * now for an order that needed a transfer: the order read then says 7 where its shopper was told 3;
 * in CheckoutOperations::placeOrder(), leave `stock.location_inactive` or `stock.location_missing`
 * out of the errors: the placement refused it is then reported to the developer as answering a
 * code it does not declare; leave `stock.item_missing` out of updateSession()'s errors, or out of
 * placeOrder()'s: the session write, or the placement, refused for a product deleted while it was
 * priced is reported so too.
 *
 * @group contract
 *
 * @since 0.2.0
 */
final class RoutingOperationsTest extends PlacementTestCase {

	use RoutedPlacements;
	use ServesStoreApi;

	/**
	 * Wires the kernel and boots a spy server.
	 *
	 * @since 0.2.0
	 */
	public function set_up(): void {
		parent::set_up();

		$this->bootStoreApi();
	}

	/**
	 * Restores the request globals and discards the server.
	 *
	 * @since 0.2.0
	 */
	public function tear_down(): void {
		$this->shutStoreApi();

		parent::tear_down();
	}

	/**
	 * Tests that the session write answers no delivery before a shipping address, none needing a transfer for units where the order ships from, and the days a transfer adds for units brought there.
	 *
	 * @since 0.2.0
	 */
	public function test_the_session_write_answers_what_the_plan_says_of_delivery(): void {
		$here      = $this->sellable( 5, 1999 );
		$away      = $this->sellable( 0, 1999 );
		$warehouse = $this->plantLocation( 5, true, false );

		// A warehouse that ships no online orders has the second variant's units: they are brought to the first location.
		$this->stockAt( $away, $warehouse, 5 );
		$this->routeIn( FulfilmentMode::Transfer );
		$this->startOverTheWire( array( $here => 1 ) );

		$unplanned = $this->checkoutOverTheWire( 1, false );

		$this->assertSame( 200, $unplanned['status'], (string) wp_json_encode( $unplanned['body'] ) );
		$this->assertArrayHasKey( 'delivery', $unplanned['body'] );
		$this->assertNull( $unplanned['body']['delivery'], 'Nothing is planned before the order has a shipping address.' );
		$this->assertSame(
			array(
				'requires_transfer' => false,
				'transfer_days'     => null,
			),
			$this->checkoutOverTheWire( 2, true )['body']['delivery'] ?? 'none'
		);

		$this->startOverTheWire( array( $away => 1 ) );

		$this->assertSame(
			array(
				'requires_transfer' => true,
				'transfer_days'     => 3,
			),
			$this->checkoutOverTheWire( 1, true )['body']['delivery'] ?? 'none'
		);
	}

	/**
	 * Tests that the placement and the order's status read answer the days a transfer adds as told at placement, whatever the store says later, and null for an order that needs none.
	 *
	 * @since 0.2.0
	 */
	public function test_the_placement_and_the_order_read_answer_the_days_told_at_placement(): void {
		$away      = $this->sellable( 0, 1999 );
		$here      = $this->sellable( 5, 1999 );
		$warehouse = $this->plantLocation( 5, true, false );

		$this->stockAt( $away, $warehouse, 5 );
		$this->routeIn( FulfilmentMode::Transfer );

		$moved = $this->placedOverTheWire( $away );

		// The store now tells shoppers a transfer takes a week: the order keeps what its shopper was told.
		$this->routeIn( FulfilmentMode::Transfer, 7 );

		$stayed = $this->placedOverTheWire( $here );

		$this->assertSame( array( 3, 3 ), array( $moved['placed']['transfer_days'], $this->readOverTheWire( $moved['placed'] )['transfer_days'] ), 'The days a transfer adds are the ones told at placement.' );
		$this->assertSame( array( null, null ), array( $stayed['placed']['transfer_days'], $this->readOverTheWire( $stayed['placed'] )['transfer_days'] ), 'An order that needs no transfer is told of no extra days.' );
	}

	/**
	 * Tests that a session write whose plan the store's mode refuses is answered with the router's code and its details.
	 *
	 * @since 0.2.0
	 */
	public function test_a_plan_the_store_refuses_is_answered_on_the_session_write(): void {
		$variant = $this->sellable( 1, 1999 );
		$second  = $this->plantLocation( 5 );

		// Each location has one unit, and the store ships an order from one location or not at all.
		$this->stockAt( $variant, $second, 1 );
		$this->routeIn( FulfilmentMode::Refuse );
		$this->startOverTheWire( array( $variant => 2 ) );

		$refused = $this->checkoutOverTheWire( 1, true );

		$this->assertSame( 'stock.routing_refused', $refused['body']['code'] ?? null, (string) wp_json_encode( $refused['body'] ) );
		$this->assertSame(
			array(
				'reason'      => 'quantity',
				'variant_ids' => array( $variant ),
				'available'   => 1,
			),
			$refused['body']['data']['details'] ?? null
		);
	}

	/**
	 * Tests that a placement refused because the location its order would ship from was deactivated after the plan answers a conflict the placement declares.
	 *
	 * The deactivation commits on connection B just before the hold locks the location's row. The
	 * request read its locations once, so its one re-plan finds the same units at the same
	 * locations, and the placement answers the hold's refusal to the shopper. An operation that
	 * answers a code it does not declare reports it to the developer, which the test framework
	 * records.
	 *
	 * @since 0.2.0
	 */
	public function test_a_placement_refused_for_a_deactivated_location_answers_a_declared_conflict(): void {
		$refused = $this->placementAsItsLocationChanges( 'UPDATE `%s` SET active = 0 WHERE id = %d' );

		$this->assertSame( array( 409, 'stock.location_inactive' ), array( $refused['status'], $refused['body']['code'] ?? null ), (string) wp_json_encode( $refused['body'] ) );
		$this->assertSame( array(), $this->caught_doing_it_wrong, 'The placement answered a code it does not declare.' );
	}

	/**
	 * Tests that a placement refused because the location its order would ship from was deleted after the plan answers a not-found the placement declares.
	 *
	 * As above, but connection B deletes the location's row, so the hold finds none to lock.
	 *
	 * @since 0.2.0
	 */
	public function test_a_placement_refused_for_a_deleted_location_answers_a_declared_not_found(): void {
		$refused = $this->placementAsItsLocationChanges( 'DELETE FROM `%s` WHERE id = %d' );

		$this->assertSame( array( 404, 'stock.location_missing' ), array( $refused['status'], $refused['body']['code'] ?? null ), (string) wp_json_encode( $refused['body'] ) );
		$this->assertSame( array(), $this->caught_doing_it_wrong, 'The placement answered a code it does not declare.' );
	}

	/**
	 * Tests that a session write refused because its product was deleted while the cart was priced answers a not-found the session write declares.
	 *
	 * Another runner of the plugin deletes the product, with its stock, after the calculation read
	 * its price and before the router reads the units available, so the router finds a variant with
	 * no stock item.
	 *
	 * @since 0.2.0
	 */
	public function test_a_session_write_refused_for_a_product_deleted_while_it_was_priced_answers_a_declared_not_found(): void {
		$variant = $this->sellable( 5, 1999 );

		$this->startOverTheWire( array( $variant => 1 ) );

		$barrier = $this->deletedBeforeTheRouterReadsItsUnits( $variant );
		$refused = $this->checkoutOverTheWire( 1, true );

		$this->assertTrue( $barrier->fired, 'The product was not deleted before the router read its units.' );
		$this->assertSame( array( 404, 'stock.item_missing' ), array( $refused['status'], $refused['body']['code'] ?? null ), (string) wp_json_encode( $refused['body'] ) );
		$this->assertSame( array(), $this->caught_doing_it_wrong, 'The session write answered a code it does not declare.' );
	}

	/**
	 * Tests that a placement refused because its product was deleted while the cart was priced again answers a not-found the placement declares.
	 *
	 * The checkout's write plans the order with the product in place; the product is then deleted,
	 * as above, while the placement prices the cart again.
	 *
	 * @since 0.2.0
	 */
	public function test_a_placement_refused_for_a_product_deleted_while_it_was_priced_answers_a_declared_not_found(): void {
		$variant = $this->sellable( 5, 1999 );

		$this->startOverTheWire( array( $variant => 1 ) );

		$written = $this->checkoutOverTheWire( 1, true );

		$this->assertSame( 200, $written['status'], (string) wp_json_encode( $written['body'] ) );

		$barrier = $this->deletedBeforeTheRouterReadsItsUnits( $variant );
		$refused = $this->placementOverTheWire( $written['body'], 'attempt-deleted' );

		$this->assertTrue( $barrier->fired, 'The product was not deleted before the router read its units.' );
		$this->assertSame( array( 404, 'stock.item_missing' ), array( $refused['status'], $refused['body']['code'] ?? null ), (string) wp_json_encode( $refused['body'] ) );
		$this->assertSame( array(), $this->caught_doing_it_wrong, 'The placement answered a code it does not declare.' );
	}

	/**
	 * Places over the wire a two-unit order no one location can ship whole, while connection B changes the row of the location the order would ship from just before the hold locks it.
	 *
	 * @since 0.2.0
	 *
	 * @param string $change The statement connection B commits: a format given the locations table's name, then the location's id.
	 * @return array{status: int|null, headers: array<string, string>, body: array<string, mixed>} The placement's answer.
	 */
	private function placementAsItsLocationChanges( string $change ): array {
		$variant     = $this->sellable( 1, 1999 );
		$destination = $this->plantLocation( -1 );
		$warehouse   = $this->plantLocation( 5, true, false );

		// No one location has both units, so the order ships from the first, which holds none of them.
		$this->stockAt( $variant, $warehouse, 1 );
		$this->routeIn( FulfilmentMode::Transfer );
		$this->startOverTheWire( array( $variant => 2 ) );

		$written = $this->checkoutOverTheWire( 1, true );

		$this->assertSame( 200, $written['status'], (string) wp_json_encode( $written['body'] ) );

		$b       = $this->secondConnection();
		$barrier = $this->beforeStatement(
			'/^SELECT .+ FROM `[^`]+locations` WHERE id = \d+ LOCK IN SHARE MODE/',
			function () use ( $b, $change, $destination ): void {
				$b->query( sprintf( $change, $this->table( InventoryTables::LOCATIONS ), $destination ) );
			}
		);
		$refused = $this->placementOverTheWire( $written['body'], 'attempt-1' );

		$this->assertTrue( $barrier->fired, 'The location was not changed before the hold.' );

		return $refused;
	}

	/**
	 * Deletes a product, with its stock, through a second runner of the plugin, just before this request's router reads the units its variants have available.
	 *
	 * @since 0.2.0
	 *
	 * @param int $productId The product, whose id is its first variant's.
	 * @return object{fired: bool, seen: int} Whether the product was deleted, and how many such reads were sent.
	 */
	private function deletedBeforeTheRouterReadsItsUnits( int $productId ): object {
		return $this->beforeStatement(
			'/AS available FROM `[^`]+stock_policies` p LEFT JOIN/',
			function () use ( $productId ): void {
				$this->secondKernel( new FakeCartTokens() )->get( DeleteProduct::class )->delete( $productId, Actor::user( 1 ) );
			}
		);
	}

	/**
	 * Starts a new cart over the wire with lines, and presents its cookie from then on.
	 *
	 * @since 0.2.0
	 *
	 * @param array<int, int> $quantities The units of each variant, by variant id.
	 */
	private function startOverTheWire( array $quantities ): void {
		unset( $_COOKIE[ CartTokenTransport::COOKIE ] );

		$started = $this->store( 'POST', CartOperations::LINES_ROUTE, self::linesBody( $quantities ) );

		$this->assertSame( 200, $started['status'], (string) wp_json_encode( $started['body'] ) );
		$this->presentCookie( $this->cookies[ count( $this->cookies ) - 1 ]['value'] );
	}

	/**
	 * Writes the cart's checkout over the wire, with a billing address and the payment method, and a shipping address when asked.
	 *
	 * @since 0.2.0
	 *
	 * @param int  $version  The cart's version.
	 * @param bool $shipping Whether to give a shipping address.
	 * @return array{status: int|null, headers: array<string, string>, body: array<string, mixed>} What was sent.
	 */
	private function checkoutOverTheWire( int $version, bool $shipping ): array {
		$address = array(
			'country'  => 'US',
			'line1'    => '1 Main Street',
			'city'     => 'Austin',
			'postcode' => '78701',
		);
		$body    = array(
			'cart_version'       => $version,
			'billing_address'    => $address + array(
				'first_name' => 'Ada',
				'last_name'  => 'Lovelace',
				'email'      => 'ada@example.com',
			),
			'payment_method_key' => StubGateway::ID,
		);

		if ( $shipping ) {
			$body['shipping_address'] = $address;
		}

		return $this->store( 'PUT', CheckoutOperations::ROUTE, $body );
	}

	/**
	 * Places a one-unit order of a variant over the wire, from a new cart, and returns the placement's answer.
	 *
	 * @since 0.2.0
	 *
	 * @param int $variantId The variant.
	 * @return array{placed: array<string, mixed>} The placement's answer.
	 */
	private function placedOverTheWire( int $variantId ): array {
		$this->startOverTheWire( array( $variantId => 1 ) );

		$written = $this->checkoutOverTheWire( 1, true );

		$this->assertSame( 200, $written['status'], (string) wp_json_encode( $written['body'] ) );

		$placed = $this->placementOverTheWire( $written['body'], 'attempt-' . $variantId );

		$this->assertSame( 200, $placed['status'], (string) wp_json_encode( $placed['body'] ) );

		return array( 'placed' => $placed['body'] );
	}

	/**
	 * Places the request's cart over the wire at the version and the grand total its checkout's write answered, with a token the gateway approves.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $written The checkout write's answer.
	 * @param string               $key     The idempotency key.
	 * @return array{status: int|null, headers: array<string, string>, body: array<string, mixed>} What was sent.
	 */
	private function placementOverTheWire( array $written, string $key ): array {
		return $this->serve(
			$this->server,
			'POST',
			'/' . RestBinding::STORE_NAMESPACE . CheckoutOperations::ROUTE,
			array(
				StoreRequestPolicy::HEADER             => StoreRequestPolicy::HEADER_VALUE,
				CheckoutOperations::IDEMPOTENCY_HEADER => $key,
			),
			array(
				'cart_version'      => $written['version'],
				'grand_total_minor' => $written['totals']['summary']['grand_minor'],
				'currency'          => $written['totals']['currency'],
				'payment_data'      => array( 'payment_token' => StubGateway::APPROVE ),
			)
		);
	}

	/**
	 * Reads a placed order's status over the wire, with the access key its placement answered.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $placed The placement's answer.
	 * @return array<string, mixed> The order's status.
	 */
	private function readOverTheWire( array $placed ): array {
		$status = $this->serve(
			$this->server,
			'GET',
			'/' . RestBinding::STORE_NAMESPACE . str_replace( '{uuid}', (string) $placed['order_uuid'], OrderStoreOperations::STATUS_ROUTE ),
			array( OrderStoreOperations::KEY_HEADER => (string) $placed['order_key'] )
		);

		$this->assertSame( 200, $status['status'], (string) wp_json_encode( $status['body'] ) );

		return $status['body'];
	}
}
