<?php
/**
 * Tests each dialect the compiler writes, and every way the dialects differ
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
use SEOCart\Support\Schema\SchemaException;

/**
 * One test per dialect compiles the same representative field set and compares the whole result
 * with the structure written out by hand; one more test states each difference between the
 * dialects on its own.
 *
 * The representative set has a required uuid, a required bounded integer, an optional closed set
 * of text values with a default, an optional integer with a default, and an optional, nullable,
 * length-limited personal-data text.
 *
 * @since 0.1.0
 */
final class JsonSchemaCompilerTest extends TestCase {

	/**
	 * Tests the WordPress REST arguments: one schema per field, `required` on each.
	 *
	 * @since 0.1.0
	 */
	public function test_rest_arguments(): void {
		$this->assertSame(
			array(
				'item_id' => array(
					'type'        => 'string',
					'format'      => 'uuid',
					'description' => 'Identifier of the item.',
					'required'    => true,
				),
				'delta'   => array(
					'type'        => 'integer',
					'minimum'     => -100,
					'maximum'     => 100,
					'description' => 'Signed change.',
					'required'    => true,
				),
				'reason'  => array(
					'type'        => 'string',
					'enum'        => array( 'recount', 'damage' ),
					'default'     => 'recount',
					'description' => 'Why the level changed.',
					'required'    => false,
				),
				'count'   => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'default'     => 1,
					'description' => 'How many times.',
					'required'    => false,
				),
				'note'    => array(
					'type'        => array( 'string', 'null' ),
					'maxLength'   => 50,
					'description' => 'A note.',
					'required'    => false,
				),
			),
			JsonSchemaCompiler::restArguments( self::fields() )
		);
	}

	/**
	 * Tests the WordPress object schema for REST responses and Abilities: draft 4, `required` on the object.
	 *
	 * @since 0.1.0
	 */
	public function test_wordpress_schema(): void {
		$this->assertSame(
			array(
				'$schema'    => 'http://json-schema.org/draft-04/schema#',
				'title'      => 'Adjustment',
				'type'       => 'object',
				'properties' => array(
					'item_id' => array(
						'type'        => 'string',
						'format'      => 'uuid',
						'description' => 'Identifier of the item.',
					),
					'delta'   => array(
						'type'        => 'integer',
						'minimum'     => -100,
						'maximum'     => 100,
						'description' => 'Signed change.',
					),
					'reason'  => array(
						'type'        => 'string',
						'enum'        => array( 'recount', 'damage' ),
						'default'     => 'recount',
						'description' => 'Why the level changed.',
					),
					'count'   => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'default'     => 1,
						'description' => 'How many times.',
					),
					'note'    => array(
						'type'        => array( 'string', 'null' ),
						'maxLength'   => 50,
						'description' => 'A note.',
					),
				),
				'required'   => array( 'item_id', 'delta' ),
			),
			JsonSchemaCompiler::wordPressSchema( 'Adjustment', self::fields() )
		);
	}

	/**
	 * Tests the OpenAPI 3.1 object schema: no `$schema`, `required` on the object, the example as `examples`.
	 *
	 * @since 0.1.0
	 */
	public function test_openapi_schema(): void {
		$this->assertSame(
			array(
				'type'       => 'object',
				'properties' => array(
					'item_id' => array(
						'type'        => 'string',
						'format'      => 'uuid',
						'description' => 'Identifier of the item.',
						'examples'    => array( '0b6f2c52-7f0a-4c1e-9a55-3f0a4e0f6d21' ),
					),
					'delta'   => array(
						'type'        => 'integer',
						'minimum'     => -100,
						'maximum'     => 100,
						'description' => 'Signed change.',
						'examples'    => array( -3 ),
					),
					'reason'  => array(
						'type'        => 'string',
						'enum'        => array( 'recount', 'damage' ),
						'default'     => 'recount',
						'description' => 'Why the level changed.',
						'examples'    => array( 'damage' ),
					),
					'count'   => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'default'     => 1,
						'description' => 'How many times.',
						'examples'    => array( 2 ),
					),
					'note'    => array(
						'type'        => array( 'string', 'null' ),
						'maxLength'   => 50,
						'description' => 'A note.',
						'examples'    => array( 'Found two.' ),
					),
				),
				'required'   => array( 'item_id', 'delta' ),
			),
			JsonSchemaCompiler::openApiSchema( self::fields() )
		);
	}

	/**
	 * Tests the WP-CLI synopsis: positional fields first, options next, the format option last.
	 *
	 * @since 0.1.0
	 */
	public function test_cli_synopsis(): void {
		$this->assertSame(
			array(
				array(
					'type'        => 'positional',
					'name'        => 'item_id',
					'description' => 'Identifier of the item.',
					'optional'    => false,
				),
				array(
					'type'        => 'assoc',
					'name'        => 'delta',
					'description' => 'Signed change.',
					'optional'    => false,
				),
				array(
					'type'        => 'assoc',
					'name'        => 'reason',
					'description' => 'Why the level changed.',
					'optional'    => true,
					'default'     => 'recount',
					'options'     => array( 'recount', 'damage' ),
				),
				array(
					'type'        => 'assoc',
					'name'        => 'count',
					'description' => 'How many times.',
					'optional'    => true,
					'default'     => '1',
				),
				array(
					'type'        => 'assoc',
					'name'        => 'note',
					'description' => 'A note.',
					'optional'    => true,
				),
				array(
					'type'        => 'assoc',
					'name'        => 'format',
					'description' => 'Render the result in a particular format.',
					'optional'    => true,
					'default'     => 'table',
					'options'     => array( 'table', 'json' ),
				),
			),
			JsonSchemaCompiler::cliSynopsis( self::fields(), array( 'item_id' ) )
		);
	}

	/**
	 * Tests the property of a core-shaped REST resource: contexts on it and on each of its properties, `readonly` where listed, no other key accepted.
	 *
	 * @since 0.1.0
	 */
	public function test_rest_object_property(): void {
		$this->assertSame(
			array(
				'description'          => 'The adjustment.',
				'type'                 => 'object',
				'context'              => array( 'view', 'edit' ),
				'properties'           => array(
					'reason' => array(
						'type'        => 'string',
						'enum'        => array( 'recount', 'damage' ),
						'default'     => 'recount',
						'description' => 'Why the level changed.',
						'context'     => array( 'view', 'edit' ),
					),
					'count'  => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'default'     => 1,
						'description' => 'How many times.',
						'context'     => array( 'view', 'edit' ),
						'readonly'    => true,
					),
					'note'   => array(
						'type'        => array( 'string', 'null' ),
						'maxLength'   => 50,
						'description' => 'A note.',
						'context'     => array( 'edit' ),
						'readonly'    => true,
					),
				),
				'additionalProperties' => false,
			),
			JsonSchemaCompiler::restObjectProperty( 'The adjustment.', self::optionalFields(), array( 'count', 'note' ), array( 'note' ) )
		);
	}

	/**
	 * Tests that the property of a core-shaped resource refuses a required field, which a partial update could not honour.
	 *
	 * @since 0.1.0
	 */
	public function test_the_rest_object_property_refuses_a_required_field(): void {
		$this->expectException( SchemaException::class );
		$this->expectExceptionMessage( 'The field item_id is required' );

		JsonSchemaCompiler::restObjectProperty( 'The adjustment.', self::fields(), array(), array() );
	}

	/**
	 * Tests that the property of a core-shaped resource refuses a listed name that is not one of its fields.
	 *
	 * @since 0.1.0
	 */
	public function test_the_rest_object_property_refuses_an_unknown_listed_name(): void {
		$this->expectException( SchemaException::class );
		$this->expectExceptionMessage( 'The name missing is listed as read-only or edit-only' );

		JsonSchemaCompiler::restObjectProperty( 'The adjustment.', self::optionalFields(), array(), array( 'missing' ) );
	}

	/**
	 * Tests each difference between the dialects on its own, and that they differ nowhere else.
	 *
	 * @since 0.1.0
	 */
	public function test_the_dialects_differ_exactly_where_declared(): void {
		$fields    = self::fields();
		$arguments = JsonSchemaCompiler::restArguments( $fields );
		$wordpress = JsonSchemaCompiler::wordPressSchema( 'Adjustment', $fields );
		$openapi   = JsonSchemaCompiler::openApiSchema( $fields );
		$cli       = JsonSchemaCompiler::cliSynopsis( $fields, array() );

		// Where `required` lives: a boolean on each REST argument, a list on the object in both object dialects, `optional` in WP-CLI.
		$this->assertSame( array( true, true, false, false, false ), array_column( $arguments, 'required' ) );
		$this->assertSame( array( 'item_id', 'delta' ), $wordpress['required'] );
		$this->assertSame( array( 'item_id', 'delta' ), $openapi['required'] );
		$this->assertSame( array( false, false, true, true, true, true ), array_column( $cli, 'optional' ) );

		// The draft is declared by the WordPress dialect only; OpenAPI 3.1 declares its dialect once per document.
		$this->assertSame( 'http://json-schema.org/draft-04/schema#', $wordpress['$schema'] );
		$this->assertArrayNotHasKey( '$schema', $openapi );

		// Only OpenAPI carries the examples.
		$this->assertSame( array( 'Found two.' ), $openapi['properties']['note']['examples'] );
		$this->assertArrayNotHasKey( 'examples', $wordpress['properties']['note'] );
		$this->assertArrayNotHasKey( 'examples', $arguments['note'] );

		// Nullable is a type list in every JSON dialect; the OpenAPI 3.0 `nullable` keyword appears nowhere.
		foreach ( array( $arguments['note'], $wordpress['properties']['note'], $openapi['properties']['note'] ) as $note ) {
			$this->assertSame( array( 'string', 'null' ), $note['type'] );
		}

		$this->assertStringNotContainsString( '"nullable"', (string) json_encode( array( $arguments, $wordpress, $openapi, $cli ) ) );

		// A uuid is a string with the uuid format in every JSON dialect, and plain text on the command line.
		foreach ( array( $arguments['item_id'], $wordpress['properties']['item_id'], $openapi['properties']['item_id'] ) as $item_id ) {
			$this->assertSame( 'string', $item_id['type'] );
			$this->assertSame( 'uuid', $item_id['format'] );
		}

		$this->assertArrayNotHasKey( 'format', $cli[0] );

		// A default is a value in the JSON dialects and text on the command line; a closed set is `enum` there and `options` here.
		$this->assertSame( 1, $arguments['count']['default'] );
		$this->assertSame( '1', $cli[3]['default'] );
		$this->assertSame( $arguments['reason']['enum'], $cli[2]['options'] );

		// Apart from `required` and `examples`, a property is the same in the three JSON dialects.
		foreach ( $fields as $field ) {
			$name     = $field->name();
			$argument = $arguments[ $name ];
			$property = $openapi['properties'][ $name ];

			unset( $argument['required'], $property['examples'] );

			$this->assertSame( $argument, $wordpress['properties'][ $name ], "{$name}: the REST argument and the WordPress property differ beyond `required`." );
			$this->assertSame( $argument, $property, "{$name}: the REST argument and the OpenAPI property differ beyond `required` and `examples`." );
		}

		// The property of a core-shaped resource differs from the REST arguments by `context` and `readonly` only, and carries no `required`.
		$optional = JsonSchemaCompiler::restArguments( self::optionalFields() );
		$resource = JsonSchemaCompiler::restObjectProperty( 'The adjustment.', self::optionalFields(), array( 'count' ), array() );

		$this->assertArrayNotHasKey( 'required', $resource );

		foreach ( $resource['properties'] as $name => $property ) {
			$argument = $optional[ $name ];

			unset( $argument['required'], $property['context'], $property['readonly'] );

			$this->assertSame( $argument, $property, "{$name}: the REST argument and the core-shaped property differ beyond `required`, `context` and `readonly`." );
		}
	}

	/**
	 * Tests that an object with no field has neither properties nor a required list.
	 *
	 * @since 0.1.0
	 */
	public function test_an_empty_field_list_compiles_to_a_bare_object(): void {
		$this->assertSame( array( 'type' => 'object' ), JsonSchemaCompiler::openApiSchema( array() ) );
		$this->assertSame( array(), JsonSchemaCompiler::restArguments( array() ) );
	}

	/**
	 * Tests that the synopsis refuses a positional name that is not a field.
	 *
	 * @since 0.1.0
	 */
	public function test_the_synopsis_refuses_an_unknown_positional_argument(): void {
		$this->expectException( SchemaException::class );
		$this->expectExceptionMessage( 'The positional argument missing is not one of the fields.' );

		JsonSchemaCompiler::cliSynopsis( self::fields(), array( 'missing' ) );
	}

	/**
	 * Tests that the synopsis refuses a field that would shadow the format option.
	 *
	 * @since 0.1.0
	 */
	public function test_the_synopsis_refuses_a_field_named_like_the_format_option(): void {
		$this->expectException( SchemaException::class );
		$this->expectExceptionMessage( 'has the name of the option every command prints its result with' );

		JsonSchemaCompiler::cliSynopsis(
			array(
				new FieldSpec(
					name: 'format',
					type: FieldType::String,
					description: 'A format.',
					label: static fn(): string => 'Format',
					example: 'a4'
				),
			),
			array()
		);
	}

	/**
	 * Returns the fields of the representative set that are not required.
	 *
	 * @since 0.1.0
	 *
	 * @return list<FieldSpec> The fields.
	 */
	private static function optionalFields(): array {
		return array_values( array_filter( self::fields(), static fn( FieldSpec $field ): bool => ! $field->isRequired() ) );
	}

	/**
	 * Returns the representative field set.
	 *
	 * @since 0.1.0
	 *
	 * @return list<FieldSpec> The fields.
	 */
	private static function fields(): array {
		return array(
			new FieldSpec(
				name: 'item_id',
				type: FieldType::Uuid,
				description: 'Identifier of the item.',
				label: static fn(): string => 'Item',
				example: '0b6f2c52-7f0a-4c1e-9a55-3f0a4e0f6d21',
				required: true
			),
			new FieldSpec(
				name: 'delta',
				type: FieldType::Integer,
				description: 'Signed change.',
				label: static fn(): string => 'Change',
				example: -3,
				required: true,
				minimum: -100,
				maximum: 100
			),
			new FieldSpec(
				name: 'reason',
				type: FieldType::String,
				description: 'Why the level changed.',
				label: static fn(): string => 'Reason',
				example: 'damage',
				default_value: 'recount',
				allowed: array( 'recount', 'damage' )
			),
			new FieldSpec(
				name: 'count',
				type: FieldType::Integer,
				description: 'How many times.',
				label: static fn(): string => 'Count',
				example: 2,
				default_value: 1,
				minimum: 1
			),
			new FieldSpec(
				name: 'note',
				type: FieldType::String,
				description: 'A note.',
				label: static fn(): string => 'Note',
				example: 'Found two.',
				nullable: true,
				max_length: 50,
				privacy: Privacy::Pii
			),
		);
	}
}
