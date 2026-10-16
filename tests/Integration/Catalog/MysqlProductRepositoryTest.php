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

use SEOCart\Catalog\Application\UpdatingMark;
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
 * even when the other variant was committed after this transaction's snapshot. The six reads
 * doctor pages through (unbound products, invalid source bindings, unbound posts, bindings,
 * incomplete products, variants) list the rows each WHERE clause selects, from the first page
 * and from a cursor.
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
 * - In MysqlProductRepository::remark(), write `updated_at = UTC_TIMESTAMP(6)`:
 *   test_every_instant_is_later_than_the_rows_last_one sees a mark earlier than the row's last instant.
 * - In the cursor statement of boundPostBindings(), compare `post_id >= %d`: the cursor test sees the cursor's own binding on the next page.
 * - In the unbound-posts scan of unboundPostIds(), drop `AND pp.post_id IS NULL`: its test sees the bound post listed.
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
	 * Tests that marking returns the marker it replaced and the instant it wrote, inside a transaction only.
	 *
	 * @since 0.1.0
	 */
	public function test_marking_returns_the_marker_it_replaced_and_the_instant_it_wrote(): void {
		$productId = (int) $this->storedProduct()->id();
		$first     = $this->mark( $productId );
		$stored    = $this->productRow( $productId );

		$this->assertInstanceOf( UpdatingMark::class, $first );
		$this->assertSame( GenerationState::Complete, $first->before, 'The mark replaced the stored marker.' );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{6}$/', $first->markedAt, 'The instant is not written to the microsecond.' );
		$this->assertSame( $first->markedAt, $stored['updated_at'] ?? null );
		$this->assertSame( 'updating', $stored['generation_state'] ?? null );

		$second = $this->mark( $productId );

		$this->assertInstanceOf( UpdatingMark::class, $second );
		$this->assertSame( GenerationState::Updating, $second->before, 'A second mark replaces the first.' );
		$this->assertNotSame( $first->markedAt, $second->markedAt, 'Marking an updating product again writes a new instant.' );
		$this->assertNull( $this->mark( $productId + 1000 ), 'There is no such product.' );

		$this->expectException( \LogicException::class );
		$this->products->markUpdating( $productId );
	}

	/**
	 * Tests that every marker write moves the row's instant forward, even when the row's last instant is ahead of the database clock.
	 *
	 * A row whose instant is a day ahead stands for two writes in one microsecond, and for a
	 * database clock that stepped back: in both, the clock alone would not move the instant.
	 *
	 * @since 0.1.0
	 */
	public function test_every_instant_is_later_than_the_rows_last_one(): void {
		$productId = (int) $this->storedProduct()->id();

		$this->db->execute( 'UPDATE %i SET updated_at = UTC_TIMESTAMP(6) + INTERVAL 1 DAY WHERE id = %d', $this->catalogTable( CatalogTables::PRODUCTS ), $productId );

		$ahead = (string) ( $this->productRow( $productId )['updated_at'] ?? '' );
		$mark  = $this->mark( $productId );

		$this->assertInstanceOf( UpdatingMark::class, $mark );
		$this->assertGreaterThan( $ahead, $mark->markedAt, 'The mark did not move the instant forward.' );

		$this->db->transaction( fn(): bool => $this->products->relock( $productId ) );

		$relocked = (string) ( $this->productRow( $productId )['updated_at'] ?? '' );

		$this->assertGreaterThan( $mark->markedAt, $relocked, 'The relock did not move the instant forward.' );
		$this->assertFalse( $this->products->restoreMark( $productId, $mark->before, $mark->markedAt ), 'A restore took the relock\'s mark for its own.' );
		$this->assertTrue( $this->db->transaction( fn(): bool => $this->products->leaveUpdating( $productId, GenerationState::Complete ) ) );
		$this->assertGreaterThan( $relocked, (string) ( $this->productRow( $productId )['updated_at'] ?? '' ), 'Settling did not move the instant forward.' );
	}

	/**
	 * Tests that the window's relock marks the product again with a new instant, inside a transaction only.
	 *
	 * @since 0.1.0
	 */
	public function test_the_relock_marks_again_with_a_new_instant(): void {
		$productId = (int) $this->storedProduct()->id();
		$mark      = $this->mark( $productId );

		$this->assertInstanceOf( UpdatingMark::class, $mark );
		$this->assertTrue( $this->db->transaction( fn(): bool => $this->products->relock( $productId ) ) );

		$row = $this->productRow( $productId );

		$this->assertSame( 'updating', $row['generation_state'] ?? null );
		$this->assertNotSame( $mark->markedAt, $row['updated_at'] ?? null, 'The relock did not write a new instant, so a committed window would pass for the mark before it.' );
		$this->assertFalse( $this->products->restoreMark( $productId, $mark->before, $mark->markedAt ), 'A restore took back a mark a committed window had renewed.' );
		$this->assertFalse( $this->db->transaction( fn(): bool => $this->products->relock( $productId + 1000 ) ), 'There is no such product.' );

		$this->expectException( \LogicException::class );
		$this->products->relock( $productId );
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
		$markedAt  = (string) $this->mark( $productId )?->markedAt;

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
	 * @return UpdatingMark|null The mark, or null when there is no such product.
	 */
	private function mark( int $productId ): ?UpdatingMark {
		return $this->db->transaction( fn(): ?UpdatingMark => $this->products->markUpdating( $productId ) );
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

	/**
	 * Tests that the products with no `product_posts` row are listed by id, a page at a time, from a cursor.
	 *
	 * @since 0.2.0
	 */
	public function test_unbound_product_ids_page_by_cursor_and_leave_out_the_bound_ones(): void {
		$this->storedProduct( 'SKU-BOUND' );

		$first  = $this->bareProduct();
		$second = $this->bareProduct();
		$third  = $this->bareProduct();

		$this->assertSame( array( $first, $second, $third ), $this->products->unboundProductIds( 0, 10 ), 'The bound product is not listed.' );
		$this->assertSame( array( $first, $second, $third ), $this->products->unboundProductIds( -1, 10 ), 'A cursor that is not positive is a first page.' );
		$this->assertSame( array( $first, $second ), $this->products->unboundProductIds( 0, 2 ), 'The limit ends the page.' );
		$this->assertSame( array( $second, $third ), $this->products->unboundProductIds( $first, 10 ), 'A cursor lists the products above it.' );
		$this->assertSame( array( $second ), $this->products->unboundProductIds( $first, 1 ), 'The limit applies after a cursor.' );
		$this->assertSame( array(), $this->products->unboundProductIds( $third, 10 ), 'Nothing is above the last.' );
	}

	/**
	 * Tests that each way a source binding is invalid lists the product, and a valid one is not listed, from a cursor.
	 *
	 * @since 0.2.0
	 */
	public function test_invalid_source_bindings_page_by_cursor_and_leave_out_the_valid_ones(): void {
		global $wpdb;

		$this->storedProduct( 'SKU-VALID' );

		$noSource = $this->bareProduct();

		$unbound = (int) $this->storedProduct( 'SKU-UNBOUND' )->id();

		$this->db->execute( 'DELETE FROM %i WHERE product_id = %d', $this->catalogTable( CatalogTables::PRODUCT_POSTS ), $unbound );

		$goneProduct = $this->storedProduct( 'SKU-GONE' );
		$gone        = (int) $goneProduct->id();

		$this->db->execute( 'DELETE FROM %i WHERE ID = %d', $wpdb->posts, (int) $goneProduct->sourcePostId() );

		$otherTypeProduct = $this->storedProduct( 'SKU-OTHER-TYPE' );
		$otherType        = (int) $otherTypeProduct->id();

		$this->db->execute( 'UPDATE %i SET post_type = %s WHERE ID = %d', $wpdb->posts, 'page', (int) $otherTypeProduct->sourcePostId() );

		$invalid = array( $noSource, $unbound, $gone, $otherType );

		$this->assertSame( $invalid, $this->products->invalidSourceBindings( 0, 10 ), 'The valid product is not listed; each other one is.' );
		$this->assertSame( $invalid, $this->products->invalidSourceBindings( -1, 10 ) );
		$this->assertSame( array( $noSource, $unbound ), $this->products->invalidSourceBindings( 0, 2 ) );
		$this->assertSame( array( $unbound, $gone, $otherType ), $this->products->invalidSourceBindings( $noSource, 10 ) );
		$this->assertSame( array( $unbound ), $this->products->invalidSourceBindings( $noSource, 1 ) );
		$this->assertSame( array(), $this->products->invalidSourceBindings( $otherType, 10 ) );
	}

	/**
	 * Tests that the product posts with no binding are listed by id from a cursor, and a bound post, an auto-draft and a post of another type are not.
	 *
	 * @since 0.2.0
	 */
	public function test_unbound_post_ids_page_by_cursor_and_leave_out_what_the_check_does_not_cover(): void {
		$this->storedProduct( 'SKU-BOUND-POST' );
		$this->unboundPost( 'auto-draft' );
		$this->post( 'publish', 'page' );

		$published = $this->unboundPost();
		$draft     = $this->unboundPost( 'draft' );
		$private   = $this->unboundPost( 'private' );

		$unbound = array( $published, $draft, $private );

		$this->assertSame( $unbound, $this->products->unboundPostIds( 0, 10 ) );
		$this->assertSame( $unbound, $this->products->unboundPostIds( -1, 10 ) );
		$this->assertSame( array( $published, $draft ), $this->products->unboundPostIds( 0, 2 ) );
		$this->assertSame( array( $draft, $private ), $this->products->unboundPostIds( $published, 10 ), 'A cursor lists the posts above it.' );
		$this->assertSame( array( $draft ), $this->products->unboundPostIds( $published, 1 ) );
		$this->assertSame( array(), $this->products->unboundPostIds( $private, 10 ) );
	}

	/**
	 * Tests that every binding is listed by post id with its product and locale, from a cursor.
	 *
	 * @since 0.2.0
	 */
	public function test_bound_post_bindings_page_by_cursor_with_their_product_and_locale(): void {
		$bindings = array();

		foreach ( array( 'SKU-BIND-A', 'SKU-BIND-B', 'SKU-BIND-C' ) as $sku ) {
			$product    = $this->storedProduct( $sku );
			$bindings[] = array(
				'post_id'    => (int) $product->sourcePostId(),
				'product_id' => (int) $product->id(),
				'locale'     => self::LOCALE,
			);
		}

		$this->assertSame( $bindings, $this->products->boundPostBindings( 0, 10 ) );
		$this->assertSame( $bindings, $this->products->boundPostBindings( -1, 10 ) );
		$this->assertSame( array_slice( $bindings, 0, 2 ), $this->products->boundPostBindings( 0, 2 ) );
		$this->assertSame( array_slice( $bindings, 1 ), $this->products->boundPostBindings( $bindings[0]['post_id'], 10 ), 'A cursor lists the bindings of the posts above it.' );
		$this->assertSame( array( $bindings[1] ), $this->products->boundPostBindings( $bindings[0]['post_id'], 1 ) );
		$this->assertSame( array(), $this->products->boundPostBindings( $bindings[2]['post_id'], 10 ) );
	}

	/**
	 * Tests that a complete product with no enabled variant or no base price is listed from a cursor, and a whole one and an incomplete one are not.
	 *
	 * @since 0.2.0
	 */
	public function test_incomplete_mismatch_ids_page_by_cursor_and_cover_only_complete_products(): void {
		$prices = $this->catalogTable( CatalogTables::VARIANT_PRICES );

		$whole = (int) $this->storedProduct( 'SKU-WHOLE' )->id();

		$unpriced = $this->storedProduct( 'SKU-UNPRICED' );

		$this->db->execute( 'DELETE FROM %i WHERE variant_id = %d', $prices, (int) $unpriced->defaultVariant()?->id() );

		$disabled = (int) $this->storedProduct( 'SKU-DISABLED' )->id();

		$this->db->execute( 'UPDATE %i SET enabled_variant_count = 0 WHERE id = %d', $this->catalogTable( CatalogTables::PRODUCTS ), $disabled );

		$stillIncomplete = $this->storedProduct( 'SKU-INCOMPLETE' );

		$this->db->execute( 'DELETE FROM %i WHERE variant_id = %d', $prices, (int) $stillIncomplete->defaultVariant()?->id() );
		$this->db->execute( 'UPDATE %i SET generation_state = %s WHERE id = %d', $this->catalogTable( CatalogTables::PRODUCTS ), GenerationState::Incomplete->value, (int) $stillIncomplete->id() );

		$mismatched = array( (int) $unpriced->id(), $disabled );

		$this->assertSame( $mismatched, $this->products->incompleteMismatchIds( self::BASE_CURRENCY, 0, 10 ), 'The whole product and the incomplete one are not listed.' );
		$this->assertSame( $mismatched, $this->products->incompleteMismatchIds( self::BASE_CURRENCY, -1, 10 ) );
		$this->assertSame( array( $mismatched[0] ), $this->products->incompleteMismatchIds( self::BASE_CURRENCY, 0, 1 ) );
		$this->assertSame( array( $mismatched[1] ), $this->products->incompleteMismatchIds( self::BASE_CURRENCY, $mismatched[0], 10 ), 'A cursor lists the products above it.' );
		$this->assertSame( array(), $this->products->incompleteMismatchIds( self::BASE_CURRENCY, $mismatched[1], 10 ) );
		$this->assertSame( array( $whole, $mismatched[0], $mismatched[1] ), $this->products->incompleteMismatchIds( 'EUR', 0, 10 ), 'The price asked for is the one in the currency given: no product has a EUR price, and the incomplete one is still left out.' );
	}

	/**
	 * Tests that the variants whose product exists are listed by id from a cursor, and an orphan variant is not.
	 *
	 * @since 0.2.0
	 */
	public function test_variant_ids_page_by_cursor_and_leave_out_a_variant_with_no_product(): void {
		$first  = (int) $this->storedProduct( 'SKU-VARIANT-A' )->defaultVariant()?->id();
		$second = (int) $this->storedProduct( 'SKU-VARIANT-B' )->defaultVariant()?->id();
		$third  = (int) $this->storedProduct( 'SKU-VARIANT-C' )->defaultVariant()?->id();

		$this->db->execute(
			'INSERT INTO %i ( uuid, product_id, sku, combination_hash, generation, is_enabled, position, created_at, updated_at ) VALUES ( %s, 999999997, %s, %s, 1, 1, 0, UTC_TIMESTAMP(), UTC_TIMESTAMP() )',
			$this->catalogTable( CatalogTables::VARIANTS ),
			$this->ids->generate(),
			'SKU-ORPHAN',
			str_repeat( '0', 64 )
		);

		$this->assertSame( array( $first, $second, $third ), $this->products->variantIds( 0, 10 ), 'The orphan variant is not listed.' );
		$this->assertSame( array( $first, $second, $third ), $this->products->variantIds( -1, 10 ) );
		$this->assertSame( array( $first, $second ), $this->products->variantIds( 0, 2 ) );
		$this->assertSame( array( $second, $third ), $this->products->variantIds( $first, 10 ), 'A cursor lists the variants above it.' );
		$this->assertSame( array( $second ), $this->products->variantIds( $first, 1 ) );
		$this->assertSame( array(), $this->products->variantIds( $third, 10 ) );
	}

	/**
	 * Stores a product row with no source post and no binding.
	 *
	 * @since 0.2.0
	 *
	 * @return int The product's id.
	 */
	private function bareProduct(): int {
		$this->db->execute(
			'INSERT INTO %i ( uuid, generation_state, active_variant_generation, created_at, updated_at ) VALUES ( %s, %s, 0, UTC_TIMESTAMP(), UTC_TIMESTAMP(6) )',
			$this->catalogTable( CatalogTables::PRODUCTS ),
			$this->ids->generate(),
			GenerationState::Incomplete->value
		);

		return $this->db->lastInsertId();
	}
}
