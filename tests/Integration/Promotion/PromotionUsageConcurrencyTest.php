<?php
/**
 * Tests that a promotion's usage limit holds against concurrent orders, on two real connections
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Promotion;

use SEOCart\Promotion\Application\PromotionError;
use SEOCart\Promotion\Infrastructure\MysqlPromotionRepository;
use SEOCart\Promotion\Infrastructure\PromotionTables;
use SEOCart\Support\Error\CodedException;
use SEOCart\Tests\Support\Promotion\PromotionTestCase;

/**
 * Two orders racing for the last use of a promotion: exactly one gets it.
 *
 * Connection A is the usage ledger over wpdb. Connection B is another request: first it sends
 * the repository's own claim, built from its constant, at the moment A is about to record its
 * use, and the server shows B waiting for A's lock on the promotion; then it runs the whole
 * ledger over a second connection. Nothing waits on the clock.
 *
 * Planted violation, shown red and removed: in MysqlPromotionRepository::CLAIM, drop
 * `AND ( usage_limit IS NULL OR used < usage_limit )`. B's claim then counts a use that is not
 * there: it reaps one row instead of none, and the promotion ends with `used` 2 (3 once B's
 * second run also gets through) on a limit of 1.
 *
 * @group concurrency
 *
 * @since 0.1.0
 */
final class PromotionUsageConcurrencyTest extends PromotionTestCase {

	/**
	 * Tests that of two orders claiming the last use, the second is refused and changes nothing.
	 *
	 * @since 0.1.0
	 */
	public function test_two_orders_claiming_the_last_use_leave_exactly_one(): void {
		$promotion = $this->plantPromotion( 'LAST', array( 'usage_limit' => 1 ) );
		$b         = $this->secondConnection();
		$claim     = $this->raw( MysqlPromotionRepository::CLAIM, PromotionTables::PROMOTIONS, $promotion );
		$ours      = self::claimOf( $promotion, 1001 );

		$raced = $this->beforeStatement(
			'/^' . preg_quote( $this->raw( MysqlPromotionRepository::INSERT_USAGE, PromotionTables::USAGE, $promotion, 1001, 0, '', -100, 'USD', -100, 'USD' ), '/' ) . '$/',
			function () use ( $b, $claim ): void {
				$b->queryAsync( $claim );
				$this->awaitWaiting( $b, $claim, 'updating' );
			}
		);

		$this->db->transaction( fn() => $this->usage->claim( array( $ours ) ) );

		$this->assertTrue( $raced->fired, 'B never raced A.' );
		$this->assertSame( 0, $b->reap(), 'B counted the use A had just claimed.' );

		try {
			$this->claimOnSecondRunner( self::claimOf( $promotion, 1002 ) );
			$this->fail( 'A second order was given the last use.' );
		} catch ( CodedException $refused ) {
			$this->assertSame( PromotionError::LimitReached, $refused->errorCode() );
			$this->assertSame( array(), $refused->context(), 'The refusal is constant-shaped.' );
		}

		$this->assertSame( 1, $this->committedUsed( $b, $promotion ) );
		$this->assertSame( array( '1001:reserved' ), $this->committedUsage( $b, $promotion ) );
	}
}
