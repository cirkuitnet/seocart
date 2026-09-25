<?php
/**
 * Tests that a product post written by a path the plugin does not own becomes an unsellable incomplete product
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Catalog;

use SEOCart\Catalog\Application\Query\Sellability;
use SEOCart\Catalog\Domain\GenerationState;
use SEOCart\Catalog\Domain\Product;
use SEOCart\Catalog\Domain\ReportCode;
use SEOCart\Catalog\Domain\SellabilityReason;
use SEOCart\Catalog\Infrastructure\CatalogTables;
use SEOCart\Platform\Authorization\ProductCapabilities;
use SEOCart\Platform\Database\TransactionManager;
use SEOCart\Platform\Kernel\GateState;
use SEOCart\Platform\Kernel\GatedTransactionManager;
use SEOCart\Tests\Support\Catalog\ProductWrites;
use SEOCart\Tests\Support\Catalog\ProductWriteTestCase;
use SEOCart\Tests\Support\Doubles\ClosedGateTransactions;
use SEOCart\Tests\Support\KernelContainer;

/**
 * A product post written by a path the plugin does not own becomes an unsellable `incomplete` product, whatever the path.
 *
 * `wp post create`, a generic duplicator, an import and another plugin's wp_insert_post() all
 * write the post with wp_insert_post(), which fires `wp_after_insert_post` inside its own call;
 * the lifecycle hooked there binds the post to a new product, `incomplete`, without a variant, in
 * the site's locale, and the plugin's next save completes it. Writes to a post that is already
 * bound leave its product alone, and one refused by a closed schema gate leaves the post unbound
 * with a report, never an exception in the writer's path. The lifecycle is hooked through the
 * kernel's own Modules::catalogLifecycleHooks(), in the kernel's place.
 *
 * Planted violations, each confirmed to fail a test here:
 * - In Modules::catalogLifecycleHooks(), remove the `wp_after_insert_post` callback: no product
 *   row is created, and the first test fails.
 * - In PostLifecycle::postWritten(), reconcile an auto-draft too: the auto-draft test fails.
 * - In PostLifecycle::boundPostWrittenElsewhere(), mark the post's product `updating` (as a rule
 *   that marked such a write's product `incomplete` would): the test of a write to a bound post
 *   fails, and so do the autosave and revision tests of the product endpoint.
 * - In PostLifecycle::boundPostWrittenElsewhere(), drop the return for a step into or out of
 *   the trash: the trash test fails, the trash reported as a write by another path.
 * - In the same method, skip every write whose old or new status is the trash, whether or not
 *   the status changed: the test of a post that stays in the trash fails, its edit unreported.
 * - In PostLifecycle::failed(), throw the failure: the closed-gate test fails.
 * - In PostLifecycle::failed(), report a site that is not installed too: the test of such a
 *   site fails.
 * - In Reconciler::reconcile(), let the duplicate key go: the race test fails.
 *
 * @since 0.1.0
 */
final class ReconcilerTest extends ProductWriteTestCase {

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
	 * Tests that a product post written as `wp post create` writes one becomes an unsellable incomplete product, which a later save completes.
	 *
	 * The recovery proof that a post written by a path the plugin does not own becomes an
	 * unsellable `incomplete` product.
	 *
	 * @since 0.1.0
	 */
	public function test_a_post_written_by_another_path_becomes_an_unsellable_incomplete_product(): void {
		$b       = $this->secondConnection();
		$postId  = $this->writtenElsewhere( 'publish' );
		$product = $this->products->findByPost( $postId );

		$this->assertInstanceOf( Product::class, $product, 'The post was not bound.' );
		$this->assertSame( GenerationState::Incomplete, $product->generation() );
		$this->assertSame( $postId, $product->sourcePostId() );
		$this->assertNull( $product->defaultVariant() );
		$this->assertSame( array( get_locale() ), array_map( static fn( $binding ): string => $binding->locale()->toString(), $product->bindings() ), 'The post is bound in the site\'s locale.' );
		$this->assertSame( GenerationState::Incomplete->value, $this->committedMarker( $b, (int) $product->id() )[0], 'The product is committed.' );
		$this->assertSame( SellabilityReason::Incomplete, ( new Sellability( $this->products ) )->ofProduct( $product, true ), 'A reconciled product cannot be sold.' );
		$this->assertSame( array(), $this->reports );

		$saved = $this->save(
			$postId,
			array(),
			array(
				'sku'         => 'SKU-RECONCILED',
				'price_minor' => 500,
			)
		);

		$this->assertSame( (int) $product->id(), $saved->productId, 'The save completed the reconciled product.' );
		$this->assertSame( GenerationState::Complete, $saved->generation );
		$this->assertSame( SellabilityReason::Sellable, $this->verdict( (int) $saved->variantId ) );
	}

	/**
	 * Tests that an auto-draft, which the editor creates when it opens for a new product, is left unbound.
	 *
	 * @since 0.1.0
	 */
	public function test_an_auto_draft_creates_nothing(): void {
		$b      = $this->secondConnection();
		$postId = $this->writtenElsewhere( 'auto-draft' );

		$this->assertNull( $this->products->findByPost( $postId ) );
		$this->assertSame( 0, $this->committedCount( $b, CatalogTables::PRODUCTS ) );
	}

	/**
	 * Tests that a generic copy of a complete product's post is a second, incomplete product, and the original is untouched.
	 *
	 * A duplicator that knows nothing of the plugin copies the post's fields and meta into a new
	 * post with wp_insert_post().
	 *
	 * @since 0.1.0
	 */
	public function test_a_generic_copy_of_a_complete_products_post_is_a_second_incomplete_product(): void {
		$b        = $this->secondConnection();
		$original = $this->savedProduct();
		$before   = $this->committedProduct( $b, $original );
		$source   = get_post( $original->postId );

		$this->assertInstanceOf( \WP_Post::class, $source );

		$copyId = wp_insert_post(
			array(
				'post_type'    => $source->post_type,
				'post_status'  => 'draft',
				'post_title'   => $source->post_title . ' (copy)',
				'post_content' => $source->post_content,
				'meta_input'   => array( 'copied_meta' => 'from the original' ),
			),
			true
		);

		$this->assertIsInt( $copyId );
		$this->trackPost( $copyId );

		$copy = $this->products->findByPost( $copyId );

		$this->assertInstanceOf( Product::class, $copy, 'The copy was not bound.' );
		$this->assertNotSame( $original->productId, $copy->id() );
		$this->assertSame( GenerationState::Incomplete, $copy->generation() );
		$this->assertNull( $copy->defaultVariant(), 'The copy shares no variant, and no SKU, with the original.' );
		$this->assertSame( $before, $this->committedProduct( $b, $original ), 'The original\'s rows changed.' );
		$this->assertSame( SellabilityReason::Sellable, $this->verdict( (int) $original->variantId ) );
	}

	/**
	 * Tests that a write by another path to a bound post, as `wp post update` makes one, leaves its product as it was, and is reported once under WP_DEBUG.
	 *
	 * @since 0.1.0
	 */
	public function test_a_write_by_another_path_to_a_bound_post_leaves_its_product_alone(): void {
		ProductWrites::services( $this->db, $this->reporter(), true )->attach();

		$b      = $this->secondConnection();
		$saved  = $this->savedProduct();
		$before = $this->catalogChecksums( $b );

		$this->assertIsInt(
			wp_update_post(
				array(
					'ID'         => $saved->postId,
					'post_title' => 'Edited elsewhere',
				),
				true
			)
		);
		$this->assertIsInt(
			wp_update_post(
				array(
					'ID'           => $saved->postId,
					'post_excerpt' => 'Edited again',
				),
				true
			)
		);

		$this->assertSame( 'Edited elsewhere', get_post_field( 'post_title', $saved->postId ) );
		$this->assertSame( $before, $this->catalogChecksums( $b ), 'A write by another path changed a catalog row.' );
		$this->assertSame( SellabilityReason::Sellable, $this->verdict( (int) $saved->variantId ), 'The product went off sale.' );
		$this->assertSame(
			array(
				array(
					'code'    => ReportCode::ForeignPostWrite->value,
					'context' => array(
						'post_id' => $saved->postId,
						'update'  => true,
					),
				),
			),
			$this->reports,
			'The write is reported, once per request.'
		);
	}

	/**
	 * Tests that a status change by another path takes a product off sale by its status alone.
	 *
	 * @since 0.1.0
	 */
	public function test_a_status_change_by_another_path_leaves_the_product_not_published(): void {
		$b      = $this->secondConnection();
		$saved  = $this->savedProduct();
		$before = $this->catalogChecksums( $b );

		$this->assertIsInt(
			wp_update_post(
				array(
					'ID'          => $saved->postId,
					'post_status' => 'draft',
				),
				true
			)
		);

		$this->assertSame( $before, $this->catalogChecksums( $b ) );
		$this->assertSame( SellabilityReason::NotPublished, $this->verdict( (int) $saved->variantId ) );
	}

	/**
	 * Tests that moving a bound post into the trash and out of it is no write by another path: under WP_DEBUG neither is reported, while a status change that is no trash step still is.
	 *
	 * The plugin's own REST delete moves a post into the trash through the same core call.
	 *
	 * @since 0.1.0
	 */
	public function test_the_trash_and_back_are_not_writes_by_another_path(): void {
		ProductWrites::services( $this->db, $this->reporter(), true )->attach();

		$b      = $this->secondConnection();
		$saved  = $this->savedProduct();
		$before = $this->catalogChecksums( $b );

		$this->assertInstanceOf( \WP_Post::class, wp_trash_post( $saved->postId ) );
		$this->assertInstanceOf( \WP_Post::class, wp_untrash_post( $saved->postId ) );

		$this->assertSame( 'draft', get_post_status( $saved->postId ) );
		$this->assertSame( $before, $this->catalogChecksums( $b ), 'The trash changed a catalog row.' );
		$this->assertSame( array(), $this->reports, 'A trash step was reported as a write by another path.' );

		$this->assertIsInt(
			wp_update_post(
				array(
					'ID'          => $saved->postId,
					'post_status' => 'pending',
				),
				true
			)
		);

		$this->assertSame(
			array(
				array(
					'code'    => ReportCode::ForeignPostWrite->value,
					'context' => array(
						'post_id' => $saved->postId,
						'update'  => true,
					),
				),
			),
			$this->reports,
			'A status change that is no trash step went unreported.'
		);
	}

	/**
	 * Tests that a write by another path to a bound post that stays in the trash is reported under WP_DEBUG: only a change of status into or out of the trash is a lifecycle step.
	 *
	 * @since 0.1.0
	 */
	public function test_a_write_to_a_post_that_stays_in_the_trash_is_reported(): void {
		ProductWrites::services( $this->db, $this->reporter(), true )->attach();

		$saved = $this->savedProduct();

		$this->assertInstanceOf( \WP_Post::class, wp_trash_post( $saved->postId ) );
		$this->assertSame( array(), $this->reports );

		$this->assertIsInt(
			wp_update_post(
				array(
					'ID'         => $saved->postId,
					'post_title' => 'Edited in the trash',
				),
				true
			)
		);

		$this->assertSame( 'trash', get_post_status( $saved->postId ) );
		$this->assertSame( array( ReportCode::ForeignPostWrite->value ), array_column( $this->reports, 'code' ), 'A write to a trashed post was taken for a trash step.' );
	}

	/**
	 * Tests that a write refused by a closed schema gate leaves the post unbound, with one report and no exception.
	 *
	 * @since 0.1.0
	 */
	public function test_a_closed_schema_gate_leaves_the_post_unbound_and_reports_it(): void {
		$this->attachOver( new ClosedGateTransactions( $this->db, GateState::SchemaNewer ) );

		$b      = $this->secondConnection();
		$postId = $this->writtenElsewhere( 'publish' );

		$this->assertNull( $this->products->findByPost( $postId ), 'The post was bound past a closed gate.' );
		$this->assertSame( 0, $this->committedCount( $b, CatalogTables::PRODUCTS ) );
		$this->assertSame(
			array(
				array(
					'code'    => ReportCode::ReconcileFailed->value,
					'context' => array(
						'post_id' => $postId,
						'error'   => 'store.unavailable',
					),
				),
			),
			$this->reports
		);
	}

	/**
	 * Tests that on a site where the plugin is loaded but not installed a product post is left alone, and nothing is reported.
	 *
	 * The transaction manager is the production one, whose gate reads the site's boot record: the
	 * test site has none.
	 *
	 * @since 0.1.0
	 */
	public function test_a_site_without_the_plugin_installed_is_left_alone(): void {
		$gated = KernelContainer::build( $this->db, $this->reporter() )->get( TransactionManager::class );

		$this->assertInstanceOf( GatedTransactionManager::class, $gated );

		$this->attachOver( $gated );

		$postId = $this->writtenElsewhere( 'publish' );

		$this->assertNull( $this->products->findByPost( $postId ) );
		$this->assertSame( array(), $this->reports, 'A site without the plugin\'s tables has no log to report to.' );
	}

	/**
	 * Tests that a post another writer binds between the reconciler's read and its insert stays that writer's, with no error.
	 *
	 * Connection B binds the post, as a concurrent save would, just before the reconciler inserts
	 * its product; the reconciler's insert meets B's product on the unique source post.
	 *
	 * @since 0.1.0
	 */
	public function test_a_post_bound_meanwhile_by_another_writer_stays_that_writers(): void {
		$b      = $this->secondConnection();
		$postId = $this->unboundPost();
		$raced  = $this->beforeStatement(
			'/^INSERT INTO `' . preg_quote( $this->catalogTable( CatalogTables::PRODUCTS ), '/' ) . '`/',
			function () use ( $b, $postId ): void {
				$b->query( sprintf( "INSERT INTO `%s` ( uuid, source_post_id, generation_state, active_variant_generation, variant_count, enabled_variant_count, created_at, updated_at ) VALUES ( '0199713c-4d7b-7a3c-9e2b-000000000b0b', %d, 'incomplete', 0, 0, 0, UTC_TIMESTAMP(), UTC_TIMESTAMP(6) )", $this->catalogTable( CatalogTables::PRODUCTS ), $postId ) );
				$b->query( sprintf( "INSERT INTO `%s` ( post_id, product_id, locale, linked_at, created_at ) VALUES ( %d, LAST_INSERT_ID(), 'en_US', UTC_TIMESTAMP(), UTC_TIMESTAMP() )", $this->catalogTable( CatalogTables::PRODUCT_POSTS ), $postId ) );
			}
		);

		$this->assertFalse( $this->services->reconciler->reconcile( $postId ), 'The reconciler bound a post another writer had bound.' );
		$this->assertTrue( $raced->fired );
		$this->assertSame( 1, $this->committedCount( $b, CatalogTables::PRODUCTS, sprintf( 'source_post_id = %d', $postId ) ) );
		$this->assertSame( '0199713c-4d7b-7a3c-9e2b-000000000b0b', $this->products->findByPost( $postId )?->uuid() );
		$this->assertSame( 0, $this->db->depth() );
	}

	/**
	 * Writes a product post as `wp post create` does, through wp_insert_post() with its after hooks, and deletes it after the test.
	 *
	 * @since 0.1.0
	 *
	 * @param string $status The post status.
	 * @return int The post's id.
	 */
	private function writtenElsewhere( string $status ): int {
		$postId = wp_insert_post(
			array(
				'post_type'   => ProductCapabilities::POST_TYPE,
				'post_status' => $status,
				'post_title'  => 'Created elsewhere',
			),
			true
		);

		$this->assertIsInt( $postId, 'The post was not written.' );
		$this->trackPost( $postId );

		return $postId;
	}

	/**
	 * Hooks a product lifecycle whose units of work run on another transaction manager.
	 *
	 * @since 0.1.0
	 *
	 * @param TransactionManager $transactions The lifecycle's transaction manager.
	 */
	private function attachOver( TransactionManager $transactions ): void {
		ProductWrites::services( $this->db, $this->reporter(), false, $transactions )->attach();
	}
}
