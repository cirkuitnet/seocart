<?php
/**
 * Tests the resource schema: its declaration rules and the privacy rules of its serialization
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
use SEOCart\Support\Schema\Privacy;
use SEOCart\Support\Schema\ResourceSchema;
use SEOCart\Support\Schema\SchemaException;

/**
 * A secret is never serialized, personal data only for a viewer allowed to see it, and nothing the
 * schema does not declare.
 *
 * @since 0.1.0
 */
final class ResourceSchemaTest extends TestCase {

	/**
	 * Tests that a secret is left out of the serialized fields and a personal-data field becomes optional.
	 *
	 * @since 0.1.0
	 */
	public function test_serialized_fields_leave_out_secrets_and_make_personal_data_optional(): void {
		$fields = self::schema()->serializedFields();

		$this->assertSame( array( 'id', 'level', 'email' ), array_map( static fn( FieldSpec $field ): string => $field->name(), $fields ) );
		$this->assertTrue( $fields[0]->isRequired() );
		$this->assertFalse( $fields[2]->isRequired(), 'A personal-data field is left out for some viewers, so no output schema may require it.' );
		$this->assertCount( 4, self::schema()->fields(), 'fields() still returns the secret.' );
	}

	/**
	 * Tests serialization for a viewer who may not see personal data.
	 *
	 * @since 0.1.0
	 */
	public function test_a_viewer_without_the_personal_data_right_gets_public_fields_only(): void {
		$this->assertSame(
			array(
				'id'    => 'a',
				'level' => 3,
			),
			self::schema()->serialize( self::result(), false )
		);
	}

	/**
	 * Tests serialization for a viewer who may see personal data: never the secret, never an undeclared value.
	 *
	 * @since 0.1.0
	 */
	public function test_a_viewer_with_the_personal_data_right_also_gets_personal_data_but_never_a_secret(): void {
		$this->assertSame(
			array(
				'id'    => 'a',
				'level' => 3,
				'email' => 'person@example.com',
			),
			self::schema()->serialize( self::result(), true )
		);
	}

	/**
	 * Tests that the declaration order, not the result's order, decides the output order.
	 *
	 * @since 0.1.0
	 */
	public function test_the_output_follows_the_declaration_order(): void {
		$this->assertSame( array( 'id', 'level' ), array_keys( self::schema()->serialize( array_reverse( self::result(), true ), false ) ) );
	}

	/**
	 * Tests that a required field the viewer would receive must be in the result: its absence is a programming error.
	 *
	 * @since 0.1.0
	 */
	public function test_an_absent_required_field_the_viewer_would_receive_is_refused(): void {
		$result = self::result();
		unset( $result['email'] );

		$this->assertSame( array( 'id', 'level' ), array_keys( self::schema()->serialize( $result, false ) ), 'Personal data the viewer may not see is not looked for.' );

		$this->expectException( SchemaException::class );
		$this->expectExceptionMessage( 'lacks its required field email' );

		self::schema()->serialize( $result, true );
	}

	/**
	 * Tests that an absent optional field is left out of the output.
	 *
	 * @since 0.1.0
	 */
	public function test_an_absent_optional_field_is_left_out(): void {
		$schema = new ResourceSchema( 'Note', array( self::text( 'id', Privacy::Public, true ), self::text( 'comment', Privacy::Public ) ) );

		$this->assertSame( array( 'id' => 'a' ), $schema->serialize( array( 'id' => 'a' ), true ) );
		$this->assertSame(
			array(
				'id'      => 'a',
				'comment' => null,
			),
			$schema->serialize(
				array(
					'id'      => 'a',
					'comment' => null,
				),
				true
			),
			'A value that is present is serialized as it is, null included.'
		);
	}

	/**
	 * Tests that a required secret may be absent: it is never serialized, so never looked for.
	 *
	 * @since 0.1.0
	 */
	public function test_an_absent_secret_is_not_looked_for(): void {
		$result = self::result();
		unset( $result['token'] );

		$this->assertSame( array( 'id', 'level' ), array_keys( self::schema()->serialize( $result, false ) ) );
	}

	/**
	 * Provides schema declarations the constructor refuses.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{string, array<int, mixed>, string}> Name, fields, expected message part.
	 */
	public static function refusedSchemas(): array {
		return array(
			'name not PascalCase' => array( 'stock_level', array( self::text( 'id', Privacy::Public ) ), 'is not PascalCase' ),
			'no field'            => array( 'StockLevel', array(), 'has no field that could ever be serialized' ),
			'only a secret'       => array( 'StockLevel', array( self::text( 'token', Privacy::Secret ) ), 'has no field that could ever be serialized' ),
			'a field twice'       => array( 'StockLevel', array( self::text( 'id', Privacy::Public ), self::text( 'id', Privacy::Pii ) ), 'declares the field id twice' ),
			'not a field'         => array( 'StockLevel', array( 'id' ), 'must be a FieldSpec' ),
		);
	}

	/**
	 * Tests that each malformed schema is refused.
	 *
	 * @since 0.1.0
	 *
	 * @dataProvider refusedSchemas
	 *
	 * @param string            $name             The resource name.
	 * @param array<int, mixed> $fields           The fields.
	 * @param string            $expected_message Part of the message.
	 */
	public function test_a_malformed_schema_is_refused( string $name, array $fields, string $expected_message ): void {
		$this->expectException( SchemaException::class );
		$this->expectExceptionMessage( $expected_message );

		new ResourceSchema( $name, $fields );
	}

	/**
	 * Returns the schema the tests serialize with: public, public, personal data and secret.
	 *
	 * @since 0.1.0
	 *
	 * @return ResourceSchema The schema.
	 */
	private static function schema(): ResourceSchema {
		return new ResourceSchema(
			'StockLevel',
			array(
				self::text( 'id', Privacy::Public, true ),
				new FieldSpec(
					name: 'level',
					type: FieldType::Integer,
					description: 'The level.',
					label: static fn(): string => 'Level',
					example: 3,
					required: true
				),
				self::text( 'email', Privacy::Pii, true ),
				self::text( 'token', Privacy::Secret, true ),
			)
		);
	}

	/**
	 * Returns a service result for schema(), with one value the schema does not declare.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed> The result.
	 */
	private static function result(): array {
		return array(
			'id'         => 'a',
			'level'      => 3,
			'email'      => 'person@example.com',
			'token'      => 'secret-value',
			'undeclared' => 'internal',
		);
	}

	/**
	 * Declares a text field.
	 *
	 * @since 0.1.0
	 *
	 * @param string  $name     The name.
	 * @param Privacy $privacy  The privacy class.
	 * @param bool    $required Optional. Whether it is required. Default false.
	 * @return FieldSpec The field.
	 */
	private static function text( string $name, Privacy $privacy, bool $required = false ): FieldSpec {
		return new FieldSpec(
			name: $name,
			type: FieldType::String,
			description: 'A text value.',
			label: static fn(): string => 'Text',
			example: 'x',
			required: $required,
			privacy: $privacy
		);
	}
}
