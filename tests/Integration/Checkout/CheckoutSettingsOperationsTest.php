<?php
/**
 * Tests the staff operations over the checkout's fields and order limits, over the wire
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Checkout;

use SEOCart\Application\Operations\RestBinding;
use SEOCart\Checkout\Application\CheckoutSettings;
use SEOCart\Checkout\Domain\Fields\BuiltInFields;
use SEOCart\Checkout\Interfaces\Rest\CheckoutSettingsOperations;
use SEOCart\Contracts\Checkout\CheckoutField;
use SEOCart\Platform\Settings\Settings;
use SEOCart\Platform\Settings\SettingsStore;
use SEOCart\Tests\Support\Cart\ServesStoreApi;
use SEOCart\Tests\Support\Checkout\CheckoutTestCase;
use SEOCart\Tests\Support\CreatesUsers;
use SEOCart\Tests\Support\GrantsCapabilities;

/**
 * `checkout.get_fields`, `checkout.update_fields`, `checkout.get_order_limits` and `checkout.update_order_limits` on their routes: every field with its presence, a change that keeps every other choice and stores only what differs from a default, the refusals of an unknown field and of a presence a field does not allow, the limits replaced whole, and the capability every one requires.
 *
 * Planted violation: in CheckoutSettingsService::updateFields(), skip the `allows()` check: the
 * email address is made optional, and the presence_not_allowed row fails.
 *
 * @since 0.2.0
 */
final class CheckoutSettingsOperationsTest extends CheckoutTestCase {

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

		foreach ( CheckoutSettings::settings() as $setting ) {
			delete_option( $setting->optionName() );
		}

		$this->deleteCreatedUsers();

		parent::tear_down();
	}

	/**
	 * Tests that the read lists every declared field in form order, hidden ones too, with its default presence on a store that chose nothing.
	 *
	 * @since 0.2.0
	 */
	public function test_the_read_lists_every_field_with_its_presence(): void {
		$read = $this->staff( 'GET', CheckoutSettingsOperations::FIELDS_ROUTE );

		$this->assertSame( 200, $read['status'], (string) wp_json_encode( $read['body'] ) );
		$this->assertSame(
			array_map( static fn( CheckoutField $field ): array => array( $field->key, $field->defaultPresence()->value, BuiltInFields::SOURCE ), BuiltInFields::all() ),
			array_map( static fn( array $field ): array => array( $field['key'], $field['presence'], $field['source'] ), $read['body']['fields'] )
		);
	}

	/**
	 * Tests that a change sets the fields it names, keeps every other choice, stores only what differs from a field's default, and drops a stored choice no declaration names.
	 *
	 * @since 0.2.0
	 */
	public function test_a_change_keeps_every_other_choice(): void {
		$this->assertSame( 200, $this->change( array( 'company' => 'optional' ) )['status'] );
		$this->assertSame( '{"company":"optional"}', $this->stored() );

		$this->assertSame( 200, $this->change( array( 'phone' => 'required' ) )['status'] );
		$this->assertSame( '{"company":"optional","phone":"required"}', $this->stored() );

		$back = $this->change( array( 'company' => 'hidden' ) );

		$this->assertSame( 200, $back['status'] );
		$this->assertSame( '{"phone":"required"}', $this->stored(), 'A field set back to its default is stored as no choice.' );
		$this->assertSame( 'hidden', self::presenceIn( $back['body'], 'company' ) );

		// A choice stored for a field no longer declared is read as nothing, and dropped by the next change.
		( new SettingsStore( Settings::registry(), $this->db ) )->writeScalars( array( CheckoutSettings::FIELD_PRESENCE => '{"gone_field":"required","phone":"required"}' ) );

		$this->assertSame( 200, $this->change( array( 'line2' => 'hidden' ) )['status'] );
		$this->assertSame( '{"line2":"hidden","phone":"required"}', $this->stored() );
	}

	/**
	 * Tests the refusals: a field no declaration names, and a presence the field does not allow, each before anything is written.
	 *
	 * @since 0.2.0
	 */
	public function test_an_unknown_field_and_a_presence_not_allowed_are_refused(): void {
		$unknown = $this->change( array( 'nickname' => 'optional' ) );

		$this->assertErrorShape( $unknown['body'], 'checkout.field_unknown', 422 );
		$this->assertSame( 'nickname', $unknown['body']['data']['details']['field'] ?? null );

		foreach ( array( 'email', 'country', 'last_name', 'line1', 'city' ) as $locked ) {
			$refused = $this->change(
				array(
					'company' => 'optional',
					$locked   => 'optional',
				)
			);

			$this->assertErrorShape( $refused['body'], 'checkout.presence_not_allowed', 422 );
			$this->assertSame( array( $locked, 'optional' ), array( $refused['body']['data']['details']['field'] ?? null, $refused['body']['data']['details']['presence'] ?? null ) );
		}

		$this->assertFalse( get_option( 'seocart_checkout_field_presence' ), 'A refused change wrote something.' );
	}

	/**
	 * Tests the limits over the wire: replaced whole, read back by currency, and a currency left out has none.
	 *
	 * @since 0.2.0
	 */
	public function test_the_limits_are_replaced_whole(): void {
		$first = $this->staff(
			'PUT',
			CheckoutSettingsOperations::LIMITS_ROUTE,
			array(
				'limits' => array(
					array(
						'currency'           => 'USD',
						'min_subtotal_minor' => 2500,
					),
					array(
						'currency'           => 'EUR',
						'max_subtotal_minor' => 90000,
					),
				),
			)
		);

		$this->assertSame( 200, $first['status'], (string) wp_json_encode( $first['body'] ) );

		$second = $this->staff(
			'PUT',
			CheckoutSettingsOperations::LIMITS_ROUTE,
			array(
				'limits' => array(
					array(
						'currency'           => 'GBP',
						'min_subtotal_minor' => 1000,
						'max_subtotal_minor' => 1000,
					),
				),
			)
		);

		$this->assertSame( 200, $second['status'], (string) wp_json_encode( $second['body'] ) );
		$this->assertSame(
			array(
				array(
					'currency'           => 'GBP',
					'min_subtotal_minor' => 1000,
					'max_subtotal_minor' => 1000,
				),
			),
			$this->staff( 'GET', CheckoutSettingsOperations::LIMITS_ROUTE )['body']['limits'] ?? null
		);
	}

	/**
	 * Tests that each of the four routes refuses a visitor and a user without the store settings' capability.
	 *
	 * @since 0.2.0
	 */
	public function test_every_route_requires_the_settings_capability(): void {
		$change = array(
			'fields' => array(
				array(
					'key'      => 'company',
					'presence' => 'optional',
				),
			),
		);
		$routes = array(
			array( 'GET', CheckoutSettingsOperations::FIELDS_ROUTE, array() ),
			array( 'PATCH', CheckoutSettingsOperations::FIELDS_ROUTE, $change ),
			array( 'GET', CheckoutSettingsOperations::LIMITS_ROUTE, array() ),
			array( 'PUT', CheckoutSettingsOperations::LIMITS_ROUTE, array( 'limits' => array( array( 'currency' => 'USD' ) ) ) ),
		);
		$other  = $this->userGranted( 'seocart_view_orders' );

		foreach ( array( 0, $other->userId() ) as $user ) {
			wp_set_current_user( $user );

			foreach ( $routes as list( $method, $route, $body ) ) {
				$sent = $this->serve( $this->server, $method, '/' . RestBinding::NAMESPACE . $route, array(), $body );

				$this->assertContains( $sent['status'], array( 401, 403 ), "{$method} {$route} as user {$user}: " . (string) wp_json_encode( $sent['body'] ) );
			}
		}

		$this->assertFalse( get_option( 'seocart_checkout_field_presence' ) );
		$this->assertFalse( get_option( 'seocart_checkout_order_limits' ) );
	}

	/**
	 * Sends a change of presences.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, string> $presences Presence by field key.
	 * @return array{status: int|null, headers: array<string, string>, body: array<string, mixed>} What was sent.
	 */
	private function change( array $presences ): array {
		$fields = array();

		foreach ( $presences as $key => $presence ) {
			$fields[] = array(
				'key'      => $key,
				'presence' => $presence,
			);
		}

		return $this->staff( 'PATCH', CheckoutSettingsOperations::FIELDS_ROUTE, array( 'fields' => $fields ) );
	}

	/**
	 * Returns what the store keeps of the presences, as committed.
	 *
	 * @since 0.2.0
	 *
	 * @return string|null The option's text, or null when it has none.
	 */
	private function stored(): ?string {
		$value = $this->secondConnection()->fetchValue( sprintf( "SELECT option_value FROM `%s` WHERE option_name = 'seocart_checkout_field_presence'", $GLOBALS['wpdb']->options ) );

		return null === $value ? null : (string) $value;
	}

	/**
	 * Returns a field's presence in a fields answer.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $body The answer.
	 * @param string               $key  The field.
	 * @return string|null Its presence.
	 */
	private static function presenceIn( array $body, string $key ): ?string {
		foreach ( (array) ( $body['fields'] ?? array() ) as $field ) {
			if ( $key === $field['key'] ) {
				return $field['presence'];
			}
		}

		return null;
	}

	/**
	 * Serves a staff request, as a user who may manage the store's settings.
	 *
	 * @since 0.2.0
	 *
	 * @param string               $method The HTTP method.
	 * @param string               $route  The route, relative to the namespace.
	 * @param array<string, mixed> $body   Optional. The body. Default none.
	 * @return array{status: int|null, headers: array<string, string>, body: array<string, mixed>} What was sent.
	 */
	private function staff( string $method, string $route, array $body = array() ): array {
		wp_set_current_user( $this->userGranted( CheckoutSettingsOperations::CAPABILITY )->userId() );

		$sent = $this->serve( $this->server, $method, '/' . RestBinding::NAMESPACE . $route, array(), $body );

		wp_set_current_user( 0 );

		return $sent;
	}
}
