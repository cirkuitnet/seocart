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

use SEOCart\Cart\Infrastructure\CartTables;
use SEOCart\Checkout\Application\PlaceOrder;
use SEOCart\Checkout\Application\ReceiveWebhook;
use SEOCart\Contracts\Payment\GatewayResult;
use SEOCart\Contracts\Payment\Operation;
use SEOCart\Contracts\Payment\Outcome;
use SEOCart\Order\Infrastructure\OrderTables;
use SEOCart\Payment\Domain\Webhook\ReceiptResult;
use SEOCart\Payment\Infrastructure\Gateway\StubGateway;
use SEOCart\Payment\Infrastructure\PaymentTables;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Platform\Database\Schema\DdlGenerator;
use SEOCart\Platform\Database\Schema\SchemaVerifier;
use SEOCart\Platform\Database\SchemaOperations;
use SEOCart\Platform\Logging\Migrations\CreateLogsMigration;
use SEOCart\Support\Currency;
use SEOCart\Support\Error\CodedException;
use SEOCart\Support\Money;
use SEOCart\Tests\Support\Checkout\PlacementTestCase;
use SEOCart\Tests\Support\Payment\StubWebhooks;
use SEOCart\Tests\Support\Performance\ReferenceCarts;
use SEOCart\Tests\Support\Pricing\Inputs;
use SEOCart\Tests\Support\Pricing\PricesInCurrencies;

/**
 * A placement of Cart A, of Cart B, and of Cart B in a presentment currency: every statement it sends, attributed to the step that sent it.
 *
 * The numbers are the baseline of a placement's cost, as measured: the statements before the
 * first transaction, in each of the two, and the gateway's read between them. Each is asserted at
 * most the baseline, so a placement that grows fails here and names the step that grew. The step
 * of a statement is the first placement step its caller passed through; a transaction's own
 * control statements are counted apart. The session is read once, before the transaction; a
 * cart's hold rows are one insert, and so are its allocation rows and the outbox rows of one
 * publish.
 *
 * Planted violations: in PlaceOrder::placeInside(), read the session again before storing its
 * quotes: the first unit's count grows by one, and the step is named; and in
 * StockService::allocateInside(), read the items' configuration twice: the second unit's count
 * grows by one, under the allocation; and in PlaceOrder::priced(), price the cart without the
 * session just read: the part before the transaction grows by one, under the calculation.
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
		'2. the order settled as paid'     => 'PaymentService->settleNothingDue',
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
		'before'  => 7,
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
		'before'  => 8,
		'first'   => 44,
		'between' => 1,
		'second'  => 51,
	);

	/**
	 * The most statements each part of a placement of Cart B in a presentment currency may send: its calculation sends one more read before the transaction.
	 *
	 * @since 0.1.0
	 *
	 * @var array{before: int, first: int, between: int, second: int}
	 */
	private const CART_B_PRESENTMENT = array(
		'before'  => 9,
		'first'   => 44,
		'between' => 1,
		'second'  => 51,
	);

	/**
	 * The most statements each part of a placement of Cart A may send when its codes leave nothing to pay: no intent, and no gateway between the two units.
	 *
	 * @since 0.1.0
	 *
	 * @var array{before: int, first: int, between: int, second: int}
	 */
	private const CART_A_NOTHING_DUE = array(
		'before'  => 8,
		'first'   => 33,
		'between' => 0,
		'second'  => 29,
	);

	/**
	 * The statements of each part of a placement of Cart A whose shopper the gateway asks to act: the second unit is the wait, which keeps the next action sealed.
	 *
	 * @since 0.2.0
	 *
	 * @var array{before: int, first: int, between: int, second: int}
	 */
	private const CART_A_ACTING = array(
		'before'  => 7,
		'first'   => 31,
		'between' => 1,
		'second'  => 15,
	);

	/**
	 * The statements a delivered approval costs beyond the second unit of work it runs: the receipt recorded, the intent read, the receipt settled, and the line logged.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	private const WEBHOOK_EXTRA = 4;

	/**
	 * The most statements a placement of Cart B in a presentment currency may send beyond one in the base currency.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private const PRESENTMENT_EXTRA = 2;

	/**
	 * Plants the installation record every store that takes orders has, autoloaded: the placement reads it, for the payment gateways' switches, at no cost.
	 *
	 * @since 0.2.0
	 */
	public function set_up(): void {
		parent::set_up();

		$this->plantBootRecord();
	}

	/**
	 * Restores the boot record the test planted.
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
	 * Tests that placing Cart A with codes that leave nothing to pay, every line's price and the shipping taken off, stays within its baseline, in two transactions with nothing between them.
	 *
	 * @since 0.1.0
	 */
	public function test_placing_cart_a_with_nothing_due_stays_within_its_baseline(): void {
		$variant = $this->sellable( 5, Inputs::money( ReferenceCarts::CART_A_PRICE, self::CURRENCY )->minorUnits() );

		$this->plantPromotion(
			'BUDGET-FULL',
			array(
				'effect_kind'                 => 'percent',
				'effect_percent_micropercent' => 100000000,
			)
		);
		$this->plantPromotion(
			'BUDGET-SHIP',
			array(
				'effect_kind'                 => 'free_shipping',
				'effect_percent_micropercent' => null,
			)
		);
		$this->readyCart( self::quantities( ReferenceCarts::cartA( $variant ) ), array( 'BUDGET-FULL', 'BUDGET-SHIP' ) );

		$parts = $this->measure( 'Cart A, nothing due' );

		$this->assertWithin( self::CART_A_NOTHING_DUE, $parts, 'Cart A, nothing due' );
	}

	/**
	 * Tests that placing Cart A whose shopper the gateway asks to act stays within its baseline, in two transactions, the second the wait.
	 *
	 * @since 0.2.0
	 */
	public function test_placing_cart_a_whose_shopper_must_act_stays_within_its_baseline(): void {
		$variant = $this->sellable( 5, Inputs::money( ReferenceCarts::CART_A_PRICE, self::CURRENCY )->minorUnits() );

		$this->readyCart( self::quantities( ReferenceCarts::cartA( $variant ) ) );

		$parts = $this->measure( 'Cart A, the shopper to act', StubGateway::REQUIRES_ACTION, 'requires_action' );

		$this->assertWithin( self::CART_A_ACTING, $parts, 'Cart A, the shopper to act' );
	}

	/**
	 * Tests that placing Cart B, with its two promotion codes, stays within its baseline, in two transactions.
	 *
	 * @since 0.1.0
	 */
	public function test_placing_cart_b_stays_within_its_baseline(): void {
		$this->readyCartB();

		$parts = $this->measure( 'Cart B' );

		$this->assertWithin( self::CART_B, $parts, 'Cart B' );
	}

	/**
	 * Tests that settling Cart A and Cart B by a delivered approval, for placements the gateway never answered, costs the second unit of work and WEBHOOK_EXTRA statements, exactly.
	 *
	 * @since 0.2.0
	 */
	public function test_settling_by_webhook_costs_the_second_unit_and_the_receipt(): void {
		( new CreateLogsMigration() )->up( new SchemaOperations( $this->db, new DdlGenerator(), new SchemaVerifier( $this->db ) ) );

		$variant = $this->sellable( 5, Inputs::money( ReferenceCarts::CART_A_PRICE, self::CURRENCY )->minorUnits() );

		$this->readyCart( self::quantities( ReferenceCarts::cartA( $variant ) ) );
		$this->assertSame( self::CART_A['second'] + self::WEBHOOK_EXTRA, $this->settledByWebhook( 'Cart A' ), 'Cart A, settled by a delivered approval.' );

		$this->readyCartB();
		$this->assertSame( self::CART_B['second'] + self::WEBHOOK_EXTRA, $this->settledByWebhook( 'Cart B' ), 'Cart B, settled by a delivered approval.' );
	}

	/**
	 * Places the request's cart with a gateway that does not answer, then settles it by the stand-in's delivered approval, in a request of its own, cold, and returns the delivery's statements, after printing them.
	 *
	 * @since 0.2.0
	 *
	 * @param string $name The cart's name.
	 * @return int The delivery's statements.
	 */
	private function settledByWebhook( string $name ): int {
		try {
			$this->placement->place( $this->placeInput( 'webhook-' . $name, StubGateway::THROW ), self::guest() );
		} catch ( CodedException $unanswered ) {
			unset( $unanswered );
		}

		$intent   = (array) $this->db->fetchRow( 'SELECT uuid, amount_minor, currency FROM %i ORDER BY id DESC LIMIT 1', $this->table( PaymentTables::INTENTS ) );
		$uuid     = (string) $intent['uuid'];
		$approval = StubWebhooks::of( new GatewayResult( StubGateway::ID, Operation::Authorize, Outcome::Approved, $uuid, Money::of( (int) $intent['amount_minor'], Currency::of( (string) $intent['currency'] ) ), 'stub-ch-' . $uuid, 'stub-pi-approve-' . $uuid ) );
		$receiver = $this->kernelOver( $this->db, $this->tokens )->get( ReceiveWebhook::class );
		$envelope = $approval->envelope( new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) ) );
		$results  = array();

		wp_cache_flush();
		wp_load_alloptions();

		$log = $this->captureQueries(
			static function () use ( $receiver, $envelope, &$results ): void {
				$results[] = $receiver->receive( $envelope );
			}
		);

		$this->assertSame( array( ReceiptResult::Applied ), $results, $name );
		$this->assertSame( 'processing', $this->db->fetchValue( 'SELECT o.status FROM %i o JOIN %i i ON i.order_id = o.id WHERE i.uuid = %s', $this->table( OrderTables::ORDERS ), $this->table( PaymentTables::INTENTS ), $uuid ), $name );

		fwrite( STDOUT, sprintf( "\nSettling %s by a delivered approval: %d statements.\n  %s\n", $name, $log->count(), implode( "\n  ", array_map( static fn( string $sql ): string => substr( (string) preg_replace( '/\s+/', ' ', $sql ), 0, 110 ), $log->sqls() ) ) ) );

		return $log->count();
	}

	/**
	 * Tests that placing Cart B in a presentment currency stays within its baseline, and costs at most PRESENTMENT_EXTRA statements more than in the base currency.
	 *
	 * @group international
	 *
	 * @since 0.1.0
	 */
	public function test_placing_cart_b_in_a_presentment_currency_costs_at_most_two_statements_more(): void {
		$this->readyCartB();

		$base = array_sum( $this->measure( 'Cart B, USD' ) );

		$this->enableCurrency( 'EUR' );
		self::ratesOver( $this->db, static function (): void {} )->saveVersion( array( self::rateTo( 'EUR', '0.91230' ) ), Actor::user( 0 ) );
		$this->plantBootRecord( 1 );

		$this->readyCartB();
		$this->assertSame( 1, $this->db->execute( "UPDATE %i SET currency = 'EUR' WHERE status = 'open'", $this->table( CartTables::CARTS ) ), 'One cart is open.' );

		$parts = $this->measure( 'Cart B, EUR' );

		$this->assertWithin( self::CART_B_PRESENTMENT, $parts, 'Cart B, EUR' );
		$this->assertLessThanOrEqual( $base + self::PRESENTMENT_EXTRA, array_sum( $parts ), 'Placing Cart B in EUR, against placing it in USD.' );
	}

	/**
	 * Places the request's cart, approved unless another token is given, and returns its statements by part, after printing them by step.
	 *
	 * @since 0.1.0
	 *
	 * @param string $name    The cart's name, for the report.
	 * @param string $token   Optional. The payment token. Default APPROVE.
	 * @param string $outcome Optional. What the placement comes to. Default `approved`.
	 * @return array{before: int, first: int, between: int, second: int} The statements of each part.
	 */
	private function measure( string $name, string $token = self::APPROVE, string $outcome = 'approved' ): array {
		$input = $this->placeInput( 'budget-' . $name, $token );

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

		$this->assertSame( $outcome, $answer['outcome'] ?? null, $name );
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
