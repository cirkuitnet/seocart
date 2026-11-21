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
use SEOCart\Checkout\Domain\Fields\Carrier;
use SEOCart\Checkout\Infrastructure\CheckoutTables;
use SEOCart\Checkout\Interfaces\StoreApi\CheckoutOperations;
use SEOCart\Tests\Support\Cart\ServesStoreApi;
use SEOCart\Tests\Support\Checkout\CheckoutTestCase;

/**
 * The session write's budget, measured on a whole request served through the production wiring, the calculation of the totals and the request policy's count included:
 *
 * - one transaction, which sends the cart's compare-and-swap and one statement to the session table;
 * - after it, one read of the session, which gives the totals their destination;
 * - no read of the lines' figures for sale: the answer carries the totals, never the lines;
 * - 17 statements on a cold cache, within a budget of 20, on a three-line cart.
 *
 * The write is measured cold, with the object cache flushed after the request that fills the
 * cart, because no real request inherits the options an earlier request read.
 *
 * WordPress installs every site with its permalink structure among the options it loads at boot,
 * and the REST server reads it for each answer's Link header; the test library's single-site
 * install deletes that option, where a network keeps it, so the test puts it back as WordPress
 * installs it and measures the request a real site serves, single or networked.
 *
 * The measurement is printed for the report.
 *
 * Planted violations: in UpdateCheckoutSession::update(), save the session twice in the closure:
 * the transaction then sends two statements to the session table; in CheckoutRules::current(),
 * read the `checkout` group in a values() call of its own: the cold write sends 18.
 *
 * @since 0.1.0
 * @since 0.2.0 Measured cold, and its statements pinned.
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
	 * The statements a session write on a three-line cart sends on a cold cache.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	private const COLD_WRITE = 17;

	/**
	 * Whether the test added the permalink structure's option, which it then removes.
	 *
	 * @since 0.2.0
	 *
	 * @var bool
	 */
	private bool $addedPermalinkStructure = false;

	/**
	 * Wires the kernel, boots a spy server, and gives the site the permalink structure WordPress installs it with.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 Gives the site its permalink structure.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->bootStoreApi();

		// WordPress's installer adds it empty and autoloaded; add_option() changes nothing where it exists.
		$this->addedPermalinkStructure = add_option( 'permalink_structure', '' );
	}

	/**
	 * Restores the request globals, discards the server, and removes the permalink structure if the test added it.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 Removes the permalink structure it added.
	 */
	public function tear_down(): void {
		$this->shutStoreApi();

		if ( $this->addedPermalinkStructure ) {
			delete_option( 'permalink_structure' );
		}

		parent::tear_down();
	}

	/**
	 * Tests that a session write on a three-line cart stays within its budget, in one transaction.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 Measured on a cold cache.
	 */
	public function test_a_session_write_stays_within_its_budget(): void {
		$quantities = array(
			self::variant() => 1,
			self::variant() => 2,
			self::variant() => 3,
		);

		$this->price( array_fill_keys( array_keys( $quantities ), 1999 ) );
		$this->stocked( array_keys( $quantities ) );

		$started = $this->store( 'POST', CartOperations::LINES_ROUTE, self::linesBody( $quantities ) );

		$this->assertSame( 200, $started['status'] );
		$this->presentCookie( $this->cookies[0]['value'] );

		// A request starts cold: no object cache but the options WordPress loads at boot.
		wp_cache_flush();
		wp_load_alloptions();

		$written = array();
		$log     = $this->captureQueries(
			function () use ( &$written ): void {
				$written = $this->store(
					'PUT',
					CheckoutOperations::ROUTE,
					array(
						'cart_version'     => 1,
						'billing_address'  => self::wireAddress( Carrier::BILLING, 'GB', 'ada@example.com' ),
						'shipping_address' => self::wireAddress( Carrier::SHIPPING, 'GB' ),
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
		$this->assertQueryCount( 0, $log->matching( self::SALE_FIGURES ), 'The reads of the figures for sale, MysqlStockRepository::SALE_FIGURES' );
		$this->assertQueryCount( self::COLD_WRITE, $log, 'A session write on a three-line cart, cold' );
		$this->assertQueryCountAtMost( self::WRITE_BUDGET, $log, 'A session write on a three-line cart' );

		fwrite( STDOUT, sprintf( "\nA checkout session write on a three-line cart, cold: %d queries (budget %d), the transaction's statements included:\n  %s\n", $log->count(), self::WRITE_BUDGET, implode( "\n  ", $log->sqls() ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- The measurement is printed for the report.
	}
}
