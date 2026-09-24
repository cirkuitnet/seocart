<?php
/**
 * Tests ProductCommerceSchema: the `seocart` property is the commerce fields plus two read-only ones, compiled once
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Catalog;

use PHPUnit\Framework\TestCase;
use SEOCart\Catalog\Application\ProductWrite\CommerceFields;
use SEOCart\Catalog\Domain\GenerationState;
use SEOCart\Catalog\Domain\SellabilityReason;
use SEOCart\Catalog\Interfaces\Rest\ProductCommerceSchema;
use SEOCart\Support\Schema\FieldSpec;
use SEOCart\Support\Schema\JsonSchemaCompiler;

/**
 * The property is declared from CommerceFields unchanged and from the two enums, and compiled by
 * the compiler's dialect for a core-shaped resource, so a client reads the same bounds a save
 * checks, the read-only fields are never arguments, and the marker is sent to an editor only.
 *
 * Planted violation: in ProductCommerceSchema::property(), leave GENERATION_STATE out of the
 * edit-only list: the marker is sent in the view context, and the context test fails.
 *
 * @since 0.1.0
 */
final class ProductCommerceSchemaTest extends TestCase {

	/**
	 * Tests that the fields are the commerce fields, in their order, then the verdict and the marker, whose values are the enums' own.
	 *
	 * @since 0.1.0
	 */
	public function test_the_fields_are_the_commerce_fields_then_two_read_only_ones(): void {
		$fields = array();

		foreach ( ProductCommerceSchema::fields() as $field ) {
			$fields[ $field->name() ] = $field;
		}

		$this->assertSame( array_merge( array_keys( CommerceFields::all() ), array( ProductCommerceSchema::SELLABILITY, ProductCommerceSchema::GENERATION_STATE ) ), array_keys( $fields ) );
		$this->assertSame( array_keys( CommerceFields::all() ), ProductCommerceSchema::writable() );

		$property = ProductCommerceSchema::property()['properties'];

		foreach ( JsonSchemaCompiler::restArguments( array_values( CommerceFields::all() ) ) as $name => $argument ) {
			unset( $argument['required'], $property[ $name ]['context'], $property[ $name ]['readonly'] );

			$this->assertSame( $argument, $property[ $name ], "{$name} is not compiled from CommerceFields' own declaration." );
		}

		$this->assertSame( array_map( static fn( SellabilityReason $reason ): string => $reason->value, SellabilityReason::cases() ), $fields[ ProductCommerceSchema::SELLABILITY ]->allowedValues() );
		$this->assertSame( array_map( static fn( GenerationState $state ): string => $state->value, GenerationState::cases() ), $fields[ ProductCommerceSchema::GENERATION_STATE ]->allowedValues() );
		$this->assertSame( array(), array_filter( $fields, static fn( FieldSpec $field ): bool => $field->isRequired() ), 'A field of a partially updated resource cannot be required.' );
	}

	/**
	 * Tests that the compiled property refuses other keys, sends the marker in the edit context only, and makes the two read-only fields read-only.
	 *
	 * @since 0.1.0
	 */
	public function test_the_property_is_compiled_with_its_contexts_and_read_only_fields(): void {
		$property = ProductCommerceSchema::property();

		$this->assertFalse( $property['additionalProperties'] );
		$this->assertSame( array( 'view', 'edit' ), $property['context'] );

		$readOnly = array();
		$contexts = array();

		foreach ( $property['properties'] as $name => $schema ) {
			$contexts[ $name ] = $schema['context'];

			if ( ! empty( $schema['readonly'] ) ) {
				$readOnly[] = $name;
			}
		}

		$this->assertSame( array( ProductCommerceSchema::SELLABILITY, ProductCommerceSchema::GENERATION_STATE ), $readOnly );
		$this->assertSame( array( 'edit' ), $contexts[ ProductCommerceSchema::GENERATION_STATE ] );

		unset( $contexts[ ProductCommerceSchema::GENERATION_STATE ] );

		foreach ( $contexts as $name => $context ) {
			$this->assertSame( array( 'view', 'edit' ), $context, "{$name} is not sent in both the view and the edit contexts." );
		}

		$this->assertSame( 0, $property['properties'][ CommerceFields::PRICE_MINOR ]['minimum'] ?? null, 'The bound a save checks is the bound a client reads.' );
	}
}
