<?php
/**
 * Tests the built-in checkout fields against every other place an address member is named
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Checkout;

use PHPUnit\Framework\TestCase;
use SEOCart\Checkout\Domain\AddressDocument;
use SEOCart\Checkout\Domain\Fields\BuiltInFields;
use SEOCart\Checkout\Domain\Fields\Carrier;
use SEOCart\Checkout\Interfaces\StoreApi\CheckoutOperations;
use SEOCart\Contracts\Checkout\CheckoutField;
use SEOCart\Contracts\Checkout\CheckoutFieldType;
use SEOCart\Contracts\Checkout\Presence;
use SEOCart\Contracts\Checkout\Section;
use SEOCart\Order\Infrastructure\OrderTables;
use SEOCart\Platform\Database\Schema\ColumnSpec;
use SEOCart\Support\Schema\FieldSpec;
use SEOCart\Support\Schema\FieldType;
use SEOCart\Support\Schema\Privacy;

/**
 * The built-in fields are the address's members: the same names and lengths as the address document, the session write's compiled address and the order's address columns.
 *
 * Planted violation: in BuiltInFields::addressMembers(), declare `city` with a max_length of 120
 * instead of the field's: the compiled members are no longer the registry's, and the test names
 * the member. In AddressDocument::FIELDS, give `postcode` 40 characters: the order's column holds
 * 32, and the test names it.
 *
 * @since 0.2.0
 */
final class BuiltInFieldsTest extends TestCase {

	/**
	 * The columns of `order_addresses` that are not an address member: storage and a validation's record.
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	private const NOT_MEMBERS = array( 'id', 'order_id', 'role', 'validated_at', 'validation_provider' );

	/**
	 * Tests that the fields an address carries are the address document's members, named and limited alike, and the order's address columns.
	 *
	 * @since 0.2.0
	 */
	public function test_the_address_fields_are_the_documents_the_schemas_and_the_orders(): void {
		$registry = array();

		foreach ( BuiltInFields::all() as $field ) {
			if ( array() !== Carrier::addressesOf( $field ) ) {
				$registry[ $field->key ] = $field->maxLength;
			}
		}

		$compiled = array();

		foreach ( array( Carrier::BILLING, Carrier::SHIPPING ) as $address ) {
			foreach ( self::membersOf( $address ) as $member ) {
				$compiled[ $member->name() ] = $member->maxLength();
			}
		}

		$columns = array();

		foreach ( OrderTables::addresses()->columns() as $column ) {
			if ( ! in_array( $column->name(), self::NOT_MEMBERS, true ) ) {
				$columns[ $column->name() ] = self::lengthOf( $column );
			}
		}

		$document = AddressDocument::FIELDS;

		ksort( $registry );
		ksort( $compiled );
		ksort( $columns );
		ksort( $document );

		$this->assertSame( $document, $registry, 'The registry\'s address fields, by name and length, against AddressDocument::FIELDS.' );
		$this->assertSame( $registry, $compiled, 'The compiled address members of checkout.update_session, by name and length, against the registry.' );
		$this->assertSame( $registry, $columns, 'The order_addresses columns, by name and length, against the registry.' );
	}

	/**
	 * Tests each compiled member against its field: its label is the declaration's own closure, it is personal text, and only the country is asked for by the wire.
	 *
	 * @since 0.2.0
	 */
	public function test_each_member_is_compiled_from_its_field(): void {
		$fields = array();

		foreach ( BuiltInFields::all() as $field ) {
			$fields[ $field->key ] = $field;
		}

		foreach ( array( Carrier::BILLING, Carrier::SHIPPING ) as $address ) {
			foreach ( self::membersOf( $address ) as $member ) {
				$field = $fields[ $member->name() ];

				$this->assertSame( self::declaredAt( $field->label ), self::declaredAt( $member->label() ), "{$address}.{$member->name()}: the label is the declaration's." );
				$this->assertSame( array( FieldType::String, Privacy::Pii, 'country' === $member->name() ), array( $member->type(), $member->privacy(), $member->isRequired() ), "{$address}.{$member->name()}" );
			}
		}
	}

	/**
	 * Tests that the billing address carries the email address and the shipping address does not.
	 *
	 * @since 0.2.0
	 */
	public function test_only_the_billing_address_carries_the_email_address(): void {
		$billing  = array_map( static fn( FieldSpec $member ): string => $member->name(), self::membersOf( Carrier::BILLING ) );
		$shipping = array_map( static fn( FieldSpec $member ): string => $member->name(), self::membersOf( Carrier::SHIPPING ) );

		$this->assertContains( 'email', $billing );
		$this->assertNotContains( 'email', $shipping );
		$this->assertSame( array_values( array_diff( $billing, array( 'email' ) ) ), $shipping );
	}

	/**
	 * Tests the form order, and the presences each field allows: five locked required, the default first.
	 *
	 * @since 0.2.0
	 */
	public function test_the_form_order_and_the_presences(): void {
		$presences = array();

		foreach ( BuiltInFields::all() as $field ) {
			$presences[ $field->key ] = array_map( static fn( Presence $presence ): string => $presence->value, $field->presences );
		}

		$this->assertSame(
			array(
				'email'      => array( 'required' ),
				'country'    => array( 'required' ),
				'first_name' => array( 'required', 'optional' ),
				'last_name'  => array( 'required' ),
				'company'    => array( 'hidden', 'optional', 'required' ),
				'line1'      => array( 'required' ),
				'line2'      => array( 'optional', 'hidden', 'required' ),
				'city'       => array( 'required' ),
				'region'     => array( 'optional', 'required' ),
				'postcode'   => array( 'optional', 'required' ),
				'phone'      => array( 'optional', 'hidden', 'required' ),
				'tax_id'     => array( 'hidden', 'optional', 'required' ),
				'order_note' => array( 'optional', 'hidden' ),
			),
			$presences
		);
		$this->assertSame( array( 'email', 'country', 'last_name', 'line1', 'city' ), array_keys( array_filter( self::byKey(), static fn( CheckoutField $field ): bool => $field->isLocked() ) ) );
	}

	/**
	 * Tests the order note, the one built-in field that is no address member: an order field, a textarea of 1,000 characters, never required, carried in the custom fields.
	 *
	 * @since 0.2.0
	 */
	public function test_the_order_note_is_a_textarea_carried_in_the_custom_fields(): void {
		$note = self::byKey()['order_note'] ?? null;

		$this->assertNotNull( $note );
		$this->assertSame( array( Section::Order, CheckoutFieldType::Textarea, 1000, null ), array( $note->section, $note->type, $note->maxLength, $note->autocomplete ) );
		$this->assertSame( Carrier::CustomFields, Carrier::of( $note ) );
		$this->assertSame( array(), Carrier::addressesOf( $note ), 'An address carries the order note.' );
	}

	/**
	 * Returns the built-in fields by key.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, CheckoutField> The fields.
	 */
	private static function byKey(): array {
		$fields = array();

		foreach ( BuiltInFields::all() as $field ) {
			$fields[ $field->key ] = $field;
		}

		return $fields;
	}

	/**
	 * Returns the members of an address as the session write's input declares them.
	 *
	 * @since 0.2.0
	 *
	 * @param string $address The address's wire name.
	 * @return list<FieldSpec> The members.
	 */
	private static function membersOf( string $address ): array {
		foreach ( CheckoutOperations::updateSession()->input() as $field ) {
			if ( $address === $field->name() ) {
				return $field->fields();
			}
		}

		self::fail( "The session write declares no {$address}." );
	}

	/**
	 * Returns where a closure is written: two labels written at one place are one declaration's.
	 *
	 * @since 0.2.0
	 *
	 * @param \Closure $label The label's closure.
	 * @return string Its file and line.
	 */
	private static function declaredAt( \Closure $label ): string {
		$written = new \ReflectionFunction( $label );

		return $written->getFileName() . ':' . $written->getStartLine();
	}

	/**
	 * Returns the characters a text column holds.
	 *
	 * @since 0.2.0
	 *
	 * @param ColumnSpec $column The column.
	 * @return int The length its type declares.
	 */
	private static function lengthOf( ColumnSpec $column ): int {
		self::assertSame( 1, preg_match( '/^(?:var)?char\((\d+)\)$/', $column->type(), $length ), "{$column->name()} is a text column." );

		return (int) $length[1];
	}
}
