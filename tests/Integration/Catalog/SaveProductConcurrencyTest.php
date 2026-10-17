<?php
/**
 * Tests two saves of one product on two real connections: they take turns, two refused ones leave the product on sale, and a lost one never hides under another's `complete`
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Catalog;

use SEOCart\Catalog\Application\ProductWrite\SaveProduct;
use SEOCart\Catalog\Domain\CatalogError;
use SEOCart\Catalog\Domain\GenerationState;
use SEOCart\Catalog\Domain\SellabilityReason;
use SEOCart\Catalog\Infrastructure\CatalogTables;
use SEOCart\Catalog\Infrastructure\MysqlProductRepository;
use SEOCart\Platform\Authorization\ProductCapabilities;
use SEOCart\Platform\Database\LockService;
use SEOCart\Platform\Database\ServerKind;
use SEOCart\Support\Error\CodedException;
use SEOCart\Support\Error\ErrorDefinition;
use SEOCart\Tests\Support\Catalog\ProductWrites;
use SEOCart\Tests\Support\Catalog\ProductWriteTestCase;

/**
 * Two saves of one product on two real connections: they take turns on the product's lock and at
 * the relock, a reader sees `updating` while a window is open, two refused saves leave a live
 * product on sale, and a save that loses its transaction after another save completed never
 * hides under that save's `complete`.
 *
 * A save in this process holds its window open inside a listener while another connection acts;
 * a save in a probe process (tests/Support/product-save-probe.php) runs while this process holds
 * locks. Every wait is shown by the server's process list, never assumed after a pause.
 *
 * Planted violations, each confirmed to fail a test here:
 * - In SaveProduct::update(), drop the relock from the window: the second save never waits
 *   (the in-process test), and the probe's save never waits at its relock (the probe test).
 * - In SaveProduct::update(), run the mark inside the window: the reader sees `complete` while
 *   the window is open.
 * - In MysqlProductRepository::relock(), lock the row with `SELECT … FOR UPDATE` instead of
 *   marking it again: the save that loses its transaction after another save completed leaves
 *   the product `complete`.
 * - In SaveProduct::update(), call updateInTurn() without the product's lock: the second of two
 *   refused saves puts back the first one's mark, and the product is left `updating`.
 *
 * @group concurrency
 *
 * @since 0.1.0
 */
final class SaveProductConcurrencyTest extends ProductWriteTestCase {

	/**
	 * Tests that a second save's relock waits for the first save's window to commit, and that a reader sees `updating` while that window is open.
	 *
	 * @since 0.1.0
	 */
	public function test_a_second_save_waits_for_the_first_and_readers_see_updating_meanwhile(): void {
		$b      = $this->secondConnection();
		$reader = $this->secondConnection();
		$saved  = $this->savedProduct();
		$relock = ProductWrites::markStatement( $this->db, $saved->productId );
		$seen   = null;

		add_action(
			'save_post_' . ProductCapabilities::POST_TYPE,
			function () use ( $b, $reader, $relock, $saved, &$seen ): void {
				$seen = $this->committedMarker( $reader, $saved->productId )[0];

				// The second save begins its window: its relock must wait for this one's.
				$b->query( 'START TRANSACTION' );
				$b->queryAsync( $relock );

				$this->awaitWaiting( $b, $relock, 'updating' );
			}
		);

		$first = $this->save( $saved->postId, array( 'post_title' => 'First writer' ), array( 'price_minor' => 2100 ) );

		$this->assertSame( 1, $b->reap(), 'The second save\'s relock went on once the first window committed.' );
		$this->assertSame( '2100', $b->fetchValue( sprintf( "SELECT price_minor FROM `%s` WHERE variant_id = %d AND currency = '%s'", $this->db->table( CatalogTables::VARIANT_PRICES ), (int) $saved->variantId, self::BASE_CURRENCY ) ), 'The second save reads what the first committed.' );

		$b->query( 'ROLLBACK' );

		$this->assertSame( 'updating', $seen, 'While the window was open, a reader saw the product unsellable.' );
		$this->assertSame( GenerationState::Complete, $first->generation );
		$this->assertSame( 'complete', $this->committedMarker( $reader, $saved->productId )[0] );
	}

	/**
	 * Tests that a save in another process waits at its relock while this connection holds the product's row, and completes once it is let go.
	 *
	 * @since 0.1.0
	 */
	public function test_a_save_waits_at_its_relock_while_another_connection_holds_the_product(): void {
		$barrier = $this->secondConnection();
		$b       = $this->secondConnection();
		$saved   = $this->savedProduct();

		$this->assertSame( '1', $barrier->fetchValue( ProductWrites::BARRIER_HOLD ) );

		$probe = $this->saveElsewhere( $saved->postId, 'Waited for its turn', 'saves', true );

		// The probe's mark committed and its window is open, just before the relock.
		$this->awaitProbeWaiting( $probe, ProductWrites::BARRIER_WAIT, 'User lock' );

		$b->query( 'START TRANSACTION' );

		$this->assertSame( 'updating', $b->fetchValue( sprintf( 'SELECT generation_state FROM `%s` WHERE id = %d FOR UPDATE', $this->db->table( CatalogTables::PRODUCTS ), $saved->productId ) ) );

		$barrier->fetchValue( ProductWrites::BARRIER_RELEASE );

		$this->awaitProbeWaiting( $probe, ProductWrites::markStatement( $this->db, $saved->productId ), 'updating' );

		$b->query( 'COMMIT' );

		$report = $probe->finish();

		$this->assertSame( array( 'saved', 'complete' ), array( $report['outcome'] ?? null, $report['generation'] ?? null ), (string) wp_json_encode( $report ) );
		$this->assertSame( array( 'Waited for its turn', 'SKU-1', '1999' ), $this->committedProduct( $b, $saved ) );
		$this->assertSame( 'complete', $this->committedMarker( $b, $saved->productId )[0] );
		$this->assertSame( 2, $this->committedSaves( $b, $saved->productId ) );
	}

	/**
	 * Tests that two refused saves of one product leave it on sale: the second takes its turn after the first has put its marker back.
	 *
	 * Save A, in this process, holds the product's lock, marks the product and is held just before
	 * its relock. Save B, in a probe process, asks for the lock meanwhile. A's window is then
	 * refused, and A puts back `complete`; B, once it has the lock, marks the product it finds
	 * `complete`, is refused too, and puts back `complete` as well. Were B to mark while A's mark
	 * stood, B would find `updating`, and its restore would put that back.
	 *
	 * @since 0.1.0
	 */
	public function test_two_refused_saves_leave_the_product_on_sale(): void {
		global $wpdb;

		$b      = $this->secondConnection();
		$saved  = $this->savedProduct();
		$probe  = null;
		$waited = null;
		$lock   = (string) $wpdb->prepare(
			'SELECT GET_LOCK( %s, %d )',
			LockService::serverLockName( $this->db->databaseName(), $this->db->prefix(), SaveProduct::LOCK_PREFIX . $saved->productId ),
			SaveProduct::LOCK_WAIT_SECONDS
		);

		$this->beforeStatement(
			'/^' . preg_quote( ProductWrites::markStatement( $this->db, $saved->productId ), '/' ) . '$/',
			function () use ( $saved, $lock, &$probe, &$waited ): void {
				$probe  = $this->saveElsewhere( $saved->postId, 'Refused second', 'fails', false );
				$waited = $this->awaitProbeWaitingOrEnd( $probe, $lock, 'User lock' );
			},
			2
		);
		$this->failInsideThePostWrite();

		$failure = $this->saveFailure( fn() => $this->save( $saved->postId, array( 'post_title' => 'Refused first' ) ) );
		$report  = null === $probe ? array() : $probe->finish();

		$this->assertSame( self::LISTENER_FAILED, $failure->getMessage() );
		$this->assertSame( 'failed', $report['outcome'] ?? null, (string) wp_json_encode( $report ) );
		$this->assertSame( 'complete', $this->committedMarker( $b, $saved->productId )[0], 'Two refused saves took a live product off sale.' );
		$this->assertSame( SellabilityReason::Sellable, $this->verdict( (int) $saved->variantId ) );
		$this->assertSame( array( 'Saved product', 'SKU-1', '1999' ), $this->committedProduct( $b, $saved ) );
		$this->assertTrue( $waited, 'The second save did not wait for the first to let go of the product\'s lock.' );
	}

	/**
	 * Tests that a save whose transaction is lost after another save completed leaves the product `updating`, never under the other's `complete`.
	 *
	 * Save A, in a probe process, holds the product's lock, marks the product and opens its window,
	 * and is held just before its relock. Save B then marks, writes and settles `complete` on
	 * another connection, as a save whose lock was lost would, without the lock. A then relocks,
	 * and a listener sends COMMIT and throws: everything A wrote so far is committed. Because the
	 * relock marks the product again, that COMMIT commits `updating`, and A's restore, which
	 * finds a mark newer than its own, leaves it: the relock is the defence when the lock is lost.
	 *
	 * @since 0.1.0
	 */
	public function test_a_save_that_loses_its_transaction_after_another_completed_leaves_the_product_updating(): void {
		$barrier = $this->secondConnection();
		$b       = $this->secondConnection();
		$saved   = $this->savedProduct();

		$this->assertSame( '1', $barrier->fetchValue( ProductWrites::BARRIER_HOLD ) );

		$probe = $this->saveElsewhere( $saved->postId, 'Lost after another save', 'loses', true );

		$this->awaitProbeWaiting( $probe, ProductWrites::BARRIER_WAIT, 'User lock' );

		$products = $this->db->table( CatalogTables::PRODUCTS );

		$b->query( 'START TRANSACTION' );
		$b->query( sprintf( "UPDATE `%s` SET generation_state = 'updating', updated_at = %s WHERE id = %d", $products, MysqlProductRepository::NEXT_INSTANT, $saved->productId ) );
		$b->query( sprintf( 'UPDATE `%s` SET price_minor = 2222 WHERE variant_id = %d', $this->db->table( CatalogTables::VARIANT_PRICES ), (int) $saved->variantId ) );
		$b->query( sprintf( "UPDATE `%s` SET generation_state = 'complete', updated_at = %s WHERE id = %d AND generation_state = 'updating'", $products, MysqlProductRepository::NEXT_INSTANT, $saved->productId ) );
		$b->query( 'COMMIT' );

		$this->assertSame( 'complete', $this->committedMarker( $b, $saved->productId )[0], 'B completed while A waited to relock.' );

		$barrier->fetchValue( ProductWrites::BARRIER_RELEASE );

		$report = $probe->finish();

		$this->assertSame( 'failed', $report['outcome'] ?? null, (string) wp_json_encode( $report ) );
		$this->assertStringContainsString( 'A listener failed after ending the transaction.', (string) ( $report['failure'] ?? '' ) );
		$this->assertSame( 'updating', $this->committedMarker( $b, $saved->productId )[0], 'A\'s committed statements sit under B\'s `complete`.' );
		$this->assertSame( SellabilityReason::Updating, $this->verdict( (int) $saved->variantId ) );
		$this->assertSame( array( 'Lost after another save', 'SKU-1', '2222' ), $this->committedProduct( $b, $saved ), 'A\'s post write was committed by its listener; B\'s price stands.' );
	}

	/**
	 * Tests that a first save of a post another writer binds meanwhile is refused alike on both servers, as a write conflict, and writes nothing.
	 *
	 * The other writer links the post to another product, committing the binding from a second
	 * connection just before this save's binding leaves, after WordPress's reads of the post
	 * opened the window's snapshot. MySQL's insert of the binding meets the duplicate; MariaDB
	 * refuses the insert of a binding committed since the snapshot (error 1020) and rolls the window
	 * back. Both answer `catalog.write_conflict`, and the post and the binding are as the other
	 * writer left them.
	 *
	 * Planted violation: in SaveProduct::firstBind(), run the window with
	 * `$this->transactions->transaction( ..., RetryPolicy::none() )` again: on MariaDB the save fails
	 * with `database.transaction_retryable`.
	 *
	 * @since 0.2.0
	 */
	public function test_a_first_save_of_a_post_bound_meanwhile_is_refused_as_a_write_conflict(): void {
		$other  = $this->savedProduct( 'SKU-OTHER', 'Other product' );
		$postId = $this->unboundPost();
		$posts  = $this->db->table( CatalogTables::PRODUCT_POSTS );
		$b      = $this->secondConnection();
		$raced  = $this->beforeStatement(
			'/^INSERT INTO `' . preg_quote( $posts, '/' ) . '`/',
			static function () use ( $b, $posts, $postId, $other ): void {
				$b->query( "INSERT INTO `$posts` ( post_id, product_id, locale, linked_at, created_at ) VALUES ( $postId, {$other->productId}, 'fr_FR', UTC_TIMESTAMP(), UTC_TIMESTAMP() )" );
			}
		);

		$failure = $this->saveFailure(
			fn() => $this->save(
				$postId,
				array( 'post_title' => 'Bound by this save' ),
				array(
					'sku'         => 'SKU-FIRST',
					'price_minor' => 500,
				)
			)
		);

		$this->assertTrue( $raced->fired, 'The other writer never committed, so nothing raced the save.' );
		$this->assertInstanceOf( CodedException::class, $failure );
		$this->assertSame( array( CatalogError::WriteConflict, array( 'post_id' => $postId ) ), array( $failure->errorCode(), $failure->context() ) );
		$this->assertSame( 'Unbound product', (string) $b->fetchValue( sprintf( 'SELECT post_title FROM `%s` WHERE ID = %d', $this->postsTable(), $postId ) ), 'The save\'s post write was kept.' );
		$this->assertSame( (string) $other->productId, (string) $b->fetchValue( "SELECT product_id FROM `$posts` WHERE post_id = $postId" ), 'The other writer\'s binding is gone.' );
		$this->assertSame( 0, $this->committedCount( $b, CatalogTables::VARIANTS, "sku = 'SKU-FIRST'" ), 'The save\'s variant was kept.' );
	}

	/**
	 * Tests a save during which the post's row changes: MySQL saves; MariaDB refuses it, writes nothing, and tells the editor the post changed meanwhile.
	 *
	 * A comment counted on the post commits from a second connection just before WordPress's update
	 * of the post leaves, after the window's first read. MySQL's update writes over the row and
	 * keeps the count. MariaDB refuses the update of a row changed since the snapshot (error 1020),
	 * inside WordPress's own query: WordPress reports `db_update_error`, the save is refused with
	 * `catalog.post_rejected`, and the server has rolled the window back. The window is never run
	 * again (other plugins' listeners run inside it): the editor saves again.
	 *
	 * Planted violation: in CatalogError::definitions(), give PostRejected its earlier message
	 * ("WordPress did not save the product's post (%1$s)."): on MariaDB the editor is not told the
	 * post changed meanwhile.
	 *
	 * @since 0.2.0
	 */
	public function test_a_save_meeting_a_change_of_the_post_saves_on_mysql_and_is_refused_on_mariadb(): void {
		global $wpdb;

		$saved   = $this->savedProduct( 'SKU-POST' );
		$posts   = $this->postsTable();
		$failure = null;
		$b       = $this->secondConnection();
		$raced   = $this->beforeStatement(
			'/^UPDATE `' . preg_quote( $posts, '/' ) . '` SET/',
			static function () use ( $b, $posts, $saved ): void {
				$b->query( "UPDATE `$posts` SET comment_count = comment_count + 1 WHERE ID = {$saved->postId}" );
			}
		);

		// On MariaDB the refusal meets WordPress's own query, which would print it to the error log.
		$suppressed = $wpdb->suppress_errors( true );

		try {
			$this->save(
				$saved->postId,
				array( 'post_title' => 'Renamed by this save' ),
				array(
					'sku'         => 'SKU-POST',
					'price_minor' => 2500,
				)
			);
		} catch ( CodedException $refused ) {
			$failure = $refused;
		} finally {
			$wpdb->suppress_errors( $suppressed );
		}

		$title = (string) $b->fetchValue( "SELECT post_title FROM `$posts` WHERE ID = {$saved->postId}" );

		$this->assertTrue( $raced->fired, 'The other request never committed, so nothing raced the save.' );
		$this->assertSame( '1', (string) $b->fetchValue( "SELECT comment_count FROM `$posts` WHERE ID = {$saved->postId}" ), 'The other request\'s count was lost.' );
		$this->assertSame( 'complete', $this->committedMarker( $b, $saved->productId )[0], 'The product was left marked.' );
		$this->assertSame( array(), $this->sleeps, 'The window ran again.' );

		if ( ServerKind::MariaDB !== $this->db->server()->kind() ) {
			$this->assertNull( $failure, 'MySQL refused the save.' );
			$this->assertSame( 'Renamed by this save', $title );

			return;
		}

		$this->assertInstanceOf( CodedException::class, $failure );
		$this->assertSame( array( CatalogError::PostRejected, array( 'wordpress_code' => 'db_update_error' ) ), array( $failure->errorCode(), $failure->context() ) );
		$this->assertStringContainsString( 'another request changed the post meanwhile: save it again', ErrorDefinition::of( $failure->errorCode() )->render( $failure->context() ) );
		$this->assertSame( 'Saved product', $title, 'The refused save wrote the post.' );
	}

	/**
	 * Returns the full name of `wp_posts`.
	 *
	 * @since 0.2.0
	 *
	 * @return string The name.
	 */
	private function postsTable(): string {
		global $wpdb;

		return $wpdb->posts;
	}
}
