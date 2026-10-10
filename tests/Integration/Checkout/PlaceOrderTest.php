<?php
/**
 * Tests placing an order: the two units of work, what each outcome leaves, and every refusal before them
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Checkout;

use SEOCart\Cart\Application\CartError;
use SEOCart\Cart\Application\StoreApiError;
use SEOCart\Cart\Domain\CartToken;
use SEOCart\Cart\Infrastructure\CartTables;
use SEOCart\Checkout\Application\KeptAnswer;
use SEOCart\Checkout\Application\PlaceOrder;
use SEOCart\Checkout\Application\SettlePlacement;
use SEOCart\Checkout\Domain\CheckoutError;
use SEOCart\Checkout\Infrastructure\CheckoutTables;
use SEOCart\Contracts\Payment\GatewayResult;
use SEOCart\Contracts\Payment\PaymentGateway;
use SEOCart\Contracts\Payment\PaymentQuery;
use SEOCart\Inventory\Domain\Event\StockAllocated;
use SEOCart\Inventory\Domain\Event\StockReservationReleased;
use SEOCart\Inventory\Infrastructure\InventoryTables;
use SEOCart\Order\Domain\AccessKeys;
use SEOCart\Order\Domain\Event\OrderCreated;
use SEOCart\Order\Domain\Event\OrderPlaced;
use SEOCart\Order\Domain\Event\OrderStatusChanged;
use SEOCart\Order\Infrastructure\OrderTables;
use SEOCart\Order\Interfaces\StoreApi\OrderStatusRead;
use SEOCart\Payment\Application\PaymentError;
use SEOCart\Payment\Application\PaymentService;
use SEOCart\Payment\Domain\Event\PaymentAuthorized;
use SEOCart\Payment\Domain\Event\PaymentFailed;
use SEOCart\Payment\Domain\Event\PaymentIntentCreated;
use SEOCart\Payment\Infrastructure\Gateway\StubGateway;
use SEOCart\Payment\Infrastructure\PaymentTables;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Platform\Events\EventEnvelope;
use SEOCart\Platform\Kernel\Container;
use SEOCart\Platform\RateLimiter\RateCountersTable;
use SEOCart\Promotion\Infrastructure\PromotionTables;
use SEOCart\Support\Error\CodedException;
use SEOCart\Support\Error\ErrorCode;
use SEOCart\Support\Money;
use SEOCart\Tests\Support\Checkout\PlacementKernel;
use SEOCart\Tests\Support\Checkout\PlacementTestCase;
use SEOCart\Tests\Support\CreatesUsers;
use SEOCart\Tests\Support\Doubles\WebhookFirstGateway;
use SEOCart\Tests\Support\GrantsCapabilities;
use SEOCart\Tests\Support\SecondConnection;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- The tests plant rows and read them back directly.

/**
 * A placement goes through in two units of work, with the gateway between them, and leaves exactly what its outcome says.
 *
 * Planted violations, each named on its test.
 *
 * @since 0.1.0
 */
final class PlaceOrderTest extends PlacementTestCase {

	use CreatesUsers;
	use GrantsCapabilities;

	/**
	 * The stock releases delivered after commit, in order.
	 *
	 * @since 0.1.0
	 *
	 * @var list<StockReservationReleased>
	 */
	private array $released = array();

	/**
	 * Listens for the releases.
	 *
	 * @since 0.1.0
	 */
	public function set_up(): void {
		parent::set_up();

		$this->released = array();

		add_action(
			EventEnvelope::hookFor( StockReservationReleased::eventName() ),
			function ( StockReservationReleased $event ): void {
				$this->released[] = $event;
			}
		);
	}

	/**
	 * Deletes the users the test created.
	 *
	 * @since 0.1.0
	 */
	public function tear_down(): void {
		$this->deleteCreatedUsers();

		parent::tear_down();
	}

	/**
	 * Tests that an approved placement leaves an accepted order, its units allocated, its intent authorized, its cart converted and its key placed.
	 *
	 * @since 0.1.0
	 */
	public function test_an_approved_placement_accepts_the_order_and_converts_the_cart(): void {
		$mug  = $this->sellable( 5, 1000, 'Mug' );
		$tee  = $this->sellable( 3, 2500, 'Tee' );
		$cart = $this->readyCart(
			array(
				$mug => 2,
				$tee => 1,
			)
		);

		$answer = $this->placement->place( $this->placeInput(), self::guest() );
		$b      = $this->secondConnection();

		$this->assertSame( array( 'order_uuid', 'order_number', 'order_key', 'cart_version', 'outcome', 'status', 'payment_status', 'next_action' ), array_keys( $answer ) );
		$this->assertSame( array( 'approved', 'processing', 'authorized', 3 ), array( $answer['outcome'], $answer['status'], $answer['payment_status'], $answer['cart_version'] ) );

		$order = $this->committedOrder( $b, $answer['order_uuid'] );

		$this->assertNotNull( $order );
		$this->assertSame( array( 'processing', 'authorized', 0 ), array( $order['status'], $order['payment_status'], $order['has_unreconciled_money'] ) );
		$this->assertSame( array( 5, 2, 0 ), $this->committedStock( $b, $mug ) );
		$this->assertSame( array( 3, 1, 0 ), $this->committedStock( $b, $tee ) );
		$this->assertSame( 0, $this->committedCount( $b, InventoryTables::HOLDS ) );
		$this->assertSame( 2, $this->committedCount( $b, InventoryTables::ALLOCATIONS, sprintf( "order_id = %d AND state = 'open'", $order['id'] ) ) );
		$this->assertSame( 1, $this->committedCount( $b, PaymentTables::INTENTS, "status = 'authorized'" ) );
		$this->assertSame( 1, $this->committedCount( $b, CheckoutTables::IDEMPOTENCY_KEYS, sprintf( "state = 'placed' AND order_id = %d", $order['id'] ) ) );
		$this->assertSame( 'converted', $this->committedCart( $b, $cart->id )['status'] ?? null );
		$this->assertSame( 'Mug', $b->fetchValue( sprintf( 'SELECT title_snapshot FROM `%s` WHERE order_id = %d AND variant_id = %d', $this->table( OrderTables::LINES ), $order['id'], $mug ) ) );
		$this->assertSame( 'SKU-' . $tee, $b->fetchValue( sprintf( 'SELECT sku_snapshot FROM `%s` WHERE order_id = %d AND variant_id = %d', $this->table( OrderTables::LINES ), $order['id'], $tee ) ) );
		$this->assertSame( 'flat', json_decode( (string) $b->fetchValue( sprintf( 'SELECT shipping_quote_json FROM `%s` WHERE cart_id = %d', $this->table( CheckoutTables::SESSIONS ), $cart->id ) ), true )['method_key'] ?? null, 'The session keeps the rate the order was priced with.' );

		foreach ( array( OrderCreated::eventName(), PaymentIntentCreated::eventName(), PaymentAuthorized::eventName(), OrderPlaced::eventName(), StockAllocated::eventName() ) as $event ) {
			$this->assertSame( 1, $this->committedEventRows( $b, $event ), $event );
		}

		$this->assertSame( array( 'authorize:0' ), $this->gatewayCalls(), 'The gateway was called once, outside any transaction.' );
		$this->assertSame( 'processing', $this->statusRead( $answer )['status'], 'The order\'s status reads with the access key the answer gave.' );
	}

	/**
	 * Tests the refusals made before anything is written: no key, a key longer than 64 bytes, an empty cart, an incomplete checkout, a stale version, totals that changed, and a line that cannot be sold.
	 *
	 * Each leaves the store as it was: no order, no key, no hold, and the cart open at its version.
	 *
	 * Planted violations, each shown red and removed: in PlaceOrder::priced(), compare only the grand
	 * total, not the currency: a placement for the same number in another currency then goes through;
	 * in PlaceOrder::place(), drop the check of the key's bytes: a key longer than 64 bytes reaches
	 * the hash, which throws.
	 *
	 * @since 0.1.0
	 */
	public function test_a_placement_that_cannot_be_made_is_refused_before_anything_is_written(): void {
		$mug   = $this->sellable();
		$cart  = $this->readyCart( array( $mug => 1 ) );
		$input = $this->placeInput();

		$cases = array(
			'no key'              => array( CheckoutError::IdempotencyKeyMissing, array( 'idempotency_key' => '' ) ),
			'a 65-byte key'       => array( CheckoutError::IdempotencyKeyMissing, array( 'idempotency_key' => str_repeat( 'k', 65 ) ) ),
			'a 128-byte key'      => array( CheckoutError::IdempotencyKeyMissing, array( 'idempotency_key' => str_repeat( "\u{00e9}", 64 ) ) ),
			'a stale version'     => array( CartError::VersionStale, array( 'cart_version' => $cart->version - 1 ) ),
			'another grand total' => array( CheckoutError::TotalsChanged, array( 'grand_total_minor' => $input['grand_total_minor'] + 1 ) ),
			'another currency'    => array( CheckoutError::TotalsChanged, array( 'currency' => 'EUR' ) ),
		);

		foreach ( $cases as $case => list( $code, $change ) ) {
			$refused = $this->assertRefused( $code, fn() => $this->placement->place( array_replace( $input, $change ), self::guest() ), $case );

			if ( CartError::VersionStale === $code ) {
				$this->assertSame( array( 'current_version' => $cart->version ), $refused->context() );
				$this->assertArrayHasKey( 'totals', $refused->details() );
			}

			if ( CheckoutError::TotalsChanged === $code ) {
				$this->assertSame( $cart->version, $refused->details()['version'] ?? null, $case );
				$this->assertSame( $input['grand_total_minor'], $refused->details()['totals']['summary']['grand_minor'] ?? null, $case );
			}
		}

		$this->assertNothingPlaced( $cart->id, $cart->version );
	}

	/**
	 * Tests that a cart emptied by its last line set to zero, and a cart whose checkout lacks a detail an order needs, are refused.
	 *
	 * @since 0.1.0
	 */
	public function test_an_empty_cart_and_an_incomplete_checkout_are_refused(): void {
		$mug  = $this->sellable();
		$cart = $this->readyCart( array( $mug => 1 ) );

		$this->service->changeQuantity( $cart->lines[0]->identity, 0, $cart->version, self::guest() );

		$this->assertRefused( CheckoutError::CartEmpty, fn() => $this->placement->place( $this->placeInput(), self::guest() ) );

		$bare = $this->startCart( array( $this->sellable() => 1 ) );

		$refused = $this->assertRefused( CheckoutError::SessionIncomplete, fn() => $this->placement->place( $this->placeInput(), self::guest() ) );

		$this->assertSame( array( 'missing' => array( 'billing_address', 'shipping_address', 'payment_method_key' ) ), $refused->details() );

		$this->checkout->update(
			array(
				'cart_version'       => $bare->version,
				'billing_address'    => array( 'country' => 'US' ),
				'shipping_address'   => array( 'country' => 'US' ),
				'payment_method_key' => StubGateway::ID,
			),
			self::guest()
		);

		$refused = $this->assertRefused( CheckoutError::SessionIncomplete, fn() => $this->placement->place( $this->placeInput(), self::guest() ) );

		$this->assertSame( array( 'missing' => array( 'billing_address.email' ) ), $refused->details(), 'An order needs an e-mail address to send its messages to.' );
	}

	/**
	 * Tests that a line whose product is not published, or that has no price in the cart's currency, is refused, and nothing is held.
	 *
	 * Planted violation: in PlaceOrder::saleOf(), skip the verdict: the order then sells a product
	 * that is a draft.
	 *
	 * @since 0.1.0
	 */
	public function test_a_line_that_cannot_be_sold_is_refused(): void {
		global $wpdb;

		$draft = $this->sellable( 5, 1000, 'Draft' );
		$cart  = $this->readyCart( array( $draft => 1 ) );

		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->posts} SET post_status = 'draft' WHERE post_name = %s", 'placement-' . $draft ) );

		$refused = $this->assertRefused( CheckoutError::LineUnsellable, fn() => $this->placement->place( $this->placeInput(), self::guest() ) );

		$this->assertSame(
			array(
				'variant_id' => $draft,
				'reason'     => 'not_published',
			),
			$refused->context()
		);
		$this->assertNothingPlaced( $cart->id, $cart->version );
	}

	/**
	 * Tests that a placement on a cart that is placing an order is refused with the order's uuid and status in the refusal's details.
	 *
	 * @since 0.1.0
	 */
	public function test_a_cart_placing_an_order_refuses_another_naming_the_order(): void {
		$this->readyCart( array( $this->sellable() => 1 ) );

		$first = $this->placement->place( $this->placeInput( 'attempt-1', StubGateway::REQUIRES_ACTION ), self::guest() );

		$this->assertSame( 'requires_action', $first['outcome'] );

		$refused = $this->assertRefused( CartError::NotOpen, fn() => $this->placement->place( array_replace( $this->placeInput( 'attempt-2' ), array( 'cart_version' => $first['cart_version'] ) ), self::guest() ) );

		$this->assertSame( array( 'status' => 'placing' ), $refused->context() );
		$this->assertSame(
			array(
				'order_uuid'   => $first['order_uuid'],
				'order_status' => 'pending_payment',
			),
			$refused->details()
		);
	}

	/**
	 * Tests that the same request sent twice places one order, and the second is answered with the placement as it stands: before its payment is known, the answer unit of work 1 kept, with the order key it lost; once settled, the first answer, the order key included.
	 *
	 * Planted violations:
	 * - in PlaceOrder::placeInside(), skip $this->keys->complete(): the second request then finds
	 *   the key claimed and is told `checkout.placement_in_progress`;
	 * - in PlaceOrder::fingerprint(), hash only the cart's version: a request with another payment
	 *   token and the same key then gets the first answer instead of `checkout.idempotency_key_reused`;
	 * - in SettlePlacement::settle(), skip $this->keys->settleAnswer(): the retry after the
	 *   approval is then told `pending`.
	 *
	 * @since 0.1.0
	 */
	public function test_a_double_submit_returns_the_placement_as_it_stands_and_places_one_order(): void {
		$b = $this->secondConnection();

		$this->readyCart( array( $this->sellable() => 1 ) );

		$unknown = $this->placeInput( 'unknown', StubGateway::THROW );

		$this->assertRefused( CheckoutError::GatewayUnavailable, fn() => $this->placement->place( $unknown, self::guest() ) );

		$kept     = (array) json_decode( (string) $b->fetchValue( sprintf( 'SELECT response_json FROM `%s`', $this->table( CheckoutTables::IDEMPOTENCY_KEYS ) ) ), true );
		$replayed = $this->placement->place( $unknown, self::guest() );
		$hash     = (string) $b->fetchValue( sprintf( "SELECT access_key_hash FROM `%s` WHERE uuid = '%s'", $this->table( OrderTables::ORDERS ), (string) ( $kept['order_uuid'] ?? '' ) ) );

		$this->assertSame( 'pending', $kept['outcome'] ?? null );
		$this->assertSame(
			array_diff_key(
				$kept,
				array(
					KeptAnswer::SEALED             => true,
					KeptAnswer::NEXT_ACTION_SEALED => true,
				)
			),
			array_diff_key(
				$replayed,
				array(
					KeptAnswer::ORDER_KEY   => true,
					KeptAnswer::NEXT_ACTION => true,
				)
			),
			'Before its payment is known, the retry gets the answer unit of work 1 kept.'
		);
		$this->assertSame( array( true, null, true, null ), array( array_key_exists( KeptAnswer::NEXT_ACTION_SEALED, $kept ), $kept[ KeptAnswer::NEXT_ACTION_SEALED ], array_key_exists( KeptAnswer::NEXT_ACTION, $replayed ), $replayed[ KeptAnswer::NEXT_ACTION ] ), 'Nothing for the shopper to do yet, kept and answered as none.' );
		$this->assertTrue( $this->kernel->get( AccessKeys::class )->verify( (string) ( $replayed['order_key'] ?? '' ), $hash ), 'The retry gets the order key the refused request never received.' );

		$mug  = $this->sellable();
		$cart = $this->readyCart( array( $mug => 1 ) );

		$input  = $this->placeInput();
		$first  = $this->placement->place( $input, self::guest() );
		$stored = json_decode( (string) $b->fetchValue( sprintf( 'SELECT response_json FROM `%s` ORDER BY id DESC LIMIT 1', $this->table( CheckoutTables::IDEMPOTENCY_KEYS ) ) ), true );
		$again  = array();
		$log    = $this->captureQueries(
			function () use ( $input, &$again ): void {
				$again = $this->placement->place( $input, self::guest() );
			}
		);

		$this->assertSame( 'approved', $first['outcome'] );
		$this->assertEquals(
			array_diff_key(
				$first,
				array(
					KeptAnswer::ORDER_KEY   => true,
					KeptAnswer::NEXT_ACTION => true,
				)
			),
			array_diff_key(
				$stored,
				array(
					KeptAnswer::SEALED             => true,
					KeptAnswer::NEXT_ACTION_SEALED => true,
				)
			),
			'The key keeps the settled placement.'
		);
		$this->assertEquals( $first, $again, 'The retry gets the placement as it stands: the first answer, its order key included.' );
		$this->assertSame( 2, $this->committedCount( $b, OrderTables::ORDERS ) );
		$this->assertSame( 2, $this->committedCount( $b, CheckoutTables::IDEMPOTENCY_KEYS ) );
		$this->assertSame(
			array(
				'version' => $cart->version + 1,
				'status'  => 'converted',
			),
			array_intersect_key( $this->committedCart( $b, $cart->id ) ?? array(), array_flip( array( 'version', 'status' ) ) ),
			'The cart moved on once.'
		);
		$this->assertSame( 1, $this->committedCount( $b, InventoryTables::ALLOCATIONS ) );
		$this->assertSame( 0, $log->matching( '/^(INSERT|UPDATE|DELETE|START TRANSACTION)/' )->count(), 'The replay wrote nothing.' );
		$this->assertSame( array( 'authorize:0', 'authorize:0' ), $this->gatewayCalls(), 'The replays did not call the gateway.' );

		$this->assertRefused( CheckoutError::IdempotencyKeyReused, fn() => $this->placement->place( array_replace( $input, array( 'payment_data' => array( 'payment_token' => StubGateway::DECLINE ) ) ), self::guest() ) );
	}

	/**
	 * Tests that a declined payment releases every reservation: the hold, the promotion's use and the intent; the order fails with its number, the cart opens again naming it, a retry of the request is told it was declined, and a new attempt places.
	 *
	 * Planted violations:
	 * - in SettlePlacement::settle(), skip the release of the declined order's hold: `held` stays 1;
	 * - in SettlePlacement::close(), skip settleOrder(): the cart stays placing, and the next
	 *   attempt is refused with `cart.not_open`.
	 *
	 * @since 0.1.0
	 */
	public function test_a_decline_releases_every_reservation_and_opens_the_cart_again(): void {
		$mug       = $this->sellable( 1, 1000 );
		$promotion = $this->plantPromotion( 'SAVE10' );
		$cart      = $this->readyCart( array( $mug => 1 ), array( 'SAVE10' ) );

		$declined = $this->placeInput( 'attempt-1', StubGateway::DECLINE );
		$refused  = $this->assertRefused( CheckoutError::PaymentDeclined, fn() => $this->placement->place( $declined, self::guest() ) );
		$uuid     = (string) ( $refused->details()['order_uuid'] ?? '' );
		$b        = $this->secondConnection();
		$order    = $this->committedOrder( $b, $uuid );

		$this->assertNotNull( $order, 'The refusal names the order.' );
		$this->assertSame( array( 'failed', 'failed' ), array( $order['status'], $order['payment_status'] ) );
		$this->assertSame( array( 1, 0, 0 ), $this->committedStock( $b, $mug ), 'The hold is released.' );
		$this->assertSame( 0, $this->committedCount( $b, InventoryTables::HOLDS ) );
		$this->assertSame( 'payment_declined', $this->released[0]->reason ?? null );
		$this->assertSame( '0', $b->fetchValue( sprintf( 'SELECT used FROM `%s` WHERE id = %d', $this->table( PromotionTables::PROMOTIONS ), $promotion ) ), 'The promotion\'s use is given back.' );
		$this->assertSame( 'released', $b->fetchValue( sprintf( 'SELECT state FROM `%s` WHERE order_id = %d', $this->table( PromotionTables::USAGE ), $order['id'] ) ) );
		$this->assertSame( 'failed', $b->fetchValue( sprintf( 'SELECT status FROM `%s` WHERE order_id = %d', $this->table( PaymentTables::INTENTS ), $order['id'] ) ) );
		$this->assertNotSame( '', (string) $b->fetchValue( sprintf( 'SELECT order_number FROM `%s` WHERE id = %d', $this->table( OrderTables::ORDERS ), $order['id'] ) ), 'The failed order keeps its number.' );
		$this->assertSame( array( 'open', $order['id'] ), array( $this->committedCart( $b, $cart->id )['status'] ?? null, $this->committedCart( $b, $cart->id )['order_id'] ?? null ) );
		$this->assertSame( 1, $this->committedEventRows( $b, PaymentFailed::eventName() ) );
		$this->assertSame( 1, $this->committedEventRows( $b, OrderStatusChanged::eventName() ), 'The order went from pending payment to failed.' );

		$replayed = $this->placement->place( $declined, self::guest() );

		$this->assertSame(
			array( $uuid, 'declined', 'failed', 'failed' ),
			array( $replayed['order_uuid'], $replayed['outcome'], $replayed['status'], $replayed['payment_status'] ),
			'A retry of the declined request is answered with the placement as it stands: declined, not refused again.'
		);

		$again = $this->placement->place( $this->placeInput( 'attempt-2' ), self::guest() );

		$this->assertSame( 'approved', $again['outcome'], 'A new attempt from the same cart places.' );
	}

	/**
	 * Tests that an approval of another amount, or in another currency, parks the order on hold and flagged, allocates its units, and is never captured.
	 *
	 * Planted violation: in SettlePlacement::settle(), skip allocate() for a mismatch: the order
	 * on hold then keeps no units while a person looks.
	 *
	 * @since 0.1.0
	 */
	public function test_an_amount_or_currency_mismatch_parks_the_order_with_no_capture(): void {
		$capturer = $this->capturer();

		foreach ( array( StubGateway::WRONG_AMOUNT, StubGateway::WRONG_CURRENCY ) as $token ) {
			$mug = $this->sellable( 2, 1000 );

			$this->readyCart( array( $mug => 1 ) );

			$answer = $this->placement->place( $this->placeInput( 'attempt-' . $token, $token ), self::guest() );
			$b      = $this->secondConnection();
			$order  = $this->committedOrder( $b, $answer['order_uuid'] );

			$this->assertNotNull( $order );
			$this->assertSame( array( 'amount_mismatch', 'on_hold' ), array( $answer['outcome'], $answer['status'] ), $token );
			$this->assertSame( array( 'on_hold', 1 ), array( $order['status'], $order['has_unreconciled_money'] ), $token );
			$this->assertSame( 'amount_mismatch', $b->fetchValue( sprintf( "SELECT reason FROM `%s` WHERE order_id = %d AND to_status = 'on_hold'", $this->table( OrderTables::EVENTS ), $order['id'] ) ), $token );
			$this->assertSame( array( 2, 1, 0 ), $this->committedStock( $b, $mug ), 'The units are allocated while a person looks: ' . $token );
			$this->assertSame( '0', $b->fetchValue( sprintf( 'SELECT captured_minor FROM `%s` WHERE order_id = %d', $this->table( PaymentTables::INTENTS ), $order['id'] ) ) );

			$intent = (string) $b->fetchValue( sprintf( 'SELECT uuid FROM `%s` WHERE order_id = %d', $this->table( PaymentTables::INTENTS ), $order['id'] ) );

			// The intent of a mismatched authorization stays created, and its unapplied ledger row is kept: no capture can start.
			$this->assertSame( '0', $b->fetchValue( sprintf( 'SELECT applied FROM `%s` WHERE order_id = %d', $this->table( PaymentTables::TRANSACTIONS ), $order['id'] ) ), $token );
			$this->assertRefused( PaymentError::NotCapturable, fn() => $this->kernel->get( PaymentService::class )->capture( $intent, $capturer ), $token );
		}

		$this->assertSame( array( 'authorize:0', 'authorize:0' ), $this->gatewayCalls(), 'Nothing was ever captured.' );
	}

	/**
	 * Tests that a payment the shopper must confirm leaves the order waiting, its hold kept and its cart placing; the gateway's answer then settles it, and the same answer again changes nothing.
	 *
	 * Planted violation: in SettlePlacement::settle(), settle a duplicate as an approval: the second
	 * delivery then allocates the order's units again.
	 *
	 * @since 0.1.0
	 */
	public function test_a_webhook_before_the_redirect_settles_once(): void {
		$mug    = $this->sellable( 3, 1000 );
		$cart   = $this->readyCart( array( $mug => 1 ) );
		$answer = $this->placement->place( $this->placeInput( 'attempt-1', StubGateway::REQUIRES_ACTION ), self::guest() );
		$b      = $this->secondConnection();

		$this->assertSame( array( 'requires_action', 'pending_payment', 'pending' ), array( $answer['outcome'], $answer['status'], $answer['payment_status'] ) );
		$this->assertSame( array( 3, 0, 1 ), $this->committedStock( $b, $mug ), 'The hold is kept.' );
		$this->assertSame( 'placing', $this->committedCart( $b, $cart->id )['status'] ?? null );
		$this->assertSame( 'pending_payment', $this->statusRead( $answer )['status'] );

		$completion = $this->completionOf( $answer['order_uuid'] );
		$settlement = $this->kernel->get( SettlePlacement::class );

		$this->assertSame( 'approved', $settlement->apply( $completion, self::guest() )->outcome->value, 'The webhook delivers the approval.' );
		$this->assertSame( 'processing', $this->statusRead( $answer )['status'] );
		$this->assertSame( array( 3, 1, 0 ), $this->committedStock( $b, $mug ) );
		$this->assertSame( 'converted', $this->committedCart( $b, $cart->id )['status'] ?? null );

		$again = null;
		$log   = $this->captureQueries(
			function () use ( $settlement, $completion, &$again ): void {
				$again = $settlement->apply( $completion, self::guest() );
			}
		);

		$this->assertSame( 'duplicate', $again?->outcome->value, 'The redirect delivers the same answer again.' );
		$this->assertSame( array( 3, 1, 0 ), $this->committedStock( $b, $mug ), 'Nothing was allocated twice.' );
		$this->assertSame( 1, $this->committedCount( $b, InventoryTables::ALLOCATIONS ) );
		$this->assertSame( 0, $log->matching( '/^(UPDATE|DELETE)/' )->count(), 'A duplicate changes nothing: ' . "\n" . $log->describe() );
		$this->assertSame( 'processing', $this->statusRead( $answer )['status'] );
	}

	/**
	 * Tests that the same answer delivered again for an order of two lines, the second line's variant sold out by the first delivery, changes nothing and leaves the order processing.
	 *
	 * The first variant's hold is gone and it still has units, so a second allocation of the order
	 * would find units for it and none for the second variant. Whatever reaches the allocation a
	 * second time must fail on the order line's unique key at the first variant, as an allocation
	 * written one variant at a time does, and roll everything back; it must never go on to the sold
	 * out variant and settle the order on hold for stock that is in fact allocated to it.
	 *
	 * Planted violation: in SettlePlacement::settle(), settle a duplicate as an approval: the second
	 * delivery is refused with database.duplicate_key and changes nothing, and the order stays
	 * processing. With the allocation rows written only after every variant is claimed, the same
	 * plant settles the order on hold with its stock unavailable instead.
	 *
	 * @since 0.1.0
	 */
	public function test_a_duplicate_answer_for_an_order_with_a_line_now_sold_out_changes_nothing(): void {
		$mug  = $this->sellable( 3, 1000 );
		$cup  = $this->sellable( 1, 500 );
		$cart = $this->readyCart(
			array(
				$mug => 1,
				$cup => 1,
			)
		);

		$answer     = $this->placement->place( $this->placeInput( 'attempt-1', StubGateway::REQUIRES_ACTION ), self::guest() );
		$completion = $this->completionOf( $answer['order_uuid'] );
		$settlement = $this->kernel->get( SettlePlacement::class );
		$b          = $this->secondConnection();

		$this->assertLessThan( $cup, $mug, 'The variant with units left is allocated first.' );
		$this->assertSame( 'approved', $settlement->apply( $completion, self::guest() )->outcome->value );
		$this->assertSame( array( array( 3, 1, 0 ), array( 1, 1, 0 ) ), array( $this->committedStock( $b, $mug ), $this->committedStock( $b, $cup ) ), 'The cup is sold out now.' );

		$again   = null;
		$refused = null;

		try {
			$again = $settlement->apply( $completion, self::guest() );
		} catch ( \Throwable $failure ) {
			$refused = $failure;
		}

		$this->assertSame( 'processing', $this->statusRead( $answer )['status'], 'The order is not put on hold.' );
		$this->assertSame( array( array( 3, 1, 0 ), array( 1, 1, 0 ) ), array( $this->committedStock( $b, $mug ), $this->committedStock( $b, $cup ) ), 'Nothing was allocated twice.' );
		$this->assertSame( 2, $this->committedCount( $b, InventoryTables::ALLOCATIONS ) );
		$this->assertSame( 'converted', $this->committedCart( $b, $cart->id )['status'] ?? null );
		$this->assertNull( $refused, 'The second delivery was refused: ' . ( null === $refused ? '' : get_class( $refused ) . ' ' . $refused->getMessage() ) );
		$this->assertSame( 'duplicate', $again?->outcome->value, 'The redirect delivers the same answer again.' );
	}

	/**
	 * Tests that when the gateway's webhook delivers the approval before the authorization call returns it, the placement answers that the result came already, with the order's status as it now is.
	 *
	 * Planted violation: in PlaceOrder::settledAnswer(), answer `pending_payment` when the
	 * settlement changed no status: the shopper is then told the order waits, which is processing.
	 *
	 * @since 0.1.0
	 */
	public function test_a_result_the_webhook_delivered_first_is_answered_with_the_order_as_it_is(): void {
		$kernel = PlacementKernel::over(
			$this->db,
			$this->tokens,
			$this->identities,
			$this->wake,
			$this->reporter(),
			array(
				PaymentGateway::class => static fn( Container $c ): PaymentGateway => new WebhookFirstGateway( new StubGateway(), static fn( GatewayResult $result ) => $c->get( SettlePlacement::class )->apply( $result, Actor::user( 0 ) ) ),
			)
		);

		$this->readyCart( array( $this->sellable() => 1 ) );

		$answer = $kernel->get( PlaceOrder::class )->place( $this->placeInput(), self::guest() );
		$b      = $this->secondConnection();
		$kept   = json_decode( (string) $b->fetchValue( sprintf( 'SELECT response_json FROM `%s`', $this->table( CheckoutTables::IDEMPOTENCY_KEYS ) ) ), true );

		$this->assertSame( array( 'duplicate', 'processing', 'authorized' ), array( $answer['outcome'], $answer['status'], $answer['payment_status'] ) );
		$this->assertSame( 'processing', $this->committedOrder( $b, $answer['order_uuid'] )['status'] ?? null );
		$this->assertSame( 'approved', $kept['outcome'] ?? null, 'The key keeps what the webhook\'s settlement came to.' );
	}

	/**
	 * Tests that an approval whose units were taken while the shopper confirmed puts the order on hold: the payment stands, its promotion use is committed and its cart converted, and the stock is left as the other checkout left it.
	 *
	 * The first placement asks its shopper to act; its hold expires meanwhile, and another
	 * checkout reclaims it and takes the last unit. Then the gateway's approval of the first arrives.
	 *
	 * Planted violation: in SettlePlacement::allocate(), answer true when the units are gone: the
	 * order is then accepted with no units allocated to it.
	 *
	 * @since 0.1.0
	 */
	public function test_an_approval_whose_units_are_gone_puts_the_order_on_hold(): void {
		$mug       = $this->sellable( 1, 1000 );
		$promotion = $this->plantPromotion( 'SAVE10' );
		$waiting   = $this->readyCart( array( $mug => 1 ), array( 'SAVE10' ) );
		$answer    = $this->placement->place( $this->placeInput( 'waiting', StubGateway::REQUIRES_ACTION ), self::guest() );
		$b         = $this->secondConnection();

		$this->assertSame( 'requires_action', $answer['outcome'] );

		$b->query( sprintf( 'UPDATE `%s` SET expires_at = UTC_TIMESTAMP() - INTERVAL 1 MINUTE', $this->table( InventoryTables::HOLDS ) ) );

		$this->readyCart( array( $mug => 1 ) );

		$this->assertSame( 'approved', $this->placement->place( $this->placeInput( 'taker' ), self::guest() )['outcome'], 'Another checkout takes the unit the expired hold gave back.' );

		$before  = $this->committedStock( $b, $mug );
		$settled = $this->kernel->get( SettlePlacement::class )->apply( $this->completionOf( $answer['order_uuid'] ), self::guest() );
		$order   = $this->committedOrder( $b, $answer['order_uuid'] );

		$this->assertNotNull( $order );
		$this->assertSame( array( 'stock_unavailable', 'on_hold', 'authorized' ), array( $settled->outcome->value, $settled->orderStatus?->value, $settled->paymentStatus->value ) );
		$this->assertSame( array( 'on_hold', 'authorized' ), array( $order['status'], $order['payment_status'] ) );
		$this->assertSame( SettlePlacement::STOCK_UNAVAILABLE, $b->fetchValue( sprintf( "SELECT reason FROM `%s` WHERE order_id = %d AND to_status = 'on_hold'", $this->table( OrderTables::EVENTS ), $order['id'] ) ) );
		$this->assertSame( 'committed', $b->fetchValue( sprintf( 'SELECT state FROM `%s` WHERE order_id = %d AND promotion_id = %d', $this->table( PromotionTables::USAGE ), $order['id'], $promotion ) ), 'The payment stands, and with it the promotion\'s use.' );
		$this->assertSame( 'converted', $this->committedCart( $b, $waiting->id )['status'] ?? null );
		$this->assertSame( array( 1, 1, 0 ), $before );
		$this->assertSame( $before, $this->committedStock( $b, $mug ), 'The stock is as the other checkout left it.' );
		$this->assertSame( 0, $this->committedCount( $b, InventoryTables::HOLDS ) );
		$this->assertSame( 0, $this->committedCount( $b, InventoryTables::ALLOCATIONS, sprintf( 'order_id = %d', $order['id'] ) ) );
	}

	/**
	 * Tests that three declines within an hour lock their cart, while another shopper's cart behind the same address still places, and that a token naming no cart is never counted.
	 *
	 * Planted violations:
	 * - in PlaceOrder::paid(), count the decline for the client (`$this->identities->of(
	 *   $actor->userId() )`, as is the check): the second shopper's cart is then refused;
	 * - in PlaceOrder::place(), count the cart's declines before the cart is matched
	 *   (`$this->limiter->hit()` on `ofCart( $token )` ahead of openCart()): a token that names no
	 *   cart then leaves a counter.
	 *
	 * @since 0.1.0
	 */
	public function test_declines_lock_their_cart_and_no_other_shoppers(): void {
		$declines = sprintf( "scope = '%s'", PlaceOrder::DECLINES_BUCKET );

		$this->tokens->presented = CartToken::generate();

		$this->assertRefused(
			CartError::NotFound,
			fn() => $this->placement->place(
				array(
					'idempotency_key'   => 'no-such-cart',
					'cart_version'      => 1,
					'grand_total_minor' => 1000,
					'currency'          => 'USD',
					'payment_data'      => array( 'payment_token' => StubGateway::APPROVE ),
				),
				self::guest()
			)
		);
		$this->assertSame( 0, $this->committedCount( $this->secondConnection(), RateCountersTable::NAME, $declines ), 'A token that names no cart is counted.' );

		$this->readyCart( array( $this->sellable( 10 ) => 1 ) );

		for ( $attempt = 1; $attempt <= PlaceOrder::DECLINES; $attempt++ ) {
			$this->assertRefused( CheckoutError::PaymentDeclined, fn() => $this->placement->place( $this->placeInput( 'decline-' . $attempt, StubGateway::DECLINE ), self::guest() ), 'Decline ' . $attempt );
		}

		$this->assertRefused( StoreApiError::RateLimited, fn() => $this->placement->place( $this->placeInput( 'after-the-declines' ), self::guest() ), 'The cart is locked.' );

		$this->readyCart( array( $this->sellable() => 1 ) );

		$this->assertSame( 'approved', $this->placement->place( $this->placeInput( 'another-shopper' ), self::guest() )['outcome'], 'Another shopper behind the same address places.' );
		$this->assertSame( 1, $this->committedCount( $this->secondConnection(), RateCountersTable::NAME, $declines ), 'One cart\'s declines are counted, and nothing else.' );
	}

	/**
	 * Builds the answer the gateway gives an intent that waited for its shopper, once they confirmed: the approval a webhook delivers.
	 *
	 * @since 0.1.0
	 *
	 * @param string $orderUuid The order.
	 * @return \SEOCart\Contracts\Payment\GatewayResult The approval.
	 */
	private function completionOf( string $orderUuid ): \SEOCart\Contracts\Payment\GatewayResult {
		$b      = $this->secondConnection();
		$row    = $b->fetchRow( sprintf( "SELECT i.uuid, i.provider_intent_id, i.amount_minor, i.currency FROM `%s` i JOIN `%s` o ON o.id = i.order_id WHERE o.uuid = '%s'", $this->table( PaymentTables::INTENTS ), $this->table( OrderTables::ORDERS ), $orderUuid ) );
		$answer = null === $row ? null : ( new StubGateway() )->query( new PaymentQuery( (string) $row['uuid'], (string) $row['provider_intent_id'], Money::of( (int) $row['amount_minor'], \SEOCart\Support\Currency::of( (string) $row['currency'] ) ), \SEOCart\Contracts\Payment\Mode::Test ) );

		$this->assertNotNull( $answer, 'The stub has no answer for the intent.' );

		return $answer;
	}

	/**
	 * Reads an order's status as its shopper does: by its uuid, with the access key the placement's answer gave.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $answer The placement's answer.
	 * @return array<string, mixed> The status read's answer.
	 */
	private function statusRead( array $answer ): array {
		return $this->kernel->get( OrderStatusRead::class )->read(
			array(
				'uuid'      => $answer['order_uuid'],
				'order_key' => $answer['order_key'],
			),
			self::guest()
		);
	}

	/**
	 * Returns the gateway's calls, each as `method:depth`.
	 *
	 * @since 0.1.0
	 *
	 * @return list<string> The calls.
	 */
	private function gatewayCalls(): array {
		return array_map( static fn( array $call ): string => $call['method'] . ':' . $call['depth'], $this->gateway->calls );
	}

	/**
	 * Returns an actor who may capture payments.
	 *
	 * @since 0.1.0
	 *
	 * @return Actor The actor.
	 */
	private function capturer(): Actor {
		return $this->userGranted( PaymentService::CAPTURE_CAPABILITY );
	}

	/**
	 * Asserts that the store is as it was before a refused placement: no order, no key, no hold, and the cart open at its version.
	 *
	 * @since 0.1.0
	 *
	 * @param int $cartId  The cart.
	 * @param int $version Its version.
	 */
	private function assertNothingPlaced( int $cartId, int $version ): void {
		$b = $this->secondConnection();

		$this->assertSame( 0, $this->committedCount( $b, OrderTables::ORDERS ) );
		$this->assertSame( 0, $this->committedCount( $b, CheckoutTables::IDEMPOTENCY_KEYS ) );
		$this->assertSame( 0, $this->committedCount( $b, InventoryTables::HOLDS ) );
		$this->assertSame( array( $version, 'open' ), array( $this->committedCart( $b, $cartId )['version'] ?? null, $this->committedCart( $b, $cartId )['status'] ?? null ) );
		$this->assertSame( array(), $this->gatewayCalls() );
	}

	/**
	 * Asserts that work is refused with a code.
	 *
	 * @since 0.1.0
	 *
	 * @param ErrorCode $code    The code.
	 * @param \Closure  $work    The work.
	 * @param string    $message Optional. What is being checked. Default empty.
	 * @return CodedException The refusal.
	 */
	private function assertRefused( ErrorCode $code, \Closure $work, string $message = '' ): CodedException {
		try {
			$work();
		} catch ( CodedException $refused ) {
			$this->assertSame( $code, $refused->errorCode(), $message . ': ' . $refused->getMessage() );

			return $refused;
		}

		$this->fail( sprintf( 'The work was not refused with %s. %s', $code->value, $message ) );
	}
}
