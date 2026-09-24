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
 * Planted violation: in MysqlProductRepository::restoreMark(), drop `AND updated_at = %s` (and
 * its argument): A's restore wipes B's mark, and the test fails.
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
		$markedAt  = (string) $this->db->transaction( fn(): ?string => $this->products->markUpdating( $productId ) );
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
}
