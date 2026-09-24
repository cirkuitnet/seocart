<?php
/**
 * Tests MysqlProductRepository: the aggregate's round trip, its lookups, the marker statements and the SKU translation
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Catalog;

use SEOCart\Catalog\Domain\CatalogError;
use SEOCart\Catalog\Domain\GenerationState;
use SEOCart\Catalog\Domain\Product;
use SEOCart\Catalog\Domain\Sku;
use SEOCart\Catalog\Domain\Variant;
use SEOCart\Catalog\Domain\VariantPrice;
use SEOCart\Catalog\Infrastructure\CatalogTables;
use SEOCart\Platform\Database\Exception\DuplicateKey;
use SEOCart\Support\Currency;
use SEOCart\Support\Error\CodedException;
use SEOCart\Support\Locale;
use SEOCart\Tests\Support\Catalog\CatalogTestCase;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- The tests read the rows as the database holds them.

/**
 * A product stored through the repository reads back the same through every lookup; a stored
 * product keeps its marker when saved again; marking returns the instant it wrote, and settling
 * and restoring the marker are one conditional statement each, a restore only over its own mark;
 * and a SKU another variant holds is `catalog.sku_taken`, never the database's duplicate key,
 * even when the other variant was committed after this transaction's snapshot.
 *
 * Planted violations, one at a time, each put back afterwards:
 *
 * - In MysqlProductRepository::translatingSkuCollision(), rethrow the DuplicateKey at once:
 *   test_a_sku_another_variant_holds_is_sku_taken sees `database.duplicate_key`.
 * - In the same method, drop `LOCK IN SHARE MODE` from the holder query:
 *   test_a_sku_committed_after_the_snapshot_is_still_sku_taken sees `database.duplicate_key`.
 * - In MysqlProductRepository::leaveUpdating(), drop `AND generation_state = %s` (and its argument):
 *   test_leaving_updating_is_conditional overwrites a marker that is not `updating`.
 * - In MysqlProductRepository::restoreMark(), drop `AND updated_at = %s` (and its argument):
 *   test_a_restore_puts_back_only_its_own_mark restores over a mark of another instant.
 *
 * @since 0.1.0
 */
final class MysqlProductRepositoryTest extends CatalogTestCase {

	/**
	 * Tests that a first binding is stored whole and reads back the same through every lookup.
	 *
	 * @since 0.1.0
	 */
	public function test_a_first_binding_round_trips_through_every_lookup(): void {
		$product = $this->boundProduct( 'TSHIRT-RED-L', 1999 );

		$this->products->save( $product );

		$productId = (int) $product->id();
		$variantId = (int) $product->defaultVariant()?->id();
		$postId    = (int) $product->sourcePostId();

		$this->assertGreaterThan( 0, $productId );
		$this->assertGreaterThan( 0, $variantId );

		$row = $this->productRow( $productId );

		$this->assertSame( 'updating', $row['generation_state'] ?? null, 'A first binding is stored `updating`.' );
		$this->assertSame( '1', $row['active_variant_generation'] ?? null );
		$this->assertSame( '1', $row['variant_count'] ?? null );
		$this->assertSame( '1', $row['enabled_variant_count'] ?? null );
		$this->assertSame( (string) $postId, $row['source_post_id'] ?? null );
		$this->assertSame( 'standard', $row['kind'] ?? null, 'The columns nothing writes yet keep their defaults.' );
		$this->assertSame( 'hidden', $row['visibility'] ?? null );
		$this->assertSame( 'unknown', $row['stock_summary'] ?? null );
		$this->assertSame( 'buy', $row['purchasability_mode'] ?? null );
		$this->assertNull( $row['tax_class_id'] ?? null );
		$this->assertSame(
			array(
				'currency'         => 'USD',
				'amount_basis'     => 'net',
				'price_minor'      => '1999',
				'compare_at_minor' => null,
			),
			$this->db->fetchRow( 'SELECT currency, amount_basis, price_minor, compare_at_minor FROM %i WHERE variant_id = %d', $this->catalogTable( CatalogTables::VARIANT_PRICES ), $variantId )
		);

		foreach ( array(
			'by post'        => $this->products->findByPost( $postId ),
			'by source post' => $this->products->findBySourcePost( $postId ),
			'by variant'     => $this->products->findByVariant( $variantId ),
		) as $lookup => $found ) {
			$this->assertNotNull( $found, $lookup );
			$this->assertSame( $productId, $found->id(), $lookup );
			$this->assertSame( $product->uuid(), $found->uuid(), $lookup );
			$this->assertSame( $postId, $found->sourcePostId(), $lookup );
			$this->assertSame( GenerationState::Updating, $found->generation(), $lookup );
			$this->assertSame( 1, $found->activeGeneration(), $lookup );
			$this->assertCount( 1, $found->bindings(), $lookup );
			$this->assertSame( $postId, $found->bindings()[0]->postId(), $lookup );
			$this->assertSame( self::LOCALE, $found->bindings()[0]->locale()->toString(), $lookup );
			$this->assertSame( '2026-09-25 10:00:00', $found->bindings()[0]->linkedAt()->format( 'Y-m-d H:i:s' ), $lookup );

			$variant = $found->defaultVariant();

			$this->assertNotNull( $variant, $lookup );
			$this->assertSame( $variantId, $variant->id(), $lookup );
			$this->assertSame( $product->defaultVariant()?->uuid(), $variant->uuid(), $lookup );
			$this->assertSame( 'TSHIRT-RED-L', $variant->sku()->toString(), $lookup );
			$this->assertSame( Variant::FIRST_GENERATION, $variant->generation(), $lookup );
			$this->assertSame( Variant::defaultCombinationHash(), $variant->combinationHash(), $lookup );
			$this->assertTrue( $variant->isEnabled(), $lookup );
			$this->assertSame( 250, $variant->weightGrams(), $lookup );

			$price = $variant->basePrice();

			$this->assertNotNull( $price, $lookup );
			$this->assertSame( 1999, $price->priceMinor(), $lookup );
			$this->assertSame( 'USD', $price->currency()->code(), $lookup );
		}

		$this->assertNull( $this->products->findByPost( $postId + 1000 ) );
		$this->assertNull( $this->products->findBySourcePost( $postId + 1000 ) );
		$this->assertNull( $this->products->findByVariant( $variantId + 1000 ) );
	}

	/**
	 * Tests that a stored product saved again keeps its marker, and its commerce changes are written.
	 *
	 * @since 0.1.0
	 */
	public function test_saving_a_stored_product_writes_its_changes_and_keeps_its_marker(): void {
		$usd     = Currency::of( self::BASE_CURRENCY );
		$stored  = $this->storedProduct( 'A-1' );
		$product = $this->products->findBySourcePost( (int) $stored->sourcePostId() );

		$this->assertNotNull( $product );
		$this->assertSame( GenerationState::Complete, $product->generation() );

		$product->applyCommerce( Sku::of( 'A-2' ), VariantPrice::net( $usd, 2500, 3000 ), null, $usd );
		$product->settle( false );
		$this->products->save( $product );

		$again = $this->products->findByVariant( (int) $stored->defaultVariant()?->id() );

		$this->assertNotNull( $again );

		$variant = $again->defaultVariant();
		$price   = $variant?->basePrice();

		$this->assertNotNull( $variant );
		$this->assertNotNull( $price );
		$this->assertSame( GenerationState::Complete, $again->generation(), 'save() leaves the marker of a stored product to the marker statements.' );
		$this->assertSame( 'A-2', $variant->sku()->toString() );
		$this->assertSame( 2500, $price->priceMinor() );
		$this->assertSame( 3000, $price->compareAtMinor() );
		$this->assertNull( $variant->weightGrams() );
		$this->assertCount( 1, $again->bindings(), 'The stored binding is not written twice.' );

		$again->applyCommerce( Sku::of( 'A-2' ), null, null, $usd );
		$this->products->save( $again );

		$this->assertNull( $this->products->findByPost( (int) $stored->sourcePostId() )?->defaultVariant()?->basePrice(), 'A price the variant no longer has is removed.' );
	}

	/**
	 * Tests that saving a product whose row is gone is `catalog.product_not_found`.
	 *
	 * @since 0.1.0
	 */
	public function test_saving_a_product_whose_row_is_gone_is_product_not_found(): void {
		$product = $this->storedProduct();

		$this->db->execute( 'DELETE FROM %i WHERE id = %d', $this->catalogTable( CatalogTables::PRODUCTS ), (int) $product->id() );

		try {
			$this->products->save( $product );
		} catch ( CodedException $refused ) {
			$this->assertSame( CatalogError::ProductNotFound, $refused->errorCode() );
			$this->assertSame( array( 'product_id' => (int) $product->id() ), $refused->context() );

			return;
		}

		$this->fail( 'A product whose row is gone was saved.' );
	}

	/**
	 * Tests that a product reconciled from a foreign post is stored `incomplete`, bound, without a variant.
	 *
	 * @since 0.1.0
	 */
	public function test_a_reconciled_product_is_stored_incomplete_without_a_variant(): void {
		$postId  = $this->post();
		$product = Product::reconciled( $this->ids->generate(), $postId, Locale::of( 'en_GB' ), new \DateTimeImmutable( '2026-09-25 11:00:00', new \DateTimeZone( 'UTC' ) ) );

		$this->products->save( $product );

		$found = $this->products->findByPost( $postId );

		$this->assertNotNull( $found );
		$this->assertSame( GenerationState::Incomplete, $found->generation() );
		$this->assertNull( $found->defaultVariant() );
		$this->assertSame( 0, $found->activeGeneration() );
		$this->assertSame( 'en_GB', $found->bindings()[0]->locale()->toString() );
	}

	/**
	 * Tests that marking returns the instant it wrote, inside a transaction only, and that a second mark writes a later one.
	 *
	 * @since 0.1.0
	 */
	public function test_marking_returns_the_instant_it_wrote(): void {
		$productId = (int) $this->storedProduct()->id();
		$first     = $this->mark( $productId );
		$stored    = $this->productRow( $productId );

		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{6}$/', (string) $first, 'The instant is not written to the microsecond.' );
		$this->assertSame( $first, $stored['updated_at'] ?? null );
		$this->assertSame( 'updating', $stored['generation_state'] ?? null );

		$second = $this->mark( $productId );

		$this->assertNotSame( $first, $second, 'Marking an updating product again writes a new instant.' );
		$this->assertNull( $this->mark( $productId + 1000 ), 'There is no such product.' );

		$this->expectException( \LogicException::class );
		$this->products->markUpdating( $productId );
	}

	/**
	 * Tests that settling is one conditional statement: it changes the marker only while it is `updating`.
	 *
	 * @since 0.1.0
	 */
	public function test_leaving_updating_is_conditional(): void {
		$productId = (int) $this->storedProduct()->id();

		$this->assertFalse( $this->products->leaveUpdating( $productId, GenerationState::Incomplete ), 'A complete product is not updating, so nothing changes.' );
		$this->assertSame( 'complete', $this->productRow( $productId )['generation_state'] ?? null );

		$this->mark( $productId );

		$this->assertTrue( $this->products->leaveUpdating( $productId, GenerationState::Complete ) );
		$this->assertSame( 'complete', $this->productRow( $productId )['generation_state'] ?? null );
		$this->assertFalse( $this->products->leaveUpdating( $productId, GenerationState::Incomplete ) );
	}

	/**
	 * Tests that a restore puts back the marker only over the mark written at the instant it is given.
	 *
	 * @since 0.1.0
	 */
	public function test_a_restore_puts_back_only_its_own_mark(): void {
		$productId = (int) $this->storedProduct()->id();
		$markedAt  = (string) $this->mark( $productId );

		$this->assertFalse( $this->products->restoreMark( $productId, GenerationState::Complete, '2000-01-01 00:00:00.000000' ), 'A restore changed a mark of another instant.' );
		$this->assertSame( 'updating', $this->productRow( $productId )['generation_state'] ?? null );

		$this->assertTrue( $this->products->restoreMark( $productId, GenerationState::Complete, $markedAt ) );
		$this->assertSame( 'complete', $this->productRow( $productId )['generation_state'] ?? null );
		$this->assertFalse( $this->products->restoreMark( $productId, GenerationState::Incomplete, $markedAt ), 'The product is no longer marked.' );
	}

	/**
	 * Marks a product in a transaction of its own, as a write does before its window.
	 *
	 * @since 0.1.0
	 *
	 * @param int $productId The product's id.
	 * @return string|null The instant the mark wrote, or null when there is no such product.
	 */
	private function mark( int $productId ): ?string {
		return $this->db->transaction( fn(): ?string => $this->products->markUpdating( $productId ) );
	}

	/**
	 * Tests that a SKU another variant holds, in any case, is `catalog.sku_taken`, and nothing of the second product is left.
	 *
	 * @since 0.1.0
	 */
	public function test_a_sku_another_variant_holds_is_sku_taken(): void {
		$this->storedProduct( 'TSHIRT-RED' );

		$second = $this->boundProduct( 'tshirt-red' );

		try {
			$this->db->transaction( fn() => $this->products->save( $second ) );
		} catch ( CodedException $refused ) {
			$this->assertNotInstanceOf( DuplicateKey::class, $refused, 'The database\'s duplicate key reached the caller.' );
			$this->assertSame( CatalogError::SkuTaken, $refused->errorCode() );
			$this->assertSame( array( 'sku' => 'tshirt-red' ), $refused->context() );
			$this->assertSame( 1, (int) $this->db->fetchValue( 'SELECT COUNT(*) FROM %i', $this->catalogTable( CatalogTables::PRODUCTS ) ), 'The second product was rolled back with its variant.' );

			return;
		}

		$this->fail( 'Two variants were saved with one SKU.' );
	}

	/**
	 * Tests the translation against a SKU another connection committed after this transaction read its snapshot.
	 *
	 * @since 0.1.0
	 */
	public function test_a_sku_committed_after_the_snapshot_is_still_sku_taken(): void {
		$product = $this->boundProduct( 'LATE-1' );
		$other   = $this->secondConnection();
		$code    = null;

		try {
			$this->db->transaction(
				function () use ( $product, $other ): void {
					// The first read fixes this transaction's snapshot; the other variant is committed after it.
					$this->db->fetchValue( 'SELECT COUNT(*) FROM %i', $this->catalogTable( CatalogTables::VARIANTS ) );

					$other->query(
						sprintf(
							"INSERT INTO `%s` ( uuid, product_id, sku, combination_hash, generation, created_at, updated_at ) VALUES ( '00000000-0000-7000-8000-000000000999', 999, 'late-1', '%s', 1, UTC_TIMESTAMP(), UTC_TIMESTAMP() )",
							$this->catalogTable( CatalogTables::VARIANTS ),
							hash( 'sha256', '' )
						)
					);

					$this->products->save( $product );
				}
			);
		} catch ( CodedException $refused ) {
			$code = $refused->errorCode();
		}

		$this->assertSame( CatalogError::SkuTaken, $code );
	}
}
