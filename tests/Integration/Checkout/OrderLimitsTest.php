<?php
/**
 * Tests the order limits: what the goods are measured as, per currency, told by the session write and enforced at placement
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Checkout;

use SEOCart\Cart\Application\CartService;
use SEOCart\Cart\Infrastructure\CartTables;
use SEOCart\Checkout\Application\CheckoutRules;
use SEOCart\Checkout\Application\CheckoutSettingsService;
use SEOCart\Checkout\Application\UpdateCheckoutSession;
use SEOCart\Checkout\Domain\CheckoutError;
use SEOCart\Checkout\Domain\Fields\Carrier;
use SEOCart\Checkout\Infrastructure\CheckoutTables;
use SEOCart\Order\Infrastructure\OrderTables;
use SEOCart\Payment\Infrastructure\Gateway\StubGateway;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Support\Error\CodedException;
use SEOCart\Tests\Support\Checkout\PlacementTestCase;
use SEOCart\Tests\Support\Checkout\RoutedPlacements;
use SEOCart\Tests\Support\Pricing\PricesInCurrencies;

/**
 * A minimum and a maximum per currency, compared with the goods after their discounts, gross in a currency whose prices were entered with tax and net in one whose prices were not; never with shipping, fees or a client's figure; never converted.
 *
 * The USD prices are entered net, the EUR prices gross, at the fixed 20 % tax: a line of 20.00
 * USD comes to 20.00 net, a line of 24.00 EUR to 24.00 gross. Each request's services come from a
 * kernel of their own, which reads the limits once.
 *
 * Planted violations, each shown red and removed:
 * - in OrderLimits::amountOf(), measure the grand total: the shipping case is placed;
 * - in OrderLimits::amountOf(), measure the lines before their discounts: the code case is placed;
 * - in OrderLimits::amountOf(), always measure net: the EUR rows at the limit are refused;
 * - in OrderLimits::verdict(), take the USD entry for every currency: the currency without an
 *   entry is refused;
 * - in PlaceOrder::priced(), judge the limit only when the placement may still plan
 *   (`if ( $replan )`): the re-plan case is placed.
 *
 * @since 0.2.0
 */
final class OrderLimitsTest extends PlacementTestCase {

	use PricesInCurrencies;
	use RoutedPlacements;

	/**
	 * A line's price in USD, net, in minor units.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	private const USD_PRICE = 2000;

	/**
	 * A line's price in EUR, gross, in minor units.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	private const EUR_PRICE = 2400;

	/**
	 * The variant of the test's cart.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	private int $variant = 0;

	/**
	 * Whether the test planted the boot record a store selling in EUR reads.
	 *
	 * @since 0.2.0
	 *
	 * @var bool
	 */
	private bool $bootRecordPlanted = false;

	/**
	 * Removes the store's checkout choices and the boot record the test planted.
	 *
	 * @since 0.2.0
	 */
	public function tear_down(): void {
		foreach ( array( 'seocart_checkout_field_presence', 'seocart_checkout_order_limits' ) as $option ) {
			delete_option( $option );
		}

		if ( $this->bootRecordPlanted ) {
			$this->removeBootRecord();
		}

		parent::tear_down();
	}

	/**
	 * Provides each currency's four rows: one minor unit under the minimum, at it, at the maximum, one minor unit over it.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, array{string, int|null, int|null, string}> The currency, the minimum, the maximum, and the verdict.
	 */
	public static function bounds(): array {
		$rows = array();

		foreach ( array(
			'USD' => self::USD_PRICE,
			'EUR' => self::EUR_PRICE,
		) as $currency => $goods ) {
			$rows[ "{$currency}, one under the minimum" ] = array( $currency, $goods + 1, null, 'below_minimum' );
			$rows[ "{$currency}, at the minimum" ]        = array( $currency, $goods, null, 'ok' );
			$rows[ "{$currency}, at the maximum" ]        = array( $currency, null, $goods, 'ok' );
			$rows[ "{$currency}, one over the maximum" ]  = array( $currency, null, $goods - 1, 'above_maximum' );
		}

		return $rows;
	}

	/**
	 * Tests one row: the session write tells the verdict, measured on the goods, and the placement refuses outside the limit and places within it.
	 *
	 * @since 0.2.0
	 *
	 * @dataProvider bounds
	 *
	 * @param string   $currency The cart's currency.
	 * @param int|null $min      The minimum, in its minor units.
	 * @param int|null $max      The maximum.
	 * @param string   $verdict  What the write tells.
	 */
	public function test_the_goods_against_each_bound( string $currency, ?int $min, ?int $max, string $verdict ): void {
		$this->setLimits( array( $currency => array( $min, $max ) ) );
		$this->cartIn( $currency );

		$written = $this->writeAgain();
		$goods   = 'EUR' === $currency ? self::EUR_PRICE : self::USD_PRICE;

		$this->assertSame(
			array(
				'status'        => $verdict,
				'amount_minor'  => $goods,
				'minimum_minor' => $min,
				'maximum_minor' => $max,
				'basis'         => 'EUR' === $currency ? 'gross' : 'net',
			),
			$written['order_limit']
		);

		if ( 'ok' === $verdict ) {
			$this->assertSame( 'approved', $this->freshPlacement()->place( $this->placeInput( 'within' ), self::guest() )['outcome'] );

			return;
		}

		$refused = $this->refused( 'outside' );
		$below   = 'below_minimum' === $verdict;

		$this->assertSame( $below ? CheckoutError::SubtotalBelowMinimum : CheckoutError::SubtotalAboveMaximum, $refused->errorCode() );
		$this->assertSame(
			array(
				'amount_minor' => $goods,
				'limit_minor'  => $below ? $min : $max,
				'currency'     => $currency,
				'basis'        => 'EUR' === $currency ? 'gross' : 'net',
			),
			$refused->details()
		);
		$this->assertNothingPlaced();
	}

	/**
	 * Tests that a currency without an entry is never refused, and its write tells no verdict, while the base currency has a limit the cart would break.
	 *
	 * @since 0.2.0
	 */
	public function test_a_currency_without_an_entry_has_no_limit(): void {
		$this->setLimits( array( 'USD' => array( 999999, null ) ) );
		$this->cartIn( 'EUR' );

		$this->assertNull( $this->writeAgain()['order_limit'] );
		$this->assertSame( 'approved', $this->freshPlacement()->place( $this->placeInput( 'no-entry' ), self::guest() )['outcome'] );
	}

	/**
	 * Tests that a code taking the goods under the minimum is accepted by the cart, told by the write, and refused at placement with nothing written and the key not claimed; and that shipping, which lifts the grand total over the minimum, changes nothing.
	 *
	 * @since 0.2.0
	 */
	public function test_a_code_under_the_minimum_and_the_shipping_over_it(): void {
		$this->plantPromotion(
			'TENOFF',
			array(
				'effect_kind'                 => 'percent',
				'effect_percent_micropercent' => 10000000,
			)
		);
		$this->setLimits( array( 'USD' => array( self::USD_PRICE, null ) ) );
		$this->cartIn( 'USD', array( 'TENOFF' ) );

		$written = $this->writeAgain();

		$this->assertSame( array( 'below_minimum', 1800 ), array( $written['order_limit']['status'], $written['order_limit']['amount_minor'] ), 'The code took 10 % off the goods.' );
		$this->assertGreaterThan( self::USD_PRICE, $written['totals']['summary']['grand_minor'], 'With shipping and tax the grand total is over the minimum, so the test proves the goods are measured.' );

		$refused = $this->refused( 'under-after-code' );

		$this->assertSame( array( CheckoutError::SubtotalBelowMinimum, 1800 ), array( $refused->errorCode(), $refused->details()['amount_minor'] ?? null ) );
		$this->assertNothingPlaced();
	}

	/**
	 * Tests that a cart a code takes to nothing is refused by a minimum, and placed, with nothing due, without one.
	 *
	 * @since 0.2.0
	 */
	public function test_a_cart_discounted_to_zero(): void {
		$this->plantPromotion(
			'ALLOFF',
			array(
				'effect_kind'                 => 'percent',
				'effect_percent_micropercent' => 100000000,
			)
		);
		$this->plantPromotion(
			'SHIPOFF',
			array(
				'effect_kind'                 => 'free_shipping',
				'effect_percent_micropercent' => null,
			)
		);
		$this->setLimits( array( 'USD' => array( 1, null ) ) );
		$this->cartIn( 'USD', array( 'ALLOFF', 'SHIPOFF' ) );

		$limit = $this->writeAgain()['order_limit'];

		$this->assertSame( array( 'below_minimum', 0 ), array( $limit['status'], $limit['amount_minor'] ) );
		$this->assertSame( CheckoutError::SubtotalBelowMinimum, $this->refused( 'zero-limited' )->errorCode() );

		$this->setLimits( array() );

		$this->assertSame( 'approved', $this->freshPlacement()->place( $this->placeInput( 'zero-free' ), self::guest() )['outcome'] );
	}

	/**
	 * Tests that switching the cart's currency switches the entry applied, and refuses nothing.
	 *
	 * @since 0.2.0
	 */
	public function test_the_currency_switch_changes_the_entry_applied(): void {
		$this->setLimits(
			array(
				'USD' => array( 1, null ),
				'EUR' => array( 999999, null ),
			)
		);
		$this->cartIn( 'USD' );

		$this->assertSame( 'ok', $this->writeAgain()['order_limit']['status'] );

		$this->switchToEur();

		$limit   = $this->writeAgain()['order_limit'];
		$refused = $this->refused( 'switched' );

		$this->assertSame( array( 'below_minimum', self::EUR_PRICE, 999999 ), array( $limit['status'], $limit['amount_minor'], $limit['minimum_minor'] ) );
		$this->assertSame( array( 'EUR', 999999 ), array( $refused->details()['currency'] ?? null, $refused->details()['limit_minor'] ?? null ) );
	}

	/**
	 * Tests that a client's grand total is never what is measured: a figure other than the cart's is refused as changed totals, and the right one is judged on the goods.
	 *
	 * @since 0.2.0
	 */
	public function test_a_clients_figure_is_never_measured(): void {
		$this->setLimits( array( 'USD' => array( self::USD_PRICE + 1, null ) ) );
		$this->cartIn( 'USD' );

		$input = $this->placeInput( 'client-figure' );

		try {
			$this->freshPlacement()->place( array( 'grand_total_minor' => self::USD_PRICE + 1 ) + $input, self::guest() );
			$this->fail( 'A grand total the cart does not come to was accepted.' );
		} catch ( CodedException $refused ) {
			$this->assertSame( CheckoutError::TotalsChanged, $refused->errorCode() );
		}

		$this->assertSame( self::USD_PRICE, $this->refused( 'client-figure-2' )->details()['amount_minor'] ?? null, 'The goods are measured, never the grand total the client agreed to.' );
	}

	/**
	 * Tests that the calculation a re-plan places with is judged too.
	 *
	 * A request reads the store's limits once, and a re-plan reaches the same totals as the plan it
	 * replaces, so the test changes what the request read between the two plans: another checkout
	 * takes the planned unit, and the limits read become a minimum the goods are under. The first
	 * plan was within the limits; the re-plan must be refused.
	 *
	 * @since 0.2.0
	 */
	public function test_the_re_plan_is_judged(): void {
		$variant = $this->sellable( 1, self::USD_PRICE );
		$second  = $this->plantLocation( 5 );

		$this->stockAt( $variant, $second, 1 );
		$this->readyCart( array( $variant => 1 ) );

		$placement = $this->freshPlacement();
		$rules     = $this->kernel->get( CheckoutRules::class );
		$input     = $this->placeInput( 're-plan' );
		$b         = $this->secondConnection();
		$barrier   = $this->beforeStatement(
			'/^UPDATE `[^`]+stock_items` SET held = held \+ /',
			function () use ( $b, $variant, $rules ): void {
				// Another checkout takes the unit at the default location, so the first unit is refused and the placement plans again.
				$b->query( sprintf( 'UPDATE `%s` SET held = held + 1 WHERE variant_id = %d AND location_id = %d', $this->table( 'stock_items' ), $variant, $this->defaultLocation() ) );

				( function (): void {
					$this->limits = array(
						'USD' => array(
							'max' => null,
							'min' => 999999,
						),
					);
				} )->call( $rules );
			}
		);

		try {
			$placement->place( $input, self::guest() );
			$this->fail( 'The re-plan was placed outside the limits.' );
		} catch ( CodedException $refused ) {
			$this->assertSame( CheckoutError::SubtotalBelowMinimum, $refused->errorCode() );
		}

		$this->assertTrue( $barrier->fired, 'The first unit was never reached, so nothing was planned again.' );
		$this->assertNothingPlaced();
	}

	/**
	 * Tests the staff's refusals of a limit: a currency SEOCart does not know, a currency twice, a minimum above the maximum; each names the currency and why, and saves nothing.
	 *
	 * @since 0.2.0
	 */
	public function test_the_staff_write_refuses_a_limit_it_cannot_keep(): void {
		$service = $this->kernel->get( CheckoutSettingsService::class );
		$cases   = array(
			'unknown_currency' => array( self::limit( 'ZZZ', 1, null ) ),
			'duplicate'        => array( self::limit( 'USD', 1, null ), self::limit( 'USD', null, 5 ) ),
			'min_above_max'    => array( self::limit( 'EUR', 501, 500 ) ),
		);

		foreach ( $cases as $reason => $limits ) {
			try {
				$service->updateOrderLimits( array( 'limits' => $limits ), Actor::user( 1 ) );
				$this->fail( "{$reason} was saved." );
			} catch ( CodedException $refused ) {
				$this->assertSame( CheckoutError::InvalidOrderLimits, $refused->errorCode() );
				$this->assertSame(
					array(
						'currency' => $limits[ count( $limits ) - 1 ]['currency'],
						'reason'   => $reason,
					),
					$refused->context()
				);
			}
		}

		$this->assertFalse( get_option( 'seocart_checkout_order_limits' ), 'A refused limit was saved.' );
		$this->assertSame(
			array(
				'limits' => array(
					array(
						'currency'           => 'EUR',
						'min_subtotal_minor' => null,
						'max_subtotal_minor' => 500,
					),
					array(
						'currency'           => 'USD',
						'min_subtotal_minor' => 1,
						'max_subtotal_minor' => null,
					),
				),
			),
			$service->updateOrderLimits( array( 'limits' => array( self::limit( 'USD', 1, null ), self::limit( 'EUR', null, 500 ), self::limit( 'GBP', null, null ) ) ), Actor::user( 1 ) ),
			'Kept by currency code; an entry without a bound is none.'
		);
	}

	/**
	 * Returns one limit as the staff write takes it.
	 *
	 * @since 0.2.0
	 *
	 * @param string   $currency The currency.
	 * @param int|null $min      The minimum.
	 * @param int|null $max      The maximum.
	 * @return array{currency: string, min_subtotal_minor: int|null, max_subtotal_minor: int|null} The limit.
	 */
	private static function limit( string $currency, ?int $min, ?int $max ): array {
		return array(
			'currency'           => $currency,
			'min_subtotal_minor' => $min,
			'max_subtotal_minor' => $max,
		);
	}

	/**
	 * Stores the order limits, as the staff write keeps them.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, array{0: int|null, 1: int|null}> $limits The minimum and maximum, by currency.
	 */
	private function setLimits( array $limits ): void {
		$list = array();

		foreach ( $limits as $currency => $bounds ) {
			$list[] = self::limit( $currency, $bounds[0], $bounds[1] );
		}

		delete_option( 'seocart_checkout_order_limits' );
		$this->freshPlacement();
		$this->kernel->get( CheckoutSettingsService::class )->updateOrderLimits( array( 'limits' => $list ), Actor::user( 1 ) );
		$this->freshPlacement();
	}

	/**
	 * Starts a cart of one line in a currency, with a complete checkout: USD priced net, EUR gross.
	 *
	 * @since 0.2.0
	 *
	 * @param string   $currency USD or EUR.
	 * @param string[] $codes    Optional. Promotion codes to apply. Default none.
	 *
	 * @phpstan-param list<string> $codes
	 */
	private function cartIn( string $currency, array $codes = array() ): void {
		$this->variant = $this->sellable( 5, self::USD_PRICE );

		$this->readyCart( array( $this->variant => 1 ), $codes );

		if ( 'EUR' === $currency ) {
			$this->switchToEur();
		}
	}

	/**
	 * Sells in EUR, the cart's variant priced 24.00 EUR with tax, and moves the open cart to it.
	 *
	 * @since 0.2.0
	 */
	private function switchToEur(): void {
		$this->enableCurrency( 'EUR' );
		self::ratesOver( $this->db, static function (): void {} )->saveVersion( array( self::rateTo( 'EUR', '0.90000' ) ), Actor::user( 0 ) );
		$this->plantBootRecord( 1 );

		$this->bootRecordPlanted = true;

		$this->db->execute( "INSERT INTO %i ( variant_id, currency, amount_basis, price_minor, created_at, updated_at ) VALUES ( %d, 'EUR', 'gross', %d, UTC_TIMESTAMP(), UTC_TIMESTAMP() )", $this->table( 'variant_prices' ), $this->variant, self::EUR_PRICE );
		$this->assertSame( 1, $this->db->execute( "UPDATE %i SET currency = 'EUR' WHERE status = 'open'", $this->table( CartTables::CARTS ) ), 'One cart is open.' );
		$this->freshPlacement();
	}

	/**
	 * Writes the open cart's checkout again with the kernel's session write, and returns its answer.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, mixed> The answer.
	 */
	private function writeAgain(): array {
		$this->freshPlacement();

		$cart = $this->kernel->get( CartService::class )->current();

		$this->assertNotNull( $cart );

		return $this->kernel->get( UpdateCheckoutSession::class )->update(
			array(
				'cart_version'       => $cart->version,
				'billing_address'    => self::wireAddress( Carrier::BILLING, 'US', 'ada@example.com' ),
				'shipping_address'   => self::wireAddress( Carrier::SHIPPING, 'US' ),
				'payment_method_key' => StubGateway::ID,
			),
			self::guest()
		);
	}

	/**
	 * Places the open cart, expecting a refusal, and returns it.
	 *
	 * @since 0.2.0
	 *
	 * @param string $key The idempotency key.
	 * @return CodedException The refusal.
	 */
	private function refused( string $key ): CodedException {
		try {
			$this->freshPlacement()->place( $this->placeInput( $key ), self::guest() );
		} catch ( CodedException $refused ) {
			return $refused;
		}

		$this->fail( 'The placement was not refused.' );
	}

	/**
	 * Asserts that nothing was placed: no order, and no key claimed.
	 *
	 * @since 0.2.0
	 */
	private function assertNothingPlaced(): void {
		$b = $this->secondConnection();

		$this->assertSame( 0, $this->committedCount( $b, OrderTables::ORDERS ), 'An order was written.' );
		$this->assertSame( 0, $this->committedCount( $b, CheckoutTables::IDEMPOTENCY_KEYS ), 'The key was claimed.' );
	}
}
