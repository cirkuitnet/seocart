<?php
/**
 * Tests what trashing, restoring and deleting a product's post does to the product and its stock
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Catalog;

use SEOCart\Catalog\Application\ProductWrite\SaveResult;
use SEOCart\Catalog\Domain\Event\ProductDeleted;
use SEOCart\Catalog\Domain\GenerationState;
use SEOCart\Catalog\Domain\ReportCode;
use SEOCart\Catalog\Domain\SellabilityReason;
use SEOCart\Catalog\Infrastructure\CatalogTables;
use SEOCart\Inventory\Domain\HoldLine;
use SEOCart\Inventory\Domain\LedgerReason;
use SEOCart\Inventory\Infrastructure\InventoryTables;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Platform\Events\OutboxTable;
use SEOCart\Tests\Support\Catalog\ProductWriteTestCase;
use SEOCart\Tests\Support\SecondConnection;

/**
 * Trashing, restoring and deleting a product's post keeps the product and its stock consistent, whichever path does it.
 *
 * - Trash: the holds on every variant of the product are released; the stock items, their units
 *   and the product stay, and the product cannot be sold while its post is in the trash.
 * - Leaving the trash re-holds nothing; the product can be sold again when its post is
 *   published again.
 * - Deleting the source post of a product with no other binding deletes the product in one
 *   transaction: its four tables' rows, its stock item, and its holds go; its ledger stays, with
 *   one final entry; ProductDeleted is stored with the SKUs. The same happens when WordPress's
 *   daily cron empties the trash.
 * - A delete the product's deletion cannot follow is refused, and the post and every row stay:
 *   when the deletion fails, and when the variant has an open allocation. A delete an earlier
 *   callback has refused is left alone, and revisions and attachments are not products.
 * - The trash and the delete decide on the rows another writer committed while they waited:
 *   the deletion's event names the SKUs its variants had then, and no variant of a newer
 *   generation keeps its stock item, even in a transaction that read the product before.
 *
 * The lifecycle is hooked through the kernel's own Modules::catalogLifecycleHooks().
 *
 * Planted violations, each confirmed to fail a test here:
 * - In PostLifecycle::statusChanged(), drop the releaseVariants() call: the trash test fails,
 *   its hold still held.
 * - Delete the product on `deleted_post` instead of `pre_delete_post`, after WordPress has
 *   deleted the post: the refused-delete test fails, the post gone and the rows left orphaned.
 * - In Modules::catalogLifecycleHooks(), drop the check of an earlier callback's answer: the
 *   test of an earlier refusal fails.
 * - In PostLifecycle::statusChanged(), release the product's default variant alone, as before:
 *   the test of every variant fails, the second variant's hold still held.
 * - Put back the reads under review in the delete: PostLifecycle::deleting() reads the product
 *   with findByPost(), MysqlProductRepository::lockForDelete() locks the product's row and then
 *   reads it without FOR UPDATE, and delete() reads the variants without it: the test of a delete
 *   after a save fails, its event naming `SKU-1`. Either half alone keeps it green: the first
 *   statement is the lock, or every read after it is a locking read.
 * - In MysqlProductRepository::delete(), read the variants without FOR UPDATE: the test of an
 *   older snapshot fails, its event naming `SKU-1`.
 * - In DeleteProduct::delete(), give deleteVariants() the loaded product's default variant in
 *   place of the variants delete() returns: both tests fail, the newer generation's stock item
 *   left.
 *
 * @since 0.1.0
 */
final class LifecycleTest extends ProductWriteTestCase {

	/**
	 * Hooks the product lifecycle in the kernel's place.
	 *
	 * @since 0.1.0
	 */
	public function set_up(): void {
		parent::set_up();

		$this->services->attach();
	}

	/**
	 * Tests that trashing a product's post releases the holds on its variant and keeps its stock, and the product cannot be sold meanwhile.
	 *
	 * @since 0.1.0
	 */
	public function test_trashing_releases_the_holds_and_keeps_the_stock(): void {
		$b     = $this->secondConnection();
		$saved = $this->stocked( 5 );

		$this->services->stock->hold( array( new HoldLine( (int) $saved->variantId, 2 ) ), 600 );

		$this->assertSame( 2, $this->committedItem( $b, (int) $saved->variantId )['held'] ?? null );
		$this->assertInstanceOf( \WP_Post::class, wp_trash_post( $saved->postId ) );

		$this->assertSame(
			array(
				'on_hand'   => 5,
				'allocated' => 0,
				'held'      => 0,
			),
			$this->committedItem( $b, (int) $saved->variantId )
		);
		$this->assertSame( 0, $this->committedCount( $b, InventoryTables::HOLDS, sprintf( 'variant_id = %d', (int) $saved->variantId ) ) );
		$this->assertSame( GenerationState::Complete->value, $this->committedMarker( $b, $saved->productId )[0], 'The product itself is kept whole.' );
		$this->assertSame( SellabilityReason::NotPublished, $this->verdict( (int) $saved->variantId ) );
		$this->assertSame( array(), $this->reports );
	}

	/**
	 * Tests that trashing a product's post releases the holds of every variant of its product, in ascending order, and keeps their stock.
	 *
	 * @since 0.1.0
	 */
	public function test_trashing_releases_the_holds_of_every_variant(): void {
		$b     = $this->secondConnection();
		$saved = $this->stocked( 5 );
		$first = (int) $saved->variantId;
		$next  = $this->variantElsewhere( $b, $saved, 'SKU-2', 1 );

		$this->db->transaction( fn() => $this->services->stock->createItems( array( $next ) ) );
		$this->services->stock->adjust( $next, 4, LedgerReason::Received, Actor::user( 1 ) );
		$this->services->stock->hold(
			array(
				new HoldLine( $first, 1 ),
				new HoldLine( $next, 2 ),
			),
			600
		);

		$this->assertSame( array( 1, 2 ), array( $this->committedItem( $b, $first )['held'] ?? null, $this->committedItem( $b, $next )['held'] ?? null ) );
		$this->assertInstanceOf( \WP_Post::class, wp_trash_post( $saved->postId ) );

		$stocked = array(
			$first => 5,
			$next  => 4,
		);

		foreach ( $stocked as $variantId => $onHand ) {
			$this->assertSame(
				array(
					'on_hand'   => $onHand,
					'allocated' => 0,
					'held'      => 0,
				),
				$this->committedItem( $b, $variantId ),
				sprintf( 'Variant %d kept a hold, or lost its stock.', $variantId )
			);
			$this->assertSame( 0, $this->committedCount( $b, InventoryTables::HOLDS, sprintf( 'variant_id = %d', $variantId ) ) );
		}

		$this->assertSame( array(), $this->reports );
	}

	/**
	 * Tests that a post leaving the trash re-holds nothing, and its product can be sold again once the post is published.
	 *
	 * WordPress restores a trashed post as a draft unless a filter restores its old status.
	 *
	 * @since 0.1.0
	 */
	public function test_leaving_the_trash_re_holds_nothing(): void {
		$b     = $this->secondConnection();
		$saved = $this->stocked( 5 );

		$this->services->stock->hold( array( new HoldLine( (int) $saved->variantId, 1 ) ), 600 );

		wp_trash_post( $saved->postId );

		add_filter( 'wp_untrash_post_status', 'wp_untrash_post_set_previous_status', 10, 3 );

		$this->assertInstanceOf( \WP_Post::class, wp_untrash_post( $saved->postId ) );
		$this->assertSame( 'publish', get_post_status( $saved->postId ) );
		$this->assertSame( SellabilityReason::Sellable, $this->verdict( (int) $saved->variantId ) );
		$this->assertSame( 0, $this->committedItem( $b, (int) $saved->variantId )['held'] ?? null, 'Leaving the trash held units again.' );

		remove_filter( 'wp_untrash_post_status', 'wp_untrash_post_set_previous_status', 10 );

		wp_trash_post( $saved->postId );
		wp_untrash_post( $saved->postId );

		$this->assertSame( 'draft', get_post_status( $saved->postId ) );
		$this->assertSame( SellabilityReason::NotPublished, $this->verdict( (int) $saved->variantId ) );
		$this->assertSame( 0, $this->committedItem( $b, (int) $saved->variantId )['held'] ?? null );
	}

	/**
	 * Tests that deleting a product's only post deletes the product, its commerce rows and its stock item, and keeps its ledger with one final entry.
	 *
	 * @since 0.1.0
	 */
	public function test_deleting_the_only_post_deletes_the_product_and_its_stock(): void {
		$b     = $this->secondConnection();
		$saved = $this->stocked( 3 );

		$this->services->stock->hold( array( new HoldLine( (int) $saved->variantId, 1 ) ), 600 );

		$this->assertInstanceOf( \WP_Post::class, wp_delete_post( $saved->postId, true ) );

		$this->assertDeleted( $b, $saved );
	}

	/**
	 * Tests that WordPress's daily emptying of the trash deletes the product with its post, as a delete by hand does.
	 *
	 * @since 0.1.0
	 */
	public function test_emptying_the_trash_deletes_the_product(): void {
		$b     = $this->secondConnection();
		$saved = $this->stocked( 3 );

		wp_trash_post( $saved->postId );
		update_post_meta( $saved->postId, '_wp_trash_meta_time', time() - ( DAY_IN_SECONDS * EMPTY_TRASH_DAYS ) - 60 );

		wp_scheduled_delete();

		$this->assertNull( get_post( $saved->postId ), 'The cron did not delete the post.' );
		$this->assertDeleted( $b, $saved );
	}

	/**
	 * Tests that a delete that waited for another writer deletes the product as that writer left it: the event names the SKUs its variants had then, and a newer generation's variant keeps no stock item.
	 *
	 * The other writer commits from a second connection just before the delete's first locking
	 * read, as a save the delete waited for commits before the delete gets the product's lock: it
	 * renames the SKU, and adds a variant of a new generation with a price and a stock item.
	 *
	 * @since 0.1.0
	 */
	public function test_a_delete_after_a_save_deletes_what_the_save_committed(): void {
		$b     = $this->secondConnection();
		$saved = $this->stocked( 3 );
		$next  = 0;

		$raced = $this->beforeStatement(
			'/^SELECT .* FOR UPDATE$/s',
			function () use ( $b, $saved, &$next ): void {
				$next = $this->savedElsewhere( $b, $saved );
			}
		);

		$this->assertInstanceOf( \WP_Post::class, wp_delete_post( $saved->postId, true ) );

		$this->assertTrue( $raced->fired, 'The other writer never committed.' );
		$this->assertDeletedAsSaved( $b, $saved, $next );
	}

	/**
	 * Tests that a product deleted in a transaction that read it before the lock is still deleted as the newest commit left it.
	 *
	 * DeleteProduct runs in its caller's transaction, whose snapshot may be older than the lock
	 * it takes; every read under the lock is a locking read, which the snapshot does not reach.
	 *
	 * @since 0.1.0
	 */
	public function test_a_delete_in_an_older_snapshot_deletes_the_newest_rows(): void {
		$b         = $this->secondConnection();
		$saved     = $this->stocked( 3 );
		$variantId = (int) $saved->variantId;
		$next      = 0;

		$raced = $this->beforeStatement(
			'/^SELECT .* FOR UPDATE$/s',
			function () use ( $b, $saved, &$next ): void {
				$next = $this->savedElsewhere( $b, $saved );
			}
		);

		$this->db->transaction(
			function () use ( $saved, $variantId ): void {
				// The transaction's snapshot is taken here, before the other writer commits.
				$this->assertSame( 'SKU-1', $this->db->fetchValue( 'SELECT sku FROM %i WHERE id = %d', $this->catalogTable( CatalogTables::VARIANTS ), $variantId ) );

				$this->services->delete->delete( $saved->productId, Actor::user( 1 ) );
			}
		);

		$this->assertTrue( $raced->fired, 'The other writer never committed.' );
		$this->assertDeletedAsSaved( $b, $saved, $next );
	}

	/**
	 * Tests that a failure inside the product's deletion refuses the post's delete, and leaves the post and every row as they were.
	 *
	 * The deletion fails as its last statement, the delete of the product's row, is sent.
	 *
	 * @since 0.1.0
	 */
	public function test_a_failed_deletion_refuses_the_delete_and_keeps_everything(): void {
		$b      = $this->secondConnection();
		$saved  = $this->stocked( 3 );
		$before = $this->everything( $b, $saved );

		$this->beforeStatement(
			'/^DELETE FROM `' . preg_quote( $this->catalogTable( CatalogTables::PRODUCTS ), '/' ) . '` WHERE id = /',
			static function (): void {
				throw new \RuntimeException( 'The deletion failed.' );
			}
		);

		$this->assertFalse( wp_delete_post( $saved->postId, true ), 'WordPress deleted the post anyway.' );

		$this->assertInstanceOf( \WP_Post::class, get_post( $saved->postId ) );
		$this->assertSame( $before, $this->everything( $b, $saved ) );
		$this->assertSame( 0, $this->db->depth() );
		$this->assertSame(
			array(
				array(
					'code'    => ReportCode::DeleteRefused->value,
					'context' => array(
						'post_id' => $saved->postId,
						'error'   => \RuntimeException::class,
					),
				),
			),
			$this->reports
		);
	}

	/**
	 * Tests that an open allocation on the product's variant refuses the post's delete, and nothing is deleted.
	 *
	 * @since 0.1.0
	 */
	public function test_an_open_allocation_refuses_the_delete(): void {
		$b     = $this->secondConnection();
		$saved = $this->stocked( 3 );

		$b->query( sprintf( "INSERT INTO `%s` ( variant_id, order_id, order_line_id, quantity, state, created_at ) VALUES ( %d, 1, 1, 1, 'open', UTC_TIMESTAMP(6) )", $this->db->table( InventoryTables::ALLOCATIONS ), (int) $saved->variantId ) );

		$before = $this->everything( $b, $saved );

		$this->assertFalse( wp_delete_post( $saved->postId, true ) );

		$this->assertInstanceOf( \WP_Post::class, get_post( $saved->postId ) );
		$this->assertSame( $before, $this->everything( $b, $saved ) );
		$this->assertSame( 'stock.delete_blocked', $this->reports[0]['context']['error'] ?? null );

		// The order is settled; the post may go after the test.
		$b->query( sprintf( 'DELETE FROM `%s` WHERE variant_id = %d', $this->db->table( InventoryTables::ALLOCATIONS ), (int) $saved->variantId ) );
	}

	/**
	 * Tests that a delete an earlier `pre_delete_post` callback refused is left alone: the product's deletion does not run.
	 *
	 * @since 0.1.0
	 */
	public function test_a_delete_refused_earlier_is_left_alone(): void {
		$b      = $this->secondConnection();
		$saved  = $this->stocked( 3 );
		$before = $this->everything( $b, $saved );

		add_filter( 'pre_delete_post', '__return_false' );

		$this->assertFalse( wp_delete_post( $saved->postId, true ) );

		$this->assertSame( $before, $this->everything( $b, $saved ) );
		$this->assertSame( array(), $this->reports );

		remove_filter( 'pre_delete_post', '__return_false' );
	}

	/**
	 * Tests that deleting a product post no product is bound to, a revision of a product post, and an attachment of one change no catalog row.
	 *
	 * @since 0.1.0
	 */
	public function test_deleting_what_is_not_a_bound_product_post_changes_nothing(): void {
		$b          = $this->secondConnection();
		$saved      = $this->stocked( 3 );
		$unbound    = $this->unboundPost();
		$revision   = _wp_put_post_revision( get_post( $saved->postId ) );
		$attachment = wp_insert_attachment(
			array(
				'post_title'     => 'An image of the product',
				'post_mime_type' => 'image/png',
				'post_status'    => 'inherit',
			),
			false,
			$saved->postId
		);
		$before     = $this->everything( $b, $saved );

		$this->assertIsInt( $revision );
		$this->assertGreaterThan( 0, $attachment );

		foreach ( array( $unbound, $revision, $attachment ) as $postId ) {
			$this->assertNotFalse( wp_delete_post( $postId, true ) );
		}

		$this->assertSame( $before, $this->everything( $b, $saved ) );
		$this->assertSame( SellabilityReason::Sellable, $this->verdict( (int) $saved->variantId ) );
		$this->assertSame( array(), $this->reports );
	}

	/**
	 * Saves a whole published product and gives its variant units on hand.
	 *
	 * @since 0.1.0
	 *
	 * @param int $onHand The units.
	 * @return SaveResult The product.
	 */
	private function stocked( int $onHand ): SaveResult {
		$saved = $this->savedProduct();

		$this->services->stock->adjust( (int) $saved->variantId, $onHand, LedgerReason::Received, Actor::user( 1 ) );

		return $saved;
	}

	/**
	 * Commits, from a second connection, what a save of the product would: the variant's SKU renamed `SKU-NEW`, and a variant of generation 2, `SKU-NEXT`, with a price and a stock item.
	 *
	 * @since 0.1.0
	 *
	 * @param SecondConnection $b     The second connection.
	 * @param SaveResult       $saved The product.
	 * @return int The new variant.
	 */
	private function savedElsewhere( SecondConnection $b, SaveResult $saved ): int {
		$b->query( sprintf( "UPDATE `%s` SET sku = 'SKU-NEW' WHERE id = %d", $this->catalogTable( CatalogTables::VARIANTS ), (int) $saved->variantId ) );
		$b->query( sprintf( 'UPDATE `%s` SET active_variant_generation = 2 WHERE id = %d', $this->catalogTable( CatalogTables::PRODUCTS ), $saved->productId ) );

		$next = $this->variantElsewhere( $b, $saved, 'SKU-NEXT', 2 );

		$b->query( sprintf( "INSERT INTO `%s` ( variant_id, currency, amount_basis, price_minor, created_at, updated_at ) VALUES ( %d, '%s', 'net', 2999, UTC_TIMESTAMP(), UTC_TIMESTAMP() )", $this->catalogTable( CatalogTables::VARIANT_PRICES ), $next, self::BASE_CURRENCY ) );
		$b->query( sprintf( 'INSERT INTO `%s` ( variant_id, on_hand, updated_at ) VALUES ( %d, 0, UTC_TIMESTAMP(6) )', $this->db->table( InventoryTables::ITEMS ), $next ) );

		return $next;
	}

	/**
	 * Adds, from a second connection, a variant of the product for another option combination.
	 *
	 * @since 0.1.0
	 *
	 * @param SecondConnection $b          The second connection.
	 * @param SaveResult       $saved      The product.
	 * @param string           $sku        The variant's SKU.
	 * @param int              $generation The generation it is written in.
	 * @return int The variant.
	 */
	private function variantElsewhere( SecondConnection $b, SaveResult $saved, string $sku, int $generation ): int {
		$variants = $this->catalogTable( CatalogTables::VARIANTS );

		$b->query(
			sprintf(
				"INSERT INTO `%s` ( uuid, product_id, sku, combination_hash, generation, is_enabled, position, created_at, updated_at ) VALUES ( '%s', %d, '%s', SHA2( '%s', 256 ), %d, 1, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP() )",
				$variants,
				wp_generate_uuid4(),
				$saved->productId,
				$sku,
				$sku,
				$generation
			)
		);

		return (int) $b->fetchValue( sprintf( "SELECT id FROM `%s` WHERE sku = '%s'", $variants, $sku ) );
	}

	/**
	 * Asserts that a deletion deleted the product as savedElsewhere() left it: both variants' rows and stock items are gone, their ledgers end with the final entry, and ProductDeleted names the SKUs they had then.
	 *
	 * @since 0.1.0
	 *
	 * @param SecondConnection $b     The second connection.
	 * @param SaveResult       $saved The deleted product.
	 * @param int              $next  The variant savedElsewhere() added.
	 */
	private function assertDeletedAsSaved( SecondConnection $b, SaveResult $saved, int $next ): void {
		$variantIds = sprintf( 'variant_id IN ( %d, %d )', (int) $saved->variantId, $next );

		$this->assertGreaterThan( (int) $saved->variantId, $next );

		$stored = $b->fetchRow( sprintf( "SELECT payload_json FROM `%s` WHERE event_name = '%s'", $this->db->table( OutboxTable::NAME ), ProductDeleted::eventName() ) );

		$this->assertSame( array( 'SKU-NEW', 'SKU-NEXT' ), json_decode( (string) ( $stored['payload_json'] ?? '' ), true )['p']['skus'] ?? null, 'The event names SKUs the variants no longer had.' );
		$this->assertSame(
			array( 0, 0, 0, 0 ),
			array(
				$this->committedCount( $b, CatalogTables::PRODUCTS, sprintf( 'id = %d', $saved->productId ) ),
				$this->committedCount( $b, CatalogTables::PRODUCT_POSTS, sprintf( 'product_id = %d', $saved->productId ) ),
				$this->committedCount( $b, CatalogTables::VARIANTS, sprintf( 'product_id = %d', $saved->productId ) ),
				$this->committedCount( $b, CatalogTables::VARIANT_PRICES, $variantIds ),
			),
			'A catalog row of the product outlived it.'
		);
		$this->assertNull( $this->committedItem( $b, (int) $saved->variantId ), 'The first variant\'s stock item outlived it.' );
		$this->assertNull( $this->committedItem( $b, $next ), 'The newer generation\'s stock item outlived its variant.' );
		$this->assertSame(
			LedgerReason::VariantDeleted->value,
			$b->fetchValue( sprintf( 'SELECT reason FROM `%s` WHERE variant_id = %d ORDER BY id DESC LIMIT 1', $this->db->table( InventoryTables::LEDGER ), $next ) ),
			'The newer generation\'s stock was not written off.'
		);

		$this->assertSame( array(), $this->reports );
	}

	/**
	 * Asserts that a product's deletion left no row of it but its ledger, which ends with the final entry, and stored ProductDeleted with its SKU.
	 *
	 * @since 0.1.0
	 *
	 * @param SecondConnection $b     The second connection.
	 * @param SaveResult       $saved The deleted product.
	 */
	private function assertDeleted( SecondConnection $b, SaveResult $saved ): void {
		$variantId = (int) $saved->variantId;

		$this->assertSame(
			array( 0, 0, 0, 0 ),
			array(
				$this->committedCount( $b, CatalogTables::PRODUCTS, sprintf( 'id = %d', $saved->productId ) ),
				$this->committedCount( $b, CatalogTables::PRODUCT_POSTS, sprintf( 'product_id = %d', $saved->productId ) ),
				$this->committedCount( $b, CatalogTables::VARIANTS, sprintf( 'product_id = %d', $saved->productId ) ),
				$this->committedCount( $b, CatalogTables::VARIANT_PRICES, sprintf( 'variant_id = %d', $variantId ) ),
			),
			'A catalog row of the product outlived it.'
		);
		$this->assertNull( $this->committedItem( $b, $variantId ), 'The stock item outlived its variant.' );
		$this->assertSame( 0, $this->committedCount( $b, InventoryTables::HOLDS, sprintf( 'variant_id = %d', $variantId ) ) );
		$this->assertSame(
			LedgerReason::Received->value . ',' . LedgerReason::VariantDeleted->value,
			$b->fetchValue( sprintf( 'SELECT GROUP_CONCAT( reason ORDER BY id ) FROM `%s` WHERE variant_id = %d', $this->db->table( InventoryTables::LEDGER ), $variantId ) ),
			'The ledger keeps the entry that stocked the variant and ends with its final one.'
		);

		$deleted = sprintf( "event_name = '%s'", ProductDeleted::eventName() );
		$stored  = $b->fetchRow( sprintf( 'SELECT aggregate_id, payload_json FROM `%s` WHERE %s', $this->db->table( OutboxTable::NAME ), $deleted ) );

		$this->assertSame( 1, $this->committedCount( $b, OutboxTable::NAME, $deleted ) );
		$this->assertSame( (string) $saved->productId, (string) ( $stored['aggregate_id'] ?? '' ) );
		$this->assertSame( array( 'SKU-1' ), json_decode( (string) ( $stored['payload_json'] ?? '' ), true )['p']['skus'] ?? null );
		$this->assertSame( array(), $this->reports );
	}

	/**
	 * Reads what a product consists of, as a second connection sees it: the four tables, its stock item, holds and ledger, and its outbox rows.
	 *
	 * @since 0.1.0
	 *
	 * @param SecondConnection $b     The second connection.
	 * @param SaveResult       $saved The product.
	 * @return array<string, mixed> The state.
	 *
	 * @phpstan-impure
	 */
	private function everything( SecondConnection $b, SaveResult $saved ): array {
		$variantId = (int) $saved->variantId;

		return array(
			'catalog' => $this->catalogChecksums( $b ),
			'item'    => $this->committedItem( $b, $variantId ),
			'holds'   => $this->committedCount( $b, InventoryTables::HOLDS, sprintf( 'variant_id = %d', $variantId ) ),
			'ledger'  => $this->committedCount( $b, InventoryTables::LEDGER, sprintf( 'variant_id = %d', $variantId ) ),
			'outbox'  => $this->committedCount( $b, OutboxTable::NAME ),
		);
	}

	/**
	 * Reads a stock item as a second connection sees it.
	 *
	 * @since 0.1.0
	 *
	 * @param SecondConnection $b         The second connection.
	 * @param int              $variantId The variant.
	 * @return array{on_hand: int, allocated: int, held: int}|null The counters, or null when there is no item.
	 *
	 * @phpstan-impure
	 */
	private function committedItem( SecondConnection $b, int $variantId ): ?array {
		$row = $b->fetchRow( sprintf( 'SELECT on_hand, allocated, held FROM `%s` WHERE variant_id = %d', $this->db->table( InventoryTables::ITEMS ), $variantId ) );

		return null === $row ? null : array(
			'on_hand'   => (int) $row['on_hand'],
			'allocated' => (int) $row['allocated'],
			'held'      => (int) $row['held'],
		);
	}
}
