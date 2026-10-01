<?php
/**
 * Tests the query cost of reading a cart and of adding lines to one, over the wire
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Performance;

use SEOCart\Cart\Domain\CartLine;
use SEOCart\Cart\Infrastructure\CartTables;
use SEOCart\Cart\Interfaces\StoreApi\CartOperations;
use SEOCart\Catalog\Infrastructure\CatalogTables;
use SEOCart\Checkout\Infrastructure\CheckoutTables;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Tests\Support\Cart\CartTestCase;
use SEOCart\Tests\Support\Cart\ServesStoreApi;
use SEOCart\Tests\Support\Performance\ReferenceCarts;
use SEOCart\Tests\Support\Pricing\Inputs;
use SEOCart\Tests\Support\Pricing\PricesInCurrencies;
use SEOCart\Tests\Support\QueryLog;

/**
 * The cart's budgets, measured on whole requests served through the production wiring, the
 * calculation of the totals and the request policy's count included:
 *
 * - reading a three-line cart costs at most 6 queries, one of them the read of its checkout
 *   session, which gives its totals their destination, and its answer at most 8 KB;
 * - an add-lines write on Reference Cart B, with its two promotion codes, costs at most 20 queries,
 *   and so does applying each of the codes;
 * - adding lines writes them in one statement, whatever their number;
 * - reading Reference Cart B in another currency, with prices both authored in it and converted
 *   into it, costs at most 2 queries more than reading it in the base currency: the currency's
 *   terms and its current rate are one read, once per request.
 *
 * Each measurement, Reference Cart A's too, is printed for the report.
 *
 * Planted violations, each shown red and removed:
 *
 * - in MysqlCartRepository::addLines(), send one ADD_LINES statement per line. A ten-line batch
 *   then costs nine statements more than a one-line batch;
 * - in MysqlPresentmentCurrencies::find(), take the current version from the rates table and read
 *   the enabled currencies on their own before the currency's row, instead of the one joined read
 *   at the version the installation record names: Cart B's read in EUR then costs three queries
 *   more than in USD.
 *
 * @since 0.1.0
 *
 * @group performance
 */
final class CartBudgetTest extends CartTestCase {

	use ServesStoreApi;
	use PricesInCurrencies;

	/**
	 * The most queries reading Cart B in another currency may cost over reading it in the base currency.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private const PRESENTMENT_EXTRA = 2;

	/**
	 * The most queries reading a three-line cart may cost.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private const READ_BUDGET = 6;

	/**
	 * The most bytes the answer to reading a three-line cart may take.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private const READ_BYTES = 8192;

	/**
	 * The most queries an add-lines write on Cart B may cost.
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
		$this->removeBootRecord();

		parent::tear_down();
	}

	/**
	 * Tests that reading a three-line cart stays within its query and size budgets.
	 *
	 * @since 0.1.0
	 */
	public function test_reading_a_three_line_cart_stays_within_its_budget(): void {
		$quantities = array(
			self::variant() => 1,
			self::variant() => 2,
			self::variant() => 3,
		);

		$this->price( array_fill_keys( array_keys( $quantities ), 1999 ) );
		$this->startOverTheWire( $quantities );

		$read = array();
		$log  = $this->captureQueries(
			function () use ( &$read ): void {
				$read = $this->store( 'GET', CartOperations::CART_ROUTE );
			}
		);

		$bytes = strlen( $this->server->sent_body );

		$this->assertSame( 200, $read['status'] );
		$this->assertCount( 3, $read['body']['totals']['lines'] );
		$this->assertQueryCountAtMost( self::READ_BUDGET, $log, 'Reading a three-line cart' );
		$this->assertQueryCount( 1, $log->forTable( $this->table( CheckoutTables::SESSIONS ) ), 'The read of the checkout session of the cart' );
		$this->assertLessThanOrEqual( self::READ_BYTES, $bytes );

		self::report( sprintf( 'G17, reading a three-line cart: %d queries (budget %d), %d bytes (budget %d).', $log->count(), self::READ_BUDGET, $bytes, self::READ_BYTES ) );
	}

	/**
	 * Tests that an add-lines write on Reference Cart B, with its two promotion codes, stays within its budget, and measures applying a code and Cart A.
	 *
	 * The write adds a unit to one of the cart's lines, so the cart keeps Cart B's shape. Applying
	 * each of Cart B's codes is a cart write too, held to the same budget.
	 *
	 * @since 0.1.0
	 */
	public function test_an_add_lines_write_on_cart_b_stays_within_its_budget(): void {
		$cartA = self::variant();
		$cartB = array();

		for ( $slot = 0; $slot < ReferenceCarts::CART_B_VARIANTS; $slot++ ) {
			$cartB[] = self::variant();
		}

		ReferenceCarts::seedPrices( $this->db, $cartA, $cartB, self::CURRENCY );

		$aStart = $this->measure( 'POST', CartOperations::LINES_ROUTE, self::bodyOf( ReferenceCarts::cartA( $cartA ) ) );

		$this->presentCookie( $this->cookies[0]['value'] );

		$aWrite = $this->measure( 'POST', CartOperations::LINES_ROUTE, self::linesBody( array( $cartA => 1 ), 1 ) );
		$aRead  = $this->measure( 'GET', CartOperations::CART_ROUTE );

		unset( $_COOKIE['seocart_cart_token'] );

		$bStart = $this->measure( 'POST', CartOperations::LINES_ROUTE, self::bodyOf( ReferenceCarts::cartB( $cartB ) ) );

		$this->presentCookie( $this->cookies[ count( $this->cookies ) - 1 ]['value'] );

		$version = 1;
		$applied = array();

		foreach ( ReferenceCarts::CART_B_CODES as $code => $columns ) {
			$this->plantPromotion( $code, $columns );

			$applied[] = $this->measure(
				'POST',
				CartOperations::CODES_ROUTE,
				array(
					'code'         => $code,
					'cart_version' => $version++,
				)
			);
		}

		$write = $this->measure( 'POST', CartOperations::LINES_ROUTE, self::linesBody( array( $cartB[0] => 1 ), $version ) );
		$read  = $this->measure( 'GET', CartOperations::CART_ROUTE );

		$this->assertSame( array(), $write['body']['unpriced_lines'] );
		$this->assertCount( ReferenceCarts::CART_B_VARIANTS, $write['body']['totals']['lines'] );
		$this->assertSame( array_keys( ReferenceCarts::CART_B_CODES ), array_column( $write['body']['promotion_codes'], 'code' ) );
		$this->assertLessThan( 0, $write['body']['totals']['summary']['discount_total_minor'], 'Cart B\'s 10 % code took nothing off.' );
		$this->assertQueryCountAtMost( self::WRITE_BUDGET, $write['log'], 'An add-lines write on Cart B' );

		foreach ( $applied as $apply ) {
			$this->assertQueryCountAtMost( self::WRITE_BUDGET, $apply['log'], 'Applying a code to Cart B' );
		}

		self::report(
			sprintf(
				"G7, an add-lines write on Cart B with its two codes: %d queries (budget %d), the transaction's four statements included.\nApplying Cart B's codes: %d and %d queries (budget %d).\nCart B: started in %d queries; read in %d queries and %d bytes.\nCart A: started in %d queries; an add-lines write in %d; read in %d queries and %d bytes.",
				$write['log']->count(),
				self::WRITE_BUDGET,
				$applied[0]['log']->count(),
				$applied[1]['log']->count(),
				self::WRITE_BUDGET,
				$bStart['log']->count(),
				$read['log']->count(),
				$read['bytes'],
				$aStart['log']->count(),
				$aWrite['log']->count(),
				$aRead['log']->count(),
				$aRead['bytes']
			)
		);
	}

	/**
	 * Tests that reading Cart B in another currency, half its prices authored in it and half converted, costs at most two queries more than in the base currency.
	 *
	 * The same read is measured twice on one cart: in USD, then, its currency changed in its row,
	 * in EUR. The store sells in EUR at a saved rate, recorded as current in the installation
	 * record, as on an installed site.
	 *
	 * @group international
	 *
	 * @since 0.1.0
	 */
	public function test_cart_b_in_another_currency_costs_at_most_two_queries_more(): void {
		$cartB = array();

		for ( $slot = 0; $slot < ReferenceCarts::CART_B_VARIANTS; $slot++ ) {
			$cartB[] = self::variant();
		}

		ReferenceCarts::seedPrices( $this->db, self::variant(), $cartB, self::CURRENCY );

		// Every other variant has a price authored in EUR; the rest are converted from USD.
		foreach ( $cartB as $slot => $variant ) {
			if ( 0 === $slot % 2 ) {
				$this->db->execute(
					"INSERT INTO %i ( variant_id, currency, amount_basis, price_minor, created_at, updated_at ) VALUES ( %d, 'EUR', 'net', %d, UTC_TIMESTAMP(), UTC_TIMESTAMP() )",
					$this->table( CatalogTables::VARIANT_PRICES ),
					$variant,
					Inputs::money( ReferenceCarts::unitPrice( $slot ), 'EUR' )->minorUnits()
				);
			}
		}

		$this->createRateTables();
		$this->enableCurrency( 'EUR' );
		self::ratesOver( $this->db, static function (): void {} )->saveVersion( array( self::rateTo( 'EUR', '0.91230' ) ), Actor::user( 0 ) );
		$this->plantBootRecord( 1 );

		$this->measure( 'POST', CartOperations::LINES_ROUTE, self::bodyOf( ReferenceCarts::cartB( $cartB ) ) );

		$this->presentCookie( $this->cookies[ count( $this->cookies ) - 1 ]['value'] );
		$this->measure( 'GET', CartOperations::CART_ROUTE );

		$base = $this->measure( 'GET', CartOperations::CART_ROUTE );

		$this->assertSame( 'USD', $base['body']['totals']['currency'] );
		$this->assertSame( 1, $this->db->execute( "UPDATE %i SET currency = 'EUR'", $this->table( CartTables::CARTS ) ), 'The test has one cart.' );

		$presentment = $this->measure( 'GET', CartOperations::CART_ROUTE );
		$sources     = array_count_values( array_column( $presentment['body']['totals']['lines'], 'price_source' ) );

		ksort( $sources );

		$this->assertSame( array( 'EUR', 1 ), array( $presentment['body']['totals']['currency'], $presentment['body']['totals']['rate_version'] ) );
		$this->assertSame( array(), $presentment['body']['unpriced_lines'] );
		$this->assertSame(
			array(
				'converted' => 5,
				'explicit'  => 5,
			),
			$sources
		);
		$this->assertQueryCountAtMost( $base['log']->count() + self::PRESENTMENT_EXTRA, $presentment['log'], 'Reading Cart B in EUR, against reading it in USD' );

		self::report( sprintf( 'Reading Cart B in the base currency: %1$d queries; in EUR, five prices authored in it and five converted: %2$d queries (%3$+d, at most +%4$d).', $base['log']->count(), $presentment['log']->count(), $presentment['log']->count() - $base['log']->count(), self::PRESENTMENT_EXTRA ) );
	}

	/**
	 * Tests that adding one line and adding ten cost the same statements, and exactly one of them writes the lines.
	 *
	 * @since 0.1.0
	 */
	public function test_adding_lines_is_one_statement_whatever_their_number(): void {
		$this->startCart( array( self::variant() => 1 ) );

		$one = $this->captureQueries( fn() => $this->service->add( self::lines( array( self::variant() => 1 ) ), 1, self::guest() ) );
		$ten = array();

		for ( $line = 0; $line < 10; $line++ ) {
			$ten[ self::variant() ] = 1;
		}

		$tenLines = $this->captureQueries( fn() => $this->service->add( self::lines( $ten ), 2, self::guest() ) );
		$batches  = array(
			'one line'  => $one,
			'ten lines' => $tenLines,
		);

		foreach ( $batches as $batch => $log ) {
			$this->assertQueryCount( 1, $this->linesWritten( $log ), 'The statements that write lines, for ' . $batch );
		}

		$this->assertSame( $one->count(), $tenLines->count(), 'Ten lines cost more statements than one.' );

		self::report( sprintf( 'Adding lines to a cart: %d statements for one line, %d for ten, one of them writing the lines.', $one->count(), $tenLines->count() ) );
	}

	/**
	 * Starts a cart over the wire, and presents its token from then on.
	 *
	 * @since 0.1.0
	 *
	 * @param array<int, int> $quantities Units by variant id.
	 */
	private function startOverTheWire( array $quantities ): void {
		$started = $this->store( 'POST', CartOperations::LINES_ROUTE, self::linesBody( $quantities ) );

		$this->assertSame( 200, $started['status'], (string) wp_json_encode( $started['body'] ) );
		$this->presentCookie( $this->cookies[ count( $this->cookies ) - 1 ]['value'] );
	}

	/**
	 * Serves one request and measures it: its queries and the bytes of its answer.
	 *
	 * @since 0.1.0
	 *
	 * @param string               $method The HTTP method.
	 * @param string               $route  The route.
	 * @param array<string, mixed> $body   Optional. The form body. Default none.
	 * @return array{log: QueryLog, bytes: int, body: array<string, mixed>} The measurement and the answer.
	 */
	private function measure( string $method, string $route, array $body = array() ): array {
		$served = array();
		$log    = $this->captureQueries(
			function () use ( &$served, $method, $route, $body ): void {
				$served = $this->store( $method, $route, $body );
			}
		);

		$this->assertSame( 200, $served['status'], (string) wp_json_encode( $served['body'] ) );

		return array(
			'log'   => $log,
			'bytes' => strlen( $this->server->sent_body ),
			'body'  => $served['body'],
		);
	}

	/**
	 * Builds the body of an add-lines write from lines.
	 *
	 * @since 0.1.0
	 *
	 * @param CartLine[] $lines The lines.
	 * @return array<string, mixed> The body.
	 *
	 * @phpstan-param list<CartLine> $lines
	 */
	private static function bodyOf( array $lines ): array {
		$quantities = array();

		foreach ( $lines as $line ) {
			$quantities[ $line->variantId ] = $line->quantity;
		}

		return self::linesBody( $quantities );
	}

	/**
	 * Narrows a log to the statements that write cart lines.
	 *
	 * @since 0.1.0
	 *
	 * @param QueryLog $log The queries.
	 * @return QueryLog The INSERT, UPDATE and DELETE statements whose target is the line table;
	 *                  not the recount of the cart's row, which reads the lines.
	 */
	private function linesWritten( QueryLog $log ): QueryLog {
		return $log->matching( '/^(?:INSERT INTO|UPDATE|DELETE FROM) `' . preg_quote( $this->table( CartTables::LINES ), '/' ) . '`/' );
	}

	/**
	 * Prints a measurement for the report.
	 *
	 * @since 0.1.0
	 *
	 * @param string $line The measurement.
	 */
	private static function report( string $line ): void {
		fwrite( STDOUT, "\n" . $line . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- The measurement is printed for the report.
	}
}
