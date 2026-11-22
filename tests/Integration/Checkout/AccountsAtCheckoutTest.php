<?php
/**
 * Tests the store's choice of accounts at checkout: how it is written and read, and the placement it refuses
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Checkout;

use SEOCart\Application\Operations\RestBinding;
use SEOCart\Cart\Infrastructure\CartTables;
use SEOCart\Cart\Interfaces\StoreApi\CartOperations;
use SEOCart\Checkout\Application\CheckoutSettings;
use SEOCart\Checkout\Domain\AccountsAtCheckout;
use SEOCart\Checkout\Domain\CheckoutError;
use SEOCart\Checkout\Domain\Fields\Carrier;
use SEOCart\Checkout\Infrastructure\CheckoutTables;
use SEOCart\Checkout\Interfaces\StoreApi\CheckoutOperations;
use SEOCart\Customer\Infrastructure\CustomerTables;
use SEOCart\Order\Infrastructure\OrderTables;
use SEOCart\Payment\Infrastructure\Gateway\StubGateway;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Platform\Settings\SettingsOperations;
use SEOCart\Support\Error\CodedException;
use SEOCart\Tests\Support\Cart\ServesStoreApi;
use SEOCart\Tests\Support\Checkout\ChecksOutOverTheWire;
use SEOCart\Tests\Support\Checkout\PlacementTestCase;
use SEOCart\Tests\Support\CreatesUsers;
use SEOCart\Tests\Support\GrantsCapabilities;

/**
 * One setting, `accounts` in the `checkout` group: written and shown by the settings operations, answered by the checkout form, `optional` on a store that never chose; `required` refuses a placement by anyone but a signed-in person, and nothing else.
 *
 * Only the placement asks the choice, and it asks who acts, never the address the checkout holds
 * (the email paths' test holds an account's address and a fresh one to the same refusal). A
 * signed-in customer's order is theirs under every choice: `guest_only` is what a storefront
 * shows, not a rule of the server.
 *
 * @since 0.2.0
 */
final class AccountsAtCheckoutTest extends PlacementTestCase {

	use ChecksOutOverTheWire;
	use CreatesUsers;
	use GrantsCapabilities;
	use ServesStoreApi;

	/**
	 * Wires the kernel and boots a spy server.
	 *
	 * @since 0.2.0
	 */
	public function set_up(): void {
		parent::set_up();

		$this->bootStoreApi();
	}

	/**
	 * Restores the request globals, the store's choices and the users.
	 *
	 * @since 0.2.0
	 */
	public function tear_down(): void {
		wp_set_current_user( 0 );
		$this->shutStoreApi();
		$this->forgetCheckoutChoices();
		$this->deleteCreatedUsers();

		parent::tear_down();
	}

	/**
	 * Tests that a store that never chose reads `optional`, on the form and among the settings, with no migration.
	 *
	 * @since 0.2.0
	 */
	public function test_a_store_that_never_chose_reads_optional(): void {
		$this->assertFalse( get_option( self::option() ), 'The store has saved no choice.' );

		$form = $this->store( 'GET', CheckoutOperations::FORM_ROUTE );

		$this->assertSame( 200, $form['status'], (string) wp_json_encode( $form['body'] ) );
		$this->assertSame( 'optional', $form['body'][ CheckoutSettings::ACCOUNTS ] ?? null );
		$this->assertSame( 'optional', $this->settings( 'GET' )['body'][ CheckoutSettings::ACCOUNTS ] ?? null );
	}

	/**
	 * Tests that the settings operations write the choice, show it, and refuse a value it does not name, and that the form read answers what was written.
	 *
	 * @since 0.2.0
	 */
	public function test_the_settings_operations_write_the_choice_the_form_answers(): void {
		$written = $this->settings( 'PATCH', array( CheckoutSettings::ACCOUNTS => 'required' ) );

		$this->assertSame( 200, $written['status'], (string) wp_json_encode( $written['body'] ) );
		$this->assertSame( 'required', $written['body'][ CheckoutSettings::ACCOUNTS ] ?? null );
		$this->assertSame( 'required', get_option( self::option() ) );

		$refused = $this->settings( 'PATCH', array( CheckoutSettings::ACCOUNTS => 'members_only' ) );

		$this->assertSame( 400, $refused['status'], (string) wp_json_encode( $refused['body'] ) );
		$this->assertSame( 'rest_invalid_param', $refused['body']['code'] ?? null );
		$this->assertSame( 'required', get_option( self::option() ), 'A refused value changes nothing.' );

		$this->newRequest();

		$this->assertSame( 'required', $this->store( 'GET', CheckoutOperations::FORM_ROUTE )['body'][ CheckoutSettings::ACCOUNTS ] ?? null );
		$this->assertSame( 'required', $this->settings( 'GET' )['body'][ CheckoutSettings::ACCOUNTS ] ?? null );

		$this->assertSame( 200, $this->settings( 'PATCH', array( CheckoutSettings::ACCOUNTS => 'guest_only' ) )['status'] );
		$this->newRequest();
		$this->assertSame( 'guest_only', $this->store( 'GET', CheckoutOperations::FORM_ROUTE )['body'][ CheckoutSettings::ACCOUNTS ] ?? null );
	}

	/**
	 * Tests that where the store requires an account, a visitor's placement is refused 401 before anything is written, while the visitor's cart and checkout writes are never refused.
	 *
	 * @since 0.2.0
	 */
	public function test_required_refuses_a_visitors_placement_and_nothing_else(): void {
		$this->chooseAccounts( AccountsAtCheckout::Required );
		$this->cartOverTheWire();

		$written = $this->writeOverTheWire( self::checkoutBody() );

		$this->assertSame( 200, $written['status'], 'The session write is never refused: ' . (string) wp_json_encode( $written['body'] ) );

		$before  = $this->written();
		$refused = $this->placeOverTheWire( $written['body'], 'a visitor\'s attempt' );

		$this->assertSame( 401, $refused['status'], (string) wp_json_encode( $refused['body'] ) );
		$this->assertErrorShape( $refused['body'], 'checkout.account_required', 401 );
		$this->assertSame( $before, $this->written(), 'Nothing was written: no order, no key, no record, and the cart is still open.' );

		$this->newRequest();

		$added = $this->store( 'POST', CartOperations::LINES_ROUTE, self::linesBody( array( $this->sellable() => 1 ), (int) $written['body']['version'] ) );

		$this->assertSame( 200, $added['status'], 'The cart\'s writes are never refused: ' . (string) wp_json_encode( $added['body'] ) );
	}

	/**
	 * Tests that an order placed by a signed-in customer is theirs whatever the store chose, and that a process acting for a user is no person where an account is required.
	 *
	 * Planted violation: in PlaceOrder::placeInside(), ask for the customer only when the store's
	 * choice is not `guest_only`: the order placed under `guest_only` has no customer.
	 *
	 * @since 0.2.0
	 */
	public function test_a_signed_in_customers_order_is_theirs_whatever_the_store_chose(): void {
		$user      = $this->createUser( 'subscriber' );
		$customers = array();

		foreach ( AccountsAtCheckout::cases() as $choice ) {
			$this->chooseAccounts( $choice );
			$this->readyCart( array( $this->sellable() => 1 ) );

			$answer = $this->freshPlacement()->place( $this->placeInput( 'signed in, ' . $choice->value ), Actor::user( $user ) );

			$this->assertSame( 'approved', $answer['outcome'], $choice->value );

			$customers[ $choice->value ] = $this->db->fetchValue( 'SELECT customer_id FROM %i WHERE uuid = %s', $this->table( OrderTables::ORDERS ), (string) $answer['order_uuid'] );
		}

		$this->assertNotNull( $customers['optional'] );
		$this->assertSame( array_fill_keys( array( 'optional', 'required', 'guest_only' ), $customers['optional'] ), $customers, 'Each order belongs to the customer\'s one record.' );

		$this->readyCart( array( $this->sellable() => 1 ) );
		$this->chooseAccounts( AccountsAtCheckout::Required );

		try {
			$this->freshPlacement()->place( $this->placeInput( 'a command' ), Actor::system( 'cli', $user ) );
			$this->fail( 'A process acting for a user placed an order where an account is required.' );
		} catch ( CodedException $refused ) {
			$this->assertSame( CheckoutError::AccountRequired, $refused->errorCode() );
		}
	}

	/**
	 * Tests that a visitor's retry of a placement made before the store required an account is answered as it was, and that a new attempt is refused.
	 *
	 * Planted violation: in PlaceOrder::place(), refuse a visitor before the kept answer is read:
	 * the retry is refused.
	 *
	 * @since 0.2.0
	 */
	public function test_a_visitors_retry_of_an_earlier_placement_is_answered(): void {
		$this->readyCart( array( $this->sellable() => 1 ) );

		$input = $this->placeInput( 'before the store chose' );
		$first = $this->freshPlacement()->place( $input, self::guest() );

		$this->assertSame( 'approved', $first['outcome'] );

		$this->chooseAccounts( AccountsAtCheckout::Required );

		$again = $this->freshPlacement()->place( $input, self::guest() );

		$this->assertSame( array( $first['order_uuid'], 'approved' ), array( $again['order_uuid'], $again['outcome'] ), 'The retry gets its first answer.' );

		$this->readyCart( array( $this->sellable() => 1 ) );

		try {
			$this->freshPlacement()->place( $this->placeInput( 'after the store chose' ), self::guest() );
			$this->fail( 'A visitor\'s new placement was taken where an account is required.' );
		} catch ( CodedException $refused ) {
			$this->assertSame( CheckoutError::AccountRequired, $refused->errorCode() );
			$this->assertSame( array(), $refused->details(), 'The refusal names nothing.' );
		}
	}

	/**
	 * Returns what a placement writes: the orders, the idempotency keys and the customer records, counted, and the carts that are not open.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, int> The counts.
	 */
	private function written(): array {
		return array(
			'orders'      => (int) $this->db->fetchValue( 'SELECT COUNT(*) FROM %i', $this->table( OrderTables::ORDERS ) ),
			'keys'        => (int) $this->db->fetchValue( 'SELECT COUNT(*) FROM %i', $this->table( CheckoutTables::IDEMPOTENCY_KEYS ) ),
			'customers'   => (int) $this->db->fetchValue( 'SELECT COUNT(*) FROM %i', $this->table( CustomerTables::CUSTOMERS ) ),
			'carts_moved' => (int) $this->db->fetchValue( 'SELECT COUNT(*) FROM %i WHERE status <> %s', $this->table( CartTables::CARTS ), 'open' ),
		);
	}

	/**
	 * Returns a session write's body: two complete addresses and the stand-in's payment method.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, mixed> The body, without the cart's version.
	 */
	private static function checkoutBody(): array {
		return array(
			'billing_address'    => self::wireAddress( Carrier::BILLING, 'US', 'ada@example.com' ),
			'shipping_address'   => self::wireAddress( Carrier::SHIPPING, 'US' ),
			'payment_method_key' => StubGateway::ID,
		);
	}

	/**
	 * Serves a request to the settings route, as a user who may manage the settings.
	 *
	 * @since 0.2.0
	 *
	 * @param string               $method The HTTP method.
	 * @param array<string, mixed> $body   Optional. The body. Default none.
	 * @return array{status: int|null, headers: array<string, string>, body: array<string, mixed>} What was sent.
	 */
	private function settings( string $method, array $body = array() ): array {
		wp_set_current_user( $this->userGranted( SettingsOperations::CAPABILITY )->userId() );

		$sent = $this->serve( $this->server, $method, '/' . RestBinding::NAMESPACE . SettingsOperations::ROUTE, array(), $body );

		wp_set_current_user( 0 );

		return $sent;
	}

	/**
	 * Returns the option the choice is stored in.
	 *
	 * @since 0.2.0
	 *
	 * @return string The option's name.
	 */
	private static function option(): string {
		foreach ( CheckoutSettings::settings() as $setting ) {
			if ( CheckoutSettings::ACCOUNTS === $setting->name() ) {
				return $setting->optionName();
			}
		}

		self::fail( 'The checkout group has no choice of accounts.' );
	}
}
