<?php
/**
 * Tests the lines not for sale on the cart's Store API answers, served through the production wiring
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Cart;

use SEOCart\Application\Operations\RestBinding;
use SEOCart\Cart\Domain\LineIdentity;
use SEOCart\Cart\Interfaces\StoreApi\CartOperations;
use SEOCart\Cart\Interfaces\StoreApi\StoreRequestPolicy;
use SEOCart\Checkout\Domain\AddressDocument;
use SEOCart\Checkout\Interfaces\StoreApi\CheckoutOperations;
use SEOCart\Inventory\Infrastructure\InventoryTables;
use SEOCart\Tests\Support\Cart\ServesStoreApi;
use SEOCart\Tests\Support\Checkout\CheckoutTestCase;
use SEOCart\Tests\Support\QueryLog;

/**
 * Every answer of the cart's five operations carries `unavailable_lines`, each item its line, its
 * variant, its reason and the units for sale, and nothing else, from the kernel's figure: a write
 * past the figure is accepted and its line flagged; a request with no cart, or a cart with no line,
 * reads no stock and no inventory setting; and the checkout's session write, which answers with the
 * totals only, reads no figure.
 *
 * Planted violations, each shown red and removed:
 *
 * - in CartService::addLines(), answer with priced() rather than answered(): the serializer then
 *   refuses the add's answer, a required field missing, and the add answers 500;
 * - in CartService::changeWith(), answer with answered(): the session write then reads the figures.
 *
 * @since 0.2.0
 */
final class CartAvailabilityOperationsTest extends CheckoutTestCase {

	use ServesStoreApi;

	/**
	 * The keys of an item of `unavailable_lines`, as the resource declares them.
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	private const ITEM_KEYS = array( 'line_identity', 'variant_id', 'reason', 'for_sale' );

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
	 * Tests that each of the cart's five answers names the lines not for sale, from an add past the figure to the removal of the only line.
	 *
	 * @since 0.2.0
	 */
	public function test_every_cart_answer_names_the_lines_not_for_sale(): void {
		$mug = self::variant();

		$this->price( array( $mug => 1000 ) );
		$this->plantItem( $mug, 3 );
		$this->plantPromotion( 'SAVE10' );

		$short   = array( 'insufficient_stock:3' );
		$added   = $this->store( 'POST', CartOperations::LINES_ROUTE, self::linesBody( array( $mug => 5 ) ) );
		$answers = array( 'cart.add_lines' => $added );

		$this->assertSame( 200, $added['status'], (string) wp_json_encode( $added['body'] ) );
		$this->presentCookie( $this->cookies[0]['value'] );

		$answers['cart.get_cart']    = $this->store( 'GET', CartOperations::CART_ROUTE );
		$answers['cart.apply_code']  = $this->store(
			'POST',
			CartOperations::CODES_ROUTE,
			array(
				'code'         => 'SAVE10',
				'cart_version' => 1,
			)
		);
		$answers['cart.remove_code'] = $this->serve(
			$this->server,
			'DELETE',
			'/' . RestBinding::STORE_NAMESPACE . '/cart/codes/SAVE10',
			array( StoreRequestPolicy::HEADER => StoreRequestPolicy::HEADER_VALUE ),
			array(),
			array( 'cart_version' => 2 )
		);
		$answers['cart.update_line'] = $this->store( 'PATCH', '/cart/lines/' . LineIdentity::of( $mug )->value(), self::quantityBody( 3, 3 ) );

		foreach ( $answers as $operation => $answer ) {
			$this->assertSame( 200, $answer['status'], $operation . ': ' . (string) wp_json_encode( $answer['body'] ) );

			foreach ( $answer['body']['unavailable_lines'] as $item ) {
				$this->assertSame( self::ITEM_KEYS, array_keys( $item ), $operation );
				$this->assertSame( array( LineIdentity::of( $mug )->value(), $mug ), array( $item['line_identity'], $item['variant_id'] ), $operation );
			}
		}

		$this->assertSame( $short, self::flags( $answers['cart.add_lines'] ), 'An add past the figure is accepted, and its line flagged.' );
		$this->assertSame( $short, self::flags( $answers['cart.get_cart'] ) );
		$this->assertSame( $short, self::flags( $answers['cart.apply_code'] ) );
		$this->assertSame( $short, self::flags( $answers['cart.remove_code'] ) );
		$this->assertSame( array(), self::flags( $answers['cart.update_line'] ), 'Three units cover a line of three.' );

		$removed = $this->store( 'PATCH', '/cart/lines/' . LineIdentity::of( $mug )->value(), self::quantityBody( 0, 4 ) );

		$this->assertSame( 200, $removed['status'] );
		$this->assertEmptyLines( $removed['body'] );
	}

	/**
	 * Tests that a read with no cart answers no line not for sale, and reads no stock and no inventory setting.
	 *
	 * @since 0.2.0
	 */
	public function test_a_read_with_no_cart_reads_no_stock(): void {
		$read = array();
		$log  = $this->captureQueries(
			function () use ( &$read ): void {
				$read = $this->store( 'GET', CartOperations::CART_ROUTE );
			}
		);

		$this->assertSame( 200, $read['status'] );
		$this->assertEmptyCart( $read['body'] );
		$this->assertQueryCount( 0, $this->stockReads( $log ), 'A read with no cart, on the stock and its settings' );
	}

	/**
	 * Tests that the checkout's session write reads no figure, and answers as it did.
	 *
	 * @since 0.2.0
	 */
	public function test_the_session_write_reads_no_figure(): void {
		$quantities = array(
			self::variant() => 1,
			self::variant() => 2,
		);

		$this->price( array_fill_keys( array_keys( $quantities ), 1999 ) );
		$this->stocked( array_keys( $quantities ) );

		$started = $this->store( 'POST', CartOperations::LINES_ROUTE, self::linesBody( $quantities ) );

		$this->assertSame( 200, $started['status'] );
		$this->presentCookie( $this->cookies[0]['value'] );

		$written = array();
		$log     = $this->captureQueries(
			function () use ( &$written ): void {
				$written = $this->store(
					'PUT',
					CheckoutOperations::ROUTE,
					array(
						'cart_version'     => 1,
						'shipping_address' => AddressDocument::of( self::address( 'GB' ) ),
					)
				);
			}
		);

		$this->assertSame( 200, $written['status'], (string) wp_json_encode( $written['body'] ) );
		$this->assertSame( array( 'checkout_session', 'version', 'totals', 'delivery' ), array_keys( $written['body'] ) );
		$this->assertQueryCount( 0, $log->matching( self::SALE_FIGURES ), 'The session write\'s reads of the figures' );
	}

	/**
	 * Builds the body of a change of a line's quantity.
	 *
	 * @since 0.2.0
	 *
	 * @param int $quantity The units.
	 * @param int $version  The cart version.
	 * @return array<string, int> The body.
	 */
	private static function quantityBody( int $quantity, int $version ): array {
		return array(
			'quantity'     => $quantity,
			'cart_version' => $version,
		);
	}

	/**
	 * Asserts that an answer has no line, and so none not for sale.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $body The answer.
	 */
	private function assertEmptyLines( array $body ): void {
		$this->assertSame( array( array(), array() ), array( $body['lines'], $body['unavailable_lines'] ) );
	}

	/**
	 * Keeps the statements on the stock tables, the locations and the inventory settings.
	 *
	 * @since 0.2.0
	 *
	 * @param QueryLog $log The statements.
	 * @return QueryLog Those of them.
	 */
	private function stockReads( QueryLog $log ): QueryLog {
		$tables = array_map( fn( string $table ): string => preg_quote( $this->table( $table ), '/' ), array( InventoryTables::POLICIES, InventoryTables::ITEMS, InventoryTables::LOCATIONS ) );

		return $log->matching( '/`(?:' . implode( '|', $tables ) . ')`|seocart_inventory/' );
	}

	/**
	 * Writes an answer's flagged lines as `reason:for_sale`, in their order.
	 *
	 * @since 0.2.0
	 *
	 * @param array{status: int|null, headers: array<string, string>, body: array<string, mixed>} $answer What was sent.
	 * @return list<string> The flags.
	 */
	private static function flags( array $answer ): array {
		return array_map( static fn( array $line ): string => $line['reason'] . ':' . $line['for_sale'], $answer['body']['unavailable_lines'] );
	}
}
