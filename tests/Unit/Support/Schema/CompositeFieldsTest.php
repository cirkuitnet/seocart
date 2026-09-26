<?php
/**
 * Tests objects and lists of objects: their declaration, each dialect, and their serialization
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Support\Schema;

use PHPUnit\Framework\TestCase;
use SEOCart\Support\Schema\FieldSpec;
use SEOCart\Support\Schema\FieldType;
use SEOCart\Support\Schema\JsonSchemaCompiler;
use SEOCart\Support\Schema\Privacy;
use SEOCart\Support\Schema\ResourceSchema;
use SEOCart\Support\Schema\SchemaException;

/**
 * A composite field is written in each dialect as an object schema of its fields, or an array of
 * them, with its bounds and no other keys; a command refuses it; its example is made from its
 * fields'; its privacy is its most private field's; and serialization applies each nested
 * field's class and drops a nested key it does not declare.
 *
 * The representative set is a required list of one to three lines, each with a required bounded
 * integer, an optional boolean and an optional, nullable personal-data note; and an optional
 * address object with a required country and a secret code.
 *
 * @since 0.1.0
 */
final class CompositeFieldsTest extends TestCase {

	/**
	 * Tests the WordPress REST arguments: `required` on each argument, a list on each object nested in one, and on each member of an object argument.
	 *
	 * An argument's own `required` says whether it must be sent, so an object argument names its
	 * required members on each of them, which WordPress also validates.
	 *
	 * @since 0.1.0
	 */
	public function test_rest_arguments(): void {
		$address = self::addressKeywords();

		$address['properties']['country']['required'] = true;
		$address['required']                          = false;

		$this->assertSame(
			array(
				'lines'   => array( 'type' => 'array' ) + self::linesKeywords() + array( 'required' => true ),
				'address' => $address,
			),
			JsonSchemaCompiler::restArguments( self::fields() )
		);
	}

	/**
	 * Tests the WordPress object schema: draft 4, the nested objects written as in the arguments.
	 *
	 * @since 0.1.0
	 */
	public function test_wordpress_schema(): void {
		$this->assertSame(
			array(
				'$schema'    => JsonSchemaCompiler::WORDPRESS_SCHEMA_DRAFT,
				'title'      => 'Basket',
				'type'       => 'object',
				'properties' => array(
					'lines'   => array( 'type' => 'array' ) + self::linesKeywords(),
					'address' => self::addressKeywords(),
				),
				'required'   => array( 'lines' ),
			),
			JsonSchemaCompiler::wordPressSchema( 'Basket', self::fields() )
		);
	}

	/**
	 * Tests the OpenAPI object schema: the nested objects as in the other dialects, and each composite field's example made from its fields'.
	 *
	 * @since 0.1.0
	 */
	public function test_openapi_schema(): void {
		$line = array(
			'variant_id' => 42,
			'gift'       => true,
			'note'       => 'For Ada',
		);

		$this->assertSame(
			array(
				'type'       => 'object',
				'properties' => array(
					'lines'   => array( 'type' => 'array' ) + self::linesKeywords() + array( 'examples' => array( array( $line ) ) ),
					'address' => self::addressKeywords() + array(
						'examples' => array(
							array(
								'country' => 'GB',
								'code'    => 'X9',
							),
						),
					),
				),
				'required'   => array( 'lines' ),
			),
			JsonSchemaCompiler::openApiSchema( self::fields() )
		);
	}

	/**
	 * Tests that a command refuses a composite field.
	 *
	 * @since 0.1.0
	 */
	public function test_a_command_refuses_a_composite_field(): void {
		$this->expectException( SchemaException::class );
		$this->expectExceptionMessage( 'which a command cannot take as an argument' );

		JsonSchemaCompiler::cliSynopsis( self::fields(), array() );
	}

	/**
	 * Tests that a composite field's privacy is its most private field's, and that outputs carry each nested field by its own class.
	 *
	 * @since 0.1.0
	 */
	public function test_nested_fields_keep_their_own_privacy(): void {
		list( $lines, $address ) = self::fields();

		$this->assertSame( Privacy::Pii, $lines->privacy() );
		$this->assertSame( Privacy::Secret, $address->privacy() );

		$carried = $lines->forOutput();

		$this->assertNotNull( $carried );
		$this->assertSame( array( 'variant_id', 'gift', 'note' ), array_map( static fn( FieldSpec $field ): string => $field->name(), $carried->fields() ) );
		$this->assertFalse( $carried->fields()[2]->isRequired(), 'A personal-data field is optional in every output.' );
		$this->assertSame( array( 'country' ), array_map( static fn( FieldSpec $field ): string => $field->name(), $address->forOutput()?->fields() ?? array() ), 'A secret never appears in an output.' );

		$secretOnly = FieldSpec::object( 'vault', 'Only a secret.', static fn(): string => 'Vault', array( self::code() ) );

		$this->assertNull( $secretOnly->forOutput(), 'An object of secrets alone is carried by no output.' );
	}

	/**
	 * Tests that serialization applies each nested field's class, and drops a nested key the declaration does not list.
	 *
	 * @since 0.1.0
	 */
	public function test_serialization_applies_nested_classes_and_drops_undeclared_keys(): void {
		$schema = new ResourceSchema( 'Basket', self::fields() );
		$values = array(
			'lines'   => array(
				array(
					'variant_id' => 7,
					'gift'       => false,
					'note'       => 'For Ada',
					'price'      => 999,
				),
			),
			'address' => array(
				'country' => 'DE',
				'code'    => 'S3',
				'street'  => 'Hauptstraße 1',
			),
			'extra'   => 'dropped',
		);

		$this->assertSame(
			array(
				'lines'   => array(
					array(
						'variant_id' => 7,
						'gift'       => false,
					),
				),
				'address' => array( 'country' => 'DE' ),
			),
			$schema->serialize( $values, false )
		);
		$this->assertSame( 'For Ada', $schema->serialize( $values, true )['lines'][0]['note'] ?? null, 'A user who may see personal data sees the note.' );
	}

	/**
	 * Tests that a composite field's value must be an array.
	 *
	 * @since 0.1.0
	 */
	public function test_a_composite_value_that_is_not_an_array_is_refused(): void {
		$this->expectException( SchemaException::class );
		$this->expectExceptionMessage( 'not an object or a list' );

		( new ResourceSchema( 'Basket', self::fields() ) )->serialize( array( 'lines' => 'three' ), true );
	}

	/**
	 * Tests the declarations a composite field refuses.
	 *
	 * @since 0.1.0
	 */
	public function test_wrong_composite_declarations_are_refused(): void {
		$label   = static fn(): string => 'Field';
		$country = self::country();
		$refused = array(
			'an object without fields'      => static fn() => FieldSpec::object( 'empty', 'No fields.', $label, array() ),
			'fields on an integer'          => static fn() => new FieldSpec( 'count', FieldType::Integer, 'A count.', $label, 1, fields: array( $country ) ),
			'bounds on an object'           => static fn() => new FieldSpec( 'thing', FieldType::Object, 'A thing.', $label, array(), fields: array( $country ), max_items: 3 ),
			'a maximum of no items'         => static fn() => FieldSpec::objectList( 'things', 'Things.', $label, array( $country ), max_items: 0 ),
			'a maximum below the minimum'   => static fn() => FieldSpec::objectList( 'things', 'Things.', $label, array( $country ), min_items: 3, max_items: 2 ),
			'a default on an object'        => static fn() => new FieldSpec( 'thing', FieldType::Object, 'A thing.', $label, array(), default_value: 'x', fields: array( $country ) ),
			'a privacy class on an object'  => static fn() => new FieldSpec( 'thing', FieldType::Object, 'A thing.', $label, array(), privacy: Privacy::Pii, fields: array( $country ) ),
			'two fields of one name'        => static fn() => FieldSpec::object( 'thing', 'A thing.', $label, array( $country, $country ) ),
			'an example on an object'       => static fn() => new FieldSpec( 'thing', FieldType::Object, 'A thing.', $label, 'x', fields: array( $country ) ),
			'a text example on a boolean'   => static fn() => new FieldSpec( 'flag', FieldType::Boolean, 'A flag.', $label, 'yes' ),
			'a boolean example on a string' => static fn() => new FieldSpec( 'word', FieldType::String, 'A word.', $label, true ),
		);

		foreach ( $refused as $case => $declare ) {
			try {
				$declare();
				$this->fail( sprintf( 'Accepted %s.', $case ) );
			} catch ( SchemaException $expected ) {
				$this->addToAssertionCount( 1 );
			}
		}
	}

	/**
	 * Returns the keywords of the representative list, without its type.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed> Its items, bounds and description.
	 */
	private static function linesKeywords(): array {
		return array(
			'items'       => array(
				'type'                 => 'object',
				'properties'           => array(
					'variant_id' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => 'The variant.',
					),
					'gift'       => array(
						'type'        => 'boolean',
						'description' => 'Whether the line is a gift.',
					),
					'note'       => array(
						'type'        => array( 'string', 'null' ),
						'maxLength'   => 40,
						'description' => 'A note for the recipient.',
					),
				),
				'required'             => array( 'variant_id' ),
				'additionalProperties' => false,
			),
			'minItems'    => 1,
			'maxItems'    => 3,
			'description' => 'The lines.',
		);
	}

	/**
	 * Returns the keywords of the representative object.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed> Its type, members and description.
	 */
	private static function addressKeywords(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'country' => array(
					'type'        => 'string',
					'maxLength'   => 2,
					'description' => 'The country.',
				),
				'code'    => array(
					'type'        => 'string',
					'description' => 'A door code.',
				),
			),
			'required'             => array( 'country' ),
			'additionalProperties' => false,
			'description'          => 'Where the basket goes.',
		);
	}

	/**
	 * Returns the representative set: a list of lines and an address.
	 *
	 * @since 0.1.0
	 *
	 * @return list<FieldSpec> The two fields.
	 */
	private static function fields(): array {
		return array(
			FieldSpec::objectList(
				'lines',
				'The lines.',
				static fn(): string => 'Lines',
				array(
					new FieldSpec( 'variant_id', FieldType::Integer, 'The variant.', static fn(): string => 'Variant', 42, required: true, minimum: 1 ),
					new FieldSpec( 'gift', FieldType::Boolean, 'Whether the line is a gift.', static fn(): string => 'Gift', true ),
					new FieldSpec( 'note', FieldType::String, 'A note for the recipient.', static fn(): string => 'Note', 'For Ada', nullable: true, max_length: 40, privacy: Privacy::Pii ),
				),
				required: true,
				min_items: 1,
				max_items: 3
			),
			FieldSpec::object( 'address', 'Where the basket goes.', static fn(): string => 'Address', array( self::country(), self::code() ) ),
		);
	}

	/**
	 * Returns a required country field.
	 *
	 * @since 0.1.0
	 *
	 * @return FieldSpec The field.
	 */
	private static function country(): FieldSpec {
		return new FieldSpec( 'country', FieldType::String, 'The country.', static fn(): string => 'Country', 'GB', required: true, max_length: 2 );
	}

	/**
	 * Returns a secret field.
	 *
	 * @since 0.1.0
	 *
	 * @return FieldSpec The field.
	 */
	private static function code(): FieldSpec {
		return new FieldSpec( 'code', FieldType::String, 'A door code.', static fn(): string => 'Code', 'X9', privacy: Privacy::Secret );
	}
}
