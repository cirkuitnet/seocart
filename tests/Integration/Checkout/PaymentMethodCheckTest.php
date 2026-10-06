<?php
/**
 * Tests that a checkout's payment method must be a gateway that can take the payment: at the session write, and at placement before anything is written
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Checkout;

use SEOCart\Checkout\Application\PlaceOrder;
use SEOCart\Checkout\Application\UpdateCheckoutSession;
use SEOCart\Checkout\Domain\CheckoutError;
use SEOCart\Contracts\Payment\CapabilityMatrix;
use SEOCart\Contracts\Payment\GatewayDescriptor;
use SEOCart\Contracts\Payment\GatewayRegistry;
use SEOCart\Contracts\Payment\MatrixRow;
use SEOCart\Contracts\Payment\Mode;
use SEOCart\Contracts\Payment\Operations;
use SEOCart\Inventory\Infrastructure\InventoryTables;
use SEOCart\Order\Infrastructure\OrderTables;
use SEOCart\Payment\Application\Gateways;
use SEOCart\Payment\Application\GatewaySettingsDeclaration;
use SEOCart\Payment\Application\PaymentError;
use SEOCart\Payment\Infrastructure\PaymentTables;
use SEOCart\Platform\Logging\Migrations\CreateLogsMigration;
use SEOCart\Platform\Database\Schema\DdlGenerator;
use SEOCart\Platform\Database\Schema\SchemaVerifier;
use SEOCart\Platform\Database\SchemaOperations;
use SEOCart\Platform\Settings\SettingsStore;
use SEOCart\Support\Currency;
use SEOCart\Support\Error\CodedException;
use SEOCart\Support\Money;
use SEOCart\Support\Schema\FieldSpec;
use SEOCart\Support\Schema\FieldType;
use SEOCart\Tests\Support\Checkout\PlacementTestCase;
use SEOCart\Tests\Support\Doubles\DeclaredGateway;
use SEOCart\Tests\Support\Payment\GatewayKernel;

/**
 * The session write accepts a payment method only when it is a gateway the store has, configured for the mode it takes new payments in; placement, which knows the order's currency and total, refuses a method that cannot take the payment any more, `checkout.payment_method_unavailable`, before the order, its hold or its intent is written; and the intent is created in the gateway's mode.
 *
 * The matrix is checked once more right before the gateway is asked to authorize: a cell that
 * stops being declared after placement's check, because the account moved to another country,
 * sends nothing, and the order waits as placed, as it does when the gateway cannot be reached.
 *
 * Planted violations, each shown red and removed:
 * - in PlaceOrder::place(), skip paymentMode() and create the intent in test mode: the placement
 *   through a gateway its matrix refuses is then made;
 * - in UpdateCheckoutSession::paymentMethod(), drop the registry's check: an unknown method is
 *   then saved;
 * - in PaymentService::authorize(), drop the require() before the call: the gateway is asked to
 *   authorize a payment its matrix no longer declares;
 * - in CapabilityMatrix::__construct(), refuse a matrix without rows again: the gateway that
 *   declares nothing is not registered, and the test finds no descriptor for it.
 *
 * @since 0.2.0
 */
final class PaymentMethodCheckTest extends PlacementTestCase {

	/**
	 * The gateway plugins this test registers, each through the action.
	 *
	 * @since 0.2.0
	 *
	 * @var array<string, DeclaredGateway>
	 */
	private array $plugins = array();

	/**
	 * Creates the log, which the kernel's reports go to, and has the test's gateways register.
	 *
	 * @since 0.2.0
	 */
	public function set_up(): void {
		parent::set_up();

		( new CreateLogsMigration() )->up( new SchemaOperations( $this->db, new DdlGenerator(), new SchemaVerifier( $this->db ) ) );

		add_action(
			GatewayRegistry::ACTION,
			function ( GatewayRegistry $registry ): void {
				foreach ( $this->plugins as $gateway ) {
					$registry->register( $gateway );
				}
			}
		);
	}

	/**
	 * Deletes the gateway settings documents a test committed.
	 *
	 * @since 0.2.0
	 */
	public function tear_down(): void {
		GatewayKernel::deleteOptions();

		parent::tear_down();
	}

	/**
	 * Tests that the session write refuses a method that is no gateway, and one that is not configured for its mode, before anything is written.
	 *
	 * @since 0.2.0
	 */
	public function test_the_session_write_accepts_only_a_gateway_set_up_for_its_mode(): void {
		$this->plugins['unset'] = new DeclaredGateway( DeclaredGateway::descriptor( 'unset' ) );

		$cart = $this->startCart( array( $this->sellable() => 1 ) );

		foreach ( array( 'nope', 'unset' ) as $method ) {
			try {
				$this->writeCheckout( $cart->version, $method );
				$this->fail( "The payment method {$method} was saved." );
			} catch ( CodedException $refused ) {
				$this->assertSame( array( CheckoutError::InvalidMethodKey, array( 'field' => 'payment_method_key' ) ), array( $refused->errorCode(), $refused->context() ), $method );
			}
		}

		$this->assertSame( $cart->version, $this->service->current()?->version, 'Nothing was written.' );
		$this->writeCheckout( $cart->version, 'stub' );
		$this->assertSame( $cart->version + 1, $this->service->current()?->version, 'The stand-in is set up.' );
	}

	/**
	 * Tests that placement refuses, before anything is written, a method whose matrix has no row for the order's currency, and one that says itself it cannot take the payment; and that once it can, the intent is created in its mode.
	 *
	 * @since 0.2.0
	 */
	public function test_placement_refuses_a_method_that_cannot_take_the_payment_before_writing(): void {
		$picky                  = new DeclaredGateway( DeclaredGateway::descriptor( 'picky', array( Mode::Test ), array(), DeclaredGateway::matrix( array( 'GBP' ), Operations::REQUIRED ) ) );
		$this->plugins['picky'] = $picky;

		$cart = $this->startCart( array( $this->sellable() => 1 ) );

		$this->writeCheckout( $cart->version, 'picky' );
		$this->assertRefusedBeforeWriting( 'a currency without a row' );

		$this->plugins['picky']            = new DeclaredGateway( DeclaredGateway::descriptor( 'picky', array( Mode::Test ), array(), DeclaredGateway::matrix( array( 'USD' ), Operations::REQUIRED ) ) );
		$this->plugins['picky']->available = false;
		$this->assertRefusedBeforeWriting( 'a gateway that says no' );

		$this->plugins['picky']->available = true;

		$placed = $this->freshPlacement()->place( $this->placeInput(), self::guest() );

		$this->assertSame( array( 'approved', 'authorized' ), array( $placed['outcome'], $placed['payment_status'] ) );
		$this->assertSame( array( 'picky', 'test' ), $this->intentOf( (string) $placed['order_uuid'] ) );
	}

	/**
	 * Tests that a gateway that declares nothing is registered, is never available in any currency, and is refused at placement before anything is written.
	 *
	 * The session write checks only that the method is a gateway the store has, configured for its
	 * mode, which does not depend on the payment: it saves the method, and placement, which knows
	 * the order's currency and total, refuses it.
	 *
	 * @since 0.2.0
	 */
	public function test_a_gateway_that_declares_nothing_is_registered_and_never_offered(): void {
		$this->plugins['nothing'] = new DeclaredGateway( DeclaredGateway::descriptor( 'nothing', array( Mode::Test ), array(), new CapabilityMatrix( array() ) ) );

		$cart = $this->startCart( array( $this->sellable() => 1 ) );

		$this->writeCheckout( $cart->version, 'nothing' );

		$this->freshPlacement();

		$gateways = $this->kernel->get( Gateways::class );

		$this->assertSame( array(), $gateways->descriptor( 'nothing' )->matrix->rows, 'The gateway is registered, declaring nothing.' );
		$this->assertSame( Mode::Test, $gateways->configuredMode( 'nothing' ) );

		foreach ( array( 'USD', 'GBP', 'EUR' ) as $code ) {
			$this->assertNull( $gateways->availableMode( 'nothing', Money::of( 1000, Currency::of( $code ) ), 'US', 'storefront' ), "Available in {$code}." );
		}

		$this->assertRefusedBeforeWriting( 'a gateway that declares nothing' );
	}

	/**
	 * Tests that a method chosen while its gateway was installed is refused at placement once the gateway is gone.
	 *
	 * @since 0.2.0
	 */
	public function test_a_method_whose_gateway_went_away_is_refused_at_placement(): void {
		$this->plugins['gone'] = new DeclaredGateway( DeclaredGateway::descriptor( 'gone', array( Mode::Test ), array() ) );

		$cart = $this->startCart( array( $this->sellable() => 1 ) );

		$this->writeCheckout( $cart->version, 'gone' );

		$this->plugins = array();

		$this->assertRefusedBeforeWriting( 'a gateway deactivated since' );
	}

	/**
	 * Tests that an authorization whose cell the matrix stops declaring after placement's check is never sent, and the order waits as placed.
	 *
	 * @since 0.2.0
	 */
	public function test_an_authorization_the_matrix_stops_declaring_after_the_check_is_never_sent(): void {
		$country  = new FieldSpec( name: GatewayDescriptor::ACCOUNT_COUNTRY, type: FieldType::String, description: 'The country of the provider account.', label: static fn(): string => 'Account country', example: 'US', max_length: 2 );
		$shifting = new DeclaredGateway( DeclaredGateway::descriptor( 'shifting', array( Mode::Test ), array( $country ), new CapabilityMatrix( array( new MatrixRow( Currency::of( 'USD' ), 'US', Operations::REQUIRED ) ) ) ) );

		$this->plugins['shifting'] = $shifting;
		$this->writeAccountCountry( 'US' );

		$cart = $this->startCart( array( $this->sellable() => 1 ) );

		$this->writeCheckout( $cart->version, 'shifting' );

		// The account moves to another country once placement has found the cell declared, before the gateway is asked.
		$shifting->whenAsked = function (): void {
			$this->writeAccountCountry( 'GB' );
		};

		$order = '';

		try {
			$this->freshPlacement()->place( $this->placeInput(), self::guest() );
			$this->fail( 'The order was placed through a cell the matrix no longer declares.' );
		} catch ( CodedException $refused ) {
			$this->assertSame( CheckoutError::GatewayUnavailable, $refused->errorCode() );
			$this->assertSame( PaymentError::OperationUnsupported, $refused->getPrevious() instanceof CodedException ? $refused->getPrevious()->errorCode() : null );

			$order = (string) ( $refused->details()['order_uuid'] ?? '' );
		}

		$row = $this->db->fetchRow( 'SELECT o.status AS o_status, i.status AS i_status FROM %i o JOIN %i i ON i.order_id = o.id WHERE o.uuid = %s', $this->table( OrderTables::ORDERS ), $this->table( PaymentTables::INTENTS ), $order );

		$this->assertSame( array(), $shifting->calls, 'Nothing was sent to the gateway.' );
		$this->assertSame( array( 'pending_payment', 'created' ), array( (string) $row['o_status'], (string) $row['i_status'] ), 'The order waits as placed, its payment not asked for.' );
	}

	/**
	 * Writes the account country of `shifting`'s test mode, as its settings document holds it.
	 *
	 * @since 0.2.0
	 *
	 * @param string $country The country.
	 */
	private function writeAccountCountry( string $country ): void {
		$store = $this->kernel->get( SettingsStore::class );
		$group = GatewaySettingsDeclaration::group( 'shifting' );
		$name  = GatewaySettingsDeclaration::storedName( 'shifting', Mode::Test, GatewayDescriptor::ACCOUNT_COUNTRY );

		$this->db->transaction(
			static function () use ( $store, $group, $name, $country ): void {
				$document = $store->documentAsStored( $group );

				$store->replaceDocument( $group, $document->version(), array( $name => $country ) + $document->values() );
			}
		);
	}

	/**
	 * Asserts that a placement, in a request of its own, is refused `checkout.payment_method_unavailable` and writes no order, hold or intent.
	 *
	 * @since 0.2.0
	 *
	 * @param string $what What makes the method unavailable, for the message.
	 */
	private function assertRefusedBeforeWriting( string $what ): void {
		try {
			$this->freshPlacement()->place( $this->placeInput( 'attempt-' . md5( $what ) ), self::guest() );
			$this->fail( "The order was placed with {$what}." );
		} catch ( CodedException $refused ) {
			$this->assertSame( CheckoutError::PaymentMethodUnavailable, $refused->errorCode(), $what );
		}

		foreach ( array( OrderTables::ORDERS, PaymentTables::INTENTS, InventoryTables::HOLDS ) as $table ) {
			$this->assertSame( 0, (int) $this->db->fetchValue( 'SELECT COUNT(*) FROM %i', $this->table( $table ) ), "{$table} after {$what}" );
		}
	}

	/**
	 * Writes the request's cart's checkout through the production wiring, with a payment method.
	 *
	 * @since 0.2.0
	 *
	 * @param int    $version The cart's version.
	 * @param string $method  The payment method.
	 */
	private function writeCheckout( int $version, string $method ): void {
		$address = array(
			'country'  => 'US',
			'line1'    => '1 Main Street',
			'city'     => 'Austin',
			'postcode' => '78701',
		);

		$this->kernel->get( UpdateCheckoutSession::class )->update(
			array(
				'cart_version'       => $version,
				'billing_address'    => $address + array(
					'first_name' => 'Ada',
					'last_name'  => 'Lovelace',
					'email'      => 'ada@example.com',
				),
				'shipping_address'   => $address,
				'payment_method_key' => $method,
			),
			self::guest()
		);
	}

	/**
	 * Builds the placement of a new request, whose registry registers the test's gateways as they are now.
	 *
	 * @since 0.2.0
	 *
	 * @return PlaceOrder The placement.
	 */
	private function freshPlacement(): PlaceOrder {
		$this->kernel = $this->kernelOver( $this->db, $this->tokens );

		return $this->kernel->get( PlaceOrder::class );
	}

	/**
	 * Returns the gateway and the mode of an order's intent.
	 *
	 * @since 0.2.0
	 *
	 * @param string $orderUuid The order.
	 * @return list<string> The gateway and the mode.
	 */
	private function intentOf( string $orderUuid ): array {
		$row = $this->db->fetchRow( 'SELECT i.gateway_id, i.mode FROM %i i JOIN %i o ON o.id = i.order_id WHERE o.uuid = %s', $this->table( PaymentTables::INTENTS ), $this->table( OrderTables::ORDERS ), $orderUuid );

		return array( (string) $row['gateway_id'], (string) $row['mode'] );
	}
}
