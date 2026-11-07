<?php
/**
 * Tests the first-party copy of a product: a new draft product with its own variant, SKU and stock item
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Catalog;

use SEOCart\Catalog\Application\Lifecycle\DuplicateProduct;
use SEOCart\Catalog\Application\ProductWrite\SaveResult;
use SEOCart\Catalog\Domain\CatalogError;
use SEOCart\Catalog\Domain\GenerationState;
use SEOCart\Catalog\Domain\OptionValue;
use SEOCart\Catalog\Domain\Product;
use SEOCart\Catalog\Domain\ReportCode;
use SEOCart\Catalog\Domain\SellabilityReason;
use SEOCart\Catalog\Domain\Sku;
use SEOCart\Catalog\Domain\Variant;
use SEOCart\Catalog\Infrastructure\CatalogTables;
use SEOCart\Catalog\Infrastructure\ProductTaxonomies;
use SEOCart\Inventory\Domain\LedgerReason;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Platform\Authorization\ProductCapabilities;
use SEOCart\Support\Error\CodedException;
use SEOCart\Tests\Support\Catalog\CreatesTerms;
use SEOCart\Tests\Support\Catalog\ProductWrites;
use SEOCart\Tests\Support\Catalog\ProductWriteTestCase;
use SEOCart\Tests\Support\Catalog\SavesVariantSets;
use SEOCart\Tests\Support\Catalog\VariantSets;

/**
 * A copy of a product is a new draft product with its own variant, its own SKU and its own stock item at zero.
 *
 * The post takes the original's title and content as a draft; the variant takes its price. The
 * SKU is the original's with `-copy`, then `-copy-2` to `-copy-9` while each is taken, and a copy
 * that finds all nine taken fails with `catalog.sku_taken` and leaves nothing behind. The product
 * lifecycle is hooked, as on a site, and hears of the copy only as the plugin's own write.
 *
 * Planted violations, each confirmed to fail a test here:
 * - In DuplicateProduct::skus(), give every copy the original's SKU: the first copy fails with
 *   `catalog.sku_taken`.
 * - In DuplicateProduct::duplicate(), save the copy `publish`: the copy can be sold, and the
 *   first test fails.
 * - In PostLifecycle::postWritten(), stop leaving the plugin's own writes alone: the copy is
 *   reported as another path's write, and the first test fails.
 * - In DuplicateProduct::saved(), drop the copyTerms() call: the copy has no categories, brand or
 *   tags, and test_a_copy_keeps_the_originals_categories_brands_and_tags fails.
 * - In DuplicateProduct::duplicate(), save a product without options through its flat fields, its
 *   SKU, base price and weight: the copy is enabled, net and has no price in another currency, and
 *   test_a_copy_of_a_product_without_options_keeps_its_state_basis_and_rows fails.
 * - In DuplicateProduct::options(), leave out the cast of the axis key: the copy of a product whose
 *   axis key is digits alone fails with an InvalidArgumentException.
 * - In DuplicateProduct::duplicate(), return early for any product without a variant: the copy of a
 *   product with axes and no variant in its generation has no axes.
 *
 * @since 0.1.0
 * @since 0.2.0 A copy keeps its original's terms.
 */
final class DuplicateProductTest extends ProductWriteTestCase {

	use SavesVariantSets;

	use CreatesTerms;

	/**
	 * Hooks the product lifecycle in the kernel's place, reporting as under WP_DEBUG.
	 *
	 * @since 0.1.0
	 */
	public function set_up(): void {
		parent::set_up();

		$this->services = ProductWrites::services( $this->db, $this->reporter(), true );
		$this->service  = $this->services->save;

		$this->services->attach();
	}

	/**
	 * Deletes the terms the test created, and WordPress's cache of the category tree.
	 *
	 * @since 0.2.0
	 */
	public function tear_down(): void {
		$this->deleteCreatedTerms();

		parent::tear_down();
	}

	/**
	 * Tests that a copy has its original's categories, brand and tags once its after-insert hook fires, and that the original keeps them.
	 *
	 * The copy is a draft, so WordPress counts it in none of them.
	 *
	 * @since 0.2.0
	 */
	public function test_a_copy_keeps_the_originals_categories_brands_and_tags(): void {
		$terms    = array(
			ProductTaxonomies::CATEGORY => array( $this->term( ProductTaxonomies::CATEGORY, 'Coffee' ), $this->term( ProductTaxonomies::CATEGORY, 'Single origin' ) ),
			ProductTaxonomies::BRAND    => array( $this->term( ProductTaxonomies::BRAND, 'Fernwood' ) ),
			ProductTaxonomies::TAG      => array( $this->term( ProductTaxonomies::TAG, 'Washed' ), $this->term( ProductTaxonomies::TAG, 'Light roast' ) ),
		);
		$original = $this->save(
			null,
			array(
				'post_title'  => 'Original',
				'post_status' => 'publish',
			),
			array( 'sku' => 'T' )
		);

		foreach ( $terms as $taxonomy => $ids ) {
			wp_set_object_terms( $original->postId, $ids, $taxonomy );
		}

		$seen  = array();
		$heard = static function ( $postId ) use ( &$seen ): void {
			$seen[ (int) $postId ] = wp_get_object_terms( (int) $postId, ProductTaxonomies::names(), array( 'fields' => 'ids' ) );
		};

		add_action( 'wp_after_insert_post', $heard, 5 );

		try {
			$copy = $this->copy( $original );
		} finally {
			remove_action( 'wp_after_insert_post', $heard, 5 );
		}

		foreach ( $terms as $taxonomy => $ids ) {
			$this->assertEqualsCanonicalizing( $ids, wp_get_object_terms( $copy->postId, $taxonomy, array( 'fields' => 'ids' ) ), "The copy's {$taxonomy} terms." );
			$this->assertEqualsCanonicalizing( $ids, wp_get_object_terms( $original->postId, $taxonomy, array( 'fields' => 'ids' ) ), "The original's {$taxonomy} terms." );
		}

		$this->assertEqualsCanonicalizing( array_merge( ...array_values( $terms ) ), $seen[ $copy->postId ] ?? null, 'The copy had not its terms when its after-insert hook fired.' );
		$this->assertSame( 1, (int) get_term( $terms[ ProductTaxonomies::BRAND ][0], ProductTaxonomies::BRAND )->count, 'WordPress counts the draft copy in its brand.' );
	}

	/**
	 * Tests that a copy is a new draft product with a new variant, the `-copy` SKU, the original's price and no stock.
	 *
	 * @since 0.1.0
	 */
	public function test_a_copy_is_a_new_draft_product_with_its_own_variant_and_no_stock(): void {
		$b        = $this->secondConnection();
		$original = $this->save(
			null,
			array(
				'post_title'   => 'Original',
				'post_content' => 'What it is.',
				'post_status'  => 'publish',
			),
			array(
				'sku'              => 'X',
				'price_minor'      => 1999,
				'compare_at_minor' => 2499,
				'weight_grams'     => 250,
			)
		);

		$this->services->stock->adjust( (int) $original->variantId, $this->defaultLocation, 7, LedgerReason::Received, Actor::user( 1 ) );

		$before = $this->committedProduct( $b, $original );
		$copy   = $this->copy( $original );

		$this->assertNotSame( $original->productId, $copy->productId );
		$this->assertNotSame( $original->postId, $copy->postId );
		$this->assertNotSame( $original->variantId, $copy->variantId );
		$this->assertTrue( $copy->postCreated );
		$this->assertSame( GenerationState::Complete, $copy->generation );
		$this->assertSame( SellabilityReason::NotPublished, $copy->sellability );
		$this->assertSame( 'draft', get_post_status( $copy->postId ) );
		$this->assertSame( ProductCapabilities::POST_TYPE, get_post_type( $copy->postId ) );
		$this->assertSame( array( 'Original', 'X-copy', '1999' ), $this->committedProduct( $b, $copy ) );
		$this->assertSame( 'What it is.', get_post_field( 'post_content', $copy->postId ) );
		$this->assertSame(
			array( '2499', '250' ),
			array(
				$b->fetchValue( sprintf( 'SELECT compare_at_minor FROM `%s` WHERE variant_id = %d', $this->catalogTable( CatalogTables::VARIANT_PRICES ), (int) $copy->variantId ) ),
				$b->fetchValue( sprintf( 'SELECT weight_grams FROM `%s` WHERE id = %d', $this->catalogTable( CatalogTables::VARIANTS ), (int) $copy->variantId ) ),
			)
		);
		$this->assertSame( 1, $this->committedStockItems( $b, (int) $copy->variantId ), 'The copy has its own stock item, at zero.' );
		$this->assertSame( $before, $this->committedProduct( $b, $original ), 'The original changed.' );
		$this->assertNotContains( ReportCode::ForeignPostWrite->value, array_column( $this->reports, 'code' ), 'The lifecycle took the copy for another path\'s write.' );
	}

	/**
	 * Tests that a second copy takes `-copy-2`, and that the tenth finds all nine SKUs taken and leaves nothing behind.
	 *
	 * @since 0.1.0
	 */
	public function test_further_copies_number_their_skus_until_nine_are_taken(): void {
		$b        = $this->secondConnection();
		$original = $this->savedProduct( 'X' );
		$skus     = array();

		for ( $copy = 1; $copy <= DuplicateProduct::COPIES; ++$copy ) {
			$skus[] = $this->committedProduct( $b, $this->copy( $original ) )[1];
		}

		$this->assertSame( array( 'X-copy', 'X-copy-2', 'X-copy-3', 'X-copy-4', 'X-copy-5', 'X-copy-6', 'X-copy-7', 'X-copy-8', 'X-copy-9' ), $skus );

		$products = $this->committedCount( $b, CatalogTables::PRODUCTS );
		$posts    = count(
			get_posts(
				array(
					'post_type'   => ProductCapabilities::POST_TYPE,
					'post_status' => 'any',
					'numberposts' => -1,
					'fields'      => 'ids',
				)
			)
		);

		try {
			$this->services->duplicate->duplicate( $original->productId, Actor::user( 1 ) );
			$this->fail( 'A tenth copy was made.' );
		} catch ( CodedException $refused ) {
			$this->assertSame( CatalogError::SkuTaken, $refused->errorCode() );
			$this->assertSame( array( 'sku' => 'X-copy-9' ), $refused->context() );
		}

		$this->assertSame( $products, $this->committedCount( $b, CatalogTables::PRODUCTS ), 'A refused copy left a product.' );
		$this->assertSame(
			$posts,
			count(
				get_posts(
					array(
						'post_type'   => ProductCapabilities::POST_TYPE,
						'post_status' => 'any',
						'numberposts' => -1,
						'fields'      => 'ids',
					)
				)
			),
			'A refused copy left a post.'
		);
	}

	/**
	 * Tests that a copy of a product whose SKU is as long as a SKU may be keeps within that length.
	 *
	 * @since 0.1.0
	 */
	public function test_a_copy_of_a_long_sku_keeps_within_the_length(): void {
		$b        = $this->secondConnection();
		$long     = str_repeat( 'L', Sku::MAX_LENGTH );
		$original = $this->savedProduct( $long );

		$this->assertSame( str_repeat( 'L', Sku::MAX_LENGTH - 5 ) . '-copy', $this->committedProduct( $b, $this->copy( $original ) )[1] );
	}

	/**
	 * Tests that a copy of a product that has no variant yet is a product without one, `incomplete`.
	 *
	 * @since 0.1.0
	 */
	public function test_a_copy_of_a_product_without_a_variant_has_none(): void {
		$original = $this->save(
			null,
			array(
				'post_title'  => 'Not yet priced',
				'post_status' => 'publish',
			)
		);
		$copy     = $this->copy( $original );

		$this->assertNull( $copy->variantId );
		$this->assertSame( GenerationState::Incomplete, $copy->generation );
		$this->assertNull( $this->products->findByPost( $copy->postId )?->defaultVariant() );
	}

	/**
	 * Tests that copying a product that does not exist fails with `catalog.product_not_found`.
	 *
	 * @since 0.1.0
	 */
	public function test_copying_an_unknown_product_fails(): void {
		try {
			$this->services->duplicate->duplicate( 999999, Actor::user( 1 ) );
			$this->fail( 'An unknown product was copied.' );
		} catch ( CodedException $refused ) {
			$this->assertSame( CatalogError::ProductNotFound, $refused->errorCode() );
		}
	}

	/**
	 * Copies a product as the administrator, and deletes the copy's post after the test.
	 *
	 * @since 0.1.0
	 *
	 * @param SaveResult $original The original.
	 * @return SaveResult The copy.
	 */
	private function copy( SaveResult $original ): SaveResult {
		$copy = $this->services->duplicate->duplicate( $original->productId, Actor::user( 1 ) );

		$this->trackPost( $copy->postId );

		return $copy;
	}

	/**
	 * Tests that a product with options is copied with its options and its six variants, new SKUs, in one save.
	 *
	 * @since 0.2.0
	 */
	public function test_a_product_with_options_is_copied_with_its_variants_in_one_save(): void {
		$b        = $this->secondConnection();
		$original = $this->saveSet( null, VariantSets::options(), VariantSets::variants( 'COF' ) );
		$copy     = $this->copy( $original );
		$variants = $this->committedVariants( $b, $copy->productId );

		$this->assertNotSame( $original->productId, $copy->productId );
		$this->assertSame( array_keys( $original->variantIds ), array_keys( $copy->variantIds ), 'The copy has the same combinations.' );
		$this->assertSame( array_map( static fn( array $variant ): string => $variant['sku'] . '-copy', VariantSets::variants( 'COF' ) ), array_column( $variants, 'sku' ) );
		$this->assertSame( array( 'complete', 1, 6, 6 ), $this->committedGeneration( $b, $copy->productId ) );
		$this->assertSame( 1, $this->committedSaves( $b, $copy->productId ), 'One save.' );
		$this->assertSame( 2, $this->committedCount( $b, CatalogTables::OPTION_AXES, 'product_id = ' . $copy->productId ) );
		$this->assertSame( 12, $this->committedCount( $b, CatalogTables::VARIANT_PRICES, 'variant_id IN ( ' . implode( ', ', $copy->variantIds ) . ' )' ) );
	}

	/**
	 * Tests that a copy keeps a value's image while its attachment exists, and leaves out one whose attachment was deleted, which a save refuses as a new image.
	 *
	 * @since 0.2.0
	 */
	public function test_a_copy_keeps_a_values_image_and_leaves_out_one_whose_attachment_is_gone(): void {
		$kept    = $this->post( 'inherit', 'attachment' );
		$gone    = $this->post( 'inherit', 'attachment' );
		$options = VariantSets::options();

		$options[0]['values'][0]['image_id'] = $kept;
		$options[0]['values'][1]['image_id'] = $gone;

		$original = $this->saveSet( null, $options, VariantSets::variants( 'PIC' ) );

		wp_delete_attachment( $gone, true );

		$copy   = $this->copy( $original );
		$images = static fn( ?Product $product ): array => array_map( static fn( OptionValue $value ): ?int => $value->imageAttachmentId, $product?->options()->axis( 'size' )->values ?? array() );

		$this->assertSame( array( $kept, null ), $images( $this->products->find( $copy->productId ) ) );
		$this->assertSame( array( $kept, $gone ), $images( $this->products->find( $original->productId ) ), 'The original keeps its own.' );
	}

	/**
	 * Tests that a copy of a product with axes and no variant in the generation it publishes keeps the axes.
	 *
	 * @since 0.2.0
	 */
	public function test_a_copy_of_a_product_with_axes_and_no_variant_keeps_the_axes(): void {
		$b        = $this->secondConnection();
		$original = $this->saveSet( null, VariantSets::options(), VariantSets::variants( 'AXES' ) );
		$sizes    = array( VariantSets::options()[0] );

		// Other axis keys, given alone: the generation moves, and no variant of the last one is admitted.
		$this->save( $original->postId, array(), array( 'options' => $sizes ) );

		$this->assertSame( array(), $this->products->find( $original->productId )?->variants() );

		$copy    = $this->copy( $original );
		$options = $this->products->find( $copy->productId )?->options();

		$this->assertSame( array( 'size' ), $options?->axisKeys(), 'The copy lost the axes.' );
		$this->assertSame( 2, $options->valueCount() );
		$this->assertSame( array(), $copy->variantIds, 'The copy has a variant the original does not publish.' );
		$this->assertSame( 0, $this->committedCount( $b, CatalogTables::VARIANTS, 'product_id = ' . $copy->productId ) );
	}

	/**
	 * Tests that a product whose axis keys and value keys are digits alone is copied with them.
	 *
	 * @since 0.2.0
	 */
	public function test_a_product_whose_keys_are_digits_alone_is_copied_with_them(): void {
		$b        = $this->secondConnection();
		$original = $this->saveSet( null, VariantSets::digitOptions(), VariantSets::digitVariants( 'DIGITS' ) );
		$copy     = $this->copy( $original );

		$this->assertSame( array_keys( $original->variantIds ), array_keys( $copy->variantIds ), 'The copy has the same combinations.' );
		$this->assertSame( array( 'DIGITS-500-copy', 'DIGITS-1000-copy' ), array_column( $this->committedVariants( $b, $copy->productId ), 'sku' ) );
		$this->assertSame( 2, $this->committedCount( $b, CatalogTables::OPTION_AXES, 'product_id = ' . $copy->productId ) );
	}

	/**
	 * Tests that a copy of a product without options keeps the variant's state, every price on its basis and its weight, under its own SKU, in one save.
	 *
	 * A copy that enabled the variant would put on sale, once published, what the merchant disabled.
	 *
	 * @since 0.2.0
	 */
	public function test_a_copy_of_a_product_without_options_keeps_its_state_basis_and_rows(): void {
		$b        = $this->secondConnection();
		$original = $this->savedOnGrossPrices( 'OFF-1', false );

		$this->assertSame( array( 'incomplete', 1, 1, 0 ), $this->committedGeneration( $b, $original->productId ) );

		$copy = $this->copy( $original );

		$this->assertSame( array( 'incomplete', 1, 1, 0 ), $this->committedGeneration( $b, $copy->productId ), 'The state and the counts.' );
		$this->assertSame( array( 'EUR:gross:999', 'USD:gross:1234:2000' ), $this->committedPrices( $b, (int) $copy->variantId ), 'Every price, on its basis.' );
		$this->assertSame(
			array(
				(int) $copy->variantId => array(
					'generation' => 1,
					'enabled'    => 0,
					'sku'        => 'OFF-1-copy',
					'hash'       => Variant::defaultCombinationHash(),
					'label'      => null,
				),
			),
			$this->committedVariants( $b, $copy->productId )
		);
		$this->assertSame( '7', $b->fetchValue( sprintf( 'SELECT weight_grams FROM `%s` WHERE id = %d', $this->catalogTable( CatalogTables::VARIANTS ), (int) $copy->variantId ) ) );
		$this->assertSame( 0, $this->committedCount( $b, CatalogTables::OPTION_AXES, 'product_id = ' . $copy->productId ), 'It has no options.' );
		$this->assertSame( 1, $this->committedStockItems( $b, (int) $copy->variantId ) );
		$this->assertSame( 1, $this->committedSaves( $b, $copy->productId ), 'One save.' );
		$this->assertSame( array( 'EUR:gross:999', 'USD:gross:1234:2000' ), $this->committedPrices( $b, (int) $original->variantId ), 'The original changed.' );
	}

	/**
	 * Tests that an enabled product without options on gross prices is copied `complete`, and that the suffixes still number its SKU on those prices.
	 *
	 * @since 0.2.0
	 */
	public function test_a_whole_product_without_options_is_copied_whole_on_its_prices(): void {
		$b        = $this->secondConnection();
		$original = $this->savedOnGrossPrices( 'ON-1' );
		$first    = $this->copy( $original );
		$second   = $this->copy( $original );

		$this->assertSame( array( 'complete', 1, 1, 1 ), $this->committedGeneration( $b, $first->productId ) );
		$this->assertSame( SellabilityReason::NotPublished, $first->sellability );
		$this->assertSame( array( 'Taxed coffee', 'ON-1-copy', '1234' ), $this->committedProduct( $b, $first ) );
		$this->assertSame( array( 'Taxed coffee', 'ON-1-copy-2', '1234' ), $this->committedProduct( $b, $second ) );
		$this->assertSame( array( 'EUR:gross:999', 'USD:gross:1234:2000' ), $this->committedPrices( $b, (int) $second->variantId ) );
	}

	/**
	 * Tests that a copy leaves out a variant whose SKU another variant took, so it never carries the placeholder as a SKU.
	 *
	 * Planted violation: in DuplicateProduct::copiedVariants(), keep every variant the options
	 * admit; the copy is then refused for the placeholder's SKU, or carries it.
	 *
	 * @since 0.2.0
	 */
	public function test_a_copy_leaves_out_a_variant_without_a_sku(): void {
		$b        = $this->secondConnection();
		$original = $this->saveSet( null, VariantSets::options(), VariantSets::variants( 'DUP' ) );
		$variants = VariantSets::variants( 'DUP' );
		$left     = VariantSets::hash(
			array(
				'size'  => '250g',
				'grind' => 'filter',
			)
		);

		// The variant of 250 g whole bean takes the SKU of 250 g filter, which the set leaves out.
		$variants[0]['sku'] = $variants[1]['sku'];

		unset( $variants[1] );

		$this->saveSet( $original->postId, VariantSets::options(), array_values( $variants ) );

		$copy   = $this->copy( $original );
		$copied = $this->committedVariants( $b, $copy->productId );

		$this->assertArrayNotHasKey( $left, $copy->variantIds, 'The variant without a SKU is left out.' );
		$this->assertSame( array_map( static fn( array $variant ): string => $variant['sku'] . '-copy', array_values( $variants ) ), array_column( $copied, 'sku' ) );
		$this->assertSame( array(), array_filter( array_column( $copied, 'sku' ), static fn( string $sku ): bool => str_contains( $sku, '~' ) ), 'No SKU of the copy is a placeholder\'s.' );
		$this->assertSame( array( 'complete', 1, 5, 5 ), $this->committedGeneration( $b, $copy->productId ) );
	}
}
