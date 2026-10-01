<?php
/**
 * CurrencySwitchTestCase: the base of the tests that switch a cart's currency, in a store that sells in several
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support\Checkout;

use SEOCart\Cart\Application\CartService;
use SEOCart\Cart\Application\CurrencyChangeLimit;
use SEOCart\Cart\Domain\Cart;
use SEOCart\Cart\Infrastructure\CartTables;
use SEOCart\Cart\Infrastructure\MysqlCartRepository;
use SEOCart\Catalog\Infrastructure\CatalogTables;
use SEOCart\Checkout\Application\ChangeCartCurrency;
use SEOCart\Checkout\Application\PlaceOrder;
use SEOCart\Checkout\Domain\FrozenQuotes;
use SEOCart\Checkout\Infrastructure\CheckoutTables;
use SEOCart\Payment\Domain\Gateway\PaymentGateway;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Platform\RateLimiter\RateCountersTable;
use SEOCart\Pricing\Infrastructure\Quotes\FlatRateShippingQuoter;
use SEOCart\Support\Error\CodedException;
use SEOCart\Support\Error\ErrorCode;
use SEOCart\Support\RoundingMode;
use SEOCart\Tests\Support\Doubles\RecordingGateway;
use SEOCart\Tests\Support\Pricing\PricesInCurrencies;
use SEOCart\Tests\Support\SecondConnection;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- The base plants prices and a session's method directly, and reads them back through a second connection.

/**
 * A PlacementTestCase in a USD store that also sells in EUR and GBP, with the currency switch wired as the kernel wires it.
 *
 * Owns one fact: the currencies a switch test meets. EUR is enabled with a rate, and base prices
 * convert into it; GBP is enabled with a rate, and sells only what is priced in GBP; CHF is
 * enabled with no rate in the current version; JPY has a rate but is not enabled. The rates are
 * saved as one version, which the installation record names as current, as on an installed site;
 * the kernel is built again once it does, so nothing in it read the record before.
 *
 * @since 0.1.0
 */
abstract class CurrencySwitchTestCase extends PlacementTestCase {

	use PricesInCurrencies;

	/**
	 * The rate from USD to EUR.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	protected const EUR_RATE = '0.91230';

	/**
	 * The currencies the store does not sell in, each for its own reason: not enabled, enabled without a current rate, and not a currency code as ISO 4217 writes one.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, string>
	 */
	protected const NOT_SOLD = array(
		'not enabled'     => 'JPY',
		'no current rate' => 'CHF',
		'malformed'       => 'eur',
	);

	/**
	 * The currency switch over `$this->db`.
	 *
	 * @since 0.1.0
	 *
	 * @var ChangeCartCurrency
	 */
	protected ChangeCartCurrency $switcher;

	/**
	 * The current exchange-rate version.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	protected int $rateVersion = 0;

	/**
	 * Enables the currencies, saves their rates as the current version, and builds the kernel again.
	 *
	 * @since 0.1.0
	 */
	public function set_up(): void {
		parent::set_up();

		$this->enableCurrency( 'EUR' );
		$this->enableCurrency( 'GBP', false );
		$this->enableCurrency( 'CHF' );
		$this->enableCurrency( 'JPY', true, RoundingMode::HalfUp, 0, false );

		$this->rateVersion = self::ratesOver( $this->db, static function (): void {} )->saveVersion(
			array( self::rateTo( 'EUR', self::EUR_RATE ), self::rateTo( 'GBP', '0.78500' ), self::rateTo( 'JPY', '149.50' ) ),
			Actor::user( 0 )
		);

		$this->plantBootRecord( $this->rateVersion );

		$this->kernel    = $this->kernelOver( $this->db, $this->tokens );
		$this->placement = $this->kernel->get( PlaceOrder::class );
		$this->switcher  = $this->kernel->get( ChangeCartCurrency::class );
		$gateway         = $this->kernel->get( PaymentGateway::class );

		$this->assertInstanceOf( RecordingGateway::class, $gateway );

		$this->gateway = $gateway;
	}

	/**
	 * Restores the installation record.
	 *
	 * @since 0.1.0
	 */
	public function tear_down(): void {
		$this->removeBootRecord();

		parent::tear_down();
	}

	/**
	 * Switches the request's cart through the kernel's switch, as a guest.
	 *
	 * @since 0.1.0
	 *
	 * @param string $currency The currency to switch to.
	 * @param int    $version  The cart version the switch is based on.
	 * @return array<string, mixed> The answer, by wire name.
	 */
	protected function switchTo( string $currency, int $version ): array {
		return $this->switcher->change(
			array(
				'cart_version' => $version,
				'currency'     => $currency,
			),
			self::guest()
		);
	}

	/**
	 * Gives variants a net price authored in another currency, straight into the catalog's price table.
	 *
	 * @since 0.1.0
	 *
	 * @param string          $currency    The currency.
	 * @param array<int, int> $pricesMinor The price of each variant, in minor units, by variant id.
	 */
	protected function priceIn( string $currency, array $pricesMinor ): void {
		foreach ( $pricesMinor as $variantId => $priceMinor ) {
			$this->db->execute(
				"INSERT INTO %i ( variant_id, currency, amount_basis, price_minor, created_at, updated_at ) VALUES ( %d, %s, 'net', %d, UTC_TIMESTAMP(), UTC_TIMESTAMP() )",
				$this->table( CatalogTables::VARIANT_PRICES ),
				$variantId,
				$currency,
				$priceMinor
			);
		}
	}

	/**
	 * Chooses the flat rate on the cart's checkout, and freezes the quotes of a calculation of the cart as it is, as a placement keeps them.
	 *
	 * @since 0.1.0
	 *
	 * @param Cart $cart The cart, with its checkout.
	 */
	protected function freezeQuotes( Cart $cart ): void {
		$this->db->execute( 'UPDATE %i SET shipping_method_key = %s WHERE cart_id = %d', $this->table( CheckoutTables::SESSIONS ), FlatRateShippingQuoter::METHOD_KEY, $cart->id );

		$calculation = $this->kernel->get( CartService::class )->calculation( $cart );

		$this->assertTrue( $this->sessions->storeQuotes( $cart->id, $cart->version, new FrozenQuotes( $calculation->selectedShippingRate(), $calculation->taxQuoteFingerprint() ) ) );
	}

	/**
	 * Reads a cart's committed version, status and currency, with its session's method and quote columns, as connection B sees them.
	 *
	 * @since 0.1.0
	 *
	 * @param SecondConnection $b      Connection B.
	 * @param int              $cartId The cart.
	 * @return array<string, string|null> The columns by name, as the server sends them; empty when there is no cart.
	 */
	protected function committedSwitch( SecondConnection $b, int $cartId ): array {
		return $b->fetchRow( sprintf( 'SELECT c.version, c.status, c.currency, s.shipping_method_key, s.quoted_at_cart_version, s.shipping_quote_json, s.tax_quote_fingerprint FROM `%s` c LEFT JOIN `%s` s ON s.cart_id = c.id WHERE c.id = %d', $this->table( CartTables::CARTS ), $this->table( CheckoutTables::SESSIONS ), $cartId ) ) ?? array();
	}

	/**
	 * Counts the switches counted against every cart's cap, as connection B sees them.
	 *
	 * @since 0.1.0
	 *
	 * @param SecondConnection $b Connection B.
	 * @return int The count.
	 */
	protected function switchesCounted( SecondConnection $b ): int {
		return (int) $b->fetchValue( sprintf( "SELECT COALESCE( SUM( count ), 0 ) FROM `%s` WHERE scope = '%s'", $this->table( RateCountersTable::NAME ), CurrencyChangeLimit::BUCKET ) );
	}

	/**
	 * Asserts that work is refused with a code, and returns the refusal.
	 *
	 * @since 0.1.0
	 *
	 * @param ErrorCode $code    The code.
	 * @param \Closure  $work    The work.
	 * @param string    $message Optional. What is being checked. Default empty.
	 * @return CodedException The refusal.
	 */
	protected function assertRefused( ErrorCode $code, \Closure $work, string $message = '' ): CodedException {
		try {
			$work();
		} catch ( CodedException $refused ) {
			$this->assertSame( $code, $refused->errorCode(), $message . ': ' . $refused->getMessage() );

			return $refused;
		}

		$this->fail( sprintf( 'The work was not refused with %s. %s', $code->value, $message ) );
	}

	/**
	 * Returns the switch's compare-and-swap, prepared for connection B from the repository's own constant.
	 *
	 * @since 0.1.0
	 *
	 * @param int    $cartId   The cart.
	 * @param int    $version  The version it is based on.
	 * @param string $currency The currency it writes.
	 * @return string The statement.
	 */
	protected function rawSwitch( int $cartId, int $version, string $currency ): string {
		return $this->raw( MysqlCartRepository::SWAP_CURRENCY, $this->table( CartTables::CARTS ), $currency, self::TTL_SECONDS, $cartId, $version );
	}
}
