<?php
/**
 * Tests the field declaration: what it carries and what it refuses
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
use SEOCart\Support\Schema\SchemaException;

/**
 * Every constraint a field can carry, and every declaration the constructor refuses.
 *
 * @since 0.1.0
 */
final class FieldSpecTest extends TestCase {

	/**
	 * Tests that a field carries every part of its declaration, and translates nothing while declared.
	 *
	 * @since 0.1.0
	 */
	public function test_a_field_carries_its_declaration_and_calls_no_label(): void {
		$labels = 0;
		$field  = new FieldSpec(
			name: 'reason',
			type: FieldType::String,
			description: 'Why the stock level changed.',
			label: static function () use ( &$labels ): string {
				++$labels;

				return 'Reason';
			},
			example: 'recount',
			default_value: 'correction',
			allowed: array( 'recount', 'correction' ),
			max_length: 20,
			privacy: Privacy::Financial,
			translatable: true
		);

		$this->assertSame( 0, $labels, 'Declaring a field called its label.' );
		$this->assertSame( 'reason', $field->name() );
		$this->assertSame( FieldType::String, $field->type() );
		$this->assertSame( 'Why the stock level changed.', $field->description() );
		$this->assertSame( 'recount', $field->example() );
		$this->assertFalse( $field->isRequired() );
		$this->assertFalse( $field->isNullable() );
		$this->assertSame( 'correction', $field->defaultValue() );
		$this->assertSame( array( 'recount', 'correction' ), $field->allowedValues() );
		$this->assertSame( 20, $field->maxLength() );
		$this->assertNull( $field->minimum() );
		$this->assertNull( $field->maximum() );
		$this->assertSame( Privacy::Financial, $field->privacy() );
		$this->assertTrue( $field->isTranslatable() );
		$this->assertSame( 'Reason', ( $field->label() )() );
		$this->assertSame( 1, $labels );
	}

	/**
	 * Tests the defaults of the optional parts.
	 *
	 * @since 0.1.0
	 */
	public function test_the_optional_parts_default_to_nothing_and_public(): void {
		$field = self::field( array() );

		$this->assertFalse( $field->isRequired() );
		$this->assertFalse( $field->isNullable() );
		$this->assertNull( $field->defaultValue() );
		$this->assertSame( array(), $field->allowedValues() );
		$this->assertNull( $field->maxLength() );
		$this->assertSame( Privacy::Public, $field->privacy() );
		$this->assertFalse( $field->isTranslatable() );
	}

	/**
	 * Tests that asOptional() returns an optional copy and leaves the field alone.
	 *
	 * @since 0.1.0
	 */
	public function test_as_optional_returns_an_optional_copy(): void {
		$field = self::field( array( 'required' => true ) );
		$copy  = $field->asOptional();

		$this->assertTrue( $field->isRequired() );
		$this->assertFalse( $copy->isRequired() );
		$this->assertNotSame( $field, $copy );
		$this->assertSame( $field->name(), $copy->name() );
	}

	/**
	 * Provides declarations the constructor refuses, each with the part of the message that names why.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{array<string, mixed>, string}> Overrides of a valid String field, and the expected message part.
	 */
	public static function refusedDeclarations(): array {
		return array(
			'name in camelCase'            => array( array( 'name' => 'itemId' ), 'is not lower-case snake_case' ),
			'name with a leading digit'    => array( array( 'name' => '1st' ), 'is not lower-case snake_case' ),
			'empty description'            => array( array( 'description' => ' ' ), 'needs a machine description' ),
			'two-line description'         => array( array( 'description' => "One.\nTwo." ), 'needs a machine description' ),
			'values on an integer'         => array(
				array(
					'type'    => FieldType::Integer,
					'example' => 1,
					'allowed' => array( 'a' ),
				),
				'only a String field can be limited',
			),
			'values on a uuid'             => array(
				array(
					'type'    => FieldType::Uuid,
					'allowed' => array( 'a' ),
				),
				'only a String field can be limited',
			),
			'maximum length on an integer' => array(
				array(
					'type'       => FieldType::Integer,
					'example'    => 1,
					'max_length' => 3,
				),
				'applies to a String field only',
			),
			'maximum length of zero'       => array( array( 'max_length' => 0 ), 'must be at least 1' ),
			'minimum on a string'          => array( array( 'minimum' => 1 ), 'applies to an Integer field only' ),
			'maximum on a uuid'            => array(
				array(
					'type'    => FieldType::Uuid,
					'maximum' => 1,
				),
				'applies to an Integer field only',
			),
			'minimum above maximum'        => array(
				array(
					'type'    => FieldType::Integer,
					'example' => 1,
					'minimum' => 5,
					'maximum' => 4,
				),
				'minimum greater than its maximum',
			),
			'required with a default'      => array(
				array(
					'required'      => true,
					'default_value' => 'x',
				),
				'is required and has a default',
			),
			'nullable with values'         => array(
				array(
					'nullable' => true,
					'allowed'  => array( 'a' ),
				),
				'lists values and is nullable',
			),
			'translatable integer'         => array(
				array(
					'type'         => FieldType::Integer,
					'example'      => 1,
					'translatable' => true,
				),
				'only a String field can be',
			),
			'a value listed twice'         => array( array( 'allowed' => array( 'a', 'a' ) ), 'must be distinct, non-empty strings' ),
			'an empty value'               => array( array( 'allowed' => array( '' ) ), 'must be distinct, non-empty strings' ),
			'a value that is not a string' => array( array( 'allowed' => array( 1 ) ), 'must be distinct, non-empty strings' ),
		);
	}

	/**
	 * Tests that each malformed declaration is refused with a message that names the field and the rule.
	 *
	 * @since 0.1.0
	 *
	 * @dataProvider refusedDeclarations
	 *
	 * @param array<string, mixed> $overrides        What differs from a valid declaration.
	 * @param string               $expected_message Part of the message.
	 */
	public function test_a_malformed_declaration_is_refused( array $overrides, string $expected_message ): void {
		$this->expectException( SchemaException::class );
		$this->expectExceptionMessage( $expected_message );

		self::field( $overrides );
	}

	/**
	 * Builds a field from a valid String declaration and overrides.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $overrides Named constructor arguments to replace or add.
	 * @return FieldSpec The field.
	 */
	private static function field( array $overrides ): FieldSpec {
		$arguments = array_merge(
			array(
				'name'        => 'note',
				'type'        => FieldType::String,
				'description' => 'A note.',
				'label'       => static fn(): string => 'Note',
				'example'     => 'text',
			),
			$overrides
		);

		return new FieldSpec( ...$arguments );
	}
}
