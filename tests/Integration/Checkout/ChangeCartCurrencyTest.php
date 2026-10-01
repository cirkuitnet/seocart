<?php
/**
 * Tests switching a cart's currency: what the switch changes, what it leaves alone, and every refusal
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Checkout;

use SEOCart\Cart\Application\CartError;
use SEOCart\Cart\Application\CartService;
use SEOCart\Cart\Application\CurrencyChangeLimit;
use SEOCart\Cart\Application\StoreApiError;
use SEOCart\Cart\Domain\CartToken;
use SEOCart\Cart\Infrastructure\CartTables;
use SEOCart\Checkout\Domain\CheckoutError;
use SEOCart\Checkout\Infrastructure\CheckoutTables;
use SEOCart\Inventory\Infrastructure\InventoryTables;
use SEOCart\Order\Infrastructure\OrderTables;
use SEOCart\Payment\Infrastructure\Gateway\StubGateway;
use SEOCart\Payment\Infrastructure\PaymentTables;
use SEOCart\Pricing\Application\PresentmentCurrencies;
use SEOCart\Pricing\Domain\Totals\TraceEntry;
use SEOCart\Promotion\Domain\Promotion;
use SEOCart\Promotion\Infrastructure\PromotionTables;
use SEOCart\Support\Currency;
use SEOCart\Tests\Support\Checkout\CurrencySwitchTestCase;

/**
 * A switch moves the cart's version on, writes its currency, drops its session's quotes and prices it in the new currency; it touches nothing a placement owns.
 *
 * Planted violations, each named on its test.
 *
 * @group international
 *
 * @since 0.1.0
 */
final class ChangeCartCurrencyTest extends CurrencySwitchTestCase {

	/**
	 * The tables a switch must never send a statement to: everything a placement writes.
	 *
	 * @since 0.1.0
	 *
	 * @var list<string>
	 */
	private const PLACEMENT_TABLES = array(
		InventoryTables::HOLDS,
		InventoryTables::ITEMS,
		InventoryTables::ALLOCATIONS,
		PaymentTables::INTENTS,
		PaymentTables::TRANSACTIONS,
		PromotionTables::PROMOTIONS,
		PromotionTables::USAGE,
		OrderTables::ORDERS,
		OrderTables::LINES,
		OrderTables::ADJUSTMENTS,
		OrderTables::TOTALS,
		OrderTables::EVENTS,
		OrderTables::NUMBER_SEQUENCE,
		OrderTables::CONVERSION_CONTEXTS,
	);

	/**
	 * Tests that a switch drops the session's quotes, keeps the shipping method chosen, writes the currency, moves the version on once, and answers the cart priced in the new currency at its current rate; and that it sends nothing to a table a placement writes.
	 *
	 * Planted violation: in ChangeCartCurrency::change(), read the order of the cart in the
	 * closure (`$this->orders->statusOf( $cartId )`), as a stray release or void would touch a
	 * placement's rows: the statement log then shows a statement on `orders`.
	 *
	 * @since 0.1.0
	 */
	public function test_a_switch_drops_the_quotes_and_prices_the_cart_in_the_new_currency(): void {
		$mug  = $this->sellable( 5, 1000 );
		$cart = $this->readyCart( array( $mug => 2 ) );

		$this->freezeQuotes( $cart );

		$b      = $this->secondConnection();
		$before = $this->committedSwitch( $b, $cart->id );

		$this->assertSame( array( (string) $cart->version, 'USD', 'flat' ), array( $before['quoted_at_cart_version'], $before['currency'], $before['shipping_method_key'] ) );
		$this->assertNotNull( $before['shipping_quote_json'], 'The quotes were frozen.' );
		$this->assertNotNull( $before['tax_quote_fingerprint'] );

		$answer = array();
		$log    = $this->captureQueries(
			function () use ( $cart, &$answer ): void {
				$answer = $this->switchTo( 'EUR', $cart->version );
			}
		);

		$this->assertSame(
			array(
				'version'                => (string) ( $cart->version + 1 ),
				'status'                 => 'open',
				'currency'               => 'EUR',
				'shipping_method_key'    => 'flat',
				'quoted_at_cart_version' => '0',
				'shipping_quote_json'    => null,
				'tax_quote_fingerprint'  => null,
			),
			$this->committedSwitch( $b, $cart->id ),
			'The quotes are dropped, the method chosen is kept.'
		);

		$eur = $this->kernel->get( PresentmentCurrencies::class )->find( Currency::of( 'USD' ), Currency::of( 'EUR' ) );

		$this->assertNotNull( $eur );
		$this->assertSame( array( 'version', 'lines', 'promotion_codes', 'totals', 'unpriced_lines' ), array_keys( $answer ) );
		$this->assertSame( $cart->version + 1, $answer['version'] );
		$this->assertSame(
			array( 'EUR', 'USD', $this->rateVersion, $eur->context->fingerprint() ),
			array( $answer['totals']['currency'], $answer['totals']['base_currency'], $answer['totals']['rate_version'], $answer['totals']['conversion_context'] ),
			'The answer is priced at the current EUR rate.'
		);
		$this->assertSame( array( 'converted' ), array_column( $answer['totals']['lines'], 'price_source' ) );
		$this->assertGreaterThan( 0, $answer['totals']['summary']['shipping_total_minor'], 'The flat rate, quoted again in EUR.' );

		foreach ( self::PLACEMENT_TABLES as $table ) {
			$this->assertSame( 0, $log->forTable( $this->table( $table ) )->count(), 'A switch sent a statement on ' . $table . ":\n" . $log->describe() );
		}

		$writes = $log->matching( '/^(INSERT|UPDATE|DELETE)/' );

		$this->assertSame( 1, $writes->forTable( $this->table( CartTables::CARTS ) )->count(), 'One write of the cart: its compare-and-swap.' );
		$this->assertSame( 1, $writes->forTable( $this->table( CheckoutTables::SESSIONS ) )->count(), 'One write of the session: its quotes dropped.' );
		$this->assertSame( 1, $log->matching( '/^START TRANSACTION$/' )->count(), 'One transaction.' );
	}

	/**
	 * Tests that a currency the store does not sell in is refused with one answer whatever the reason, before anything is read or written; and that a token naming no cart is refused by the cart, and starts no counter.
	 *
	 * Planted violation: in CartService::changeCurrency(), count the switch against
	 * `$this->tokens->presented()` before presentedRow() is read: a token that names no cart then
	 * starts a counter.
	 *
	 * @since 0.1.0
	 */
	public function test_a_currency_the_store_does_not_sell_in_is_refused_and_changes_nothing(): void {
		$cart = $this->readyCart( array( $this->sellable() => 1 ) );

		$this->freezeQuotes( $cart );

		$b      = $this->secondConnection();
		$before = $this->committedSwitch( $b, $cart->id );

		foreach ( self::NOT_SOLD as $reason => $code ) {
			$refused = $this->assertRefused( CheckoutError::CurrencyNotEnabled, fn() => $this->switchTo( $code, $cart->version ), $reason );

			$this->assertSame( array( array(), array() ), array( $refused->context(), $refused->details() ), $reason . ': the refusal names nothing.' );
		}

		$this->assertSame( $before, $this->committedSwitch( $b, $cart->id ), 'A refused currency changed the cart or its session.' );
		$this->assertSame( 0, $this->switchesCounted( $b ), 'A refused currency was counted.' );

		$this->tokens->presented = CartToken::generate();

		$this->assertRefused( CheckoutError::CurrencyNotEnabled, fn() => $this->switchTo( 'JPY', 1 ), 'The currency is decided before the cart is looked for' );
		$this->assertRefused( CartError::NotFound, fn() => $this->switchTo( 'EUR', 1 ), 'A token naming no cart' );
		$this->assertSame( 0, $this->switchesCounted( $b ), 'A token naming no cart started a counter.' );
	}

	/**
	 * Tests that a switch to the currency the cart is in already is accepted: the version moves on, the quotes go, and the totals are the same.
	 *
	 * @since 0.1.0
	 */
	public function test_a_switch_to_the_carts_own_currency_moves_only_the_version_on(): void {
		$cart = $this->readyCart( array( $this->sellable( 5, 1500 ) => 1 ) );
		$then = $this->kernel->get( CartService::class )->calculation( $cart )->totals->toArray();

		$this->freezeQuotes( $cart );

		$answer = $this->switchTo( 'USD', $cart->version );
		$b      = $this->secondConnection();

		$this->assertSame( $cart->version + 1, $answer['version'] );
		$this->assertSame( $then['summary'], $answer['totals']['summary'] );
		$this->assertSame( array( 'USD', '0' ), array( $this->committedSwitch( $b, $cart->id )['currency'], $this->committedSwitch( $b, $cart->id )['quoted_at_cart_version'] ) );
	}

	/**
	 * Tests that a switch based on an older version is refused with the cart's version and totals now, and changes nothing: neither the currency nor the quotes. A refused switch is counted.
	 *
	 * @since 0.1.0
	 */
	public function test_a_stale_switch_is_refused_and_changes_nothing(): void {
		$cart = $this->readyCart( array( $this->sellable( 5, 1000 ) => 1 ) );

		$this->freezeQuotes( $cart );

		$b      = $this->secondConnection();
		$before = $this->committedSwitch( $b, $cart->id );
		$stale  = $this->assertRefused( CartError::VersionStale, fn() => $this->switchTo( 'EUR', $cart->version - 1 ) );

		$this->assertSame( array( 'current_version' => $cart->version ), $stale->context() );
		$this->assertSame( 'USD', $stale->details()['totals']['currency'] ?? null, 'The totals of the cart as it is.' );
		$this->assertSame( $before, $this->committedSwitch( $b, $cart->id ), 'The stale switch changed the cart or dropped the quotes.' );
		$this->assertSame( 1, $this->switchesCounted( $b ) );
	}

	/**
	 * Tests that each line is priced by the new currency's terms: a price authored in it is kept, a base price converts where the currency allows it, and a line with neither is reported unpriced.
	 *
	 * @since 0.1.0
	 */
	public function test_each_line_is_priced_by_the_new_currencys_terms(): void {
		$authored  = $this->sellable( 5, 1000, 'Tee' );
		$converted = $this->sellable( 5, 2000, 'Mug' );

		$this->priceIn( 'EUR', array( $authored => 950 ) );
		$this->priceIn( 'GBP', array( $authored => 800 ) );

		$cart  = $this->readyCart(
			array(
				$authored  => 1,
				$converted => 1,
			)
		);
		$inEur = $this->switchTo( 'EUR', $cart->version );

		$this->assertSame( array(), $inEur['unpriced_lines'] );
		$this->assertSame(
			array(
				array( $authored, 'explicit', 950 ),
				array( $converted, 'converted', 1825 ),
			),
			array_map( static fn( array $line ): array => array( $line['variant_id'], $line['price_source'], $line['unit_price_minor'] ), $inEur['totals']['lines'] ),
			'20.00 USD at 0.91230 is 18.25 EUR.'
		);

		$inGbp = $this->switchTo( 'GBP', $inEur['version'] );

		$this->assertSame( array( array( $authored, 'explicit', 800 ) ), array_map( static fn( array $line ): array => array( $line['variant_id'], $line['price_source'], $line['unit_price_minor'] ), $inGbp['totals']['lines'] ) );
		$this->assertSame( array( array( $converted, 'no_price_in_currency' ) ), array_map( static fn( array $line ): array => array( $line['variant_id'], $line['reason'] ), $inGbp['unpriced_lines'] ), 'GBP sells only what is priced in GBP.' );
	}

	/**
	 * Tests that a switch keeps the cart's promotion codes, and that the next calculation leaves out a code whose fixed amount is in another currency, tracing why, while a percentage code still applies.
	 *
	 * @since 0.1.0
	 */
	public function test_the_codes_stay_and_a_fixed_amount_in_another_currency_takes_nothing_off(): void {
		$percent = $this->plantPromotion( 'TENOFF' );
		$fixed   = $this->plantPromotion(
			'FIVEUSD',
			array(
				'effect_kind'                 => 'fixed',
				'effect_percent_micropercent' => null,
				'effect_amount_minor'         => 500,
				'effect_currency'             => 'USD',
				'effect_amount_basis'         => 'net',
			)
		);
		$cart    = $this->readyCart( array( $this->sellable( 5, 3000 ) => 1 ), array( 'TENOFF', 'FIVEUSD' ) );
		$carts   = $this->kernel->get( CartService::class );

		$this->assertSame( array( $percent, $fixed ), array_column( $carts->calculation( $cart )->appliedPromotions(), 'id' ), 'In USD both codes apply.' );

		$answer = $this->switchTo( 'EUR', $cart->version );
		$now    = $carts->current();

		$this->assertNotNull( $now );

		$calculation = $carts->calculation( $now );
		$rejected    = array();

		foreach ( $calculation->totals->trace->entries as $entry ) {
			if ( TraceEntry::INPUT === $entry->kind && 'rejected_code' === ( $entry->data['record'] ?? null ) ) {
				$rejected[] = array( $entry->data['code'], $entry->data['reason'] );
			}
		}

		$this->assertSame( array( array( 'code' => 'TENOFF' ), array( 'code' => 'FIVEUSD' ) ), $answer['promotion_codes'], 'The cart keeps both codes.' );
		$this->assertSame( array( $percent ), array_column( $calculation->appliedPromotions(), 'id' ), 'In EUR only the percentage applies.' );
		$this->assertSame( array( array( 'FIVEUSD', Promotion::OTHER_CURRENCY ) ), $rejected );
		$this->assertLessThan( 0, $answer['totals']['summary']['discount_total_minor'], 'The percentage still takes its share off.' );
	}

	/**
	 * Tests that a cart placing an order refuses the switch, naming the order, and leaves the order's hold, its intent and the session exactly as they were; and that once the order settles the cart is converted, still refuses, and its token starts a new cart.
	 *
	 * The switch is sent at the version the placement left, so only the cart's status refuses it.
	 *
	 * Planted violation: in MysqlCartRepository::SWAP_CURRENCY, use a condition without
	 * `status = 'open'` (`WHERE id = %d AND version = %d AND expires_at > UTC_TIMESTAMP()`): the
	 * switch then lands on the placing cart, whose order is in another currency.
	 *
	 * @since 0.1.0
	 */
	public function test_a_cart_placing_an_order_refuses_the_switch_and_its_order_is_untouched(): void {
		$mug    = $this->sellable( 3, 1000 );
		$cart   = $this->readyCart( array( $mug => 1 ) );
		$placed = $this->placement->place( $this->placeInput( 'pending', StubGateway::REQUIRES_ACTION ), self::guest() );
		$b      = $this->secondConnection();
		$before = $this->placementRows( $cart->id );

		$this->assertSame( 'requires_action', $placed['outcome'] );

		$refused = $this->assertRefused( CartError::NotOpen, fn() => $this->switchTo( 'EUR', $placed['cart_version'] ) );

		$this->assertSame( array( 'status' => 'placing' ), $refused->context() );
		$this->assertSame(
			array(
				'order_uuid'   => $placed['order_uuid'],
				'order_status' => 'pending_payment',
			),
			$refused->details()
		);
		$this->assertSame( $before, $this->placementRows( $cart->id ), 'The refused switch changed the cart, its session, or the order\'s hold or intent.' );

		$settled = $this->startSettlement( $placed['order_uuid'] )->finish();

		$this->assertSame( 'approved', $settled['outcome'] ?? $settled );
		$this->assertSame( 'converted', $this->committedSwitch( $b, $cart->id )['status'] ?? null );

		$converted = $this->assertRefused( CartError::NotOpen, fn() => $this->switchTo( 'EUR', $placed['cart_version'] ) );

		$this->assertSame( array( 'status' => 'converted' ), $converted->context() );

		$fresh = $this->kernel->get( CartService::class )->add( self::lines( array( $mug => 1 ) ), 0, self::guest() );

		$this->tokens->keepIssued();

		$this->assertSame( 1, $fresh->version, 'The token starts a new cart.' );
		$this->assertNotSame( $cart->id, $fresh->id );
		$this->assertSame( 'EUR', $this->switchTo( 'EUR', 1 )['totals']['currency'], 'The new cart switches.' );
	}

	/**
	 * Tests that a cart may switch CurrencyChangeLimit::LIMIT times in an hour, the next switch is refused and changes nothing, and another cart keeps its own count.
	 *
	 * @since 0.1.0
	 */
	public function test_the_switch_after_the_carts_cap_is_refused(): void {
		$mug     = $this->sellable();
		$cart    = $this->readyCart( array( $mug => 1 ) );
		$version = $cart->version;

		for ( $switch = 1; $switch <= CurrencyChangeLimit::LIMIT; $switch++ ) {
			$version = $this->switchTo( 0 === $switch % 2 ? 'USD' : 'EUR', $version )['version'];
		}

		$b = $this->secondConnection();

		$this->assertRefused( StoreApiError::RateLimited, fn() => $this->switchTo( 'EUR', $version ) );
		$this->assertSame( array( (string) $version, 'USD' ), array( $this->committedSwitch( $b, $cart->id )['version'], $this->committedSwitch( $b, $cart->id )['currency'] ) );
		$this->assertSame( CurrencyChangeLimit::LIMIT + 1, $this->switchesCounted( $b ) );

		$other = $this->readyCart( array( $mug => 1 ) );

		$this->assertSame( 'EUR', $this->switchTo( 'EUR', $other->version )['totals']['currency'], 'Another cart has a cap of its own.' );
	}

	/**
	 * Reads every row a refused switch of a placing cart must leave as it was: the cart's, its session's, and every hold, item, intent, ledger row and order.
	 *
	 * @since 0.1.0
	 *
	 * @param int $cartId The cart.
	 * @return array<string, list<array<string, mixed>>> Each table's rows, every column as the server sends it.
	 */
	private function placementRows( int $cartId ): array {
		$rows = array();

		$byCart = array(
			CartTables::CARTS        => 'id',
			CheckoutTables::SESSIONS => 'cart_id',
		);

		foreach ( $byCart as $table => $column ) {
			$rows[ $table ] = $this->db->fetchAll( 'SELECT * FROM %i WHERE %i = %d', $this->table( $table ), $column, $cartId );
		}

		foreach ( array( InventoryTables::HOLDS, InventoryTables::ITEMS, PaymentTables::INTENTS, PaymentTables::TRANSACTIONS, OrderTables::ORDERS ) as $table ) {
			$rows[ $table ] = $this->db->fetchAll( 'SELECT * FROM %i ORDER BY 1', $this->table( $table ) );
		}

		return $rows;
	}
}
