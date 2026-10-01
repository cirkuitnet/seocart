<?php
/**
 * Tests doctor's promotion check: every use count agrees with its reserved and committed uses
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Promotion;

use SEOCart\Platform\Cli\Doctor\CheckResult;
use SEOCart\Promotion\Infrastructure\Doctor\PromotionUsageCheck;
use SEOCart\Tests\Support\Promotion\PromotionTestCase;

/**
 * A promotion whose count is one above its reserved and committed uses is one critical finding naming it; consistent promotions pass.
 *
 * Planted violations, shown red and removed: in MysqlPromotionRepository::USAGE_COUNTS, count
 * released uses too. The drifting promotion's released row then makes up its count, and the
 * store passes. The paging test names its own.
 *
 * @since 0.1.0
 */
final class PromotionUsageCheckTest extends PromotionTestCase {

	/**
	 * Tests that consistent promotions pass: counted reserved and committed uses, and released ones that no longer count.
	 *
	 * @since 0.1.0
	 */
	public function test_consistent_promotions_pass(): void {
		$promotion = $this->plantPromotion( 'FINE' );

		$this->plantPromotion( 'UNUSED' );
		$this->plantUsage( $promotion, 3001, 'reserved' );
		$this->plantUsage( $promotion, 3002, 'committed' );
		$this->plantUsage( $promotion, 3003, 'released' );
		$this->setUsed( $promotion, 2 );

		$result = $this->check()->run();

		$this->assertTrue( $result->passed, implode( "\n", $result->findings ) );
		$this->assertSame( PromotionUsageCheck::NAME, $result->check );
	}

	/**
	 * Tests that a count one above the counted uses is one critical finding naming the promotion and both figures.
	 *
	 * @since 0.1.0
	 */
	public function test_a_count_above_its_uses_is_critical(): void {
		$fine    = $this->plantPromotion( 'FINE' );
		$drifted = $this->plantPromotion( 'DRIFTED' );

		$this->plantUsage( $fine, 3004, 'committed' );
		$this->setUsed( $fine, 1 );
		$this->plantUsage( $drifted, 3005, 'reserved' );
		$this->plantUsage( $drifted, 3006, 'released' );
		$this->setUsed( $drifted, 2 );

		$result = $this->check()->run();

		$this->assertFalse( $result->passed );
		$this->assertSame( '1 promotion with a wrong use count.', $result->summary );
		$this->assertSame(
			array( sprintf( 'Critical: promotion %d counts 2 uses, but it has 1 reserved or committed usage row. The count is what holds it to its limit; a person must correct it.', $drifted ) ),
			$result->findings
		);
	}

	/**
	 * Tests that the check reads the promotions a page at a time, each page one read, up to the last promotion.
	 *
	 * With a page of one promotion, the drifting promotion is the third page; a fourth read comes
	 * back empty and ends the walk.
	 *
	 * Planted violation: in PromotionUsageCheck::run(), read only the first page: the drifting
	 * promotion is then never compared, and the store passes.
	 *
	 * @since 0.1.0
	 */
	public function test_the_check_reads_a_page_at_a_time_to_the_last_promotion(): void {
		$this->plantPromotion( 'FIRST' );
		$this->plantPromotion( 'SECOND' );

		$drifted = $this->plantPromotion( 'THIRD' );

		$this->setUsed( $drifted, 1 );

		$result = null;
		$reads  = $this->captureQueries(
			function () use ( &$result ): void {
				$result = $this->check( 1 )->run();
			}
		);

		$this->assertInstanceOf( CheckResult::class, $result );
		$this->assertSame( array( sprintf( 'Critical: promotion %d counts 1 use, but it has 0 reserved or committed usage rows. The count is what holds it to its limit; a person must correct it.', $drifted ) ), $result->findings );
		$this->assertQueryCount( 4, $reads, 'Reads of three promotions a page of one at a time' );
	}

	/**
	 * Returns the check over the test's repository.
	 *
	 * @since 0.1.0
	 *
	 * @param int $page Optional. How many promotions one read compares. Default the check's own.
	 * @return PromotionUsageCheck The check.
	 */
	private function check( int $page = PromotionUsageCheck::PAGE ): PromotionUsageCheck {
		return new PromotionUsageCheck( $this->repository, $page );
	}
}
