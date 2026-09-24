<?php
/**
 * Tests that a rolled-back write puts back its own mark only, never another save's
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Catalog;

use SEOCart\Catalog\Application\UpdatingMark;
use SEOCart\Catalog\Domain\GenerationState;
use SEOCart\Catalog\Infrastructure\CatalogTables;
use SEOCart\Tests\Support\Catalog\CatalogTestCase;

/**
 * Two saves of one product, on two real connections.
 *
 * A marks the product and commits the mark, then opens its window, whose first statement takes
 * the product's row lock. B marks the product in its turn and waits on that lock; the server
 * shows it waiting (awaitWaiting(), no pause). A's window rolls back, B's mark commits, and only
 * then does A put back the marker it found. A's restore must change nothing: the product stays
 * `updating` under B's mark, which B's own recovery depends on.
 *
 * Two marks, too, take turns: A's mark holds the row until its transaction commits, and B's
 * mark, whose locking read waits for it, reads A's mark as the marker it replaces. Each save's
 * restore then takes back its own mark only.
 *
 * Planted violation: in MysqlProductRepository::restoreMark(), drop `AND updated_at = %s` (and
 * its argument): A's restore wipes B's mark, and both tests fail.
 *
 * @since 0.1.0
 *
 * @group concurrency
 */
final class MarkerRestoreConcurrencyTest extends CatalogTestCase {

	/**
	 * Tests that A's restore leaves the mark B committed after A's window ended.
	 *
	 * @since 0.1.0
	 */
	public function test_a_restore_leaves_a_mark_another_save_made_after_the_window(): void {
		$productId = (int) $this->storedProduct()->id();
		$products  = $this->catalogTable( CatalogTables::PRODUCTS );
		$markedAt  = (string) $this->db->transaction( fn(): ?UpdatingMark => $this->products->markUpdating( $productId ) )?->markedAt;
		$b         = $this->secondConnection();
		$bMarks    = sprintf( "UPDATE `%s` SET generation_state = 'updating', updated_at = UTC_TIMESTAMP(6) WHERE id = %d", $products, $productId );
		$failure   = null;

		$this->assertNotSame( '', $markedAt, 'A did not mark the product.' );

		try {
			$this->db->transaction(
				function () use ( $b, $bMarks, $products, $productId ): void {
					// A's window: its first statement takes the product's row lock, as a save's does.
					$this->db->execute( 'UPDATE %i SET updated_at = UTC_TIMESTAMP(6) WHERE id = %d', $products, $productId );

					$b->queryAsync( $bMarks );
					$this->awaitWaiting( $b, $bMarks, 'updating' );

					throw new \RuntimeException( 'A listener failed.' );
				}
			);
		} catch ( \RuntimeException $failed ) {
			$failure = $failed;
		}

		$this->assertSame( 'A listener failed.', $failure?->getMessage(), 'A\'s window was not rolled back.' );
		$this->assertSame( 1, $b->reap(), 'B\'s mark was not written once A\'s window ended.' );

		$bMarkedAt = $this->productRow( $productId )['updated_at'] ?? null;

		$this->assertNotSame( $markedAt, $bMarkedAt, 'B\'s mark wrote the instant A\'s did, so the restore below would prove nothing.' );

		$this->assertFalse( $this->products->restoreMark( $productId, GenerationState::Complete, $markedAt ), 'A put its marker back over B\'s mark.' );

		$row = $this->productRow( $productId );

		$this->assertSame( 'updating', $row['generation_state'] ?? null, 'B\'s mark was wiped.' );
		$this->assertSame( $bMarkedAt, $row['updated_at'] ?? null );
	}

	/**
	 * Tests that two marks of one product take turns on its row lock, and that each save's restore takes back its own mark only.
	 *
	 * @since 0.1.0
	 */
	public function test_two_marks_take_turns_and_each_restores_only_its_own(): void {
		$productId = (int) $this->storedProduct()->id();
		$products  = $this->catalogTable( CatalogTables::PRODUCTS );
		$b         = $this->secondConnection();
		$bReads    = sprintf( 'SELECT generation_state FROM `%s` WHERE id = %d FOR UPDATE', $products, $productId );

		// A marks, and keeps its transaction open while B's mark begins and waits.
		$aMark = $this->db->transaction(
			function () use ( $b, $bReads, $productId ): ?UpdatingMark {
				$mark = $this->products->markUpdating( $productId );

				$b->query( 'START TRANSACTION' );
				$b->queryAsync( $bReads );
				$this->awaitWaiting( $b, $bReads, 'statistics' );

				return $mark;
			}
		);

		// B's mark goes on once A's commits: it read A's mark as the marker it replaces.
		$bBefore = $b->reap();

		$b->query( sprintf( "UPDATE `%s` SET generation_state = 'updating', updated_at = UTC_TIMESTAMP(6) WHERE id = %d", $products, $productId ) );

		$bMarkedAt = (string) $b->fetchValue( sprintf( 'SELECT updated_at FROM `%s` WHERE id = %d', $products, $productId ) );

		$b->query( 'COMMIT' );

		$this->assertInstanceOf( UpdatingMark::class, $aMark );
		$this->assertSame( GenerationState::Complete, $aMark->before );
		$this->assertSame( 'updating', $bBefore, 'B read the marker before A\'s mark committed: the marks did not take turns.' );
		$this->assertNotSame( $aMark->markedAt, $bMarkedAt );

		$this->assertFalse( $this->products->restoreMark( $productId, $aMark->before, $aMark->markedAt ), 'A took back B\'s mark.' );
		$this->assertSame( $bMarkedAt, $this->productRow( $productId )['updated_at'] ?? null, 'A\'s restore changed the row.' );

		$this->assertTrue( $this->products->restoreMark( $productId, GenerationState::Updating, $bMarkedAt ), 'B could not take back its own mark.' );
		$this->assertSame( 'updating', $this->productRow( $productId )['generation_state'] ?? null, 'B put back the marker it found, A\'s unsettled mark.' );
	}
}
