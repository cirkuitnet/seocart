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
use SEOCart\Tests\Support\Cart\CartTestCase;
use SEOCart\Tests\Support\Cart\ServesStoreApi;
use SEOCart\Tests\Support\Performance\ReferenceCarts;
use SEOCart\Tests\Support\QueryLog;

/**
 * The cart's budgets, measured on whole requests served through the production wiring, the
 * calculation of the totals and the request policy's count included:
 *
 * - reading a three-line cart costs at most 6 queries, and its answer at most 8 KB;
 * - an add-lines write on Reference Cart B costs at most 20 queries;
 * - adding lines writes them in one statement, whatever their number.
 *
 * Each measurement, Reference Cart A's too, is printed for the report.
 *
 * Planted violation: in MysqlCartRepository::addLines(), send one ADD_LINES statement per line.
 * A ten-line batch then costs nine statements more than a one-line batch.
 *
 * @since 0.1.0
 *
 * @group performance
 */
final class CartBudgetTest extends CartTestCase {

	use ServesStoreApi;

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
		$this->assertLessThanOrEqual( self::READ_BYTES, $bytes );

		self::report( sprintf( 'G17, reading a three-line cart: %d queries (budget %d), %d bytes (budget %d).', $log->count(), self::READ_BUDGET, $bytes, self::READ_BYTES ) );
	}

	/**
	 * Tests that an add-lines write on Reference Cart B stays within its budget, and measures Cart A.
	 *
	 * The write adds a unit to one of the cart's lines, so the cart keeps Cart B's shape.
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

		$this->presentCookie( $this->cookies[1]['value'] );

		$write = $this->measure( 'POST', CartOperations::LINES_ROUTE, self::linesBody( array( $cartB[0] => 1 ), 1 ) );
		$read  = $this->measure( 'GET', CartOperations::CART_ROUTE );

		$this->assertSame( array(), $write['body']['unpriced_lines'] );
		$this->assertCount( ReferenceCarts::CART_B_VARIANTS, $write['body']['totals']['lines'] );
		$this->assertQueryCountAtMost( self::WRITE_BUDGET, $write['log'], 'An add-lines write on Cart B' );

		self::report(
			sprintf(
				"G7, an add-lines write on Cart B: %d queries (budget %d), the transaction's four statements included.\nCart B: started in %d queries; read in %d queries and %d bytes.\nCart A: started in %d queries; an add-lines write in %d; read in %d queries and %d bytes.",
				$write['log']->count(),
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
