<?php
/**
 * Tests doctor's catalog checks and its --repair, on real tables, against the ten checks it makes
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Catalog;

use SEOCart\Catalog\Application\Doctor\ProductSettler;
use SEOCart\Catalog\Application\Lifecycle\Reconciler;
use SEOCart\Catalog\Application\ProductWrite\SaveProduct;
use SEOCart\Catalog\Application\Query\Sellability;
use SEOCart\Catalog\Domain\GenerationState;
use SEOCart\Catalog\Domain\SellabilityReason;
use SEOCart\Catalog\Infrastructure\CatalogTables;
use SEOCart\Catalog\Infrastructure\Doctor\CatalogChecks;
use SEOCart\Catalog\Infrastructure\Doctor\DanglingBindingCheck;
use SEOCart\Catalog\Infrastructure\Doctor\ForeignHooksCheck;
use SEOCart\Catalog\Infrastructure\Doctor\IncompleteMismatchCheck;
use SEOCart\Catalog\Infrastructure\Doctor\MissingSourceCheck;
use SEOCart\Catalog\Infrastructure\Doctor\MissingStockItemCheck;
use SEOCart\Catalog\Infrastructure\Doctor\NoBindingCheck;
use SEOCart\Catalog\Infrastructure\Doctor\OrphanCommerceRowCheck;
use SEOCart\Catalog\Infrastructure\Doctor\OrphanStockItemCheck;
use SEOCart\Catalog\Infrastructure\Doctor\StuckUpdatingCheck;
use SEOCart\Catalog\Infrastructure\Doctor\UnboundPostCheck;
use SEOCart\Inventory\Application\StockService;
use SEOCart\Inventory\Infrastructure\InventoryTables;
use SEOCart\Inventory\Infrastructure\Migrations\CreateStockTablesMigration;
use SEOCart\Inventory\Infrastructure\MysqlStockRepository;
use SEOCart\Platform\Authorization\Authorizer;
use SEOCart\Platform\Authorization\CapabilityDeclaration;
use SEOCart\Platform\Cli\DoctorCommand;
use SEOCart\Platform\Database\LockMode;
use SEOCart\Platform\Database\LockService;
use SEOCart\Platform\Database\Schema\DdlGenerator;
use SEOCart\Platform\Database\Schema\SchemaVerifier;
use SEOCart\Platform\Database\SchemaOperations;
use SEOCart\Platform\Events\Migrations\CreateOutboxMigration;
use SEOCart\Platform\Logging\CorrelationId;
use SEOCart\Support\Currency;
use SEOCart\Support\Locale;
use SEOCart\Tests\Support\Catalog\CatalogTestCase;
use SEOCart\Tests\Support\Doubles\FrozenClock;
use SEOCart\Tests\Support\Doubles\RecordingEventPublisher;
use SEOCart\Tests\Support\Doubles\SequentialIdGenerator;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Plants a violation of each row directly, as a crash, a foreign write or a refused delete would leave it, and reads checksums back.

/**
 * Every one of the catalog's ten doctor checks, plus the reverse cross-module line,
 * against real catalog and inventory tables: each finding with its ids, `--repair` fixing exactly
 * rows 3–7, `variants`/`variant_prices`/the stock ledger unchanged around a repair, and the exit
 * codes for clean, repaired-clean and repaired-with-a-human-finding-left.
 *
 * @since 0.1.0
 */
final class CatalogDoctorTest extends CatalogTestCase {

	/**
	 * The stock repository over `$this->db`.
	 *
	 * @since 0.1.0
	 *
	 * @var MysqlStockRepository
	 */
	private MysqlStockRepository $stockRepository;

	/**
	 * The stock service over `$this->db`.
	 *
	 * @since 0.1.0
	 *
	 * @var StockService
	 */
	private StockService $stock;

	/**
	 * The catalog's checks, wired the way Modules.php wires them.
	 *
	 * @since 0.1.0
	 *
	 * @var CatalogChecks
	 */
	private CatalogChecks $catalogChecks;

	/**
	 * Takes a named lock, over `$this->db`: the callable a repair's product lock uses.
	 *
	 * @since 0.1.0
	 *
	 * @var callable(string, int, int, callable): mixed
	 */
	private $withLock;

	/**
	 * Creates the inventory tables and the services under test, on top of the catalog's own.
	 *
	 * @since 0.1.0
	 */
	public function set_up(): void {
		parent::set_up();

		$operations = new SchemaOperations( $this->db, new DdlGenerator(), new SchemaVerifier( $this->db ) );

		( new CreateOutboxMigration() )->up( $operations );
		( new CreateStockTablesMigration() )->up( $operations );

		$this->stockRepository = new MysqlStockRepository( $this->db );
		$this->stock           = new StockService(
			$this->stockRepository,
			$this->db,
			new RecordingEventPublisher( $this->db ),
			new SequentialIdGenerator( 500000 ),
			new FrozenClock( $this->databaseNow() ),
			new CorrelationId( new SequentialIdGenerator( 600000 ) ),
			new Authorizer( new CapabilityDeclaration() )
		);

		$this->withLock = array( new LockService( $this->db, LockMode::GetLock ), 'withLock' );

		$reconciler = new Reconciler(
			$this->products,
			$this->db,
			new class() implements \SEOCart\Platform\Localization\PostLocales {
				/**
				 * Returns the fixture locale for every post.
				 *
				 * @param int $postId Unused.
				 * @return Locale The fixture locale.
				 */
				public function localeOf( int $postId ): Locale {
					unset( $postId );

					return Locale::of( 'en_US' );
				}
			},
			new FrozenClock( $this->databaseNow() ),
			new SequentialIdGenerator( 700000 )
		);

		$settler = new ProductSettler(
			$this->products,
			$this->stock,
			$this->db,
			array( new LockService( $this->db, LockMode::GetLock ), 'withLock' )
		);

		$this->catalogChecks = new CatalogChecks(
			$this->products,
			$this->stock,
			$reconciler,
			$settler,
			new FrozenClock( $this->databaseNow() ),
			$this->db,
			static fn(): Currency => Currency::of( CatalogTestCase::BASE_CURRENCY ),
			$this->withLock
		);
	}

	/**
	 * Runs a list of checks and returns their results, the same way Doctor::runList() does — this
	 * test exercises the catalog's checks and --repair's sequencing directly, without building the
	 * platform's own checks or their dependencies (migrations, jobs, the outbox), which this test
	 * does not touch.
	 *
	 * @since 0.1.0
	 *
	 * @param \SEOCart\Platform\Cli\Doctor\Check[] $checks The checks.
	 * @return list<\SEOCart\Platform\Cli\Doctor\CheckResult> The results, in order.
	 */
	private function runChecks( array $checks ): array {
		return array_map( static fn( \SEOCart\Platform\Cli\Doctor\Check $check ): \SEOCart\Platform\Cli\Doctor\CheckResult => $check->run(), $checks );
	}

	/**
	 * Tests row 1: products with no binding at all, reported and never repaired.
	 *
	 * Planted violation: a `products` row with no `product_posts` row.
	 *
	 * @since 0.1.0
	 */
	public function test_row_1_no_binding(): void {
		$this->plantBareProductRow();

		$result = ( new NoBindingCheck( $this->products ) )->run();

		$this->assertFalse( $result->passed );
		$this->assertStringContainsString( 'no `product_posts` row', $result->findings[0] );
	}

	/**
	 * Tests row 2: an invalid source binding, critical, never repaired.
	 *
	 * Planted violation: `source_post_id` set to a post that does not exist.
	 *
	 * @since 0.1.0
	 */
	public function test_row_2_missing_source(): void {
		$product = $this->storedProduct( 'SKU-ROW2' );

		$this->db->execute( 'UPDATE %i SET source_post_id = 999999999 WHERE id = %d', $this->catalogTable( CatalogTables::PRODUCTS ), (int) $product->id() );

		$result = ( new MissingSourceCheck( $this->products ) )->run();

		$this->assertFalse( $result->passed );
		$this->assertStringContainsString( (string) $product->id(), $result->findings[0] );
	}

	/**
	 * Tests row 3: a dangling non-source binding is reported and deleted by --repair, the source binding never touched.
	 *
	 * Planted violations: a second binding whose post is deleted (dangling); then, to prove the
	 * exclusion really holds, the repair's own delete statement is asked to remove the source
	 * binding directly — refused, because deleteDanglingBinding() re-states that the row must not
	 * be the source binding.
	 *
	 * @since 0.1.0
	 */
	public function test_row_3_dangling_binding_repaired_source_excluded(): void {
		$product    = $this->storedProduct( 'SKU-ROW3' );
		$secondPost = $this->post();
		$productId  = (int) $product->id();

		$this->db->execute(
			'INSERT INTO %i ( post_id, product_id, locale, linked_at, created_at ) VALUES ( %d, %d, %s, UTC_TIMESTAMP(), UTC_TIMESTAMP() )',
			$this->catalogTable( CatalogTables::PRODUCT_POSTS ),
			$secondPost,
			$productId,
			'de_DE'
		);
		wp_delete_post( $secondPost, true );

		$check  = new DanglingBindingCheck( $this->products, $this->db, $this->withLock );
		$result = $check->run();

		$this->assertFalse( $result->passed );
		$this->assertStringContainsString( (string) $secondPost, $result->findings[0] );

		// Plant: the repair deletes the source binding → red. Confirmed, then removed.
		$sourcePostId = $product->sourcePostId();
		$this->assertNotNull( $sourcePostId );
		$this->assertFalse(
			$this->products->deleteDanglingBinding( $sourcePostId, $productId ),
			'Plant: deleteDanglingBinding() must refuse the source binding even when asked for it directly.'
		);
		$this->assertNotNull( $this->productRow( $productId ), 'The product survived the refused plant.' );

		$repair = $check->repair();

		$this->assertSame( array( sprintf( 'deleted the binding of post %1$d to product %2$d.', $secondPost, $productId ) ), $repair->changes );
		$this->assertNull( $this->bindingRow( $secondPost, $productId ), 'The dangling binding was deleted.' );
		$this->assertNotNull( $this->bindingRow( $sourcePostId, $productId ), 'The source binding survived.' );

		$this->assertTrue( ( new DanglingBindingCheck( $this->products, $this->db, $this->withLock ) )->run()->passed );
	}

	/**
	 * Tests row 2/3's boundary: the source post itself gone, not a second binding,
	 * is reported under row 2 and never touched by row 3's delete.
	 *
	 * Plant: dropping the source exclusion from danglingBindings() or deleteDanglingBinding()
	 * stays green on test_row_3 alone, since that test's second post is the one deleted, never the
	 * source post; only this scenario — the source post itself gone — would go red.
	 *
	 * @since 0.1.0
	 */
	public function test_row_3_excludes_the_source_binding_even_when_its_own_post_is_gone(): void {
		$product      = $this->storedProduct( 'SKU-ROW3SOURCEGONE' );
		$productId    = (int) $product->id();
		$sourcePostId = $product->sourcePostId();

		$this->assertNotNull( $sourcePostId );

		wp_delete_post( $sourcePostId, true );

		$this->assertTrue(
			( new DanglingBindingCheck( $this->products, $this->db, $this->withLock ) )->run()->passed,
			'The source binding is never dangling, whatever became of its post.'
		);

		$missingSource = ( new MissingSourceCheck( $this->products ) )->run();

		$this->assertFalse( $missingSource->passed );
		$this->assertContains( $productId, $this->extractIds( $missingSource->findings[0] ) );

		$this->assertFalse(
			$this->products->deleteDanglingBinding( $sourcePostId, $productId ),
			'deleteDanglingBinding() must refuse the source binding even when its post is genuinely gone.'
		);
		$this->assertNotNull( $this->bindingRow( $sourcePostId, $productId ), 'The source binding survived.' );
	}

	/**
	 * Tests row 3: a binding whose product no longer exists is dangling too, not
	 * only one whose post is gone, and is deleted by --repair — there is no product for it to be
	 * the source binding of. Row 4 then finds the post unbound again on the next pass.
	 *
	 * Plant: the repository's inner JOIN to `products` (in place of the LEFT JOIN this fix uses)
	 * hides this binding from both danglingBindings() and deleteDanglingBinding() entirely.
	 *
	 * @since 0.1.0
	 */
	public function test_row_3_a_binding_whose_product_is_gone_is_dangling_too(): void {
		$postId = $this->post();

		$this->db->execute(
			'INSERT INTO %i ( post_id, product_id, locale, linked_at, created_at ) VALUES ( %d, 999999996, %s, UTC_TIMESTAMP(), UTC_TIMESTAMP() )',
			$this->catalogTable( CatalogTables::PRODUCT_POSTS ),
			$postId,
			'en_US'
		);

		$check  = new DanglingBindingCheck( $this->products, $this->db, $this->withLock );
		$result = $check->run();

		$this->assertFalse( $result->passed );
		$this->assertContains( $postId, $this->extractIds( $result->findings[0] ) );

		$repair = $check->repair();

		$this->assertNotEmpty( $repair->changes );
		$this->assertNull( $this->bindingRow( $postId, 999999996 ), 'The binding to the missing product was deleted.' );
		$this->assertTrue( ( new DanglingBindingCheck( $this->products, $this->db, $this->withLock ) )->run()->passed );

		// The post is unbound again: row 4 finds it and binds it to a new, incomplete product.
		$unboundPost = ( new UnboundPostCheck( $this->products, $this->catalogChecksReconciler() ) )->run();

		$this->assertFalse( $unboundPost->passed );
		$this->assertContains( $postId, $this->extractIds( $unboundPost->findings[0] ) );
	}

	/**
	 * Tests row 4: an unbound product post is reported and bound to a new incomplete product by --repair.
	 *
	 * Planted violation: `wp_insert_post( …, false )`, which never fires `wp_after_insert_post`.
	 *
	 * @since 0.1.0
	 */
	public function test_row_4_unbound_post_repaired(): void {
		$postId = $this->unboundPost();

		$check  = new UnboundPostCheck( $this->products, $this->catalogChecksReconciler() );
		$result = $check->run();

		$this->assertFalse( $result->passed );
		$this->assertStringContainsString( (string) $postId, $result->findings[0] );

		$repair = $check->repair();

		$this->assertCount( 1, $repair->changes );
		$this->assertStringContainsString( 'bound post ' . $postId, $repair->changes[0] );

		$product = $this->products->findByPost( $postId );

		$this->assertNotNull( $product );
		$this->assertSame( GenerationState::Incomplete, $this->productRowState( (int) $product->id() ) );
		$this->assertTrue( ( new UnboundPostCheck( $this->products, $this->catalogChecksReconciler() ) )->run()->passed );
	}

	/**
	 * Tests row 4's repair guard: a post deleted since the scan gains no product.
	 *
	 * Plant: reconcile() trusting the scanned id, with no re-check of the post's own existence
	 * inside its write transaction, would still create a product for a post already gone.
	 *
	 * @since 0.1.0
	 *
	 * @group concurrency
	 */
	public function test_reconcile_refuses_a_post_deleted_since_the_scan(): void {
		$postId = $this->unboundPost();

		wp_delete_post( $postId, true );

		$this->assertFalse( $this->catalogChecksReconciler()->reconcile( $postId ), 'A post deleted since the scan gains no product.' );
		$this->assertNull( $this->products->findByPost( $postId ) );
	}

	/**
	 * Tests row 4's repair guard: a post back to `auto-draft` since the scan gains no product.
	 *
	 * Plant: the same trust as above, for a post that went back to `auto-draft` — never a product
	 * to reconcile — instead of being deleted outright.
	 *
	 * @since 0.1.0
	 *
	 * @group concurrency
	 */
	public function test_reconcile_refuses_a_post_that_is_now_an_auto_draft(): void {
		$postId = $this->unboundPost();

		wp_update_post(
			array(
				'ID'          => $postId,
				'post_status' => 'auto-draft',
			)
		);

		$this->assertFalse( $this->catalogChecksReconciler()->reconcile( $postId ), 'A post back to auto-draft since the scan gains no product.' );
		$this->assertNull( $this->products->findByPost( $postId ) );
	}

	/**
	 * Tests row 4's repair guard: a post whose type changed since the scan gains no product.
	 *
	 * @since 0.1.0
	 *
	 * @group concurrency
	 */
	public function test_reconcile_refuses_a_post_whose_type_changed(): void {
		$postId = $this->unboundPost();

		wp_update_post(
			array(
				'ID'        => $postId,
				'post_type' => 'post',
			)
		);

		$this->assertFalse( $this->catalogChecksReconciler()->reconcile( $postId ), 'A post no longer the product post type gains no product.' );
		$this->assertNull( $this->products->findByPost( $postId ) );
	}

	/**
	 * Tests row 4's repair guard reads the post's row fresh, never through WordPress's post
	 * cache: a post primed into that cache, then deleted from a second connection, gains no
	 * product.
	 *
	 * Plant: reading the post's type and status through get_post_type()/get_post_status()
	 * (WordPress's cache) instead of a locking read of the post's own row would still see the
	 * cached post here and create one.
	 *
	 * @since 0.1.0
	 *
	 * @group concurrency
	 */
	public function test_reconcile_refuses_a_post_whose_row_is_gone_though_the_cache_still_has_it(): void {
		$postId = $this->unboundPost();

		// Primes WordPress's own post cache with the row as it stood before the delete.
		$this->assertInstanceOf( \WP_Post::class, get_post( $postId ) );

		global $wpdb;

		$b = $this->secondConnection();
		$b->query( 'DELETE FROM ' . $wpdb->posts . ' WHERE ID = ' . $postId );

		$this->assertInstanceOf( \WP_Post::class, get_post( $postId ), 'The cache must still answer for the post: this is the staleness the fix guards against.' );

		$this->assertFalse( $this->catalogChecksReconciler()->reconcile( $postId ), 'A post whose row is gone gains no product, whatever the cache still says.' );
		$this->assertNull( $this->products->findByPost( $postId ) );
	}

	/**
	 * Tests row 5: a complete product with no base-currency price is marked incomplete by --repair.
	 *
	 * Planted violation: the default variant's base-currency price row deleted while the product
	 * stays `complete`.
	 *
	 * This check's SQL also flags a complete product with zero enabled variants
	 * (`enabled_variant_count = 0`), but `Product::settle()` — the one rule, unchanged here — does
	 * not itself weigh `is_enabled`, so an otherwise-whole product wrong only that way is reported
	 * and left unchanged by repair(): test_repair_sequence_fixes_exactly_3_to_7() covers that case.
	 *
	 * @since 0.1.0
	 */
	public function test_row_5_incomplete_mismatch_repaired(): void {
		$product   = $this->storedProduct( 'SKU-ROW5' );
		$productId = (int) $product->id();
		$variantId = (int) $product->defaultVariant()?->id();

		$this->db->execute( 'DELETE FROM %i WHERE variant_id = %d', $this->catalogTable( CatalogTables::VARIANT_PRICES ), $variantId );

		$settler = $this->newSettler();
		$check   = new IncompleteMismatchCheck( $this->products, static fn(): Currency => Currency::of( self::BASE_CURRENCY ), $settler );
		$result  = $check->run();

		$this->assertFalse( $result->passed );
		$this->assertContains( $productId, $this->extractIds( $result->findings[0] ) );

		$repair = $check->repair();

		$this->assertCount( 1, $repair->changes );
		$this->assertSame( GenerationState::Incomplete, $this->productRowState( $productId ) );
		$this->assertTrue( ( new IncompleteMismatchCheck( $this->products, static fn(): Currency => Currency::of( self::BASE_CURRENCY ), $settler ) )->run()->passed );
	}

	/**
	 * Tests row 5's race: a save that fixes the product between the check's read and the repair wins.
	 *
	 * Plant: use leaveUpdating() (state-only, no re-check) in place of the settler's reload — the
	 * save's fix would be overwritten. The settler itself is what is under test, and it must not
	 * regress to that shape.
	 *
	 * @since 0.1.0
	 *
	 * @group concurrency
	 */
	public function test_row_5_settle_leaves_a_product_a_save_already_fixed_alone(): void {
		$product   = $this->storedProduct( 'SKU-ROW5RACE' );
		$productId = (int) $product->id();
		$variantId = (int) $product->defaultVariant()?->id();

		$this->db->transaction( fn () => $this->stock->createItems( array( $variantId ) ) );

		// The check's read: broken (as row 5 would have found it) — no base-currency price.
		$this->db->execute( 'DELETE FROM %i WHERE variant_id = %d', $this->catalogTable( CatalogTables::VARIANT_PRICES ), $variantId );

		// Between the check's read and the repair, a real save fixes it: the price is set again.
		$this->db->execute(
			'INSERT INTO %i ( variant_id, currency, amount_basis, price_minor, created_at, updated_at ) VALUES ( %d, %s, %s, 1999, UTC_TIMESTAMP(), UTC_TIMESTAMP() )',
			$this->catalogTable( CatalogTables::VARIANT_PRICES ),
			$variantId,
			self::BASE_CURRENCY,
			'net'
		);

		$change = $this->newSettler()->settle( $productId );

		$this->assertNull( $change, 'Nothing to settle: the save already fixed it before the repair reloaded.' );
		$this->assertSame( GenerationState::Complete, $this->productRowState( $productId ), 'The save\'s marker survives.' );
	}

	/**
	 * Tests row 6: a variant with no stock item is reported and given one at zero by --repair.
	 *
	 * Planted violation: the stock item deleted.
	 *
	 * @since 0.1.0
	 */
	public function test_row_6_missing_stock_item_repaired(): void {
		$product   = $this->storedProduct( 'SKU-ROW6' );
		$variantId = (int) $product->defaultVariant()?->id();

		$this->db->transaction( fn () => $this->stock->createItems( array( $variantId ) ) );
		$this->db->execute( 'DELETE FROM %i WHERE variant_id = %d', $this->db->table( InventoryTables::ITEMS ), $variantId );

		$check  = new MissingStockItemCheck( $this->products, $this->stock, $this->db, $this->withLock );
		$result = $check->run();

		$this->assertFalse( $result->passed );
		$this->assertContains( $variantId, $this->extractIds( $result->findings[0] ) );

		$repair = $check->repair();

		$this->assertNotEmpty( $repair->changes );
		$this->assertArrayHasKey( $variantId, $this->stock->levels( array( $variantId ) ) );
		$this->assertSame( 0, $this->stock->levels( array( $variantId ) )[ $variantId ]->onHand ?? -1 );
	}

	/**
	 * Tests row 6's repair guard: a variant deleted from a second connection between the check's
	 * scan and the repair's write gets no stock item.
	 *
	 * Plant: repair() trusting the scan's id, with no locking re-check under the product's lock,
	 * would still call createItems() for a variant already gone.
	 *
	 * @since 0.1.0
	 *
	 * @group concurrency
	 */
	public function test_row_6_repair_refuses_a_variant_deleted_since_the_scan(): void {
		$product   = $this->storedProduct( 'SKU-ROW6RACE' );
		$productId = (int) $product->id();
		$variantId = (int) $product->defaultVariant()?->id();

		$check  = new MissingStockItemCheck( $this->products, $this->stock, $this->db, $this->withLock );
		$result = $check->run();

		$this->assertFalse( $result->passed );
		$this->assertContains( $variantId, $this->extractIds( $result->findings[0] ) );

		$b = $this->secondConnection();

		// Races the repair's product-row lock (reloadUnderLock(), taken before lockVariants()):
		// once that lock is held it also holds the default variant's row, so connection B's
		// delete must land before it, not before lockVariants()'s own statement.
		$raced = $this->beforeStatement(
			'/^SELECT id, uuid, source_post_id, generation_state, active_variant_generation FROM .*WHERE id = ' . $productId . ' FOR UPDATE$/s',
			function () use ( $b, $variantId ): void {
				$b->query( 'DELETE FROM ' . $this->catalogTable( CatalogTables::VARIANTS ) . ' WHERE id = ' . $variantId );
			}
		);

		$repair = $check->repair();

		$this->assertTrue( $raced->fired, 'The other connection never raced the repair.' );
		$this->assertSame( array(), $repair->changes, 'A variant already gone by the time of the re-check gets no stock item.' );
		$this->assertArrayNotHasKey( $variantId, $this->stock->levels( array( $variantId ) ) );
	}

	/**
	 * Tests row 6's walk: a defect past the first scan page is still found.
	 *
	 * Plant: run() calling variantIds( 0, LIMIT ) once, instead of walking every page to the end,
	 * would see only the first LIMIT of the PAGE clean variants seeded here and report nothing.
	 *
	 * @since 0.1.0
	 */
	public function test_missing_stock_item_walks_past_the_first_page_to_find_a_later_defect(): void {
		$host  = $this->storedProduct( 'SKU-PAGE-HOST6' );
		$clean = $this->bulkInsertVariants( MissingStockItemCheck::PAGE, (int) $host->id() );

		$this->db->transaction( fn () => $this->stock->createItems( $clean ) );

		$defect          = $this->storedProduct( 'SKU-PAGE-DEFECT' );
		$defectVariantId = (int) $defect->defaultVariant()?->id();

		$this->assertGreaterThan( max( $clean ), $defectVariantId, 'The defect must fall on a later page than the seeded, clean variants.' );

		$result = ( new MissingStockItemCheck( $this->products, $this->stock, $this->db, $this->withLock ) )->run();

		$this->assertFalse( $result->passed );
		$this->assertContains( $defectVariantId, $this->extractIds( $result->findings[0] ) );
	}

	/**
	 * Tests the reverse line's walk: a defect past the first scan page is still found.
	 *
	 * Plant: run() calling itemVariantIds( 0, LIMIT ) once, instead of walking every page to the
	 * end, would see only the first LIMIT of the PAGE clean items seeded here and report nothing.
	 *
	 * @since 0.1.0
	 */
	public function test_orphan_stock_item_walks_past_the_first_page_to_find_a_later_defect(): void {
		$host  = $this->storedProduct( 'SKU-PAGE-HOST' );
		$clean = $this->bulkInsertVariants( OrphanStockItemCheck::PAGE, (int) $host->id() );

		$this->db->transaction( fn () => $this->stock->createItems( $clean ) );

		$orphan   = $this->storedProduct( 'SKU-PAGE-ORPHAN' );
		$orphanId = (int) $orphan->defaultVariant()?->id();

		$this->db->transaction( fn () => $this->stock->createItems( array( $orphanId ) ) );
		$this->db->execute( 'DELETE FROM %i WHERE id = %d', $this->catalogTable( CatalogTables::VARIANTS ), $orphanId );

		$this->assertGreaterThan( max( $clean ), $orphanId, 'The orphan must fall on a later page than the seeded, clean items.' );

		$result = ( new OrphanStockItemCheck( $this->products, $this->stock ) )->run();

		$this->assertFalse( $result->passed );
		$this->assertContains( $orphanId, $this->extractIds( $result->findings[0] ) );
	}

	/**
	 * Tests row 7: a product stuck `updating` for more than 15 minutes is settled by --repair.
	 *
	 * Planted violation: `updated_at` moved back 20 minutes while `generation_state` stays `updating`.
	 *
	 * @since 0.1.0
	 */
	public function test_row_7_stuck_updating_repaired(): void {
		$product   = $this->storedProduct( 'SKU-ROW7' );
		$productId = (int) $product->id();

		$this->db->transaction( fn () => $this->stock->createItems( array( (int) $product->defaultVariant()?->id() ) ) );

		$this->db->execute(
			"UPDATE %i SET generation_state = 'updating', updated_at = UTC_TIMESTAMP(6) - INTERVAL 20 MINUTE WHERE id = %d",
			$this->catalogTable( CatalogTables::PRODUCTS ),
			$productId
		);

		$settler = $this->newSettler();
		$check   = new StuckUpdatingCheck( $this->products, new FrozenClock( $this->databaseNow() ), $settler );
		$result  = $check->run();

		$this->assertFalse( $result->passed );
		$this->assertContains( $productId, $this->extractIds( $result->findings[0] ) );

		$repair = $check->repair();

		$this->assertCount( 1, $repair->changes );
		$this->assertSame( GenerationState::Complete, $this->productRowState( $productId ), 'A fully recoverable product settles back to complete.' );
		$this->assertTrue( ( new StuckUpdatingCheck( $this->products, new FrozenClock( $this->databaseNow() ), $settler ) )->run()->passed );
	}

	/**
	 * Proves: `doctor --repair` settles a product left `updating` by a crash and never makes a
	 * product sellable that `settle()` would not.
	 *
	 * The product here is stuck `updating` and not whole (no stock item was ever created for it);
	 * repair must settle it to `incomplete`, never `complete`, and it must stay unsellable.
	 *
	 * Plant: the unconditional shape, `leaveUpdating( Complete )` — no reload, no
	 * re-check of settle() — in place of the settler's own write, marks this same, not-whole
	 * product complete and, wrongly, sellable.
	 *
	 * @since 0.1.0
	 */
	public function test_gate_a_a_product_stuck_updating_and_not_whole_settles_incomplete_and_stays_unsellable(): void {
		$product   = $this->storedProduct( 'SKU-GATEA' );
		$productId = (int) $product->id();
		$variantId = (int) $product->defaultVariant()?->id();

		// Not whole: no stock item was ever created for its default variant.
		$this->db->execute(
			"UPDATE %i SET generation_state = 'updating', updated_at = UTC_TIMESTAMP(6) - INTERVAL 20 MINUTE WHERE id = %d",
			$this->catalogTable( CatalogTables::PRODUCTS ),
			$productId
		);

		$settler = $this->newSettler();
		$check   = new StuckUpdatingCheck( $this->products, new FrozenClock( $this->databaseNow() ), $settler );
		$result  = $check->run();

		$this->assertFalse( $result->passed );

		$repair = $check->repair();

		$this->assertCount( 1, $repair->changes );
		$this->assertSame( GenerationState::Incomplete, $this->productRowState( $productId ), 'Not whole: settle() gives incomplete, never complete, however long it was stuck.' );

		$sellability = ( new Sellability( $this->products ) )->of( array( $variantId ), false );

		$this->assertNotSame( SellabilityReason::Sellable, $sellability[ $variantId ] ?? null, 'Repair never makes a product sellable that settle() would not.' );

		// Plant, on a second product in the same, still-unrepaired state: the unconditional
		// statement (no reload, no re-check) settles a not-whole product to complete regardless —
		// the exact bug this proof exists to catch.
		$plantProduct   = $this->storedProduct( 'SKU-GATEAPLANT' );
		$plantProductId = (int) $plantProduct->id();

		$this->db->execute(
			"UPDATE %i SET generation_state = 'updating', updated_at = UTC_TIMESTAMP(6) - INTERVAL 20 MINUTE WHERE id = %d",
			$this->catalogTable( CatalogTables::PRODUCTS ),
			$plantProductId
		);

		$this->assertTrue(
			$this->products->leaveUpdating( $plantProductId, GenerationState::Complete ),
			'Plant: the unconditional statement writes over a product still updating.'
		);
		$this->assertSame( GenerationState::Complete, $this->productRowState( $plantProductId ), 'Plant confirmed: without the re-check, a not-whole product wrongly becomes complete.' );
	}

	/**
	 * Tests row 7's race: a repair against a save that re-marks the same product — the save's marker survives.
	 *
	 * Plant: settleIfUnchanged() called without the updated_at match (the unconditional shape) —
	 * it would settle the save's fresh `updating` window too, which must never happen.
	 *
	 * @since 0.1.0
	 *
	 * @group concurrency
	 */
	public function test_row_7_race_a_relocked_product_is_not_settled(): void {
		$product   = $this->storedProduct( 'SKU-ROW7RACE' );
		$productId = (int) $product->id();

		$this->db->execute(
			"UPDATE %i SET generation_state = 'updating', updated_at = UTC_TIMESTAMP(6) - INTERVAL 20 MINUTE WHERE id = %d",
			$this->catalogTable( CatalogTables::PRODUCTS ),
			$productId
		);

		$stale = (string) $this->productRow( $productId )['updated_at'];

		// Between the check's read and the repair, a real save relocks the product: a new,
		// committed window (relock() must run, and is committed, inside a transaction).
		$this->assertTrue( $this->db->transaction( fn (): bool => $this->products->relock( $productId ) ) );

		$change = $this->newSettler()->settle( $productId, $stale );

		$this->assertNull( $change, 'The compare-and-set must not match the save\'s fresh mark.' );
		$this->assertSame( GenerationState::Updating, $this->productRowState( $productId ), 'The save\'s own window survives untouched.' );

		// Plant: the unconditional shape (leaveUpdating(), no updated_at match) would wrongly settle it → red.
		$this->assertTrue(
			$this->products->leaveUpdating( $productId, GenerationState::Complete ),
			'Plant: the unconditional statement settles a product a real save just relocked — the bug the compare-and-set exists to prevent.'
		);
		$this->assertSame( GenerationState::Complete, $this->productRowState( $productId ), 'Plant confirmed: without the updated_at guard, the save\'s window is stomped.' );
	}

	/**
	 * Tests the pin the race test above relies on directly: settleIfUnchanged()
	 * with a stale updated_at writes nothing and changes nothing.
	 *
	 * Plant: dropping the updated_at condition from settleIfUnchanged()'s own UPDATE — exactly the
	 * unconditional shape the race test's own plant stands in for — would let this call through.
	 *
	 * @since 0.1.0
	 */
	public function test_settle_if_unchanged_refuses_a_stale_updated_at(): void {
		$product   = $this->storedProduct( 'SKU-PIN' );
		$productId = (int) $product->id();

		$stale = '2000-01-01 00:00:00.000000';

		$this->assertNotSame( $stale, (string) $this->productRow( $productId )['updated_at'] );

		$changed = $this->products->settleIfUnchanged( $productId, GenerationState::Complete, GenerationState::Incomplete, $stale );

		$this->assertFalse( $changed, 'A stale updated_at must match nothing.' );
		$this->assertSame( GenerationState::Complete, $this->productRowState( $productId ), 'Nothing was written.' );
	}

	/**
	 * Tests that a repair skips, with a message naming the busy lock, when
	 * another connection holds the product's own named lock.
	 *
	 * @since 0.1.0
	 *
	 * @group concurrency
	 */
	public function test_repair_skips_a_product_whose_lock_another_connection_holds(): void {
		$product   = $this->storedProduct( 'SKU-LOCKBUSY' );
		$productId = (int) $product->id();

		$this->db->execute(
			"UPDATE %i SET generation_state = 'updating', updated_at = UTC_TIMESTAMP(6) - INTERVAL 20 MINUTE WHERE id = %d",
			$this->catalogTable( CatalogTables::PRODUCTS ),
			$productId
		);

		$b          = $this->secondConnection();
		$serverName = LockService::serverLockName( DB_NAME, $this->db->prefix(), SaveProduct::LOCK_PREFIX . $productId );

		$this->assertSame( '1', $b->fetchValue( "SELECT GET_LOCK( '" . $serverName . "', 5 )" ), 'The other connection must hold the product\'s own named lock.' );

		$check  = new StuckUpdatingCheck( $this->products, new FrozenClock( $this->databaseNow() ), $this->newSettler() );
		$result = $check->run();

		$this->assertFalse( $result->passed );

		$repair = $check->repair();

		$this->assertCount( 1, $repair->changes );
		$this->assertStringContainsString( 'lock is busy', $repair->changes[0] );
		$this->assertSame( GenerationState::Updating, $this->productRowState( $productId ), 'Nothing was written while the lock was held elsewhere.' );
	}

	/**
	 * Tests row 8: orphan variants and prices are reported, never repaired.
	 *
	 * Planted violations: a `variants` row and a `variant_prices` row whose product does not exist.
	 *
	 * @since 0.1.0
	 */
	public function test_row_8_orphan_commerce_rows(): void {
		$variantId = $this->plantOrphanVariant();
		$this->plantOrphanPrice( $variantId + 1 );

		$result = ( new OrphanCommerceRowCheck( $this->products ) )->run();

		$this->assertFalse( $result->passed );
		$this->assertCount( 2, $result->findings );
	}

	/**
	 * Tests row 9: a third-party callback on one of the six hooks is reported as drift, whichever
	 * of the shapes WordPress accepts for a callback it was registered as — a plain function, a
	 * `Class::method` string, or an invokable object — because ownership is decided by the
	 * callback's own resolved file, not by inspecting its shape one way per kind.
	 *
	 * Planted violation: a fixture "plugin" file registers one callback of each shape.
	 *
	 * @since 0.1.0
	 */
	public function test_row_9_foreign_hooks(): void {
		require_once dirname( __DIR__, 2 ) . '/Fixtures/foreign-plugin/foreign-plugin.php';

		\SeocartTestFixture\ForeignPlugin\register();

		try {
			$result   = ( new ForeignHooksCheck() )->run();
			$findings = implode( ' ', $result->findings );

			$this->assertTrue( $result->passed, 'Drift is reported, but never a failure this check decides on its own.' );
			$this->assertStringContainsString( 'code outside a recognised plugin, theme or mu-plugin directory', $findings );
			$this->assertStringNotContainsString( 'foreign-plugin.php', $findings, 'No full server path is ever printed.' );

			// All three shapes: the plain function (pre_delete_post), the Class::method string
			// (save_post) and the invokable object (wp_insert_post).
			$this->assertStringContainsString( 'pre_delete_post', $findings );
			$this->assertStringContainsString( 'save_post', $findings );
			$this->assertStringContainsString( 'wp_insert_post', $findings );
		} finally {
			\SeocartTestFixture\ForeignPlugin\deregister();
		}
	}

	/**
	 * Tests the reverse cross-module line: a stock item whose variant is gone is reported, never repaired.
	 *
	 * Planted violation: the variant deleted directly from `variants`, its stock item kept.
	 *
	 * @since 0.1.0
	 */
	public function test_reverse_line_orphan_stock_item(): void {
		$product   = $this->storedProduct( 'SKU-ORPHANITEM' );
		$variantId = (int) $product->defaultVariant()?->id();

		$this->db->transaction( fn () => $this->stock->createItems( array( $variantId ) ) );
		$this->db->execute( 'DELETE FROM %i WHERE id = %d', $this->catalogTable( CatalogTables::VARIANTS ), $variantId );

		$result = ( new OrphanStockItemCheck( $this->products, $this->stock ) )->run();

		$this->assertFalse( $result->passed );
		$this->assertContains( $variantId, $this->extractIds( $result->findings[0] ) );
	}

	/**
	 * Tests the full --repair sequence: first pass finds rows 1–9 broken; repair fixes exactly
	 * 3–7 and leaves 1, 2, 5 (zero enabled variants), 8, 9 and the orphan stock item unchanged;
	 * checksums of `variants`, `variant_prices` and the stock ledger are identical before and
	 * after; exit codes.
	 *
	 * Plants (each confirmed to go red, then removed): the checksum comparison itself, proved by
	 * temporarily changing a kept price and re-running the assertion.
	 *
	 * @since 0.1.0
	 */
	public function test_repair_sequence_fixes_exactly_3_to_7(): void {
		$clean = $this->storedProduct( 'SKU-CLEAN' );
		$this->db->transaction( fn () => $this->stock->createItems( array( (int) $clean->defaultVariant()?->id() ) ) );

		$this->plantBareProductRow();
		$missingSource = $this->storedProduct( 'SKU-ROW2B' );
		$this->db->execute( 'UPDATE %i SET source_post_id = 999999998 WHERE id = %d', $this->catalogTable( CatalogTables::PRODUCTS ), (int) $missingSource->id() );

		$row3Product = $this->storedProduct( 'SKU-ROW3B' );
		$row3Post    = $this->post();
		$this->db->execute( 'INSERT INTO %i ( post_id, product_id, locale, linked_at, created_at ) VALUES ( %d, %d, %s, UTC_TIMESTAMP(), UTC_TIMESTAMP() )', $this->catalogTable( CatalogTables::PRODUCT_POSTS ), $row3Post, (int) $row3Product->id(), 'de_DE' );
		wp_delete_post( $row3Post, true );

		$row4Post = $this->unboundPost();

		// Zero enabled variants, otherwise whole (a stock item, a price): the ruled gap, reported
		// but never repaired, since Product::settle() does not weigh is_enabled on its own.
		$row5Product = $this->storedProduct( 'SKU-ROW5B' );
		$this->db->transaction( fn () => $this->stock->createItems( array( (int) $row5Product->defaultVariant()?->id() ) ) );
		$this->db->execute( 'UPDATE %i SET is_enabled = 0, updated_at = UTC_TIMESTAMP() WHERE product_id = %d', $this->catalogTable( CatalogTables::VARIANTS ), (int) $row5Product->id() );
		$this->db->execute( 'UPDATE %i SET enabled_variant_count = 0 WHERE id = %d', $this->catalogTable( CatalogTables::PRODUCTS ), (int) $row5Product->id() );

		$row6Product = $this->storedProduct( 'SKU-ROW6B' );
		$row6Variant = (int) $row6Product->defaultVariant()?->id();
		$this->db->transaction( fn () => $this->stock->createItems( array( $row6Variant ) ) );
		$this->db->execute( 'DELETE FROM %i WHERE variant_id = %d', $this->db->table( InventoryTables::ITEMS ), $row6Variant );

		$row7Product = $this->storedProduct( 'SKU-ROW7B' );
		$this->db->execute( "UPDATE %i SET generation_state = 'updating', updated_at = UTC_TIMESTAMP(6) - INTERVAL 20 MINUTE WHERE id = %d", $this->catalogTable( CatalogTables::PRODUCTS ), (int) $row7Product->id() );

		$row8Variant = $this->plantOrphanVariant();

		$row9File = dirname( __DIR__, 2 ) . '/Fixtures/foreign-plugin/foreign-plugin.php';
		require_once $row9File;
		\SeocartTestFixture\ForeignPlugin\register();

		$orphanItemProduct = $this->storedProduct( 'SKU-ORPHANITEMB' );
		$orphanItemVariant = (int) $orphanItemProduct->defaultVariant()?->id();
		$this->db->transaction( fn () => $this->stock->createItems( array( $orphanItemVariant ) ) );
		$this->db->execute( 'DELETE FROM %i WHERE id = %d', $this->catalogTable( CatalogTables::VARIANTS ), $orphanItemVariant );

		try {
			$checks = $this->catalogChecks->checks();
			$before = $this->checksum();

			$first = $this->runChecks( $checks );

			$firstByName = array();
			foreach ( $checks as $i => $check ) {
				$firstByName[ $check->name() ] = $first[ $i ]->passed;
			}

			$this->assertFalse( $firstByName['no_binding'] ?? true );
			$this->assertFalse( $firstByName['missing_source'] ?? true );
			$this->assertFalse( $firstByName['dangling_binding'] ?? true );
			$this->assertFalse( $firstByName['unbound_post'] ?? true );
			$this->assertFalse( $firstByName['incomplete_mismatch'] ?? true );
			$this->assertFalse( $firstByName['missing_stock_item'] ?? true );
			$this->assertFalse( $firstByName['stuck_updating'] ?? true );
			$this->assertFalse( $firstByName['orphan_commerce_rows'] ?? true );
			$this->assertFalse( $firstByName['orphan_stock_item'] ?? true );

			// Drift is reported, but never a failure this check decides on its own.
			$this->assertTrue( $firstByName['foreign_hooks'] ?? false );
			$firstForeignHooks = $first[ array_search( 'foreign_hooks', array_map( static fn( $c ) => $c->name(), $checks ), true ) ];
			$this->assertNotSame( array(), $firstForeignHooks->findings, 'Drift is still listed even though the check passed.' );

			foreach ( $checks as $index => $check ) {
				if ( $check instanceof \SEOCart\Platform\Cli\Doctor\Repairable && ! $first[ $index ]->passed ) {
					$check->repair();
				}
			}

			$second = $this->runChecks( $checks );

			$secondByName = array();
			foreach ( $checks as $i => $check ) {
				$secondByName[ $check->name() ] = $second[ $i ]->passed;
			}

			// Repaired clean.
			$this->assertTrue( $secondByName['dangling_binding'] ?? false );
			$this->assertTrue( $secondByName['unbound_post'] ?? false );
			$this->assertTrue( $secondByName['missing_stock_item'] ?? false );
			$this->assertTrue( $secondByName['stuck_updating'] ?? false );

			// Row 8's own orphan variant (its product gone) is never in row 6's scan
			// at all — variantIds() only lists variants whose product still exists — so the
			// repair never reaches it, and it gets no stock item.
			$this->assertArrayNotHasKey( $row8Variant, $this->stock->levels( array( $row8Variant ) ) );

			// Reported, still failing: 1, 2, 5 (zero enabled variants, the ruled gap), 8, the
			// reverse line.
			$this->assertFalse( $secondByName['no_binding'] ?? true );
			$this->assertFalse( $secondByName['missing_source'] ?? true );
			$this->assertFalse( $secondByName['incomplete_mismatch'] ?? true, 'Zero enabled variants, otherwise whole, is never repaired: settle() does not weigh it.' );
			$this->assertFalse( $secondByName['orphan_commerce_rows'] ?? true );
			$this->assertFalse( $secondByName['orphan_stock_item'] ?? true );

			// 9: still drift, still never a failure.
			$this->assertTrue( $secondByName['foreign_hooks'] ?? false );
			$secondForeignHooks = $second[ array_search( 'foreign_hooks', array_map( static fn( $c ) => $c->name(), $checks ), true ) ];
			$this->assertNotSame( array(), $secondForeignHooks->findings, 'Drift is still listed even though the check passed.' );

			$after = $this->checksum();

			$this->assertSame( $before, $after, 'variants, variant_prices and the stock ledger are unchanged around the repair.' );

			// Plant: repair touches price_minor → checksum red. Confirmed here, then undone.
			$this->db->execute( 'UPDATE %i SET price_minor = price_minor + 1 WHERE variant_id = %d', $this->catalogTable( CatalogTables::VARIANT_PRICES ), (int) $clean->defaultVariant()?->id() );
			$this->assertNotSame( $before, $this->checksum(), 'Plant: the checksum catches a repair that touched a price.' );
			$this->db->execute( 'UPDATE %i SET price_minor = price_minor - 1 WHERE variant_id = %d', $this->catalogTable( CatalogTables::VARIANT_PRICES ), (int) $clean->defaultVariant()?->id() );
			$this->assertSame( $before, $this->checksum(), 'Restored.' );

			// Plant: deleting the orphan stock item the way a real delete would leave the ledger
			// balanced writes it off with a final ledger entry first (StockService::deleteVariants()'s
			// own contract) — that write-off row is exactly what the ledger checksum must catch.
			$this->db->execute(
				'INSERT INTO %i ( variant_id, delta, on_hand_after, reason, actor_type, actor_id, created_at ) VALUES ( %d, 0, 0, %s, %s, %s, UTC_TIMESTAMP(6) )',
				$this->db->table( InventoryTables::LEDGER ),
				$orphanItemVariant,
				'variant_deleted',
				'system',
				'0'
			);
			$this->assertNotSame( $before, $this->checksum(), 'Plant: a ledger row from deleting the orphan item is caught by the checksum.' );
			$this->db->execute( 'DELETE FROM %i WHERE variant_id = %d AND reason = %s', $this->db->table( InventoryTables::LEDGER ), $orphanItemVariant, 'variant_deleted' );
			$this->assertSame( $before, $this->checksum(), 'Restored.' );

			$this->assertSame( DoctorCommand::EXIT_FAILED, $this->exitCodeFor( $second ) );
		} finally {
			\SeocartTestFixture\ForeignPlugin\deregister();
		}
	}

	/**
	 * Tests the exit codes: clean is 0, a human finding left is 1.
	 *
	 * @since 0.1.0
	 */
	public function test_exit_codes(): void {
		$this->assertSame( DoctorCommand::EXIT_OK, $this->exitCodeFor( $this->runChecks( $this->catalogChecks->checks() ) ), 'A freshly stored product with a stock item passes every check.' );

		$this->plantBareProductRow();

		$this->assertSame( DoctorCommand::EXIT_FAILED, $this->exitCodeFor( $this->runChecks( $this->catalogChecks->checks() ) ) );
	}

	/**
	 * Returns DoctorCommand's exit code for a set of results, the same rule it uses.
	 *
	 * @since 0.1.0
	 *
	 * @param \SEOCart\Platform\Cli\Doctor\CheckResult[] $results The results.
	 * @return int EXIT_OK or EXIT_FAILED.
	 */
	private function exitCodeFor( array $results ): int {
		foreach ( $results as $result ) {
			if ( ! $result->passed ) {
				return DoctorCommand::EXIT_FAILED;
			}
		}

		return DoctorCommand::EXIT_OK;
	}

	/**
	 * Builds a fresh ProductSettler over this test's connection.
	 *
	 * @since 0.1.0
	 *
	 * @return ProductSettler The settler.
	 */
	private function newSettler(): ProductSettler {
		return new ProductSettler( $this->products, $this->stock, $this->db, array( new LockService( $this->db, LockMode::GetLock ), 'withLock' ) );
	}

	/**
	 * Returns the reconciler CatalogChecks was built with, for a check built by hand.
	 *
	 * @since 0.1.0
	 *
	 * @return Reconciler The reconciler.
	 */
	private function catalogChecksReconciler(): Reconciler {
		return new Reconciler(
			$this->products,
			$this->db,
			new class() implements \SEOCart\Platform\Localization\PostLocales {
				/**
				 * Returns the fixture locale for every post.
				 *
				 * @param int $postId Unused.
				 * @return Locale The fixture locale.
				 */
				public function localeOf( int $postId ): Locale {
					unset( $postId );

					return Locale::of( 'en_US' );
				}
			},
			new FrozenClock( $this->databaseNow() ),
			new SequentialIdGenerator( 800000 )
		);
	}

	/**
	 * Plants a `products` row with no `product_posts` row at all: row 1's violation.
	 *
	 * @since 0.1.0
	 *
	 * @return int The product's id.
	 */
	private function plantBareProductRow(): int {
		$this->db->execute(
			'INSERT INTO %i ( uuid, generation_state, active_variant_generation, created_at, updated_at ) VALUES ( %s, %s, 0, UTC_TIMESTAMP(), UTC_TIMESTAMP(6) )',
			$this->catalogTable( CatalogTables::PRODUCTS ),
			$this->ids->generate(),
			GenerationState::Incomplete->value
		);

		return $this->db->lastInsertId();
	}

	/**
	 * Plants a `variants` row whose product does not exist: row 8's violation.
	 *
	 * @since 0.1.0
	 *
	 * @return int The variant's id.
	 */
	private function plantOrphanVariant(): int {
		$this->db->execute(
			'INSERT INTO %i ( uuid, product_id, sku, combination_hash, generation, is_enabled, position, created_at, updated_at ) VALUES ( %s, 999999997, %s, %s, 1, 1, 0, UTC_TIMESTAMP(), UTC_TIMESTAMP() )',
			$this->catalogTable( CatalogTables::VARIANTS ),
			$this->ids->generate(),
			'SKU-ORPHAN-' . $this->ids->generate(),
			str_repeat( '0', 64 )
		);

		return $this->db->lastInsertId();
	}

	/**
	 * Inserts many `variants` rows in one statement, all under one product id, for a check's walk
	 * to page past.
	 *
	 * @since 0.1.0
	 *
	 * @param int $count     How many to insert.
	 * @param int $productId The product id every row carries; need not be a real product.
	 * @return list<int> The variant ids, ascending.
	 */
	private function bulkInsertVariants( int $count, int $productId ): array {
		$groups = array();
		$args   = array( $this->catalogTable( CatalogTables::VARIANTS ) );

		for ( $i = 0; $i < $count; $i++ ) {
			$groups[] = '( %s, %d, %s, %s, 1, 1, 0, UTC_TIMESTAMP(), UTC_TIMESTAMP() )';
			$args[]   = $this->ids->generate();
			$args[]   = $productId;
			$args[]   = 'SKU-BULK-' . $i . '-' . $this->ids->generate();
			$args[]   = str_pad( (string) $i, 64, '0', STR_PAD_LEFT );
		}

		$this->db->execute(
			'INSERT INTO %i ( uuid, product_id, sku, combination_hash, generation, is_enabled, position, created_at, updated_at ) VALUES ' . implode( ', ', $groups ),
			...$args
		);

		$lastId = $this->db->lastInsertId();

		return range( $lastId, $lastId + $count - 1 );
	}

	/**
	 * Plants a `variant_prices` row whose variant does not exist: row 8's other violation.
	 *
	 * @since 0.1.0
	 *
	 * @param int $variantId A variant id that does not exist.
	 */
	private function plantOrphanPrice( int $variantId ): void {
		$this->db->execute(
			'INSERT INTO %i ( variant_id, currency, amount_basis, price_minor, created_at, updated_at ) VALUES ( %d, %s, %s, 100, UTC_TIMESTAMP(), UTC_TIMESTAMP() )',
			$this->catalogTable( CatalogTables::VARIANT_PRICES ),
			$variantId,
			self::BASE_CURRENCY,
			'net'
		);
	}

	/**
	 * Reads a product's stored marker.
	 *
	 * @since 0.1.0
	 *
	 * @param int $productId The product.
	 * @return GenerationState The marker.
	 */
	private function productRowState( int $productId ): GenerationState {
		$row = $this->productRow( $productId );

		$this->assertNotNull( $row );

		return GenerationState::fromStored( (string) $row['generation_state'] );
	}

	/**
	 * Reads one `product_posts` row.
	 *
	 * @since 0.1.0
	 *
	 * @param int $postId    The post.
	 * @param int $productId The product.
	 * @return array<string, mixed>|null The row, or null.
	 */
	private function bindingRow( int $postId, int $productId ): ?array {
		return $this->db->fetchRow( 'SELECT * FROM %i WHERE post_id = %d AND product_id = %d', $this->catalogTable( CatalogTables::PRODUCT_POSTS ), $postId, $productId );
	}

	/**
	 * Pulls the ids a finding line names, as decimal numbers.
	 *
	 * @since 0.1.0
	 *
	 * @param string $finding The finding.
	 * @return list<int> The ids, in the order they appear.
	 */
	private function extractIds( string $finding ): array {
		preg_match_all( '/\d+/', $finding, $matches );

		return array_map( 'intval', $matches[0] );
	}

	/**
	 * Checksums `variants`, `variant_prices` and the stock ledger: unchanged around a repair.
	 *
	 * @since 0.1.0
	 *
	 * @return string The combined checksum.
	 */
	private function checksum(): string {
		$parts = array();

		foreach ( array( CatalogTables::VARIANTS, CatalogTables::VARIANT_PRICES ) as $table ) {
			$parts[] = md5( (string) wp_json_encode( $this->db->fetchAll( 'SELECT * FROM %i ORDER BY id', $this->catalogTable( $table ) ) ) );
		}

		$parts[] = md5( (string) wp_json_encode( $this->db->fetchAll( 'SELECT * FROM %i ORDER BY id', $this->db->table( InventoryTables::LEDGER ) ) ) );

		return implode( ':', $parts );
	}

	/**
	 * Returns the database's current UTC time, the clock every stuck row in these tests is aged against.
	 *
	 * The checks' clock must agree with the rows' `UTC_TIMESTAMP()` ages, so it is read from the database rather than fixed.
	 *
	 * @since 0.1.0
	 *
	 * @return \DateTimeImmutable The database's time.
	 */
	private function databaseNow(): \DateTimeImmutable {
		return new \DateTimeImmutable( (string) $this->db->fetchValue( 'SELECT UTC_TIMESTAMP(6)' ), new \DateTimeZone( 'UTC' ) );
	}
}
