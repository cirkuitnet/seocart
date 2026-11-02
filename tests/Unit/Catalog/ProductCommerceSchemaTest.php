<?php
/**
 * Tests ProductCommerceSchema: the `seocart` property is the default variant's id, the commerce fields, the options and the variants, the two translation fields and two more read-only ones, compiled once
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Catalog;

use PHPUnit\Framework\TestCase;
use SEOCart\Catalog\Application\ProductWrite\CommerceFields;
use SEOCart\Catalog\Application\ProductWrite\VariantFields;
use SEOCart\Catalog\Domain\GenerationState;
use SEOCart\Catalog\Domain\Product;
use SEOCart\Catalog\Domain\SellabilityReason;
use SEOCart\Catalog\Interfaces\Rest\ProductCommerceSchema;
use SEOCart\Inventory\Domain\StockOutcome;
use SEOCart\Support\Schema\FieldSpec;
use SEOCart\Support\Schema\FieldType;
use SEOCart\Support\Schema\JsonSchemaCompiler;

/**
 * The property is declared from CommerceFields unchanged, VariantFields' two lists, the two translation fields and the two enums, and compiled by
 * the compiler's dialect for a core-shaped resource, so a client reads the same bounds a save
 * checks, the read-only fields are never arguments, and the marker, the options and the variants
 * are sent to an editor only.
 *
 * Planted violations, each shown failing and put back:
 * - In ProductCommerceSchema::property(), leave GENERATION_STATE out of the edit-only list: the
 *   marker is sent in the view context, and the context test fails.
 * - In VariantFields::variants(), declare the variant's SKU as a new FieldSpec instead of
 *   CommerceFields' own: the shared-instance test fails.
 *
 * @since 0.1.0
 * @since 0.2.0 The options and the variants.
 */
final class ProductCommerceSchemaTest extends TestCase {

	/**
	 * Tests that the fields are the commerce fields, in their order, then the two lists, then the locale and the post translated, all writable, then the verdict and the marker, whose values are the enums' own.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 The options and the variants.
	 */
	public function test_the_fields_are_the_commerce_fields_the_lists_the_translation_fields_then_two_read_only_ones(): void {
		$fields = self::fields();

		$this->assertSame( array_merge( array( ProductCommerceSchema::VARIANT_ID ), array_keys( CommerceFields::all() ), array( VariantFields::OPTIONS, VariantFields::VARIANTS, ProductCommerceSchema::LOCALE, ProductCommerceSchema::TRANSLATION_OF, ProductCommerceSchema::SELLABILITY, ProductCommerceSchema::GENERATION_STATE ) ), array_keys( $fields ) );
		$this->assertSame( FieldType::Integer, $fields[ ProductCommerceSchema::VARIANT_ID ]->type() );
		$this->assertSame( array_merge( array_keys( CommerceFields::all() ), array( VariantFields::OPTIONS, VariantFields::VARIANTS, ProductCommerceSchema::LOCALE, ProductCommerceSchema::TRANSLATION_OF ) ), ProductCommerceSchema::writable() );
		$this->assertTrue( $fields[ ProductCommerceSchema::LOCALE ]->isNullable() && $fields[ ProductCommerceSchema::TRANSLATION_OF ]->isNullable(), 'A post that presents no product has no locale and translates nothing.' );

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
	 * Tests that the compiled property refuses other keys, sends the marker, the options and the variants in the edit context only, and makes the variant's id, the verdict and the marker read-only.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 The options and the variants, edit only.
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

		$this->assertSame( array( ProductCommerceSchema::VARIANT_ID, ProductCommerceSchema::SELLABILITY, ProductCommerceSchema::GENERATION_STATE ), $readOnly );
		$this->assertNotContains( ProductCommerceSchema::VARIANT_ID, ProductCommerceSchema::writable(), 'A client reads the variant\'s id; it never writes it.' );

		foreach ( array( VariantFields::OPTIONS, VariantFields::VARIANTS, ProductCommerceSchema::GENERATION_STATE ) as $editOnly ) {
			$this->assertSame( array( 'edit' ), $contexts[ $editOnly ], "{$editOnly} is sent to an editor only." );

			unset( $contexts[ $editOnly ] );
		}

		foreach ( $contexts as $name => $context ) {
			$this->assertSame( array( 'view', 'edit' ), $context, "{$name} is not sent in both the view and the edit contexts." );
		}

		$this->assertSame( 0, $property['properties'][ CommerceFields::PRICE_MINOR ]['minimum'] ?? null, 'The bound a save checks is the bound a client reads.' );
	}

	/**
	 * Tests that both lists compile to arrays of objects whose `items.properties` are their fields, nested lists included, with their bounds and the read-only fields marked.
	 *
	 * @since 0.2.0
	 */
	public function test_both_lists_compile_their_items_with_the_nested_fields(): void {
		$property = ProductCommerceSchema::property()['properties'];
		$options  = $property[ VariantFields::OPTIONS ];
		$variants = $property[ VariantFields::VARIANTS ];

		$this->assertSame( array( 'array', Product::MAX_AXES ), array( $options['type'], $options['maxItems'] ) );
		$this->assertSame( array( 'key', 'label', 'position', 'values' ), array_keys( $options['items']['properties'] ) );
		$this->assertSame( array( 'key', 'label' ), $options['items']['required'] );
		$this->assertFalse( $options['items']['additionalProperties'] );
		$this->assertSame( array( 'key', 'label', 'position', 'image_id', 'swatch' ), array_keys( $options['items']['properties']['values']['items']['properties'] ) );
		$this->assertSame( Product::MAX_VALUES, $options['items']['properties']['values']['maxItems'] );

		$this->assertSame( array( 'array', Product::MAX_SYNC_VARIANTS ), array( $variants['type'], $variants['maxItems'] ) );
		$this->assertSame( array( 'variant_id', 'combination', 'sku', 'enabled', 'position', 'label', 'weight_grams', 'prices', 'stock', 'sellability' ), array_keys( $variants['items']['properties'] ) );
		$this->assertSame( array( 'axis', 'value' ), $variants['items']['properties']['combination']['items']['required'] );
		$this->assertSame( array( 'currency', 'price_minor', 'compare_at_minor', 'amount_basis' ), array_keys( $variants['items']['properties']['prices']['items']['properties'] ) );
		$this->assertSame( array( 'location_uuid', 'on_hand', 'expected_on_hand', 'outcome' ), array_keys( $variants['items']['properties']['stock']['items']['properties'] ) );
		$this->assertSame( StockOutcome::OUTCOMES, $variants['items']['properties']['stock']['items']['properties']['outcome']['enum'] );

		$readOnly = array();

		foreach ( $variants['items']['properties'] as $name => $schema ) {
			if ( ! empty( $schema['readonly'] ) ) {
				$readOnly[] = $name;
			}
		}

		$this->assertSame( array( 'variant_id', 'label', 'sellability' ), $readOnly, 'A variant\'s id, label and verdict are read only.' );
		$this->assertTrue( $variants['items']['properties']['stock']['items']['properties']['outcome']['readonly'] ?? false, 'A cell\'s outcome is read only.' );
		$this->assertArrayNotHasKey( 'readonly', $options['items']['properties']['label'], 'An axis\'s label is written.' );
		$this->assertSame( array( 'edit' ), $variants['context'], 'The stock cells ride the variants, which are sent to an editor only.' );
	}

	/**
	 * Tests that a variant's weight, price, compare-at price and currency are CommerceFields' very instances, its SKU the single variant's declaration with null allowed, and the labels of axes and values are translatable.
	 *
	 * @since 0.2.0
	 */
	public function test_the_variant_fields_are_the_commerce_fields_own_instances(): void {
		$commerce = CommerceFields::all();
		$variant  = self::members( VariantFields::all()[ VariantFields::VARIANTS ] );
		$price    = self::members( $variant[ VariantFields::PRICES ] );
		$axis     = self::members( VariantFields::all()[ VariantFields::OPTIONS ] );
		$value    = self::members( $axis[ VariantFields::VALUES ] );
		$flat     = $commerce[ CommerceFields::SKU ];
		$listed   = $variant[ CommerceFields::SKU ];

		$this->assertSame( CommerceFields::listedSku(), $listed, 'An entry\'s SKU is built once.' );
		$this->assertSame( array( $flat->name(), $flat->type(), $flat->example(), $flat->maxLength() ), array( $listed->name(), $listed->type(), $listed->example(), $listed->maxLength() ), 'An entry\'s SKU is the single variant\'s declaration.' );
		$this->assertSame( array( false, true ), array( $flat->isNullable(), $listed->isNullable() ), 'Only a variant of a set may have no SKU, read as null.' );
		$this->assertSame( $commerce[ CommerceFields::WEIGHT_GRAMS ], $variant[ CommerceFields::WEIGHT_GRAMS ] );
		$this->assertSame( $commerce[ CommerceFields::PRICE_MINOR ], $price[ CommerceFields::PRICE_MINOR ] );
		$this->assertSame( $commerce[ CommerceFields::COMPARE_AT_MINOR ], $price[ CommerceFields::COMPARE_AT_MINOR ] );
		$this->assertSame( $commerce[ CommerceFields::CURRENCY ], $price[ CommerceFields::CURRENCY ] );
		$this->assertSame( CommerceFields::all(), $commerce, 'The commerce fields are built once.' );
		$this->assertTrue( $axis[ VariantFields::LABEL ]->isTranslatable() && $value[ VariantFields::LABEL ]->isTranslatable(), 'The labels of axes and values are translatable.' );
		$this->assertFalse( $variant[ VariantFields::LABEL ]->isTranslatable(), 'A variant\'s label is written from its values\' labels.' );
	}

	/**
	 * Returns the fields of the property, by name.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, FieldSpec> The fields.
	 */
	private static function fields(): array {
		$fields = array();

		foreach ( ProductCommerceSchema::fields() as $field ) {
			$fields[ $field->name() ] = $field;
		}

		return $fields;
	}

	/**
	 * Returns the fields of a list's items, by name.
	 *
	 * @since 0.2.0
	 *
	 * @param FieldSpec $declaration The list.
	 * @return array<string, FieldSpec> The fields.
	 */
	private static function members( FieldSpec $declaration ): array {
		$members = array();

		foreach ( $declaration->fields() as $field ) {
			$members[ $field->name() ] = $field;
		}

		return $members;
	}
}
