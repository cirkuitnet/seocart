<?php
/**
 * Tests CommerceInput: a save's commerce fields, each checked against its one declaration
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Catalog;

use PHPUnit\Framework\TestCase;
use SEOCart\Catalog\Application\ProductWrite\CommerceFields;
use SEOCart\Catalog\Application\ProductWrite\CommerceInput;
use SEOCart\Catalog\Domain\Sku;

/**
 * The input takes the fields CommerceFields declares, checks each value by its declaration, and
 * tells a field left out from one given as null.
 *
 * Planted violation: in CommerceInput::checked(), drop the minimum check: the negative price is
 * accepted and the refusal test fails.
 *
 * @since 0.1.0
 */
final class CommerceInputTest extends TestCase {

	/**
	 * Tests that the declared fields are the five the product write reads, keyed by name.
	 *
	 * @since 0.1.0
	 */
	public function test_the_fields_are_declared_once_by_name(): void {
		$this->assertSame(
			array( CommerceFields::SKU, CommerceFields::PRICE_MINOR, CommerceFields::CURRENCY, CommerceFields::COMPARE_AT_MINOR, CommerceFields::WEIGHT_GRAMS ),
			array_keys( CommerceFields::all() )
		);
		$this->assertSame( Sku::MAX_LENGTH, CommerceFields::all()[ CommerceFields::SKU ]->maxLength(), 'The SKU field takes its length from the SKU\'s own rule.' );
	}

	/**
	 * Tests that given fields are kept in the declared order, left-out fields are absent and a null price is given.
	 *
	 * @since 0.1.0
	 */
	public function test_a_field_left_out_is_not_a_field_given_as_null(): void {
		$input = CommerceInput::fromArray(
			array(
				'price_minor' => null,
				'sku'         => 'SKU-1',
			)
		);

		$this->assertSame( array( CommerceFields::SKU, CommerceFields::PRICE_MINOR ), $input->fields() );
		$this->assertTrue( $input->has( CommerceFields::PRICE_MINOR ) );
		$this->assertNull( $input->priceMinor(), 'A price given as null removes the price.' );
		$this->assertFalse( $input->has( CommerceFields::WEIGHT_GRAMS ), 'A field left out keeps its stored value.' );
		$this->assertSame( 'SKU-1', $input->sku() );
	}

	/**
	 * Tests that a value its declaration refuses is refused before any save.
	 *
	 * @dataProvider refused
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $values The fields.
	 */
	public function test_a_value_its_declaration_refuses_is_refused( array $values ): void {
		$this->expectException( \InvalidArgumentException::class );

		CommerceInput::fromArray( $values );
	}

	/**
	 * Returns values the declarations refuse.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{0: array<string, mixed>}> The cases.
	 */
	public static function refused(): array {
		return array(
			'an unknown field'           => array( array( 'colour' => 'red' ) ),
			'a negative price'           => array( array( 'price_minor' => -1 ) ),
			'a price that is text'       => array( array( 'price_minor' => '1999' ) ),
			'a SKU given as null'        => array( array( 'sku' => null ) ),
			'a SKU that is too long'     => array( array( 'sku' => str_repeat( 'A', Sku::MAX_LENGTH + 1 ) ) ),
			'a currency code too long'   => array( array( 'currency' => 'USDX' ) ),
			'a weight beyond its column' => array( array( 'weight_grams' => CommerceFields::WEIGHT_MAX_GRAMS + 1 ) ),
		);
	}
}
