<?php
/**
 * Tests how a failed product save recovers: nothing half-built is ever sellable, and a refused save takes nothing off sale
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Catalog;

use SEOCart\Catalog\Domain\CatalogError;
use SEOCart\Catalog\Domain\SellabilityReason;
use SEOCart\Catalog\Infrastructure\CatalogTables;
use SEOCart\Inventory\Infrastructure\InventoryTables;
use SEOCart\Platform\Authorization\ProductCapabilities;
use SEOCart\Platform\Database\DatabaseError;
use SEOCart\Platform\Database\Exception\TransactionIntegrityLost;
use SEOCart\Platform\Events\OutboxTable;
use SEOCart\Support\Error\CodedException;
use SEOCart\Tests\Support\Catalog\ProductWrites;
use SEOCart\Tests\Support\Catalog\ProductWriteTestCase;

/**
 * The recovery proofs.
 *
 * A failure the transaction manager rolled back puts the marker back, so a refused update
 * leaves a live product on sale. A failure it could not roll back, because the transaction was
 * lost or the process died, leaves the committed `updating` mark, so the product is unsellable
 * until a later save or doctor settles it.
 *
 * Planted violations, each confirmed to fail a test here:
 * - In SaveProduct::update(), run the mark inside the window instead of in its own transaction:
 *   the lost-connection and crash tests find the product `complete`.
 * - In SaveProduct::takeBackMark(), drop the TransactionIntegrityLost return: the
 *   lost-connection test finds the product `complete`.
 * - In MysqlProductRepository::restoreMark(), drop `AND updated_at = %s`: the listener that
 *   commits and throws leaves the product `complete`.
 *
 * @since 0.1.0
 */
final class SaveProductRecoveryTest extends ProductWriteTestCase {

	/**
	 * Tests that a first save a listener fails leaves no post, no row and no event.
	 *
	 * @since 0.1.0
	 */
	public function test_a_failed_first_save_leaves_nothing(): void {
		$b      = $this->secondConnection();
		$postId = 0;

		$this->failInsideThePostWrite(
			static function ( int $id ) use ( &$postId ): void {
				$postId = $id;
			}
		);

		$failure = $this->saveFailure(
			fn() => $this->save(
				null,
				array(
					'post_title'  => 'Never kept',
					'post_status' => 'publish',
				),
				array(
					'sku'         => 'SKU-GONE',
					'price_minor' => 1999,
				)
			)
		);

		$this->assertSame( self::LISTENER_FAILED, $failure->getMessage() );
		$this->assertGreaterThan( 0, $postId, 'The listener ran inside the post write.' );
		$this->assertNull( get_post( $postId ), 'Neither the post nor a cached copy of it survives.' );
		$this->assertSame( '0', $b->fetchValue( sprintf( 'SELECT COUNT(*) FROM `%s` WHERE ID = %d', $GLOBALS['wpdb']->posts, $postId ) ) );

		foreach ( array( CatalogTables::PRODUCTS, CatalogTables::PRODUCT_POSTS, CatalogTables::VARIANTS, CatalogTables::VARIANT_PRICES, InventoryTables::ITEMS, OutboxTable::NAME ) as $table ) {
			$this->assertSame( 0, $this->committedCount( $b, $table ), $table . ' kept a row of the failed save.' );
		}
	}

	/**
	 * Tests that an update a listener fails leaves the old post and commerce rows, and the product complete and sellable.
	 *
	 * @since 0.1.0
	 */
	public function test_a_failed_update_leaves_the_product_as_it_was_and_on_sale(): void {
		$b     = $this->secondConnection();
		$saved = $this->savedProduct();

		$this->failInsideThePostWrite();

		$failure = $this->saveFailure( fn() => $this->save( $saved->postId, array( 'post_title' => 'Refused' ), array( 'price_minor' => 2999 ) ) );

		$this->assertSame( self::LISTENER_FAILED, $failure->getMessage() );
		$this->assertSame( array( 'Saved product', 'SKU-1', '1999' ), $this->committedProduct( $b, $saved ) );
		$this->assertSame( 'complete', $this->committedMarker( $b, $saved->productId )[0], 'The refused save put back the marker it found.' );
		$this->assertSame( SellabilityReason::Sellable, $this->verdict( (int) $saved->variantId ) );
		$this->assertSame( 'Saved product', get_post( $saved->postId )?->post_title, 'No cached copy of the rolled-back title survives.' );
		$this->assertSame( 1, $this->committedSaves( $b, $saved->productId ) );
	}

	/**
	 * Tests that `catalog.sku_taken` on an update leaves the product on sale with its old SKU.
	 *
	 * @since 0.1.0
	 */
	public function test_a_taken_sku_on_an_update_leaves_the_product_on_sale_with_its_sku(): void {
		$b      = $this->secondConnection();
		$first  = $this->savedProduct( 'SKU-1', 'First' );
		$second = $this->savedProduct( 'SKU-2', 'Second' );

		$failure = $this->saveFailure( fn() => $this->save( $second->postId, array( 'post_title' => 'Second, renamed' ), array( 'sku' => 'SKU-1' ) ) );

		$this->assertInstanceOf( CodedException::class, $failure );
		$this->assertSame( CatalogError::SkuTaken, $failure->errorCode() );
		$this->assertSame( array( 'sku' => 'SKU-1' ), $failure->context() );
		$this->assertSame( array( 'Second', 'SKU-2', '1999' ), $this->committedProduct( $b, $second ) );
		$this->assertSame( 'complete', $this->committedMarker( $b, $second->productId )[0] );
		$this->assertSame( SellabilityReason::Sellable, $this->verdict( (int) $second->variantId ) );
		$this->assertSame( SellabilityReason::Sellable, $this->verdict( (int) $first->variantId ) );
	}

	/**
	 * Tests that a listener that sends COMMIT and then throws leaves the product `updating`: its COMMIT kept the window's new mark, so the restore finds no mark of its own.
	 *
	 * The guards report rather than throw, as in production, so the listener's COMMIT reaches
	 * the server. It commits what the window wrote before it: the relock's new mark and the post.
	 *
	 * @since 0.1.0
	 */
	public function test_a_listener_that_commits_and_throws_leaves_the_product_updating(): void {
		global $wpdb;

		$b       = $this->secondConnection();
		$saved   = $this->savedProduct();
		$service = ProductWrites::service( $this->makeDatabase( false ), $this->reporter() );
		$mark    = array();

		$this->failInsideThePostWrite(
			function () use ( $b, $saved, $wpdb, &$mark ): void {
				$mark = $this->committedMarker( $b, $saved->productId );

				$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- The listener ends the transaction, as another plugin might.
			}
		);

		$failure = $this->saveFailure( fn() => $this->save( $saved->postId, array( 'post_title' => 'Committed by a listener' ), array( 'price_minor' => 2999 ), $service ) );
		$after   = $this->committedMarker( $b, $saved->productId );

		$this->assertSame( self::LISTENER_FAILED, $failure->getMessage() );
		$this->assertSame( 'updating', $mark[0] ?? null, 'The mark was committed before the window.' );
		$this->assertSame( 'updating', $after[0], 'The restore put back a marker although the window had been committed.' );
		$this->assertNotSame( $mark[1] ?? null, $after[1], 'The committed window carries the relock\'s own instant.' );
		$this->assertSame( SellabilityReason::Updating, $this->verdict( (int) $saved->variantId ) );
		$this->assertSame( array( 'Committed by a listener', 'SKU-1', '1999' ), $this->committedProduct( $b, $saved ), 'The COMMIT kept the post; the commerce rows were never written.' );
		$this->assertContains( DatabaseError::ForbiddenInTransaction->value, array_column( $this->reports, 'code' ), 'The guards reported the listener\'s COMMIT.' );
	}

	/**
	 * Tests that a window whose first statement wpdb ran again on a new connection, where it committed on its own, leaves the product `updating`.
	 *
	 * The connection is killed just before the relock is sent. wpdb reconnects and runs the
	 * relock again, which commits on its own; the transaction manager then refuses the window.
	 *
	 * @since 0.1.0
	 */
	public function test_a_relock_run_again_on_a_new_connection_leaves_the_product_updating(): void {
		$b     = $this->secondConnection();
		$saved = $this->savedProduct();

		$this->beforeStatement(
			'/^' . preg_quote( ProductWrites::markStatement( $this->db, $saved->productId ), '/' ) . '$/',
			function () use ( $b ): void {
				$b->kill( $this->db->threadId() );
			},
			2
		);

		$failure = $this->saveFailure( fn() => $this->save( $saved->postId, array( 'post_title' => 'Lost' ), array( 'price_minor' => 2999 ) ) );

		$this->assertInstanceOf( TransactionIntegrityLost::class, $failure );
		$this->assertSame( TransactionIntegrityLost::CONNECTION_CHANGED, $failure->reason() );
		$this->assertSame( 'updating', $this->committedMarker( $b, $saved->productId )[0] );
		$this->assertSame( SellabilityReason::Updating, $this->verdict( (int) $saved->variantId ) );
		$this->assertSame( array( 'Saved product', 'SKU-1', '1999' ), $this->committedProduct( $b, $saved ) );
	}

	/**
	 * Tests that a connection lost inside the window, after which another plugin's write commits on its own, leaves the product `updating`.
	 *
	 * The listener kills the connection and writes post meta, which wpdb runs on a new connection,
	 * where it commits on its own. The server rolls the window back with the old connection,
	 * relock included, so only the committed mark before the window keeps the product unsellable.
	 *
	 * @since 0.1.0
	 */
	public function test_a_connection_lost_inside_the_window_leaves_the_product_updating(): void {
		$b     = $this->secondConnection();
		$saved = $this->savedProduct();

		add_action(
			'save_post_' . ProductCapabilities::POST_TYPE,
			function ( $postId ) use ( $b ): void {
				$b->kill( $this->db->threadId() );

				update_post_meta( (int) $postId, 'another_plugin_note', 'kept' );
			}
		);

		$failure = $this->saveFailure( fn() => $this->save( $saved->postId, array( 'post_title' => 'Lost' ), array( 'price_minor' => 2999 ) ) );

		$this->assertInstanceOf( TransactionIntegrityLost::class, $failure );
		$this->assertSame( TransactionIntegrityLost::CONNECTION_CHANGED, $failure->reason() );
		$this->assertSame( 'kept', $b->fetchValue( sprintf( "SELECT meta_value FROM `%s` WHERE post_id = %d AND meta_key = 'another_plugin_note'", $GLOBALS['wpdb']->postmeta, $saved->postId ) ), 'The other plugin\'s write committed on its own.' );
		$this->assertSame( 'updating', $this->committedMarker( $b, $saved->productId )[0], 'A product whose rows another write overtook must not be on sale.' );
		$this->assertSame( SellabilityReason::Updating, $this->verdict( (int) $saved->variantId ) );
		$this->assertSame( array( 'Saved product', 'SKU-1', '1999' ), $this->committedProduct( $b, $saved ) );
	}

	/**
	 * Tests that a process that dies inside the window, after its mark committed, leaves the product `updating`, and Sellability says so.
	 *
	 * The save runs in a probe process whose listener ends the process: no rollback handler and
	 * no restore run, and the server rolls the window back when the connection closes.
	 *
	 * @since 0.1.0
	 */
	public function test_a_crash_after_the_mark_leaves_the_product_updating(): void {
		$b     = $this->secondConnection();
		$saved = $this->savedProduct();

		$report = $this->saveElsewhere( $saved->postId, 'Crashed', 'crashes', false )->finish();

		$this->assertSame( 'crashed', $report['outcome'] ?? null, (string) wp_json_encode( $report ) );
		$this->assertSame( 'updating', $this->committedMarker( $b, $saved->productId )[0] );
		$this->assertSame( SellabilityReason::Updating, $this->verdict( (int) $saved->variantId ) );
		$this->assertSame( array( 'Saved product', 'SKU-1', '1999' ), $this->committedProduct( $b, $saved ) );
		$this->assertSame( 1, $this->committedSaves( $b, $saved->productId ) );
	}
}
