<?php
/**
 * Tests the query cost of a checkout session write, over the wire
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Performance;

use SEOCart\Cart\Infrastructure\CartTables;
use SEOCart\Cart\Interfaces\StoreApi\CartOperations;
use SEOCart\Checkout\Domain\AddressDocument;
use SEOCart\Checkout\Infrastructure\CheckoutTables;
use SEOCart\Checkout\Interfaces\StoreApi\CheckoutOperations;
use SEOCart\Tests\Support\Cart\ServesStoreApi;
use SEOCart\Tests\Support\Checkout\CheckoutTestCase;

/**
 * The session write's budget, measured on a whole request served through the production wiring, the calculation of the totals and the request policy's count included:
 *
 * - one transaction, which sends the cart's compare-and-swap and one statement to the session table;
 * - after it, one read of the session, which gives the totals their destination;
 * - at most 20 queries in all, on a three-line cart.
 *
 * The measurement is printed for the report.
 *
 * Planted violation: in UpdateCheckoutSession::update(), save the session twice in the closure:
 * the transaction then sends two statements to the session table.
 *
 * @since 0.1.0
 *
 * @group performance
 */
final class CheckoutBudgetTest extends CheckoutTestCase {

	use ServesStoreApi;

	/**
	 * The most queries a session write on a three-line cart may cost.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private const WRITE_BUDGET = 20;

	/**
	 * Wires the kernel and boots a spy server.
	 *
	 * @since 0.1.0
	 */
	public function set_up(): void {
		parent::set_up();

		$this->bootStoreApi();
	}

	/**
	 * Restores the request globals and discards the server.
	 *
	 * @since 0.1.0
	 */
	public function tear_down(): void {
		$this->shutStoreApi();

		parent::tear_down();
	}

	/**
	 * Tests that a session write on a three-line cart stays within its budget, in one transaction.
	 *
	 * @since 0.1.0
	 */
	public function test_a_session_write_stays_within_its_budget(): void {
		$quantities = array(
			self::variant() => 1,
			self::variant() => 2,
			self::variant() => 3,
		);

		$this->price( array_fill_keys( array_keys( $quantities ), 1999 ) );

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
						'billing_address'  => AddressDocument::of( self::address( 'GB', 'ada@example.com' ) ),
						'shipping_address' => AddressDocument::of( self::address( 'GB' ) ),
					)
				);
			}
		);

		$sessions = $log->forTable( $this->table( CheckoutTables::SESSIONS ) );

		$this->assertSame( 200, $written['status'], (string) wp_json_encode( $written['body'] ) );
		$this->assertQueryCount( 1, $log->matching( '/^START TRANSACTION/' ), 'Transactions of a session write' );
		$this->assertQueryCount( 1, $sessions->ofType( 'INSERT' ), 'The session\'s upsert' );
		$this->assertQueryCount( 1, $sessions->ofType( 'SELECT' ), 'The read of the session for the totals' );
		$this->assertQueryCount( 2, $log->forTable( $this->table( CartTables::CARTS ) )->ofType( 'UPDATE' ), 'The compare-and-swap and the recount of the cart' );
		$this->assertQueryCountAtMost( self::WRITE_BUDGET, $log, 'A session write on a three-line cart' );

		fwrite( STDOUT, sprintf( "\nA checkout session write on a three-line cart: %d queries (budget %d), the transaction's statements included.\n", $log->count(), self::WRITE_BUDGET ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- The measurement is printed for the report.
	}
}
