<?php
/**
 * Tests what the checkout accepts in each field, on the session write and at placement, over the wire
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Checkout;

use SEOCart\Checkout\Domain\Fields\BuiltInFields;
use SEOCart\Checkout\Domain\Fields\Carrier;
use SEOCart\Checkout\Infrastructure\CheckoutTables;
use SEOCart\Contracts\Checkout\CheckoutField;
use SEOCart\Contracts\Checkout\CheckoutFieldType;
use SEOCart\Contracts\Checkout\Presence;
use SEOCart\Order\Infrastructure\OrderTables;
use SEOCart\Tests\Support\Cart\ServesStoreApi;
use SEOCart\Tests\Support\Checkout\ChecksOutOverTheWire;
use SEOCart\Tests\Support\Checkout\PlacementTestCase;

/**
 * The validation table: every built-in field at each presence it allows, given, empty and absent, on the session write and at the placement that follows; characters, formats and lengths; and the store's choices changing between the write and the placement.
 *
 * The rows of the presence table are generated from the declarations, so a field added to the
 * built-ins is tested without a line here. A refusal names each value by its wire path and why,
 * never the value. Each request is served by a container of its own.
 *
 * Planted violations, each shown red and removed:
 * - in CheckoutRules::reasonFor(), let a hidden field's value through (drop the not_accepted
 *   return): the hidden rows answer 200 and store the value;
 * - in CheckoutText::refused(), leave out `|\p{Cf}`: the U+202E, U+200E and U+FEFF rows are accepted;
 * - in CheckoutText::JOINERS, take out `\x{200C}`: the Persian name's row is refused;
 * - in PlaceOrder::place(), build the order from the session as read, not from forPlacement()'s
 *   answer: the made-hidden-between row's order holds the hidden company;
 * - in CheckoutRules::missingFrom(), skip a required field (`continue` for `last_name`): the
 *   required rows at placement are placed;
 * - in CompiledFields::__construct(), take the default presence whatever the store chose: a field
 *   the store made required is not asked for at placement.
 *
 * @since 0.2.0
 */
final class FieldValidationTest extends PlacementTestCase {

	use ChecksOutOverTheWire;
	use ServesStoreApi;

	/**
	 * A value of each type the checkout accepts.
	 *
	 * @since 0.2.0
	 *
	 * @var array<string, string>
	 */
	private const SAMPLES = array(
		'text'    => 'Ada',
		'email'   => 'ada@example.com',
		'tel'     => '+44 20 7946 0000',
		'country' => 'GB',
	);

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
	 * Restores the request globals and the store's choices.
	 *
	 * @since 0.2.0
	 */
	public function tear_down(): void {
		$this->shutStoreApi();
		$this->forgetCheckoutChoices();

		parent::tear_down();
	}

	/**
	 * Provides each built-in field at each presence it allows, given, empty and absent.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, array{string, string, string}> The field's key, its presence and how the write sends it.
	 */
	public static function presenceTable(): array {
		$rows = array();

		foreach ( BuiltInFields::all() as $field ) {
			foreach ( $field->presences as $presence ) {
				foreach ( array( 'given', 'empty', 'absent' ) as $sent ) {
					$rows[ "{$field->key} {$presence->value}, {$sent}" ] = array( $field->key, $presence->value, $sent );
				}
			}
		}

		return $rows;
	}

	/**
	 * Tests one row of the presence table: what the write answers, and then what the placement answers.
	 *
	 * The write refuses a value for a hidden field, and the country's absence from an address, and
	 * accepts anything else: it never asks for a required field. The placement asks for a required
	 * field without a value, by wire path, in each address that carries it, and places otherwise.
	 *
	 * @since 0.2.0
	 *
	 * @dataProvider presenceTable
	 *
	 * @param string $key      The field.
	 * @param string $presence Its presence in the store.
	 * @param string $sent     `given`, `empty` or `absent`.
	 */
	public function test_each_field_at_each_presence( string $key, string $presence, string $sent ): void {
		$field = self::field( $key );
		$paths = array_map( static fn( string $address ): string => "{$address}.{$key}", Carrier::addressesOf( $field ) );

		$this->choosePresences( $presence === $field->defaultPresence()->value ? array() : array( $key => $presence ) );
		$this->cartOverTheWire();

		$written = $this->writeOverTheWire( $this->addresses( $key, $sent ) );

		if ( 'given' === $sent && Presence::Hidden->value === $presence ) {
			$this->assertRefusedFields( $written, array_map( static fn( string $path ): array => self::fault( $path, 'not_accepted' ), $paths ) );
			$this->assertSame( 0, $this->checkoutRows( CheckoutTables::SESSIONS ), 'A refused value was stored.' );

			return;
		}

		if ( CheckoutFieldType::Country === $field->type && 'given' !== $sent ) {
			// An address is not held without its country: the wire asks for it, and an empty one is no country.
			$this->assertSame( 'absent' === $sent ? 400 : 422, $written['status'], (string) wp_json_encode( $written['body'] ) );

			return;
		}

		$this->assertSame( 200, $written['status'], (string) wp_json_encode( $written['body'] ) );

		$placed = $this->placeOverTheWire( $written['body'], "presence-{$key}-{$presence}-{$sent}" );

		if ( 'given' !== $sent && Presence::Required->value === $presence ) {
			$this->assertSame( 422, $placed['status'], (string) wp_json_encode( $placed['body'] ) );
			$this->assertErrorShape( $placed['body'], 'checkout.session_incomplete', 422 );
			$this->assertSame( $paths, $placed['body']['data']['details']['missing'] ?? null );
			$this->assertSame( 0, $this->committedCount( $this->secondConnection(), OrderTables::ORDERS ) );

			return;
		}

		$this->assertSame( 200, $placed['status'], (string) wp_json_encode( $placed['body'] ) );
		$this->assertSame( 'given' === $sent ? self::sampleOf( $field ) : '', $this->orderAddressValue( $key ), "The order's billing {$key}." );
	}

	/**
	 * Provides one-line values the checkout refuses for their characters, and the names it accepts.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, array{string, bool}> The last name sent, and whether it is accepted.
	 */
	public static function characters(): array {
		return array(
			'U+0007 bell'                      => array( "Love\u{0007}lace", false ),
			'U+000A line feed'                 => array( "Love\nlace", false ),
			'U+000D carriage return'           => array( "Love\rlace", false ),
			'U+202E right-to-left override'    => array( "Love\u{202E}lace", false ),
			'U+200E left-to-right mark'        => array( "Love\u{200E}lace", false ),
			'U+2028 line separator'            => array( "Love\u{2028}lace", false ),
			'U+FEFF zero width no-break space' => array( "\u{FEFF}Lovelace", false ),
			'Zoë'                              => array( 'Zoë', true ),
			'Müller'                           => array( 'Müller', true ),
			'Αθηνά'                            => array( 'Αθηνά', true ),
			'Иван'                             => array( 'Иван', true ),
			'O\'Brien-Smith'                   => array( 'O\'Brien-Smith', true ),
			'علی‌رضا with U+200C'              => array( "علی\u{200C}رضا", true ),
		);
	}

	/**
	 * Tests that a one-line field refuses a control or invisible format character, `characters`, and keeps every writing system's letters and the joiners.
	 *
	 * @since 0.2.0
	 *
	 * @dataProvider characters
	 *
	 * @param string $name     The last name sent, in both addresses.
	 * @param bool   $accepted Whether the write accepts it.
	 */
	public function test_the_characters_of_a_one_line_field( string $name, bool $accepted ): void {
		$this->cartOverTheWire();

		$addresses = $this->addresses( 'last_name', 'given' );

		$addresses[ Carrier::BILLING ]['last_name']  = $name;
		$addresses[ Carrier::SHIPPING ]['last_name'] = $name;

		$written = $this->writeOverTheWire( $addresses );

		if ( ! $accepted ) {
			$this->assertRefusedFields( $written, array( self::fault( 'billing_address.last_name', 'characters' ), self::fault( 'shipping_address.last_name', 'characters' ) ) );

			return;
		}

		$this->assertSame( 200, $written['status'], (string) wp_json_encode( $written['body'] ) );
		$this->assertSame( 200, $this->placeOverTheWire( $written['body'], 'name-' . md5( $name ) )['status'] );
		$this->assertSame( $name, $this->orderAddressValue( 'last_name' ) );
	}

	/**
	 * Tests the formats: an email address and a country that are not one are refused as `format`; three faults in one write are answered as three entries.
	 *
	 * @since 0.2.0
	 */
	public function test_formats_and_every_fault_at_once(): void {
		$this->cartOverTheWire();

		$addresses = $this->addresses( 'email', 'given' );

		$addresses[ Carrier::BILLING ]['email']    = 'not an email address';
		$addresses[ Carrier::BILLING ]['country']  = 'gb';
		$addresses[ Carrier::SHIPPING ]['company'] = 'Analytical Engines';

		$this->assertRefusedFields(
			$this->writeOverTheWire( $addresses ),
			array(
				self::fault( 'billing_address.email', 'format' ),
				self::fault( 'billing_address.country', 'format' ),
				self::fault( 'shipping_address.company', 'not_accepted' ),
			)
		);
	}

	/**
	 * Tests the two values the compiled schema refuses before the checkout's rules see them: one character over a field's length, and an email address in the shipping address, which does not carry one.
	 *
	 * @since 0.2.0
	 */
	public function test_the_schema_refuses_a_long_value_and_a_shipping_email(): void {
		$this->cartOverTheWire();

		$long = $this->addresses( 'city', 'given' );

		$long[ Carrier::BILLING ]['city'] = str_repeat( 'a', self::field( 'city' )->maxLength + 1 );

		$refused = $this->writeOverTheWire( $long );

		$this->assertSame( 400, $refused['status'], (string) wp_json_encode( $refused['body'] ) );
		$this->assertSame( 'rest_invalid_param', $refused['body']['code'] ?? null );

		$emailed = $this->addresses( 'email', 'given' );

		$emailed[ Carrier::SHIPPING ]['email'] = 'ada@example.com';

		$refused = $this->writeOverTheWire( $emailed );

		$this->assertSame( 400, $refused['status'], (string) wp_json_encode( $refused['body'] ) );
		$this->assertSame( 'rest_invalid_param', $refused['body']['code'] ?? null );
		$this->assertSame( 0, $this->checkoutRows( CheckoutTables::SESSIONS ) );
	}

	/**
	 * Tests the store's choices changing between the write and the placement: a field made required is asked for, and a field made hidden is left out of the order.
	 *
	 * @since 0.2.0
	 */
	public function test_choices_changed_between_the_write_and_the_placement(): void {
		$this->cartOverTheWire();

		$written = $this->writeOverTheWire( $this->addresses( 'phone', 'absent' ) );

		$this->assertSame( 200, $written['status'], (string) wp_json_encode( $written['body'] ) );
		$this->choosePresences( array( 'phone' => 'required' ) );

		$asked = $this->placeOverTheWire( $written['body'], 'made-required' );

		$this->assertErrorShape( $asked['body'], 'checkout.session_incomplete', 422 );
		$this->assertSame( array( 'billing_address.phone', 'shipping_address.phone' ), $asked['body']['data']['details']['missing'] ?? null );

		$this->choosePresences( array( 'company' => 'optional' ) );
		$this->cartOverTheWire();

		$withCompany = $this->addresses( 'company', 'given' );
		$written     = $this->writeOverTheWire( $withCompany );

		$this->assertSame( 200, $written['status'], (string) wp_json_encode( $written['body'] ) );
		$this->forgetCheckoutChoices();

		$placed = $this->placeOverTheWire( $written['body'], 'made-hidden' );

		$this->assertSame( 200, $placed['status'], (string) wp_json_encode( $placed['body'] ) );
		$this->assertSame( '', $this->orderAddressValue( 'company' ), 'The order holds the value of a field hidden since it was written.' );
		$this->assertSame( 'Ada', $this->orderAddressValue( 'first_name' ) );
	}

	/**
	 * Tests that a session stored before the checkout refused control characters is refused at placement, `checkout.invalid_fields`, and places no order.
	 *
	 * @since 0.2.0
	 */
	public function test_a_session_stored_before_the_rules_is_judged_at_placement(): void {
		$this->cartOverTheWire();

		$written = $this->writeOverTheWire( $this->addresses( 'city', 'given' ) );

		$this->assertSame( 200, $written['status'], (string) wp_json_encode( $written['body'] ) );

		// What an older checkout stored: a bell in the city, and a city longer than the field allows now.
		$this->db->execute( "UPDATE %i SET billing_address_json = JSON_SET( billing_address_json, '$.city', %s ), shipping_address_json = JSON_SET( shipping_address_json, '$.city', %s )", $this->table( CheckoutTables::SESSIONS ), "Lon\u{0007}don", str_repeat( 'a', self::field( 'city' )->maxLength + 1 ) );

		$placed = $this->placeOverTheWire( $written['body'], 'stored-before' );

		$this->assertRefusedFields( $placed, array( self::fault( 'billing_address.city', 'characters' ), self::fault( 'shipping_address.city', 'too_long' ) ) );
		$this->assertSame( 0, $this->committedCount( $this->secondConnection(), OrderTables::ORDERS ) );
	}

	/**
	 * Returns two addresses as a session write sends them: every field required by default, the field under test given, empty or left out.
	 *
	 * @since 0.2.0
	 *
	 * @param string $key  The field under test.
	 * @param string $sent `given`, `empty` or `absent`.
	 * @return array<string, array<string, string>> The billing and the shipping address.
	 */
	private function addresses( string $key, string $sent ): array {
		$field     = self::field( $key );
		$addresses = array();

		foreach ( array( Carrier::BILLING, Carrier::SHIPPING ) as $address ) {
			$members = array();

			foreach ( BuiltInFields::all() as $declared ) {
				if ( in_array( $address, Carrier::addressesOf( $declared ), true ) && Presence::Required === $declared->defaultPresence() ) {
					$members[ $declared->key ] = self::sampleOf( $declared );
				}
			}

			if ( in_array( $address, Carrier::addressesOf( $field ), true ) ) {
				$members[ $key ] = self::sampleOf( $field );

				if ( 'empty' === $sent ) {
					$members[ $key ] = '';
				} elseif ( 'absent' === $sent ) {
					unset( $members[ $key ] );
				}
			}

			$addresses[ $address ] = $members;
		}

		return array(
			Carrier::BILLING     => $addresses[ Carrier::BILLING ],
			Carrier::SHIPPING    => $addresses[ Carrier::SHIPPING ],
			'payment_method_key' => 'stub',
		);
	}

	/**
	 * Returns a value the checkout accepts for a field.
	 *
	 * @since 0.2.0
	 *
	 * @param CheckoutField $field The field.
	 * @return string The value.
	 */
	private static function sampleOf( CheckoutField $field ): string {
		return self::SAMPLES[ $field->type->value ];
	}

	/**
	 * Returns a built-in field.
	 *
	 * @since 0.2.0
	 *
	 * @param string $key The key.
	 * @return CheckoutField The field.
	 */
	private static function field( string $key ): CheckoutField {
		foreach ( BuiltInFields::all() as $field ) {
			if ( $key === $field->key ) {
				return $field;
			}
		}

		self::fail( "No built-in field {$key}." );
	}

	/**
	 * Returns one expected fault.
	 *
	 * @since 0.2.0
	 *
	 * @param string $path   The wire path.
	 * @param string $reason Why.
	 * @return array{field: string, reason: string} The fault.
	 */
	private static function fault( string $path, string $reason ): array {
		return array(
			'field'  => $path,
			'reason' => $reason,
		);
	}

	/**
	 * Asserts a request was refused with `checkout.invalid_fields` and exactly these faults, none of which carries a value.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed>                       $sent   What was sent.
	 * @param list<array{field: string, reason: string}> $faults The faults expected, in order.
	 */
	private function assertRefusedFields( array $sent, array $faults ): void {
		$this->assertSame( 422, $sent['status'], (string) wp_json_encode( $sent['body'] ) );
		$this->assertErrorShape( $sent['body'], 'checkout.invalid_fields', 422 );
		$this->assertSame( $faults, $sent['body']['data']['details']['fields'] ?? null );
	}

	/**
	 * Returns a member of the newest order's billing address, as committed.
	 *
	 * @since 0.2.0
	 *
	 * @param string $member The member.
	 * @return string The value.
	 */
	private function orderAddressValue( string $member ): string {
		$value = $this->secondConnection()->fetchValue( sprintf( "SELECT a.`%s` FROM `%s` a WHERE a.role = 'billing' ORDER BY a.order_id DESC LIMIT 1", $member, $this->table( OrderTables::ADDRESSES ) ) );

		$this->assertNotNull( $value, 'No order was placed.' );

		return (string) $value;
	}
}
