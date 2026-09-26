<?php
/**
 * Tests that WordPress validates a composite input as its compiled arguments declare it
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Operations;

use SEOCart\Support\Schema\FieldSpec;
use SEOCart\Support\Schema\FieldType;
use SEOCart\Support\Schema\JsonSchemaCompiler;
use WP_Error;
use WP_UnitTestCase;

/**
 * WordPress's own validator, given the compiled REST arguments of a list and an object, refuses
 * a nested key the declaration does not list, a missing required member, and a list longer than
 * its bound, and accepts and sanitizes what the declaration allows.
 *
 * Planted violation: in JsonSchemaCompiler::members(), leave out `additionalProperties: false`:
 * the undeclared nested keys are then accepted.
 *
 * @since 0.1.0
 */
final class CompositeInputValidationTest extends WP_UnitTestCase {

	/**
	 * Tests that an undeclared key in an item of a list, or in an object, is refused.
	 *
	 * @since 0.1.0
	 */
	public function test_an_undeclared_nested_key_is_refused(): void {
		$arguments = JsonSchemaCompiler::restArguments( self::fields() );

		$this->assertRefused(
			'rest_additional_properties_forbidden',
			array(
				array(
					'variant_id' => 1,
					'price'      => 1,
				),
			),
			$arguments['lines'],
			'lines'
		);
		$this->assertRefused(
			'rest_additional_properties_forbidden',
			array(
				'country' => 'GB',
				'street'  => 'Elm Row',
			),
			$arguments['address'],
			'address'
		);
	}

	/**
	 * Tests that a missing required member is refused, in an item of a list and in an object argument.
	 *
	 * @since 0.1.0
	 */
	public function test_a_missing_required_member_is_refused(): void {
		$arguments = JsonSchemaCompiler::restArguments( self::fields() );

		$this->assertRefused( 'rest_property_required', array( array( 'quantity' => 1 ) ), $arguments['lines'], 'lines' );
		$this->assertRefused( 'rest_property_required', array( 'note' => 'x' ), $arguments['address'], 'address' );
	}

	/**
	 * Tests that a list is refused outside its bounds.
	 *
	 * @since 0.1.0
	 */
	public function test_a_list_outside_its_bounds_is_refused(): void {
		$arguments = JsonSchemaCompiler::restArguments( self::fields() );

		$this->assertRefused( 'rest_too_few_items', array(), $arguments['lines'], 'lines' );
		$this->assertRefused( 'rest_too_many_items', array_fill( 0, 3, array( 'variant_id' => 1 ) ), $arguments['lines'], 'lines' );
	}

	/**
	 * Tests that what the declaration allows is accepted, and a number sent as text becomes an integer.
	 *
	 * @since 0.1.0
	 */
	public function test_a_declared_value_is_accepted_and_sanitized(): void {
		$arguments = JsonSchemaCompiler::restArguments( self::fields() );
		$lines     = array(
			array(
				'variant_id' => '7',
				'quantity'   => 2,
			),
		);

		$this->assertTrue( rest_validate_value_from_schema( $lines, $arguments['lines'], 'lines' ) );
		$this->assertSame(
			array(
				array(
					'variant_id' => 7,
					'quantity'   => 2,
				),
			),
			rest_sanitize_value_from_schema( $lines, $arguments['lines'], 'lines' )
		);
		$this->assertTrue( rest_validate_value_from_schema( array( 'country' => 'GB' ), $arguments['address'], 'address' ) );
	}

	/**
	 * Asserts that WordPress refuses a value with a code.
	 *
	 * @since 0.1.0
	 *
	 * @param string               $code     The code expected.
	 * @param mixed                $value    The value.
	 * @param array<string, mixed> $argument The argument's compiled schema.
	 * @param string               $name     The argument's name.
	 */
	private function assertRefused( string $code, mixed $value, array $argument, string $name ): void {
		$result = rest_validate_value_from_schema( $value, $argument, $name );

		$this->assertInstanceOf( WP_Error::class, $result, sprintf( 'WordPress accepted %s.', (string) wp_json_encode( $value ) ) );
		$this->assertSame( $code, $result->get_error_code() );
	}

	/**
	 * Returns the fields: a list of one or two lines, and an address.
	 *
	 * @since 0.1.0
	 *
	 * @return list<FieldSpec> The fields.
	 */
	private static function fields(): array {
		return array(
			FieldSpec::objectList(
				'lines',
				'The lines.',
				static fn(): string => 'Lines',
				array(
					new FieldSpec( 'variant_id', FieldType::Integer, 'The variant.', static fn(): string => 'Variant', 42, required: true, minimum: 1 ),
					new FieldSpec( 'quantity', FieldType::Integer, 'The units.', static fn(): string => 'Quantity', 1, minimum: 1 ),
				),
				required: true,
				min_items: 1,
				max_items: 2
			),
			FieldSpec::object(
				'address',
				'Where the basket goes.',
				static fn(): string => 'Address',
				array(
					new FieldSpec( 'country', FieldType::String, 'The country.', static fn(): string => 'Country', 'GB', required: true, max_length: 2 ),
					new FieldSpec( 'note', FieldType::String, 'A note.', static fn(): string => 'Note', 'x' ),
				)
			),
		);
	}
}
