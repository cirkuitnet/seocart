<?php
/**
 * Tests VariantSelection: a combination's hash is over its sorted keys, never its labels
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Catalog;

use PHPUnit\Framework\TestCase;
use SEOCart\Catalog\Domain\CatalogError;
use SEOCart\Catalog\Domain\OptionAxis;
use SEOCart\Catalog\Domain\OptionMatrix;
use SEOCart\Catalog\Domain\OptionValue;
use SEOCart\Catalog\Domain\Sku;
use SEOCart\Catalog\Domain\Variant;
use SEOCart\Catalog\Domain\VariantSelection;
use SEOCart\Support\Error\CodedException;

/**
 * The combination hash is SHA-256 of the `axis_key=value_key` pairs sorted by axis key and joined by a line feed; the empty selection's is SHA-256 of the empty string, today's default variant's; a key is 1 to 32 lower-case letters, digits, "-" or "_".
 *
 * @since 0.2.0
 */
final class VariantSelectionTest extends TestCase {

	/**
	 * Tests that the hash is over the sorted pairs, whatever order they were given in.
	 *
	 * @since 0.2.0
	 */
	public function test_the_hash_is_over_the_sorted_pairs(): void {
		$given    = VariantSelection::of(
			array(
				'size'  => '250g',
				'grind' => 'whole',
			)
		);
		$reversed = VariantSelection::of(
			array(
				'grind' => 'whole',
				'size'  => '250g',
			)
		);

		$this->assertSame( hash( 'sha256', "grind=whole\nsize=250g" ), $given->hash() );
		$this->assertSame( $given->hash(), $reversed->hash() );
		$this->assertSame(
			array(
				'grind' => 'whole',
				'size'  => '250g',
			),
			$given->pairs()
		);
		$this->assertSame( 'grind=whole, size=250g', $given->toString() );
		$this->assertSame( 'whole', $given->valueOn( 'grind' ) );
		$this->assertNull( $given->valueOn( 'colour' ) );
	}

	/**
	 * Tests that the empty selection is the default variant's combination: SHA-256 of the empty string.
	 *
	 * @since 0.2.0
	 */
	public function test_the_empty_selection_hashes_as_the_default_variant(): void {
		$this->assertSame( hash( 'sha256', '' ), VariantSelection::none()->hash() );
		$this->assertSame( Variant::defaultCombinationHash(), VariantSelection::of( array() )->hash() );
		$this->assertTrue( VariantSelection::none()->isEmpty() );
		$this->assertSame( Variant::defaultCombinationHash(), Variant::byDefault( '00000000-0000-4000-8000-000000000001', Sku::of( 'A-1' ) )->combinationHash() );
	}

	/**
	 * Tests that a label never enters the hash: the same keys under other labels name the same variant, and the variant's label is the values' labels in axis order.
	 *
	 * Planted violation: in Variant::withState(), hash the label with the selection; the variant of
	 * the relabelled matrix then gets another hash, and the test fails.
	 *
	 * @since 0.2.0
	 */
	public function test_a_label_never_enters_the_hash(): void {
		$selection = VariantSelection::of(
			array(
				'size'  => '250g',
				'grind' => 'whole',
			)
		);
		$first     = self::matrix( 'Size', '250 g', 'Grind', 'Whole bean' );
		$renamed   = self::matrix( 'Weight', 'Quarter kilo', 'Grind', 'Beans' );
		$variant   = Variant::ofCombination( '00000000-0000-4000-8000-000000000002', $selection, Sku::of( 'C-1' ), 1 );

		$this->assertSame( '250 g / Whole bean', $first->labelOf( $selection ) );
		$this->assertSame( 'Quarter kilo / Beans', $renamed->labelOf( $selection ) );

		$labelled = $variant->withState( $variant->sku(), true, null, 0, $first->labelOf( $selection ), 1 );
		$renamed  = $variant->withState( $variant->sku(), true, null, 0, $renamed->labelOf( $selection ), 1 );

		$this->assertSame( $selection->hash(), $labelled->combinationHash() );
		$this->assertSame( $labelled->combinationHash(), $renamed->combinationHash(), 'A label names the variant to a shopper; it never identifies it.' );
		$this->assertSame( '250 g / Whole bean', $labelled->label() );
	}

	/**
	 * Tests that a key that is not lower-case letters, digits, "-" or "_", 1 to 32 of them, is refused as an invalid combination.
	 *
	 * @since 0.2.0
	 */
	public function test_a_key_of_another_shape_is_an_invalid_combination(): void {
		foreach ( array( array( 'Size' => '250g' ), array( 'size' => '' ), array( 'size' => 'a b' ), array( 'size' => str_repeat( 'x', 33 ) ), array( 'size=x' => 'y' ) ) as $pairs ) {
			try {
				VariantSelection::of( $pairs );

				$this->fail( 'The pairs ' . implode( ', ', array_keys( $pairs ) ) . ' were taken.' );
			} catch ( CodedException $refused ) {
				$this->assertSame( CatalogError::CombinationInvalid, $refused->errorCode() );
			}
		}
	}

	/**
	 * Tests that a list naming each axis once is the selection of those pairs, and that a list naming an axis twice is refused as an invalid combination, whichever values it names.
	 *
	 * Planted violation, shown failing and put back: in VariantSelection::ofList(), leave out the
	 * check, so the last value wins.
	 *
	 * @since 0.2.0
	 */
	public function test_a_list_naming_an_axis_twice_is_an_invalid_combination(): void {
		$selection = VariantSelection::ofList(
			array(
				array( 'size', '250g' ),
				array( 'grind', 'whole' ),
			)
		);

		$this->assertSame(
			array(
				'grind' => 'whole',
				'size'  => '250g',
			),
			$selection->pairs()
		);

		foreach ( array( '1kg', '250g' ) as $again ) {
			try {
				VariantSelection::ofList(
					array(
						array( 'size', '250g' ),
						array( 'grind', 'whole' ),
						array( 'size', $again ),
					)
				);

				$this->fail( 'An axis named twice, the second time ' . $again . ', was taken.' );
			} catch ( CodedException $refused ) {
				$this->assertSame( CatalogError::CombinationInvalid, $refused->errorCode() );
				$this->assertSame( array( 'combination' => 'size=250g, grind=whole, size=' . $again ), $refused->context() );
			}
		}
	}

	/**
	 * Returns a matrix of two axes, size first, each with one value.
	 *
	 * @since 0.2.0
	 *
	 * @param string $sizeLabel  The size axis's label.
	 * @param string $size       The 250g value's label.
	 * @param string $grindLabel The grind axis's label.
	 * @param string $grind      The whole value's label.
	 * @return OptionMatrix The matrix.
	 */
	private static function matrix( string $sizeLabel, string $size, string $grindLabel, string $grind ): OptionMatrix {
		return OptionMatrix::of(
			array(
				OptionAxis::of( 'grind', $grindLabel, 1, array( OptionValue::of( 'whole', $grind, 0 ) ) ),
				OptionAxis::of( 'size', $sizeLabel, 0, array( OptionValue::of( '250g', $size, 0 ) ) ),
			)
		);
	}
}
