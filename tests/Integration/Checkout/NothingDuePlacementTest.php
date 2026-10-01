<?php
/**
 * Tests placing a cart with nothing to pay: no intent, no gateway call, and still two units of work that accept the order as paid
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Checkout;

use SEOCart\Cart\Infrastructure\CartTables;
use SEOCart\Checkout\Application\SettlePlacement;
use SEOCart\Checkout\Infrastructure\CheckoutTables;
use SEOCart\Checkout\Infrastructure\Jobs\ReconcileStalePlacements;
use SEOCart\Inventory\Domain\Event\StockAllocated;
use SEOCart\Inventory\Infrastructure\InventoryTables;
use SEOCart\Order\Domain\Event\OrderCreated;
use SEOCart\Order\Domain\Event\OrderPlaced;
use SEOCart\Order\Infrastructure\OrderTables;
use SEOCart\Order\Interfaces\StoreApi\OrderStatusRead;
use SEOCart\Payment\Application\PaymentError;
use SEOCart\Payment\Application\PaymentService;
use SEOCart\Payment\Application\RefundService;
use SEOCart\Payment\Domain\Event\PaymentAuthorized;
use SEOCart\Payment\Domain\Event\PaymentIntentCreated;
use SEOCart\Payment\Domain\Event\PaymentStatusChanged;
use SEOCart\Payment\Domain\NothingDue;
use SEOCart\Payment\Domain\Refund\RefundLineRequest;
use SEOCart\Payment\Domain\Refund\RefundRequest;
use SEOCart\Payment\Infrastructure\Gateway\StubGateway;
use SEOCart\Payment\Infrastructure\PaymentTables;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Promotion\Infrastructure\PromotionTables;
use SEOCart\Support\Error\CodedException;
use SEOCart\Tests\Support\Checkout\PlacementTestCase;
use SEOCart\Tests\Support\CreatesUsers;
use SEOCart\Tests\Support\Pricing\PricesInCurrencies;
use SEOCart\Tests\Support\RunningProbe;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- The tests plant rows and read them back directly.

/**
 * A cart whose discounts bring its grand total to zero is placed: no intent and no gateway call, and the second unit of work accepts the order as paid.
 *
 * Planted violations, each named on its test.
 *
 * @since 0.1.0
 */
final class NothingDuePlacementTest extends PlacementTestCase {

	use CreatesUsers;
	use PricesInCurrencies;

	/**
	 * How long a killed probe may take to end, in milliseconds.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private const PROBE_END_MS = 30000;

	/**
	 * The promotion that takes every line's whole price off.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, int|string|null>
	 */
	private const FULL_DISCOUNT = array(
		'effect_kind'                 => 'percent',
		'effect_percent_micropercent' => 100000000,
	);

	/**
	 * The promotion that takes the shipping off.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, int|string|null>
	 */
	private const FREE_SHIPPING = array(
		'effect_kind'                 => 'free_shipping',
		'effect_percent_micropercent' => null,
	);

	/**
	 * Deletes the users the test created and puts back the installation record.
	 *
	 * @since 0.1.0
	 */
	public function tear_down(): void {
		$this->deleteCreatedUsers();
		$this->removeBootRecord();

		parent::tear_down();
	}

	/**
	 * Tests that a cart with free shipping and every line's price taken off is placed in two transactions with no intent and no gateway call, and leaves an accepted, paid order with its units allocated, its uses committed and its cart converted.
	 *
	 * Planted violations:
	 * - in PlaceOrder::placeInside(), create the intent whatever the total: the payment path refuses
	 *   an intent for nothing, and the placement rolls back;
	 * - in SettlePlacement::settleNothingDue(), settle without allocate(): the units stay held, and
	 *   the order is accepted with none allocated to it;
	 * - in Projection::status(), leave out the zero total: the order's amounts derive `unpaid`, and
	 *   the payment path refuses to accept it.
	 *
	 * @since 0.1.0
	 */
	public function test_a_cart_with_nothing_due_is_placed_without_a_payment(): void {
		$mug  = $this->sellable( 5, 1999 );
		$cart = $this->readyCart( array( $mug => 1 ), $this->codes( 'A' ) );

		$input  = $this->placeInput();
		$answer = array();
		$log    = $this->captureQueries(
			function () use ( $input, &$answer ): void {
				$answer = $this->placement->place( $input, self::guest() );
			}
		);
		$b      = $this->secondConnection();
		$order  = $this->committedOrder( $b, (string) ( $answer['order_uuid'] ?? '' ) );

		$this->assertSame( 0, $input['grand_total_minor'], 'Nothing is due.' );
		$this->assertSame( array( 'approved', 'processing', 'paid' ), array( $answer['outcome'], $answer['status'], $answer['payment_status'] ) );
		$this->assertNotSame( '', (string) $answer['order_key'] );
		$this->assertSame( 2, $log->matching( '/^START TRANSACTION$/' )->count(), 'Two units of work.' );
		$this->assertSame( array(), $this->gateway->calls, 'The gateway was never called.' );

		$this->assertNotNull( $order );
		$this->assertSame( array( 'processing', 'paid', 0 ), array( $order['status'], $order['payment_status'], $order['has_unreconciled_money'] ) );
		$this->assertSame( array( '0', '0' ), array_values( (array) $b->fetchRow( sprintf( 'SELECT grand_total_minor, due_minor FROM `%s` WHERE id = %d', $this->table( OrderTables::ORDERS ), $order['id'] ) ) ) );
		$this->assertSame( array( 5, 1, 0 ), $this->committedStock( $b, $mug ), 'The unit is allocated.' );
		$this->assertSame( 1, $this->committedCount( $b, InventoryTables::ALLOCATIONS, sprintf( "order_id = %d AND state = 'open'", $order['id'] ) ) );
		$this->assertSame( 0, $this->committedCount( $b, InventoryTables::HOLDS ) );
		$this->assertSame( 0, $this->committedCount( $b, PaymentTables::INTENTS ), 'No intent.' );
		$this->assertSame( 0, $this->committedCount( $b, PaymentTables::TRANSACTIONS ), 'No ledger row: no money moved.' );
		$this->assertSame( 2, $this->committedCount( $b, PromotionTables::USAGE, sprintf( "order_id = %d AND state = 'committed'", $order['id'] ) ), 'Both promotions\' uses are committed.' );
		$this->assertSame( 'converted', $this->committedCart( $b, $cart->id )['status'] ?? null );
		$this->assertSame( 'nothing_due', $b->fetchValue( sprintf( "SELECT reason FROM `%s` WHERE order_id = %d AND machine = 'payment' AND to_status = 'paid'", $this->table( OrderTables::EVENTS ), $order['id'] ) ) );

		$events = array(
			OrderCreated::eventName()         => 1,
			OrderPlaced::eventName()          => 1,
			StockAllocated::eventName()       => 1,
			PaymentStatusChanged::eventName() => 1,
			PaymentIntentCreated::eventName() => 0,
			PaymentAuthorized::eventName()    => 0,
		);

		foreach ( $events as $event => $rows ) {
			$this->assertSame( $rows, $this->committedEventRows( $b, $event ), $event );
		}

		$this->assertEquals( $answer, $this->placement->place( $input, self::guest() ), 'The retry gets the settled placement.' );
		$this->assertSame( 'approved', json_decode( (string) $b->fetchValue( sprintf( 'SELECT response_json FROM `%s` WHERE order_id = %d', $this->table( CheckoutTables::IDEMPOTENCY_KEYS ), $order['id'] ) ), true )['outcome'] ?? null );

		$read = $this->kernel->get( OrderStatusRead::class )->read(
			array(
				'uuid'      => $answer['order_uuid'],
				'order_key' => $answer['order_key'],
			),
			self::guest()
		);

		$this->assertSame( array( 'processing', 'paid', 0 ), array( $read['status'], $read['payment_status'], $read['grand_total_minor'] ), 'The status read shows the order processing, paid, for nothing.' );

		$again = null;
		$quiet = $this->captureQueries(
			function () use ( $order, &$again ): void {
				$again = $this->kernel->get( SettlePlacement::class )->settleNothingDue( $order['id'], Actor::user( 0 ) );
			}
		);

		$this->assertSame( 'duplicate', $again?->outcome->value, 'A second settlement finds the order settled.' );
		$this->assertSame( 0, $quiet->matching( '/^(INSERT|UPDATE|DELETE)/' )->count(), "A second settlement changes nothing:\n" . $quiet->describe() );
		$this->assertSame( array( 5, 1, 0 ), $this->committedStock( $b, $mug ) );

		$line = (string) $b->fetchValue( sprintf( 'SELECT line_uuid FROM `%s` WHERE order_id = %d', $this->table( OrderTables::LINES ), $order['id'] ) );

		try {
			$this->kernel->get( RefundService::class )->refund( new RefundRequest( (string) $answer['order_uuid'], array( new RefundLineRequest( $line, 1 ) ), false, 'customer_return' ), $this->refunder() );
			$this->fail( 'An order nothing was paid for was refunded.' );
		} catch ( CodedException $refused ) {
			$this->assertSame( PaymentError::RefundNotRefundable, $refused->errorCode(), 'Nothing was captured, so nothing can be refunded.' );
		}
	}

	/**
	 * Tests that a cart of several lines, every line's price taken off and its shipping free, is placed so too: each line allocated, each use committed.
	 *
	 * @since 0.1.0
	 */
	public function test_several_lines_with_free_shipping_and_a_full_discount_are_placed(): void {
		$mug  = $this->sellable( 5, 1000, 'Mug' );
		$tee  = $this->sellable( 3, 2500, 'Tee' );
		$cart = $this->readyCart(
			array(
				$mug => 2,
				$tee => 1,
			),
			$this->codes( 'B' )
		);

		$answer = $this->placement->place( $this->placeInput(), self::guest() );
		$b      = $this->secondConnection();

		$this->assertSame( array( 'approved', 'processing', 'paid' ), array( $answer['outcome'], $answer['status'], $answer['payment_status'] ) );
		$this->assertSame( array( 5, 2, 0 ), $this->committedStock( $b, $mug ) );
		$this->assertSame( array( 3, 1, 0 ), $this->committedStock( $b, $tee ) );
		$this->assertSame( 'converted', $this->committedCart( $b, $cart->id )['status'] ?? null );
		$this->assertSame( array(), $this->gateway->calls );
	}

	/**
	 * Tests that a code taking every line's price off leaves the shipping to pay: the order is placed through the gateway, as any other with something due.
	 *
	 * @since 0.1.0
	 */
	public function test_a_full_discount_alone_leaves_the_shipping_to_pay(): void {
		$this->plantPromotion( 'FULL-ONLY', self::FULL_DISCOUNT );
		$this->readyCart( array( $this->sellable( 5, 1999 ) => 1 ), array( 'FULL-ONLY' ) );

		$input  = $this->placeInput();
		$answer = $this->placement->place( $input, self::guest() );

		$this->assertGreaterThan( 0, $input['grand_total_minor'], 'The flat-rate shipping and its tax are due.' );
		$this->assertSame( array( 'approved', 'authorized' ), array( $answer['outcome'], $answer['payment_status'] ) );
		$this->assertSame( array( 'authorize:0' ), array_map( static fn( array $call ): string => $call['method'] . ':' . $call['depth'], $this->gateway->calls ) );
	}

	/**
	 * Tests that a placement with nothing due that stopped between its two units of work is settled by the reconciliation, once it has waited past the threshold, as its own second unit would have; and that a second run changes nothing.
	 *
	 * The placement runs in a process of its own, killed right after its first unit committed.
	 *
	 * Planted violation: in ReconcileStalePlacements::handle(), leave out settleNothingDue(): the
	 * order waits for ever, its unit held, its uses reserved and its cart placing.
	 *
	 * @group concurrency
	 *
	 * @since 0.1.0
	 */
	public function test_a_placement_stopped_between_its_units_is_settled_by_the_reconciliation(): void {
		$mug    = $this->sellable();
		$cart   = $this->readyCart( array( $mug => 1 ), $this->codes( 'C' ) );
		$token  = $this->tokens->presented;
		$b      = $this->secondConnection();
		$orders = $this->table( OrderTables::ORDERS );
		$job    = $this->kernel->get( ReconcileStalePlacements::class );

		$this->assertNotNull( $token );

		$stopped = $this->startPlacement( $token, $this->placeInput(), 1 );

		$this->awaitEnd( $stopped );
		$this->assertSame( '', $stopped->reportSoFar(), "The placement answered: it was not stopped.\n" . $stopped->output() );

		$orderUuid = (string) $b->fetchValue( sprintf( 'SELECT uuid FROM `%s`', $orders ) );

		$this->assertSame( array( 'pending_payment', 'unpaid' ), array_values( array_intersect_key( $this->committedOrder( $b, $orderUuid ) ?? array(), array_flip( array( 'status', 'payment_status' ) ) ) ), 'Only the first unit committed.' );
		$this->assertSame( array( 5, 0, 1 ), $this->committedStock( $b, $mug ) );
		$this->assertSame( 'placing', $this->committedCart( $b, $cart->id )['status'] ?? null );

		$job->handle( array() );

		$waiting = $this->committedOrder( $b, $orderUuid );

		$this->assertSame( 'pending_payment', $waiting['status'] ?? null, 'A placement a minute old is not settled: its second unit may still be on its way.' );

		$this->db->execute( 'UPDATE %i SET created_at = UTC_TIMESTAMP(6) - INTERVAL 11 MINUTE WHERE uuid = %s', $orders, $orderUuid );
		$job->handle( array() );

		$order = $this->committedOrder( $b, $orderUuid );

		$this->assertNotNull( $order );
		$this->assertSame( array( 'processing', 'paid' ), array( $order['status'], $order['payment_status'] ) );
		$this->assertSame( array( 5, 1, 0 ), $this->committedStock( $b, $mug ) );
		$this->assertSame( 2, $this->committedCount( $b, PromotionTables::USAGE, sprintf( "order_id = %d AND state = 'committed'", $order['id'] ) ) );
		$this->assertSame( 'converted', $this->committedCart( $b, $cart->id )['status'] ?? null );
		$this->assertSame( 'approved', json_decode( (string) $b->fetchValue( sprintf( 'SELECT response_json FROM `%s` WHERE order_id = %d', $this->table( CheckoutTables::IDEMPOTENCY_KEYS ), $order['id'] ) ), true )['outcome'] ?? null, 'A retry of the placement is told it was accepted.' );
		$this->assertSame( array(), $this->reports, 'Nothing was deferred.' );

		$log = $this->captureQueries( static fn() => $job->handle( array() ) );

		$this->assertSame( 0, $log->matching( '/^(INSERT|UPDATE|DELETE)/' )->count(), "A second run wrote:\n" . $log->describe() );
	}

	/**
	 * Tests that the reconciliation leaves an order with something due alone, however long ago it was placed: only an order with nothing to pay is settled without the gateway.
	 *
	 * The placement's gateway call threw, so the order waits pending payment with its unit held. Only
	 * the order is made old: its intent changed a moment ago, so the intents' reconciliation does
	 * not ask about it.
	 *
	 * Planted violation: in MysqlOrderRepository::NOTHING_DUE_IN_STATUS, leave out
	 * `grand_total_minor = 0`: the run takes the order for one with nothing due.
	 *
	 * @since 0.1.0
	 */
	public function test_an_old_order_with_something_due_is_not_settled_by_the_reconciliation(): void {
		$mug  = $this->sellable();
		$cart = $this->readyCart( array( $mug => 1 ) );
		$b    = $this->secondConnection();
		$uuid = '';

		try {
			$this->placement->place( $this->placeInput( 'unheard', StubGateway::THROW ), self::guest() );
			$this->fail( 'The gateway was reached.' );
		} catch ( CodedException $unavailable ) {
			$uuid = (string) ( $unavailable->details()['order_uuid'] ?? '' );
		}

		$this->assertNotSame( '', $uuid );
		$this->db->execute( 'UPDATE %i SET created_at = UTC_TIMESTAMP(6) - INTERVAL 11 MINUTE WHERE uuid = %s', $this->table( OrderTables::ORDERS ), $uuid );

		$this->assertNull( $this->kernel->get( ReconcileStalePlacements::class )->handle( array() ) );

		$order = $this->committedOrder( $b, $uuid );

		$this->assertNotNull( $order );
		$this->assertSame( array( 'pending_payment', 'unpaid' ), array( $order['status'], $order['payment_status'] ) );
		$this->assertSame( array( 5, 0, 1 ), $this->committedStock( $b, $mug ), 'The unit is still held, not allocated.' );
		$this->assertSame( 0, $this->committedCount( $b, InventoryTables::ALLOCATIONS ), 'Nothing is allocated.' );
		$this->assertSame( 'placing', $this->committedCart( $b, $cart->id )['status'] ?? null );
		$this->assertSame( array(), $this->reports, 'Nothing was deferred.' );
	}

	/**
	 * Tests that the payment path settles only an order with nothing to pay: one with something due is refused, and nothing changes.
	 *
	 * @since 0.1.0
	 */
	public function test_an_order_with_something_due_is_not_settled_as_paid(): void {
		$this->readyCart( array( $this->sellable() => 1 ) );

		$answer   = $this->placement->place( $this->placeInput( 'attempt-1', StubGateway::REQUIRES_ACTION ), self::guest() );
		$b        = $this->secondConnection();
		$order    = $this->committedOrder( $b, (string) $answer['order_uuid'] );
		$payments = $this->kernel->get( PaymentService::class );

		$this->assertNotNull( $order );

		try {
			$this->db->transaction( static fn(): NothingDue => $payments->settleNothingDue( $order['id'], Actor::user( 0 ) ) );
			$this->fail( 'An order with something to pay was settled as paid.' );
		} catch ( \LogicException $refused ) {
			$this->assertStringContainsString( 'only an order with nothing to pay', $refused->getMessage() );
		}

		$this->assertSame( array( 'pending_payment', 'pending' ), array( $this->committedOrder( $b, (string) $answer['order_uuid'] )['status'] ?? null, $this->committedOrder( $b, (string) $answer['order_uuid'] )['payment_status'] ?? null ) );
	}

	/**
	 * Tests that an order whose amount is positive but whose base equivalent rounds to zero is placed and paid through the gateway: a small order in a currency worth far less than the base one.
	 *
	 * One yen at 250 to the dollar is 0.4 cents, which rounds to none; the line's tax rounds to none
	 * too, and the shipping is free.
	 *
	 * Planted violation: in PaymentService::createIntent(), refuse a base amount of zero again: the
	 * placement rolls back.
	 *
	 * @group international
	 *
	 * @since 0.1.0
	 */
	public function test_an_order_whose_base_amount_rounds_to_zero_is_placed(): void {
		$this->enableCurrency( 'JPY' );
		self::ratesOver( $this->db, static function (): void {} )->saveVersion( array( self::rateTo( 'JPY', '250' ) ), Actor::user( 0 ) );
		$this->plantBootRecord( 1 );

		$sticker = $this->sellable( 5, 100, 'Sticker' );

		$this->db->execute( "INSERT INTO %i ( variant_id, currency, amount_basis, price_minor, created_at, updated_at ) VALUES ( %d, 'JPY', 'net', 1, UTC_TIMESTAMP(), UTC_TIMESTAMP() )", $this->table( 'variant_prices' ), $sticker );
		$this->plantPromotion( 'SHIPFREE', self::FREE_SHIPPING );
		$this->readyCart( array( $sticker => 1 ), array( 'SHIPFREE' ) );
		$this->assertSame( 1, $this->db->execute( "UPDATE %i SET currency = 'JPY' WHERE status = 'open'", $this->table( CartTables::CARTS ) ), 'One cart is open.' );

		$input  = $this->placeInput();
		$answer = $this->placement->place( $input, self::guest() );
		$b      = $this->secondConnection();
		$order  = $this->committedOrder( $b, (string) $answer['order_uuid'] );

		$this->assertSame( array( 1, 'JPY' ), array( $input['grand_total_minor'], $input['currency'] ) );
		$this->assertSame( array( 'approved', 'processing', 'authorized' ), array( $answer['outcome'], $answer['status'], $answer['payment_status'] ) );
		$this->assertNotNull( $order );
		$this->assertSame( '0', $b->fetchValue( sprintf( 'SELECT base_grand_total_minor FROM `%s` WHERE id = %d', $this->table( OrderTables::ORDERS ), $order['id'] ) ), 'The base equivalent rounds to zero.' );
		$this->assertSame( array( '1', 'JPY', '0', '1' ), array_values( (array) $b->fetchRow( sprintf( 'SELECT amount_minor, currency, base_amount_minor, authorized_minor FROM `%s` WHERE order_id = %d', $this->table( PaymentTables::INTENTS ), $order['id'] ) ) ) );
	}

	/**
	 * Plants a promotion that takes every line's price off and one that takes the shipping off, under codes of a round, and returns the codes.
	 *
	 * @since 0.1.0
	 *
	 * @param string $round What sets the codes apart from another test's.
	 * @return list<string> The codes.
	 */
	private function codes( string $round ): array {
		$this->plantPromotion( 'FULL-' . $round, self::FULL_DISCOUNT );
		$this->plantPromotion( 'SHIP-' . $round, self::FREE_SHIPPING );

		return array( 'FULL-' . $round, 'SHIP-' . $round );
	}

	/**
	 * Returns an actor who may refund orders.
	 *
	 * @since 0.1.0
	 *
	 * @return Actor The actor.
	 */
	private function refunder(): Actor {
		$user = $this->createUser( 'subscriber' );

		add_filter(
			'user_has_cap',
			static function ( $caps, $cap, $args ) use ( $user ) {
				if ( (int) ( $args[1] ?? 0 ) === $user ) {
					$caps[ RefundService::CAPABILITY ] = true;
				}

				return $caps;
			},
			10,
			3
		);

		return Actor::user( $user );
	}

	/**
	 * Waits for a probe to end, watching its output, never pausing; fails the test at the deadline.
	 *
	 * @since 0.1.0
	 *
	 * @param RunningProbe $probe The probe.
	 */
	private function awaitEnd( RunningProbe $probe ): void {
		$deadline = hrtime( true ) + self::PROBE_END_MS * 1000000;

		while ( ! $probe->watch( 50 ) ) {
			if ( hrtime( true ) >= $deadline ) {
				$this->fail( "The probe did not end.\n" . $probe->output() );
			}
		}
	}
}
