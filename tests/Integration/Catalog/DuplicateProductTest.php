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
use SEOCart\Catalog\Domain\ReportCode;
use SEOCart\Catalog\Domain\SellabilityReason;
use SEOCart\Catalog\Domain\Sku;
use SEOCart\Catalog\Infrastructure\CatalogTables;
use SEOCart\Inventory\Domain\LedgerReason;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Platform\Authorization\ProductCapabilities;
use SEOCart\Support\Error\CodedException;
use SEOCart\Tests\Support\Catalog\ProductWrites;
use SEOCart\Tests\Support\Catalog\ProductWriteTestCase;

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
 *
 * @since 0.1.0
 */
final class DuplicateProductTest extends ProductWriteTestCase {

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

		$this->services->stock->adjust( (int) $original->variantId, 7, LedgerReason::Received, Actor::user( 1 ) );

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
}
