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
use SEOCart\Checkout\Application\CheckoutSettings;
use SEOCart\Checkout\Interfaces\StoreApi\CheckoutOperations;
use SEOCart\Platform\Settings\SettingsOperations;
use SEOCart\Tests\Support\Cart\ServesStoreApi;
use SEOCart\Tests\Support\Checkout\ChecksOutOverTheWire;
use SEOCart\Tests\Support\Checkout\PlacementTestCase;
use SEOCart\Tests\Support\CreatesUsers;
use SEOCart\Tests\Support\GrantsCapabilities;

/**
 * One setting, `accounts` in the `checkout` group: written and shown by the settings operations, answered by the checkout form, `optional` on a store that never chose.
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
