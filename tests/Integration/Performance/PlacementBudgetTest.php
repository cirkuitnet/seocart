<?php
/**
 * Tests the statements and transactions of placing the reference carts, step by step
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Performance;

use SEOCart\Cart\Domain\CartLine;
use SEOCart\Cart\Infrastructure\CartTables;
use SEOCart\Checkout\Application\PlaceOrder;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Tests\Support\Checkout\PlacementTestCase;
use SEOCart\Tests\Support\Performance\ReferenceCarts;
use SEOCart\Tests\Support\Pricing\Inputs;
use SEOCart\Tests\Support\Pricing\PricesInCurrencies;

/**
 * A placement of Cart A, of Cart B, and of Cart B in a presentment currency: every statement it sends, attributed to the step that sent it.
 *
 * The numbers are the first baseline of a placement's cost: the statements before the first
 * transaction, in each of the two, and the gateway's read between them. Each is asserted at most
 * the baseline, so a placement that grows fails here and names the step that grew. The step of a
 * statement is the first placement step its caller passed through; a transaction's own control
 * statements are counted apart.
 *
 * Planted violation: in PlaceOrder::placeInside(), read the session again before storing its
 * quotes: the first unit's count grows by one, and the step is named.
 *
 * @group performance
 *
 * @since 0.1.0
 */
final class PlacementBudgetTest extends PlacementTestCase {

	use PricesInCurrencies;

	/**
	 * The steps of a placement, each with the caller that marks it, the most specific first.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, string>
	 */
	private const STEPS = array(
		'replay: the key, read'            => 'MysqlIdempotencyKeys->replay',
		'declines: the cart\'s count'      => 'PlaceOrder->refuseAfterDeclines',
		'cart: its row and lines'          => 'CartService->current',
		'calculation'                      => 'CartService->calculation',
		'session: its read'                => 'MysqlCheckoutSessions->find',
		'a. the cart\'s claim'             => 'CartService->claimForPlacement',
		'b. the key\'s claim'              => 'MysqlIdempotencyKeys->claim',
		'c. the sale decision'             => 'Sellability->forSale',
		'd. the stock hold'                => 'StockService->hold',
		'e. the order'                     => 'Orders->insert',
		'f. the promotions\' uses'         => 'PromotionUsage->claim',
		'g. the intent'                    => 'PaymentService->createIntent',
		'h. the order bound to the cart'   => 'CartService->bindOrder',
		'h. the quotes kept'               => 'MysqlCheckoutSessions->storeQuotes',
		'i. the key completed'             => 'MysqlIdempotencyKeys->complete',
		'gateway: the intent read'         => 'PaymentService->authorize',
		'2. the gateway\'s answer applied' => 'PaymentService->applyGatewayResult',
		'2. the order\'s lines read'       => 'Orders->stockLines',
		'2. the allocation'                => 'StockService->allocate',
		'2. the uses committed'            => 'PromotionUsage->commit',
		'2. the cart settled'              => 'CartService->settleOrder',
		'2. the kept answer settled'       => 'MysqlIdempotencyKeys->settleAnswer',
	);

	/**
	 * The most statements each part of a placement of Cart A may send.
	 *
	 * @since 0.1.0
	 *
	 * @var array{before: int, first: int, between: int, second: int}
	 */
	private const CART_A = array(
		'before'  => 8,
		'first'   => 31,
		'between' => 1,
		'second'  => 33,
	);

	/**
	 * The most statements each part of a placement of Cart B may send.
	 *
	 * @since 0.1.0
	 *
	 * @var array{before: int, first: int, between: int, second: int}
	 */
	private const CART_B = array(
		'before'  => 9,
		'first'   => 53,
		'between' => 1,
		'second'  => 60,
	);

	/**
	 * The most statements a placement of Cart B in a presentment currency may send beyond one in the base currency.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private const PRESENTMENT_EXTRA = 2;

	/**
	 * Restores the boot record a presentment-currency test planted.
	 *
	 * @since 0.1.0
	 */
	public function tear_down(): void {
		$this->removeBootRecord();

		parent::tear_down();
	}

	/**
	 * Tests that placing Cart A stays within its baseline, in two transactions.
	 *
	 * @since 0.1.0
	 */
	public function test_placing_cart_a_stays_within_its_baseline(): void {
		$variant = $this->sellable( 5, Inputs::money( ReferenceCarts::CART_A_PRICE, self::CURRENCY )->minorUnits() );

		$this->readyCart( self::quantities( ReferenceCarts::cartA( $variant ) ) );

		$parts = $this->measure( 'Cart A' );

		$this->assertWithin( self::CART_A, $parts, 'Cart A' );
	}

	/**
	 * Tests that placing Cart B, with its two promotion codes, stays within its baseline, in two transactions.
	 *
	 * @since 0.1.0
	 */
	public function test_placing_cart_b_stays_within_its_baseline(): void {
		$this->readyCart( self::quantities( ReferenceCarts::cartB( $this->cartBVariants() ) ), $this->cartBCodes() );

		$parts = $this->measure( 'Cart B' );

		$this->assertWithin( self::CART_B, $parts, 'Cart B' );
	}

	/**
	 * Tests that placing Cart B in a presentment currency costs at most PRESENTMENT_EXTRA statements more than in the base currency.
	 *
	 * @group international
	 *
	 * @since 0.1.0
	 */
	public function test_placing_cart_b_in_a_presentment_currency_costs_at_most_two_statements_more(): void {
		$this->readyCart( self::quantities( ReferenceCarts::cartB( $this->cartBVariants() ) ), $this->cartBCodes() );

		$base = array_sum( $this->measure( 'Cart B, USD' ) );

		$this->enableCurrency( 'EUR' );
		self::ratesOver( $this->db, static function (): void {} )->saveVersion( array( self::rateTo( 'EUR', '0.91230' ) ), Actor::user( 0 ) );
		$this->plantBootRecord( 1 );

		$this->readyCart( self::quantities( ReferenceCarts::cartB( $this->cartBVariants() ) ), $this->cartBCodes() );
		$this->assertSame( 1, $this->db->execute( "UPDATE %i SET currency = 'EUR' WHERE status = 'open'", $this->table( CartTables::CARTS ) ), 'One cart is open.' );

		$presentment = array_sum( $this->measure( 'Cart B, EUR' ) );

		$this->assertLessThanOrEqual( $base + self::PRESENTMENT_EXTRA, $presentment, 'Placing Cart B in EUR, against placing it in USD.' );
	}

	/**
	 * Places the request's cart, approved, and returns its statements by part, after printing them by step.
	 *
	 * @since 0.1.0
	 *
	 * @param string $name The cart's name, for the report.
	 * @return array{before: int, first: int, between: int, second: int} The statements of each part.
	 */
	private function measure( string $name ): array {
		$input = $this->placeInput( 'budget-' . $name );

		// A request starts cold: a container of its own, and no object cache but the options WordPress loads at boot.
		$placement = $this->kernelOver( $this->db, $this->tokens )->get( PlaceOrder::class );
		$answer    = array();

		wp_cache_flush();
		wp_load_alloptions();

		$log = $this->captureQueries(
			function () use ( $placement, $input, &$answer ): void {
				$answer = $placement->place( $input, self::guest() );
			}
		);

		$this->assertSame( 'approved', $answer['outcome'] ?? null, $name );
		$this->assertSame( 2, $log->matching( '/^START TRANSACTION$/' )->count(), $name . ': two transactions.' );

		$parts = array(
			'before'  => 0,
			'first'   => 0,
			'between' => 0,
			'second'  => 0,
		);
		$steps = array();
		$order = array( 'before', 'first', 'between', 'second' );
		$part  = 0;

		foreach ( $log->entries() as $entry ) {
			// Each unit of work begins by asking for its isolation level, and ends with its COMMIT.
			if ( str_starts_with( $entry['sql'], 'SET TRANSACTION' ) ) {
				++$part;
			}

			$key = $order[ min( $part, 3 ) ];

			++$parts[ $key ];

			$step = self::stepOf( $entry );

			$steps[ $key ][ $step ] = ( $steps[ $key ][ $step ] ?? 0 ) + 1;

			if ( 'COMMIT' === $entry['sql'] ) {
				++$part;
			}
		}

		$lines = array( sprintf( "\nPlacing %s: %d statements, %d transactions.", $name, $log->count(), 2 ) );

		foreach ( $order as $key ) {
			$lines[] = sprintf( '  %s: %d', $key, $parts[ $key ] );

			foreach ( $steps[ $key ] ?? array() as $step => $count ) {
				$lines[] = sprintf( '    %-36s %d', $step, $count );
			}
		}

		fwrite( STDOUT, implode( "\n", $lines ) . "\n" );

		return $parts;
	}

	/**
	 * Asserts that each part of a placement stays within its baseline.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, int> $baseline The most statements of each part.
	 * @param array<string, int> $parts    The statements of each part.
	 * @param string             $name     The cart's name.
	 */
	private function assertWithin( array $baseline, array $parts, string $name ): void {
		foreach ( $baseline as $key => $most ) {
			$this->assertLessThanOrEqual( $most, $parts[ $key ], sprintf( '%1$s: the %2$s part of the placement.', $name, $key ) );
		}
	}

	/**
	 * Plants Cart B's ten variants across eight products, each priced and stocked.
	 *
	 * @since 0.1.0
	 *
	 * @return list<int> One variant per slot, in slot order.
	 */
	private function cartBVariants(): array {
		$variants = array();
		$products = array();

		for ( $slot = 0; $slot < ReferenceCarts::CART_B_VARIANTS; $slot++ ) {
			$product    = ReferenceCarts::productOfSlot( $slot );
			$variant    = $this->sellable( 10, Inputs::money( ReferenceCarts::unitPrice( $slot ), self::CURRENCY )->minorUnits(), 'Product ' . $product, $products[ $product ] ?? 0 );
			$variants[] = $variant;

			$products[ $product ] ??= $variant;
		}

		return $variants;
	}

	/**
	 * Plants Cart B's promotions, under codes no other cart of the test used, and returns the codes.
	 *
	 * @since 0.1.0
	 *
	 * @return list<string> The codes, in the order they are applied.
	 */
	private function cartBCodes(): array {
		static $round = 0;

		++$round;

		$codes = array();

		foreach ( ReferenceCarts::CART_B_CODES as $code => $columns ) {
			$codes[] = $code . $round;

			$this->plantPromotion( $code . $round, $columns );
		}

		return $codes;
	}

	/**
	 * Returns the units of lines, by variant.
	 *
	 * @since 0.1.0
	 *
	 * @param CartLine[] $lines The lines.
	 * @return array<int, int> Units by variant id.
	 *
	 * @phpstan-param list<CartLine> $lines
	 */
	private static function quantities( array $lines ): array {
		$quantities = array();

		foreach ( $lines as $line ) {
			$quantities[ $line->variantId ] = $line->quantity;
		}

		return $quantities;
	}

	/**
	 * Returns the step a statement belongs to: a transaction's control, or the first placement step its caller passed through.
	 *
	 * @since 0.1.0
	 *
	 * @param array{sql: string, caller: string} $entry The statement.
	 * @return string The step.
	 */
	private static function stepOf( array $entry ): string {
		if ( 1 === preg_match( '/^(SET TRANSACTION|START TRANSACTION|SAVEPOINT|RELEASE SAVEPOINT|ROLLBACK TO SAVEPOINT|COMMIT)\b/', $entry['sql'] ) ) {
			return 'transaction control';
		}

		$best = 'other';
		$at   = PHP_INT_MAX;

		foreach ( self::STEPS as $step => $marker ) {
			$position = strpos( $entry['caller'], $marker );

			if ( false !== $position && $position < $at ) {
				$best = $step;
				$at   = $position;
			}
		}

		return $best;
	}
}
