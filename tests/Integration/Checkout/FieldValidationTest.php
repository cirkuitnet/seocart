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

use SEOCart\Checkout\Domain\CheckoutDetails;
use SEOCart\Checkout\Domain\Fields\BuiltInFields;
use SEOCart\Checkout\Domain\Fields\Carrier;
use SEOCart\Checkout\Infrastructure\CheckoutTables;
use SEOCart\Contracts\Checkout\CheckoutField;
use SEOCart\Contracts\Checkout\CheckoutFieldRegistry;
use SEOCart\Contracts\Checkout\CheckoutFieldType;
use SEOCart\Contracts\Checkout\Presence;
use SEOCart\Contracts\Checkout\Section;
use SEOCart\Order\Infrastructure\OrderTables;
use SEOCart\Tests\Support\Cart\ServesStoreApi;
use SEOCart\Tests\Support\Checkout\AcmeCheckoutFields;
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
		'text'     => 'Ada',
		'email'    => 'ada@example.com',
		'tel'      => '+44 20 7946 0000',
		'country'  => 'GB',
		'textarea' => "Ring twice.\nThank you.",
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
		AcmeCheckoutFields::forget();
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
	 * The write refuses a value for a hidden field, the country's absence from an address (the
	 * schema's 400) and an empty country (`format`), and accepts anything else: it never asks for a
	 * required field. The placement asks for a required
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
		$paths = self::pathsOf( $field );

		$this->choosePresences( $presence === $field->defaultPresence()->value ? array() : array( $key => $presence ) );
		$this->cartOverTheWire();

		$written = $this->write( $this->addresses( $key, $sent ) + self::customFieldsFor( $field, $sent ) );

		if ( 'given' === $sent && Presence::Hidden->value === $presence ) {
			$this->assertRefusedFields( $written, array_map( static fn( string $path ): array => self::fault( $path, 'not_accepted' ), $paths ) );
			$this->assertSame( 0, $this->checkoutRows( CheckoutTables::SESSIONS ), 'A refused value was stored.' );

			return;
		}

		if ( CheckoutFieldType::Country === $field->type && 'absent' === $sent ) {
			// An address is not held without its country: the wire asks for it.
			$this->assertSame( 400, $written['status'], (string) wp_json_encode( $written['body'] ) );

			return;
		}

		if ( CheckoutFieldType::Country === $field->type && 'empty' === $sent ) {
			// An empty country is no country at all: the rules refuse it as a value not in its format.
			$this->assertRefusedFields( $written, array_map( static fn( string $path ): array => self::fault( $path, 'format' ), $paths ) );

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
		$this->assertSame( 'given' === $sent ? self::sampleOf( $field ) : '', Carrier::CustomFields === Carrier::of( $field ) ? $this->orderFieldValue( $key ) : $this->orderAddressValue( $key ), "The order's {$key}." );
	}

	/**
	 * Provides texts for the textarea, the order note: a line feed is accepted there alone; a carriage return and the other refused characters are not.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, array{string, bool}> The note sent, and whether it is accepted.
	 */
	public static function noteCharacters(): array {
		return array(
			'U+000A line feed'              => array( "Ring twice.\nThank you.", true ),
			'Αθηνά and علی‌رضا with U+200C' => array( "Αθηνά, علی\u{200C}رضا", true ),
			'U+000D carriage return'        => array( "Ring twice.\r\nThank you.", false ),
			'U+0007 bell'                   => array( "Ring\u{0007} twice.", false ),
			'U+202E right-to-left override' => array( "Ring \u{202E}twice.", false ),
			'U+2028 line separator'         => array( "Ring twice.\u{2028}Thank you.", false ),
		);
	}

	/**
	 * Tests the characters of the textarea over the route: a line feed is accepted, kept by the order as sent; any other refused character is `characters`.
	 *
	 * @since 0.2.0
	 *
	 * @dataProvider noteCharacters
	 *
	 * @param string $note     The order note sent.
	 * @param bool   $accepted Whether the write accepts it.
	 */
	public function test_the_characters_of_the_textarea( string $note, bool $accepted ): void {
		$this->cartOverTheWire();

		$written = $this->write( $this->addresses( 'email', 'given' ) + array( 'custom_fields' => array( self::entry( 'order_note', $note ) ) ) );

		if ( ! $accepted ) {
			$this->assertRefusedFields( $written, array( self::fault( 'custom_fields.order_note', 'characters' ) ) );

			return;
		}

		$this->assertSame( 200, $written['status'], (string) wp_json_encode( $written['body'] ) );
		$this->assertSame( 200, $this->placeOverTheWire( $written['body'], 'note-' . md5( $note ) )['status'] );
		$this->assertSame( $note, $this->orderFieldValue( 'order_note' ) );
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

		$written = $this->write( $addresses );

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
			$this->write( $addresses ),
			array(
				self::fault( 'billing_address.email', 'format' ),
				self::fault( 'billing_address.country', 'format' ),
				self::fault( 'shipping_address.company', 'not_accepted' ),
			)
		);
	}

	/**
	 * Provides values an address's compiled schema refuses, each with the address it is sent in and WordPress's code for the fault.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, array{string, string, mixed, string}> The address, the member, the value sent, and the code.
	 */
	public static function schemaRefusals(): array {
		return array(
			'one character over the length, counted in characters' => array( Carrier::BILLING, 'city', str_repeat( "\u{00E9}", self::field( 'city' )->maxLength + 1 ), 'rest_too_long' ),
			'a member the address does not have'       => array( Carrier::BILLING, 'nickname', 'Ada', 'rest_additional_properties_forbidden' ),
			'a value that is not a string'             => array( Carrier::BILLING, 'city', 5, 'rest_invalid_type' ),
			'an email address in the shipping address' => array( Carrier::SHIPPING, 'email', 'ada@example.com', 'rest_additional_properties_forbidden' ),
		);
	}

	/**
	 * Tests one value the route's schema refuses, as on every operation, before the checkout's rules run: `rest_invalid_param`, HTTP 400, WordPress's code for the address that holds it, and nothing stored.
	 *
	 * @since 0.2.0
	 *
	 * @dataProvider schemaRefusals
	 *
	 * @param string $address The address the value is sent in.
	 * @param string $member  The member it is sent as.
	 * @param mixed  $value   The value.
	 * @param string $code    WordPress's code for the fault, under `param_codes`.
	 */
	public function test_the_schema_refuses_what_an_address_cannot_hold( string $address, string $member, mixed $value, string $code ): void {
		$this->cartOverTheWire();

		$addresses = $this->addresses( 'email', 'given' );

		$addresses[ $address ][ $member ] = $value;

		$refused = $this->write( $addresses );

		$this->assertSame( 400, $refused['status'], (string) wp_json_encode( $refused['body'] ) );
		$this->assertSame( 'rest_invalid_param', $refused['body']['code'] ?? null );
		$this->assertSame( array( $address => $code ), $refused['body']['data']['details']['param_codes'] ?? null );
		$this->assertSame( 0, $this->checkoutRows( CheckoutTables::SESSIONS ), 'A refused write stored a session.' );
	}

	/**
	 * Tests the store's choices changing between the write and the placement: a field made required is asked for, and a field made hidden is left out of the order.
	 *
	 * @since 0.2.0
	 */
	public function test_choices_changed_between_the_write_and_the_placement(): void {
		$this->cartOverTheWire();

		$written = $this->write( $this->addresses( 'phone', 'absent' ) );

		$this->assertSame( 200, $written['status'], (string) wp_json_encode( $written['body'] ) );
		$this->choosePresences( array( 'phone' => 'required' ) );

		$asked = $this->placeOverTheWire( $written['body'], 'made-required' );

		$this->assertErrorShape( $asked['body'], 'checkout.session_incomplete', 422 );
		$this->assertSame( array( 'billing_address.phone', 'shipping_address.phone' ), $asked['body']['data']['details']['missing'] ?? null );

		$this->choosePresences( array( 'company' => 'optional' ) );
		$this->cartOverTheWire();

		$withCompany = $this->addresses( 'company', 'given' );
		$written     = $this->write( $withCompany );

		$this->assertSame( 200, $written['status'], (string) wp_json_encode( $written['body'] ) );
		$this->forgetCheckoutChoices();

		$gateway = $this->recordingGatewayOverTheWire();
		$placed  = $this->placeOverTheWire( $written['body'], 'made-hidden' );

		$this->assertSame( 200, $placed['status'], (string) wp_json_encode( $placed['body'] ) );
		$this->assertSame( '', $this->orderAddressValue( 'company' ), 'The order holds the value of a field hidden since it was written.' );
		$this->assertSame( 'Ada', $this->orderAddressValue( 'first_name' ) );
		$this->assertCount( 1, $gateway->authorizations, 'The gateway was not asked to authorize the payment.' );
		$this->assertSame( array( '', 'Ada' ), array( $gateway->authorizations[0]->billing?->organization, $gateway->authorizations[0]->billing?->firstName ), 'The gateway\'s billing contact holds the value of a field hidden since it was written.' );
	}

	/**
	 * Tests that a session stored before the checkout refused control characters is refused at placement, `checkout.invalid_fields`, and places no order.
	 *
	 * @since 0.2.0
	 */
	public function test_a_session_stored_before_the_rules_is_judged_at_placement(): void {
		$this->cartOverTheWire();

		$written = $this->write( $this->addresses( 'city', 'given' ) );

		$this->assertSame( 200, $written['status'], (string) wp_json_encode( $written['body'] ) );

		// What an older checkout stored: a bell in the city, and a city longer than the field allows now.
		$this->db->execute( "UPDATE %i SET billing_address_json = JSON_SET( billing_address_json, '$.city', %s ), shipping_address_json = JSON_SET( shipping_address_json, '$.city', %s )", $this->table( CheckoutTables::SESSIONS ), "Lon\u{0007}don", str_repeat( 'a', self::field( 'city' )->maxLength + 1 ) );

		$placed = $this->placeOverTheWire( $written['body'], 'stored-before' );

		$this->assertRefusedFields( $placed, array( self::fault( 'billing_address.city', 'characters' ), self::fault( 'shipping_address.city', 'too_long' ) ) );
		$this->assertSame( 0, $this->committedCount( $this->secondConnection(), OrderTables::ORDERS ) );
	}

	/**
	 * Provides a kept city of two-byte characters at its field's length, and one character over.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, array{int, bool}> How many characters the kept city has, and whether the order is placed.
	 */
	public static function keptLengths(): array {
		$length = self::field( 'city' )->maxLength;

		return array(
			'at the field\'s length, in characters' => array( $length, true ),
			'one character over'                    => array( $length + 1, false ),
		);
	}

	/**
	 * Tests that the placement measures a kept value in characters, never bytes: a city of two-byte characters at its field's length is placed, and one character more is refused `too_long`.
	 *
	 * Planted violation: in CheckoutRules::reasonFor(), count the length with strlen(): the city at
	 * its length, twice as many bytes, is refused.
	 *
	 * @since 0.2.0
	 *
	 * @dataProvider keptLengths
	 *
	 * @param int  $characters How many characters the kept city has.
	 * @param bool $placed     Whether the order is placed.
	 */
	public function test_a_kept_value_is_measured_in_characters( int $characters, bool $placed ): void {
		$this->cartOverTheWire();

		$written = $this->write( $this->addresses( 'city', 'given' ) );

		$this->assertSame( 200, $written['status'], (string) wp_json_encode( $written['body'] ) );

		$city = str_repeat( "\u{00E9}", $characters );

		$this->db->execute( "UPDATE %i SET billing_address_json = JSON_SET( billing_address_json, '$.city', %s ), shipping_address_json = JSON_SET( shipping_address_json, '$.city', %s )", $this->table( CheckoutTables::SESSIONS ), $city, $city );

		$answer = $this->placeOverTheWire( $written['body'], "kept-length-{$characters}" );

		if ( ! $placed ) {
			$this->assertRefusedFields( $answer, array( self::fault( 'billing_address.city', 'too_long' ), self::fault( 'shipping_address.city', 'too_long' ) ) );

			return;
		}

		$this->assertSame( 200, $answer['status'], (string) wp_json_encode( $answer['body'] ) );
		$this->assertSame( $city, $this->orderAddressValue( 'city' ) );
	}

	/**
	 * Tests that a member an address does not carry, kept from before the checkout refused it, is left out at placement: a kept shipping address holding an email address is placed, and the order's shipping address holds none.
	 *
	 * Planted violation: in CheckoutRules::inForce(), keep a member whose field the address does not
	 * carry: the shipping email address is then judged as a value no field of that address takes, and
	 * the placement is refused.
	 *
	 * @since 0.2.0
	 */
	public function test_a_kept_member_the_address_does_not_carry_is_left_out(): void {
		$this->cartOverTheWire();

		$written = $this->write( $this->addresses( 'email', 'given' ) );

		$this->assertSame( 200, $written['status'], (string) wp_json_encode( $written['body'] ) );

		$this->db->execute( "UPDATE %i SET shipping_address_json = JSON_SET( shipping_address_json, '$.email', %s )", $this->table( CheckoutTables::SESSIONS ), 'ada@example.com' );

		$placed = $this->placeOverTheWire( $written['body'], 'kept-shipping-email' );

		$this->assertSame( 200, $placed['status'], (string) wp_json_encode( $placed['body'] ) );
		$this->assertSame( '', $this->orderAddressValue( 'email', 'shipping' ), 'The order\'s shipping address holds the email address it does not carry.' );
		$this->assertSame( 'ada@example.com', $this->orderAddressValue( 'email' ) );
	}

	/**
	 * Provides values for the custom fields the fixture extension registers, a text field with its own check, a select and a checkbox: what the write answers.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, array{list<array<string, mixed>>, list<array<string, string>>|null}> The entries sent, and the faults (null when the write is accepted).
	 */
	public static function customValues(): array {
		$po   = AcmeCheckoutFields::PO_NUMBER;
		$slot = AcmeCheckoutFields::DELIVERY_SLOT;
		$wrap = AcmeCheckoutFields::GIFT_WRAP;

		return array(
			'a text value its check accepts'            => array( array( self::entry( $po, 'PO-1234' ) ), null ),
			'a text value at its length, in characters' => array( array( self::entry( $po, 'PO-' . str_repeat( "\u{00E9}", 17 ) ) ), null ),
			'one character over the text\'s length'     => array( array( self::entry( $po, 'PO-' . str_repeat( "\u{00E9}", 18 ) ) ), array( self::fault( "custom_fields.{$po}", 'too_long' ) ) ),
			'a line feed in a one-line text'            => array( array( self::entry( $po, "PO-12\n34" ) ), array( self::fault( "custom_fields.{$po}", 'characters' ) ) ),
			'U+202E in a one-line text'                 => array( array( self::entry( $po, "PO-12\u{202E}34" ) ), array( self::fault( "custom_fields.{$po}", 'characters' ) ) ),
			'a text value its check refuses'            => array( array( self::entry( $po, '12345' ) ), array( self::fault( "custom_fields.{$po}", 'rejected' ) + array( 'message' => AcmeCheckoutFields::PO_REFUSAL ) ) ),
			'one of the select\'s options'              => array( array( self::entry( $slot, 'morning' ) ), null ),
			'an option not among the select\'s'         => array( array( self::entry( $slot, 'evening' ) ), array( self::fault( "custom_fields.{$slot}", 'not_an_option' ) ) ),
			'an unknown option longer than the select allows' => array( array( self::entry( $slot, str_repeat( 'x', 21 ) ) ), array( self::fault( "custom_fields.{$slot}", 'not_an_option' ) ) ),
			'a ticked checkbox'                         => array( array( self::entry( $wrap, '1' ) ), null ),
			'a checkbox sent as y'                      => array( array( self::entry( $wrap, 'y' ) ), array( self::fault( "custom_fields.{$wrap}", 'format' ) ) ),
			'a checkbox sent as 0'                      => array( array( self::entry( $wrap, '0' ) ), array( self::fault( "custom_fields.{$wrap}", 'format' ) ) ),
			'a checkbox sent as yes'                    => array( array( self::entry( $wrap, 'yes' ) ), array( self::fault( "custom_fields.{$wrap}", 'format' ) ) ),
			'a key no field has'                        => array( array( self::entry( 'acme_colour', 'red' ) ), array( self::fault( 'custom_fields.acme_colour', 'not_accepted' ) ) ),
			'an address member sent as a custom field'  => array( array( self::entry( 'phone', '+44 20 7946 0000' ) ), array( self::fault( 'custom_fields.phone', 'not_accepted' ) ) ),
			'a key given twice'                         => array( array( self::entry( $po, 'PO-1' ), self::entry( $po, 'PO-2' ) ), array( self::fault( "custom_fields.{$po}", 'duplicate' ) ) ),
			'empty values, not given'                   => array( array( self::entry( 'acme_colour', '' ), self::entry( $po, '' ) ), null ),
			'three faults at once'                      => array(
				array( self::entry( $po, '12345' ), self::entry( $slot, 'evening' ), self::entry( $wrap, '2' ) ),
				array( self::fault( "custom_fields.{$po}", 'rejected' ) + array( 'message' => AcmeCheckoutFields::PO_REFUSAL ), self::fault( "custom_fields.{$slot}", 'not_an_option' ), self::fault( "custom_fields.{$wrap}", 'format' ) ),
			),
		);
	}

	/**
	 * Tests one row of the custom fields' table over the route: refused with every fault and nothing stored, or accepted and kept on the session as sent, an empty value not given.
	 *
	 * @since 0.2.0
	 *
	 * @dataProvider customValues
	 *
	 * @param list<array<string, mixed>>       $entries The custom fields sent.
	 * @param list<array<string, string>>|null $faults  The faults; null when the write is accepted.
	 */
	public function test_a_custom_field_value( array $entries, ?array $faults ): void {
		AcmeCheckoutFields::listen();
		$this->cartOverTheWire();

		$written = $this->write( $this->addresses( 'email', 'given' ) + array( 'custom_fields' => $entries ) );

		if ( null !== $faults ) {
			$this->assertRefusedFields( $written, $faults );
			$this->assertSame( 0, $this->checkoutRows( CheckoutTables::SESSIONS ), 'A refused value was stored.' );

			return;
		}

		$given = array_values( array_filter( $entries, static fn( array $entry ): bool => '' !== $entry['field_value'] ) );

		$this->assertSame( 200, $written['status'], (string) wp_json_encode( $written['body'] ) );
		$this->assertSame( array_column( $given, 'field_value', 'key' ), $this->storedFieldValues(), 'The session keeps what was given, and nothing empty.' );
		$this->assertSame( array_map( static fn( array $entry ): array => array( 'key' => $entry['key'] ), $given ), $written['body']['checkout_session']['custom_fields'] ?? null, 'A guest\'s answer names each value\'s field, without the value.' );
	}

	/**
	 * Tests that a custom field the store hides refuses a value, `not_accepted`, and stores nothing.
	 *
	 * @since 0.2.0
	 */
	public function test_a_hidden_custom_field_refuses_a_value(): void {
		AcmeCheckoutFields::listen();
		$this->choosePresences( array( AcmeCheckoutFields::GIFT_WRAP => 'hidden' ) );
		$this->cartOverTheWire();

		$written = $this->write( $this->addresses( 'email', 'given' ) + array( 'custom_fields' => array( self::entry( AcmeCheckoutFields::GIFT_WRAP, '1' ) ) ) );

		$this->assertRefusedFields( $written, array( self::fault( 'custom_fields.' . AcmeCheckoutFields::GIFT_WRAP, 'not_accepted' ) ) );
		$this->assertSame( 0, $this->checkoutRows( CheckoutTables::SESSIONS ) );
	}

	/**
	 * Provides custom fields whose values each fit, four and five long notes of four-byte characters: what a session keeps, together, is bounded.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, array{int, bool}> How many notes, and whether the write is accepted.
	 */
	public static function documentSizes(): array {
		return array(
			'four notes, within the document a session keeps' => array( 4, true ),
			'five notes, longer than the document' => array( 5, false ),
		);
	}

	/**
	 * Tests that custom fields each within their length are refused together, `too_long` on custom_fields, when their document is longer than a session keeps.
	 *
	 * @since 0.2.0
	 *
	 * @dataProvider documentSizes
	 *
	 * @param int  $notes    How many notes are sent, each its field's longest value of four-byte characters.
	 * @param bool $accepted Whether the write is accepted.
	 */
	public function test_custom_fields_longer_together_than_a_session_keeps( int $notes, bool $accepted ): void {
		$register = static function ( CheckoutFieldRegistry $registry ): void {
			for ( $note = 1; $note <= 5; $note++ ) {
				$registry->register( 'bulk', new CheckoutField( key: "note_{$note}", section: Section::Order, type: CheckoutFieldType::Textarea, label: static fn(): string => 'Note', maxLength: CheckoutField::TEXTAREA_MAX_LENGTH, presences: array( Presence::Optional ) ) );
			}
		};

		add_action( CheckoutFieldRegistry::ACTION, $register );

		try {
			$this->cartOverTheWire();

			$entries = array();

			for ( $note = 1; $note <= $notes; $note++ ) {
				$entries[] = self::entry( "bulk_note_{$note}", str_repeat( "\u{1F600}", CheckoutField::TEXTAREA_MAX_LENGTH ) );
			}

			$written = $this->write( $this->addresses( 'email', 'given' ) + array( 'custom_fields' => $entries ) );
		} finally {
			remove_action( CheckoutFieldRegistry::ACTION, $register );
		}

		if ( ! $accepted ) {
			$this->assertRefusedFields( $written, array( self::fault( 'custom_fields', 'too_long' ) ) );

			return;
		}

		$this->assertSame( 200, $written['status'], (string) wp_json_encode( $written['body'] ) );
		$this->assertCount( $notes, $this->storedFieldValues() );
	}

	/**
	 * Provides custom fields the route's schema refuses, with WordPress's code for the fault: the list's shape is the schema's, every value's rules the checkout's.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, array{list<mixed>, string}> The custom fields sent, and the code.
	 */
	public static function customSchemaRefusals(): array {
		$entries = array();

		for ( $entry = 0; $entry <= CheckoutDetails::MAX_FIELD_VALUES; $entry++ ) {
			$entries[] = self::entry( "acme_field_{$entry}", 'x' );
		}

		return array(
			'one entry more than the list takes'        => array( $entries, 'rest_too_many_items' ),
			'an entry with a member it does not have'   => array( array( self::entry( AcmeCheckoutFields::PO_NUMBER, 'PO-1' ) + array( 'value' => 'PO-1' ) ), 'rest_additional_properties_forbidden' ),
			'a value that is not a string'              => array( array( self::entry( AcmeCheckoutFields::GIFT_WRAP, 1 ) ), 'rest_invalid_type' ),
			'a value longer than any field may declare' => array( array( self::entry( AcmeCheckoutFields::PO_NUMBER, str_repeat( 'a', CheckoutField::TEXTAREA_MAX_LENGTH + 1 ) ) ), 'rest_too_long' ),
		);
	}

	/**
	 * Tests one shape the route's schema refuses before the checkout's rules run: `rest_invalid_param`, HTTP 400, WordPress's code under the custom fields, and nothing stored.
	 *
	 * @since 0.2.0
	 *
	 * @dataProvider customSchemaRefusals
	 *
	 * @param array  $entries The custom fields sent.
	 * @param string $code    WordPress's code for the fault, under `param_codes`.
	 *
	 * @phpstan-param list<mixed> $entries
	 */
	public function test_the_schema_refuses_custom_fields_it_cannot_hold( array $entries, string $code ): void {
		AcmeCheckoutFields::listen();
		$this->cartOverTheWire();

		$refused = $this->write( $this->addresses( 'email', 'given' ) + array( 'custom_fields' => $entries ) );

		$this->assertSame( 400, $refused['status'], (string) wp_json_encode( $refused['body'] ) );
		$this->assertSame( 'rest_invalid_param', $refused['body']['code'] ?? null );
		$this->assertSame( array( 'custom_fields' => $code ), $refused['body']['data']['details']['param_codes'] ?? null );
		$this->assertSame( 0, $this->checkoutRows( CheckoutTables::SESSIONS ), 'A refused write stored a session.' );
	}

	/**
	 * Tests the custom fields at placement: a field the store made required is asked for by its wire path; a value kept that its check now refuses is refused as the write refuses it.
	 *
	 * @since 0.2.0
	 */
	public function test_custom_fields_at_placement(): void {
		AcmeCheckoutFields::listen();
		$this->choosePresences( array( AcmeCheckoutFields::PO_NUMBER => 'required' ) );
		$this->cartOverTheWire();

		$written = $this->write( $this->addresses( 'email', 'given' ) );

		$this->assertSame( 200, $written['status'], (string) wp_json_encode( $written['body'] ) );

		$asked = $this->placeOverTheWire( $written['body'], 'custom-required' );

		$this->assertErrorShape( $asked['body'], 'checkout.session_incomplete', 422 );
		$this->assertSame( array( 'custom_fields.' . AcmeCheckoutFields::PO_NUMBER ), $asked['body']['data']['details']['missing'] ?? null );

		$written = $this->write( $this->addresses( 'email', 'given' ) + array( 'custom_fields' => array( self::entry( AcmeCheckoutFields::PO_NUMBER, 'PO-1' ) ) ) );

		$this->assertSame( 200, $written['status'], (string) wp_json_encode( $written['body'] ) );

		// What an older version of the extension accepted, which its check refuses now.
		$this->db->execute( 'UPDATE %i SET field_values_json = %s', $this->table( CheckoutTables::SESSIONS ), (string) wp_json_encode( array( AcmeCheckoutFields::PO_NUMBER => '12345' ) ) );

		$this->assertRefusedFields( $this->placeOverTheWire( $written['body'], 'custom-kept' ), array( self::fault( 'custom_fields.' . AcmeCheckoutFields::PO_NUMBER, 'rejected' ) + array( 'message' => AcmeCheckoutFields::PO_REFUSAL ) ) );
		$this->assertSame( 0, $this->committedCount( $this->secondConnection(), OrderTables::ORDERS ) );
	}

	/**
	 * Returns one entry of the custom fields, as a session write sends it.
	 *
	 * @since 0.2.0
	 *
	 * @param string $key   The field's key.
	 * @param mixed  $value The value.
	 * @return array{key: string, field_value: mixed} The entry.
	 */
	private static function entry( string $key, mixed $value ): array {
		return array(
			'key'         => $key,
			'field_value' => $value,
		);
	}

	/**
	 * Returns the custom fields' values the cart's session keeps, by field key.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, string> The values; empty when it keeps none.
	 */
	private function storedFieldValues(): array {
		$stored = $this->db->fetchValue( 'SELECT field_values_json FROM %i', $this->table( CheckoutTables::SESSIONS ) );

		return null === $stored ? array() : (array) json_decode( (string) $stored, true );
	}

	/**
	 * Returns two addresses as a session write sends them: every field required by default, the field under test given, empty or left out.
	 *
	 * @since 0.2.0
	 *
	 * @param string $key  The field under test.
	 * @param string $sent `given`, `empty` or `absent`.
	 * @return array<string, array<string, string>> The billing and the shipping address, by wire name.
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

		return $addresses;
	}

	/**
	 * Writes the cart's checkout: two addresses, and the stand-in's payment method.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, array<string, mixed>> $addresses The billing and the shipping address, by wire name.
	 * @return array{status: int|null, headers: array<string, string>, body: array<string, mixed>} What was sent.
	 */
	private function write( array $addresses ): array {
		return $this->writeOverTheWire( $addresses + array( 'payment_method_key' => 'stub' ) );
	}

	/**
	 * Returns the wire paths a field's value is sent at: the member of each address that carries it, or its entry of the custom fields.
	 *
	 * @since 0.2.0
	 *
	 * @param CheckoutField $field The field.
	 * @return list<string> The paths.
	 */
	private static function pathsOf( CheckoutField $field ): array {
		if ( Carrier::CustomFields === Carrier::of( $field ) ) {
			return array( 'custom_fields.' . $field->key );
		}

		return array_map( static fn( string $address ): string => "{$address}.{$field->key}", Carrier::addressesOf( $field ) );
	}

	/**
	 * Returns the custom fields a session write sends for a field the custom fields carry: its value given, empty, or left out; none for an address's field.
	 *
	 * @since 0.2.0
	 *
	 * @param CheckoutField $field The field under test.
	 * @param string        $sent  `given`, `empty` or `absent`.
	 * @return array<string, list<array<string, string>>> The custom fields, by wire name; empty when none is sent.
	 */
	private static function customFieldsFor( CheckoutField $field, string $sent ): array {
		if ( Carrier::CustomFields !== Carrier::of( $field ) || 'absent' === $sent ) {
			return array();
		}

		return array( 'custom_fields' => array( self::entry( $field->key, 'given' === $sent ? self::sampleOf( $field ) : '' ) ) );
	}

	/**
	 * Returns the newest order's value of a custom field, as committed, or an empty string when it keeps none.
	 *
	 * @since 0.2.0
	 *
	 * @param string $key The field's key.
	 * @return string The value.
	 */
	private function orderFieldValue( string $key ): string {
		$order = $this->secondConnection()->fetchValue( sprintf( 'SELECT MAX( id ) FROM `%s`', $this->table( OrderTables::ORDERS ) ) );

		$this->assertNotNull( $order, 'No order was placed.' );

		return (string) $this->secondConnection()->fetchValue( sprintf( "SELECT field_value FROM `%s` WHERE order_id = %d AND field_key = '%s'", $this->table( OrderTables::FIELD_VALUES ), (int) $order, esc_sql( $key ) ) );
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
	 * Returns a member of one of the newest order's addresses, as committed.
	 *
	 * @since 0.2.0
	 *
	 * @param string $member The member.
	 * @param string $role   Optional. The address: `billing` or `shipping`. Default `billing`.
	 * @return string The value.
	 */
	private function orderAddressValue( string $member, string $role = 'billing' ): string {
		$value = $this->secondConnection()->fetchValue( sprintf( "SELECT a.`%s` FROM `%s` a WHERE a.role = '%s' ORDER BY a.order_id DESC LIMIT 1", $member, $this->table( OrderTables::ADDRESSES ), $role ) );

		$this->assertNotNull( $value, 'No order was placed.' );

		return (string) $value;
	}
}
