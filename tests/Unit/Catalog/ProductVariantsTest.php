<?php
/**
 * Tests Product over a variant set: what a set is refused for, and when it starts a new generation
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Catalog;

use PHPUnit\Framework\TestCase;
use SEOCart\Catalog\Domain\CatalogError;
use SEOCart\Catalog\Domain\GenerationState;
use SEOCart\Catalog\Domain\OptionAxis;
use SEOCart\Catalog\Domain\OptionMatrix;
use SEOCart\Catalog\Domain\OptionValue;
use SEOCart\Catalog\Domain\Product;
use SEOCart\Catalog\Domain\ProductPostBinding;
use SEOCart\Catalog\Domain\Sku;
use SEOCart\Catalog\Domain\Variant;
use SEOCart\Catalog\Domain\VariantInput;
use SEOCart\Catalog\Domain\VariantPrice;
use SEOCart\Catalog\Domain\VariantSelection;
use SEOCart\Support\Currency;
use SEOCart\Support\Error\CodedException;
use SEOCart\Support\Locale;

/**
 * A variant set is refused, before the product changes, for more variants than the cap, a combination the options do not admit or given twice, a price in a currency the store does not offer or two in one, a SKU given twice; options for more axes or values than a product may have. The generation moves only when the axis keys change.
 *
 * Planted violation, shown failing and put back: in Product::applyVariants(), decide the next
 * generation by whether the options are equal in every field, not by their axis keys. A value
 * added then moves the generation, and the value test fails.
 *
 * @since 0.2.0
 */
final class ProductVariantsTest extends TestCase {

	/**
	 * Tests that a set of six combinations of two axes is written at the first generation of a product that has none, each labelled by its values in axis order.
	 *
	 * @since 0.2.0
	 */
	public function test_six_combinations_start_the_first_generation(): void {
		$product = self::product( array(), OptionMatrix::none(), Product::NO_GENERATION );

		$product->applyOptions( self::matrix( array( '250g', '1kg' ), array( 'whole', 'filter', 'espresso' ) ) );
		$product->applyVariants( self::inputs( array( '250g', '1kg' ), array( 'whole', 'filter', 'espresso' ) ), self::offered(), self::usd(), Product::MAX_SYNC_VARIANTS );

		$this->assertSame( Variant::FIRST_GENERATION, $product->activeGeneration() );
		$this->assertSame( array( 6, 6 ), array( $product->variantCount(), $product->enabledVariantCount() ) );
		$this->assertNull( $product->defaultVariant(), 'A product with options has no variant of the empty combination.' );
		$this->assertSame( '250 g / Whole bean', $product->variants()[0]->label() );
		$this->assertSame( array( 'USD', 'EUR' ), array_map( static fn( VariantPrice $price ): string => $price->currency()->code(), $product->variants()[0]->prices() ) );
		$this->assertSame( 1000, $product->variants()[0]->basePrice()?->priceMinor() );
		$this->assertSame( GenerationState::Complete, $product->settle( true ), 'Every enabled variant has its base price.' );
	}

	/**
	 * Tests that a value added under the same axes keeps the generation and every stored variant's id, and a combination left out is disabled.
	 *
	 * @since 0.2.0
	 */
	public function test_a_value_added_keeps_the_generation_and_the_ids(): void {
		$product = self::storedSix();

		$product->applyOptions( self::matrix( array( '250g', '1kg', '2kg' ), array( 'whole', 'filter', 'espresso' ) ) );
		$product->applyVariants( self::inputs( array( '250g', '1kg', '2kg' ), array( 'whole', 'filter' ) ), self::offered(), self::usd(), Product::MAX_SYNC_VARIANTS );

		$ids = array();

		foreach ( $product->variants() as $variant ) {
			$ids[ $variant->selection()->toString() ] = array( $variant->id(), $variant->isEnabled(), $variant->generation() );
		}

		$this->assertSame( 4, $product->activeGeneration(), 'A value changes no combination of the others: the generation stays.' );
		$this->assertSame( array( 101, true, 4 ), $ids['grind=whole, size=250g'] );
		$this->assertSame( array( null, true, 4 ), $ids['grind=whole, size=2kg'], 'A new combination is a new variant, written at the generation the product publishes.' );
		$this->assertSame( array( 103, false, 4 ), $ids['grind=espresso, size=250g'], 'A combination left out is disabled, never deleted.' );
		$this->assertSame( array( 8, 6 ), array( $product->variantCount(), $product->enabledVariantCount() ) );
		$this->assertCount( 2, $product->leftOutVariants() );
	}

	/**
	 * Tests that an axis added starts the next generation, every combination new, the stored variants left behind.
	 *
	 * @since 0.2.0
	 */
	public function test_an_axis_added_starts_the_next_generation(): void {
		$product = self::storedSix();
		$options = OptionMatrix::of( array_merge( self::matrix( array( '250g', '1kg' ), array( 'whole', 'filter', 'espresso' ) )->axes, array( OptionAxis::of( 'roast', 'Roast', 2, array( OptionValue::of( 'dark', 'Dark', 0 ) ) ) ) ) );
		$inputs  = array();

		foreach ( self::inputs( array( '250g', '1kg' ), array( 'whole', 'filter', 'espresso' ) ) as $index => $input ) {
			$inputs[] = new VariantInput( $input->uuid, VariantSelection::of( $input->selection->pairs() + array( 'roast' => 'dark' ) ), Sku::of( 'R-' . $index ), prices: $input->prices );
		}

		$product->applyOptions( $options );
		$product->applyVariants( $inputs, self::offered(), self::usd(), Product::MAX_SYNC_VARIANTS );

		$this->assertSame( 5, $product->activeGeneration() );
		$this->assertSame( array(), array_filter( $product->variants(), static fn( Variant $variant ): bool => null !== $variant->id() ), 'Every combination of the new axis set is new.' );
		$this->assertSame( array(), $product->leftOutVariants(), 'The stored variants stay at their generation, unpublished.' );
		$this->assertSame( 6, $product->variantCount() );
	}

	/**
	 * Tests every refusal of a set, each before the product changed.
	 *
	 * @since 0.2.0
	 */
	public function test_a_set_that_cannot_be_saved_is_refused_before_the_product_changes(): void {
		$matrix = self::matrix( array( '250g', '1kg' ), array( 'whole', 'filter', 'espresso' ) );
		$six    = self::inputs( array( '250g', '1kg' ), array( 'whole', 'filter', 'espresso' ) );
		$first  = $six[0];
		$cases  = array(
			'over the cap'           => array( $six, 5, CatalogError::TooManyVariants ),
			'an axis missing'        => array( array( new VariantInput( $first->uuid, VariantSelection::of( array( 'size' => '250g' ) ), Sku::of( 'X-1' ) ) ), 250, CatalogError::CombinationInvalid ),
			'an unknown value'       => array(
				array(
					new VariantInput(
						$first->uuid,
						VariantSelection::of(
							array(
								'size'  => '5kg',
								'grind' => 'whole',
							)
						),
						Sku::of( 'X-1' )
					),
				),
				250,
				CatalogError::CombinationInvalid,
			),
			'an axis too many'       => array( array( new VariantInput( $first->uuid, VariantSelection::of( $first->selection->pairs() + array( 'roast' => 'dark' ) ), Sku::of( 'X-1' ) ) ), 250, CatalogError::CombinationInvalid ),
			'a combination twice'    => array( array( $first, new VariantInput( $six[1]->uuid, $first->selection, Sku::of( 'X-2' ) ) ), 250, CatalogError::CombinationDuplicate ),
			'a SKU twice, any case'  => array( array( $first, new VariantInput( $six[1]->uuid, $six[1]->selection, Sku::of( 'c-250g-whole' ) ) ), 250, CatalogError::SkuDuplicate ),
			'a new variant, no SKU'  => array( array( new VariantInput( $first->uuid, $first->selection, null, false ) ), 250, CatalogError::SkuInvalid ),
			'a currency not offered' => array( array( new VariantInput( $first->uuid, $first->selection, $first->sku, prices: array( VariantPrice::net( Currency::of( 'JPY' ), 100 ) ) ) ), 250, CatalogError::CurrencyNotOffered ),
			'a price twice'          => array( array( new VariantInput( $first->uuid, $first->selection, $first->sku, prices: array( VariantPrice::net( self::usd(), 100 ), VariantPrice::gross( self::usd(), 120 ) ) ) ), 250, CatalogError::PriceDuplicate ),
		);

		foreach ( $cases as $name => list( $inputs, $cap, $code ) ) {
			$product = self::product( array(), $matrix, Product::NO_GENERATION );

			try {
				$product->applyVariants( $inputs, self::offered(), self::usd(), $cap );

				$this->fail( $name . ' was taken.' );
			} catch ( CodedException $refused ) {
				$this->assertSame( $code, $refused->errorCode(), $name );
				$this->assertSame( array( Product::NO_GENERATION, 0 ), array( $product->activeGeneration(), $product->variantCount() ), $name . ': the product did not change.' );
			}
		}
	}

	/**
	 * Tests that a variant whose SKU another variant took keeps its placeholder while a set gives it no SKU and leaves it disabled, is enabled only with a SKU of its own, and that no SKU given may start as the placeholder does.
	 *
	 * Planted violations, each shown failing and put back:
	 * - In Product::skuOf(), keep the placeholder whether or not the variant is enabled.
	 * - In Variant::givenSku(), take a SKU that starts with Variant::NO_SKU_PREFIX.
	 *
	 * @since 0.2.0
	 */
	public function test_a_variant_without_a_sku_is_kept_disabled_until_it_is_given_one(): void {
		$stored   = self::storedSix()->variants();
		$released = Variant::stored( 102, $stored[1]->uuid(), Sku::of( Variant::NO_SKU_PREFIX . $stored[1]->uuid() ), $stored[1]->combinationHash(), 4, false, 250, VariantPrice::net( self::usd(), 1000 ), $stored[1]->selection(), 1 );
		$matrix   = self::matrix( array( '250g', '1kg' ), array( 'whole', 'filter', 'espresso' ) );
		$six      = self::inputs( array( '250g', '1kg' ), array( 'whole', 'filter', 'espresso' ) );
		$given    = static function ( ?Sku $sku, ?bool $enabled, int $at = 1 ) use ( $six ): array {
			$inputs        = $six;
			$inputs[ $at ] = new VariantInput( $six[ $at ]->uuid, $six[ $at ]->selection, $sku, $enabled );

			return $inputs;
		};
		$product  = self::product( array( $stored[0], $released, ...array_slice( $stored, 2 ) ), $matrix, 4 );

		$this->assertFalse( $released->hasSku() );
		$this->assertTrue( $stored[0]->hasSku() );

		$product->applyVariants( $given( null, null ), self::offered(), self::usd(), Product::MAX_SYNC_VARIANTS );

		$this->assertSame( array( 102, false, false, $released->sku()->toString() ), array( $product->variants()[1]->id(), $product->variants()[1]->isEnabled(), $product->variants()[1]->hasSku(), $product->variants()[1]->sku()->toString() ), 'Given no SKU, it keeps its placeholder, disabled.' );

		$refusals = array(
			'enabled without a SKU'     => $given( null, true ),
			'a SKU taken away'          => $given( null, false, 0 ),
			'a new variant without one' => array(
				new VariantInput(
					$six[0]->uuid,
					VariantSelection::of(
						array(
							'size'  => '2kg',
							'grind' => 'whole',
						)
					),
					null,
					false
				),
			),
		);

		foreach ( $refusals as $name => $inputs ) {
			$product = self::product( array( $stored[0], $released, ...array_slice( $stored, 2 ) ), self::matrix( array( '250g', '1kg', '2kg' ), array( 'whole', 'filter', 'espresso' ) ), 4 );

			try {
				$product->applyVariants( $inputs, self::offered(), self::usd(), Product::MAX_SYNC_VARIANTS );

				$this->fail( $name . ' was taken.' );
			} catch ( CodedException $refused ) {
				$this->assertSame( CatalogError::SkuInvalid, $refused->errorCode(), $name );
				$this->assertFalse( $product->variants()[1]->isEnabled(), $name . ': the product did not change.' );
			}
		}

		$product = self::product( array( $stored[0], $released, ...array_slice( $stored, 2 ) ), $matrix, 4 );

		$product->applyVariants( $given( Sku::of( 'C-250g-filter-2' ), true ), self::offered(), self::usd(), Product::MAX_SYNC_VARIANTS );

		$this->assertSame( array( true, true, 'C-250g-filter-2' ), array( $product->variants()[1]->isEnabled(), $product->variants()[1]->hasSku(), $product->variants()[1]->sku()->toString() ), 'Given a SKU of its own, it is enabled.' );

		foreach ( array( '~C-1', '  ~C-1', Variant::NO_SKU_PREFIX . $released->uuid() ) as $sku ) {
			try {
				Variant::givenSku( $sku );

				$this->fail( 'A SKU starting with "~" was taken: ' . $sku );
			} catch ( CodedException $refused ) {
				$this->assertSame( CatalogError::SkuInvalid, $refused->errorCode() );
			}
		}

		$this->assertSame( 'C~1', Variant::givenSku( 'C~1' )->toString(), 'A "~" past the first character is a merchant\'s.' );
	}

	/**
	 * Tests that options with more axes or values than a product may have are refused, with the counts and the limits.
	 *
	 * @since 0.2.0
	 */
	public function test_too_many_axes_or_values_are_refused(): void {
		$axes = array();

		for ( $axis = 0; $axis <= Product::MAX_AXES; $axis++ ) {
			$axes[] = OptionAxis::of( 'axis-' . $axis, 'Axis ' . $axis, $axis, array( OptionValue::of( 'v', 'V', 0 ) ) );
		}

		$values = array();

		for ( $value = 0; $value <= Product::MAX_VALUES; $value++ ) {
			$values[] = OptionValue::of( 'v' . $value, 'V ' . $value, $value );
		}

		foreach ( array( array( OptionMatrix::of( $axes ), Product::MAX_AXES + 1, Product::MAX_AXES + 1 ), array( OptionMatrix::of( array( OptionAxis::of( 'size', 'Size', 0, $values ) ) ), 1, Product::MAX_VALUES + 1 ) ) as list( $matrix, $axisCount, $valueCount ) ) {
			try {
				self::product( array(), OptionMatrix::none(), Product::NO_GENERATION )->applyOptions( $matrix );

				$this->fail( 'Options past a limit were taken.' );
			} catch ( CodedException $refused ) {
				$this->assertSame( CatalogError::TooManyOptions, $refused->errorCode() );
				$this->assertSame( array( $axisCount, $valueCount ), array( $refused->context()['axes'] ?? null, $refused->context()['values'] ?? null ) );
			}
		}
	}

	/**
	 * Tests that a disabled variant may lack a price, and an enabled one may not, for the product to be whole.
	 *
	 * @since 0.2.0
	 */
	public function test_only_an_enabled_variant_needs_its_base_price(): void {
		$product = self::product( array(), OptionMatrix::none(), Product::NO_GENERATION );
		$inputs  = self::inputs( array( '250g', '1kg' ), array( 'whole', 'filter', 'espresso' ) );

		$inputs[5] = new VariantInput( $inputs[5]->uuid, $inputs[5]->selection, $inputs[5]->sku, false, prices: array() );

		$product->applyOptions( self::matrix( array( '250g', '1kg' ), array( 'whole', 'filter', 'espresso' ) ) );
		$product->applyVariants( $inputs, self::offered(), self::usd(), Product::MAX_SYNC_VARIANTS );

		$this->assertSame( GenerationState::Complete, $product->settle( true ), 'A disabled variant without a price leaves the product whole.' );

		$inputs[5] = new VariantInput( $inputs[5]->uuid, $inputs[5]->selection, $inputs[5]->sku, true, prices: array() );

		$product->applyVariants( $inputs, self::offered(), self::usd(), Product::MAX_SYNC_VARIANTS );

		$this->assertSame( GenerationState::Incomplete, $product->settle( true ), 'An enabled variant without a base price leaves it incomplete.' );
		$this->assertSame( GenerationState::Incomplete, $product->settle( false ), 'So do stock items not known.' );
	}

	/**
	 * Returns a stored product at generation 4 with the six combinations of 250g and 1kg by whole, filter and espresso, ids 101 to 106.
	 *
	 * @since 0.2.0
	 *
	 * @return Product The product.
	 */
	private static function storedSix(): Product {
		$variants = array();
		$id       = 101;

		foreach ( array( '250g', '1kg' ) as $size ) {
			foreach ( array( 'whole', 'filter', 'espresso' ) as $grind ) {
				$selection  = VariantSelection::of(
					array(
						'size'  => $size,
						'grind' => $grind,
					)
				);
				$variants[] = Variant::stored( $id, sprintf( '00000000-0000-4000-8000-%012d', $id ), Sku::of( 'S-' . $id ), $selection->hash(), 4, true, 250, VariantPrice::net( self::usd(), 1000 ), $selection, $id - 101 );
				++$id;
			}
		}

		return self::product( $variants, self::matrix( array( '250g', '1kg' ), array( 'whole', 'filter', 'espresso' ) ), 4 );
	}

	/**
	 * Tests that a price takes the basis of the one it takes the place of, its amounts its own, and stays as it is when it takes the place of none.
	 *
	 * @since 0.2.0
	 */
	public function test_a_price_takes_the_basis_of_the_one_it_replaces(): void {
		$usd   = self::usd();
		$price = VariantPrice::net( $usd, 500, 700 )->onBasisOf( VariantPrice::gross( $usd, 900, 950 ) );

		$this->assertSame( array( VariantPrice::GROSS, 500, 700 ), array( $price->amountBasis(), $price->priceMinor(), $price->compareAtMinor() ) );
		$this->assertSame( VariantPrice::NET, VariantPrice::gross( $usd, 500 )->onBasisOf( VariantPrice::net( $usd, 900 ) )->amountBasis() );
		$this->assertSame( VariantPrice::NET, VariantPrice::net( $usd, 500 )->onBasisOf( null )->amountBasis() );
	}

	/**
	 * Tests that a variant of an earlier generation is applied as a stored one: its id and what the set leaves out kept, what the set gives taken, its place in the set its position.
	 *
	 * Planted violation: in Product::applyVariants(), find a combination among the generation's
	 * variants alone; the variant is new, enabled and unpriced, and the test fails.
	 *
	 * @since 0.2.0
	 */
	public function test_a_variant_of_an_earlier_generation_is_applied_as_a_stored_one(): void {
		$usd       = self::usd();
		$selection = VariantSelection::of(
			array(
				'size'  => '250g',
				'grind' => 'whole',
			)
		);
		$options   = self::matrix( array( '250g' ), array( 'whole' ) );
		$fresh     = '00000000-0000-4000-8000-000000000001';
		$row       = Variant::stored( 55, '00000000-0000-4000-8000-000000000055', Sku::of( 'OLD' ), $selection->hash(), 1, false, 250, VariantPrice::stored( $usd, 1234, 2000, VariantPrice::GROSS ), $selection, 3, 'Former', array( VariantPrice::stored( Currency::of( 'EUR' ), 999, null, VariantPrice::GROSS ) ) );
		$bare      = self::product( array(), OptionMatrix::none(), 2 );

		$bare->applyOptions( $options );
		$bare->loadSuperseded( array( $row ) );
		$bare->applyVariants( array( new VariantInput( $fresh, $selection, Sku::of( 'NEW' ) ) ), self::offered(), $usd, Product::MAX_SYNC_VARIANTS );

		$kept = $bare->variants()[0];

		$this->assertSame( array( 55, '00000000-0000-4000-8000-000000000055', 'NEW', 3, false, 250, 0 ), array( $kept->id(), $kept->uuid(), $kept->sku()->toString(), $kept->generation(), $kept->isEnabled(), $kept->weightGrams(), $kept->position() ), 'Stored but for the SKU given and its place in the set.' );
		$this->assertSame( array( array( 'USD', 'gross', 1234, 2000 ), array( 'EUR', 'gross', 999, null ) ), self::described( $kept ) );
		$this->assertSame( $options->labelOf( $selection ), $kept->label() );
		$this->assertSame( array( 'OLD' ), array_map( static fn( Variant $variant ): string => $variant->sku()->toString(), $bare->loadedVariants() ), 'Storage is told what it holds.' );

		$given = self::product( array(), OptionMatrix::none(), 2 );

		$given->applyOptions( $options );
		$given->loadSuperseded( array( $row ) );
		$given->applyVariants( array( new VariantInput( $fresh, $selection, Sku::of( 'OLD' ), true, true, null, 5, array( VariantPrice::net( $usd, 1 ) ) ) ), self::offered(), $usd, Product::MAX_SYNC_VARIANTS );

		$taken = $given->variants()[0];

		$this->assertSame( array( 55, true, null, 5 ), array( $taken->id(), $taken->isEnabled(), $taken->weightGrams(), $taken->position() ), 'What the set gives is taken.' );
		$this->assertSame( array( array( 'USD', 'net', 1, null ) ), self::described( $taken ) );

		$unnamed = self::product( array(), OptionMatrix::none(), 2 );

		$unnamed->applyOptions( $options );
		$unnamed->loadSuperseded( array( $row ) );

		try {
			$unnamed->applyVariants( array( new VariantInput( $fresh, $selection, null, false ) ), self::offered(), $usd, Product::MAX_SYNC_VARIANTS );
			$this->fail( 'A variant of an earlier generation came back without a SKU.' );
		} catch ( CodedException $refused ) {
			$this->assertSame( CatalogError::SkuInvalid, $refused->errorCode() );
		}
	}

	/**
	 * Tests that a stored variant whose selection lost a value takes the selection the set names it by, and that one the set leaves out stays as it is stored.
	 *
	 * Planted violation: in Product::applyVariants(), leave out `->withSelection( $input->selection )`;
	 * the variant keeps the one value left, and the test fails.
	 *
	 * @since 0.2.0
	 */
	public function test_a_stored_variant_takes_the_selection_the_set_names_it_by(): void {
		$named    = VariantSelection::of(
			array(
				'size'  => '1kg',
				'grind' => 'whole',
			)
		);
		$other    = VariantSelection::of(
			array(
				'size'  => '1kg',
				'grind' => 'filter',
			)
		);
		$lost     = VariantSelection::of( array( 'grind' => 'whole' ) );
		$alsoLost = VariantSelection::of( array( 'grind' => 'filter' ) );
		$product  = self::product(
			array(
				Variant::stored( 11, '00000000-0000-4000-8000-000000000011', Sku::of( 'WHOLE' ), $named->hash(), 1, false, null, null, $lost, 0, '1 kg / Whole bean' ),
				Variant::stored( 12, '00000000-0000-4000-8000-000000000012', Sku::of( 'FILTER' ), $other->hash(), 1, false, null, null, $alsoLost, 1, '1 kg / Filter' ),
			),
			self::matrix( array( '250g' ), array( 'whole', 'filter' ) ),
			1
		);

		$product->applyOptions( self::matrix( array( '1kg' ), array( 'whole', 'filter' ) ) );
		$product->applyVariants( array( new VariantInput( '00000000-0000-4000-8000-000000000001', $named, Sku::of( 'WHOLE' ) ) ), self::offered(), self::usd(), Product::MAX_SYNC_VARIANTS );

		$this->assertSame( array( 11, 'grind=whole, size=1kg', $named->hash(), false ), array( $product->variants()[0]->id(), $product->variants()[0]->selection()->toString(), $product->variants()[0]->selection()->hash(), $product->variants()[0]->isEnabled() ), 'Named, it has its whole combination; what the set does not give stays.' );
		$this->assertSame( array( 12, 'grind=filter' ), array( $product->variants()[1]->id(), $product->variants()[1]->selection()->toString() ), 'Left out, it stays as it is stored.' );
	}

	/**
	 * Tests that only a stored product past its first generation asks for a combination outside the one it publishes.
	 *
	 * Planted violation: in Product::possiblySuperseded(), leave out the bound on the generation; the
	 * first generation asks, and the test fails.
	 *
	 * @since 0.2.0
	 */
	public function test_only_a_product_past_its_first_generation_asks_for_earlier_rows(): void {
		$outside = VariantSelection::of(
			array(
				'size'  => '5kg',
				'grind' => 'whole',
			)
		);
		$active  = VariantSelection::of(
			array(
				'size'  => '250g',
				'grind' => 'whole',
			)
		);
		$first   = Product::firstBinding( '00000000-0000-4000-8000-000000000009', 42, Locale::of( 'en_US' ), null, new \DateTimeImmutable( '2026-09-01 00:00:00' ) );

		$this->assertSame( array(), $first->possiblySuperseded( array( $outside ) ), 'Not yet stored.' );
		$this->assertSame( array(), self::product( array(), OptionMatrix::none(), Product::NO_GENERATION )->possiblySuperseded( array( $outside ) ), 'No generation yet.' );
		$this->assertSame( array(), self::product( array(), OptionMatrix::none(), 1 )->possiblySuperseded( array( $outside ) ), 'Its first generation.' );
		$this->assertSame( array( $outside->hash() => $outside ), self::product( array(), OptionMatrix::none(), 2 )->possiblySuperseded( array( $outside ) ), 'Past it.' );
		$this->assertSame( array( $outside->hash() => $outside ), self::storedSix()->possiblySuperseded( array( $active, $outside ) ), 'The combinations it publishes are not asked for.' );
		$this->assertSame( array(), self::storedSix()->possiblySuperseded( array( $active ) ) );
	}

	/**
	 * Tests that a default variant of an earlier generation comes back as it is stored, in the generation the product publishes, and a product with none takes the new one.
	 *
	 * @since 0.2.0
	 */
	public function test_a_default_variant_of_an_earlier_generation_comes_back_as_stored(): void {
		$usd     = self::usd();
		$new     = Variant::byDefault( '00000000-0000-4000-8000-000000000001', Sku::of( 'NEW' ) );
		$row     = Variant::stored( 55, '00000000-0000-4000-8000-000000000055', Sku::of( 'OLD' ), Variant::defaultCombinationHash(), 1, false, 7, VariantPrice::stored( $usd, 1234, 2000, VariantPrice::GROSS ) );
		$product = self::product( array(), OptionMatrix::none(), 3 );

		$product->loadSuperseded( array( $row ) );

		$back = $product->giveDefaultVariant( $new );

		$this->assertSame( array( 55, 3, false, 7, 1234 ), array( $back->id(), $back->generation(), $back->isEnabled(), $back->weightGrams(), $back->basePrice()?->priceMinor() ) );
		$this->assertSame( $back, $product->defaultVariant() );
		$this->assertNull( self::product( array(), OptionMatrix::none(), 3 )->giveDefaultVariant( $new )->id(), 'None found: the new one.' );
	}

	/**
	 * Returns a variant's prices as lists of their fields.
	 *
	 * @since 0.2.0
	 *
	 * @param Variant $variant The variant.
	 * @return list<array{0: string, 1: string, 2: int, 3: int|null}> Each price's currency, basis, amount and compare-at amount.
	 */
	private static function described( Variant $variant ): array {
		return array_map( static fn( VariantPrice $price ): array => array( $price->currency()->code(), $price->amountBasis(), $price->priceMinor(), $price->compareAtMinor() ), $variant->prices() );
	}

	/**
	 * Returns a stored product.
	 *
	 * @since 0.2.0
	 *
	 * @param Variant[]    $variants   The variants of its generation.
	 * @param OptionMatrix $options    Its options.
	 * @param int          $generation The generation it publishes.
	 * @return Product The product.
	 *
	 * @phpstan-param list<Variant> $variants
	 */
	private static function product( array $variants, OptionMatrix $options, int $generation ): Product {
		return Product::stored( 7, '00000000-0000-4000-8000-000000000007', 42, array( new ProductPostBinding( 42, Locale::of( 'en_US' ), new \DateTimeImmutable( '2026-09-01 00:00:00' ) ) ), GenerationState::Complete, $generation, $variants, $options );
	}

	/**
	 * Returns the options of a size and a grind axis.
	 *
	 * @since 0.2.0
	 *
	 * @param string[] $sizes  The size values' keys.
	 * @param string[] $grinds The grind values' keys.
	 * @return OptionMatrix The options.
	 *
	 * @phpstan-param list<string> $sizes
	 * @phpstan-param list<string> $grinds
	 */
	private static function matrix( array $sizes, array $grinds ): OptionMatrix {
		$labels = array(
			'250g'     => '250 g',
			'1kg'      => '1 kg',
			'2kg'      => '2 kg',
			'whole'    => 'Whole bean',
			'filter'   => 'Filter',
			'espresso' => 'Espresso',
		);

		return OptionMatrix::of(
			array(
				OptionAxis::of( 'size', 'Size', 0, array_map( static fn( string $key, int $position ): OptionValue => OptionValue::of( $key, $labels[ $key ], $position ), $sizes, array_keys( $sizes ) ) ),
				OptionAxis::of( 'grind', 'Grind', 1, array_map( static fn( string $key, int $position ): OptionValue => OptionValue::of( $key, $labels[ $key ], $position ), $grinds, array_keys( $grinds ) ) ),
			)
		);
	}

	/**
	 * Returns a set of every combination of sizes by grinds, each with a SKU and prices in dollars and euros.
	 *
	 * @since 0.2.0
	 *
	 * @param string[] $sizes  The size values' keys.
	 * @param string[] $grinds The grind values' keys.
	 * @return list<VariantInput> The set, sizes first.
	 *
	 * @phpstan-param list<string> $sizes
	 * @phpstan-param list<string> $grinds
	 */
	private static function inputs( array $sizes, array $grinds ): array {
		$inputs = array();

		foreach ( $sizes as $size ) {
			foreach ( $grinds as $grind ) {
				$index    = count( $inputs );
				$inputs[] = new VariantInput(
					sprintf( '00000000-0000-4000-9000-%012d', $index + 1 ),
					VariantSelection::of(
						array(
							'size'  => $size,
							'grind' => $grind,
						)
					),
					Sku::of( 'C-' . $size . '-' . $grind ),
					prices: array( VariantPrice::net( self::usd(), 1000 + $index ), VariantPrice::gross( Currency::of( 'EUR' ), 1100 + $index ) )
				);
			}
		}

		return $inputs;
	}

	/**
	 * Returns the currencies the store offers in these tests.
	 *
	 * @since 0.2.0
	 *
	 * @return list<Currency> Dollars and euros.
	 */
	private static function offered(): array {
		return array( self::usd(), Currency::of( 'EUR' ) );
	}

	/**
	 * Returns the base currency.
	 *
	 * @since 0.2.0
	 *
	 * @return Currency Dollars.
	 */
	private static function usd(): Currency {
		return Currency::of( 'USD' );
	}
}
