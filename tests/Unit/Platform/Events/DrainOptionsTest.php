<?php
/**
 * Tests the bounds a trigger sets for a drain
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Platform\Events;

use PHPUnit\Framework\TestCase;
use SEOCart\Platform\Events\DrainOptions;

/**
 * The defaults, the two named bounds, and bounds that could never deliver anything.
 *
 * @since 0.1.0
 */
final class DrainOptionsTest extends TestCase {

	/**
	 * A request's end drains for 2 seconds; the command for 60 unless told otherwise; both with the defaults.
	 *
	 * @since 0.1.0
	 */
	public function test_the_named_bounds_and_the_defaults(): void {
		$shutdown = DrainOptions::shutdown();

		$this->assertSame( 2, $shutdown->timeBudgetSeconds );
		$this->assertSame( 50, $shutdown->batchSize );
		$this->assertSame( 5, $shutdown->maxAttempts );
		$this->assertSame( 60, $shutdown->leaseSeconds );
		$this->assertFalse( $shutdown->prune, 'The end of a request does not prune: the retention job does.' );
		$this->assertSame( 60, DrainOptions::command()->timeBudgetSeconds );
		$this->assertSame( 300, DrainOptions::command( 300 )->timeBudgetSeconds );
		$this->assertFalse( ( new DrainOptions( 20, 200, 5, 60, false ) )->prune, 'The job runner turns pruning off once its sweep prunes.' );
	}

	/**
	 * Returns bounds that are out of range.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{0: int, 1: int, 2: int, 3: int}> Budget, batch, attempts, lease.
	 */
	public static function outOfRange(): array {
		return array(
			'no budget'                  => array( 0, 50, 5, 60 ),
			'no batch'                   => array( 2, 0, 5, 60 ),
			'a batch too large'          => array( 2, 1001, 5, 60 ),
			'no attempts'                => array( 2, 50, 0, 60 ),
			'a lease within the reserve' => array( 2, 50, 5, DrainOptions::LEASE_RESERVE_SECONDS ),
		);
	}

	/**
	 * Bounds out of range are refused.
	 *
	 * @since 0.1.0
	 *
	 * @dataProvider outOfRange
	 *
	 * @param int $budget   The time budget.
	 * @param int $batch    The batch size.
	 * @param int $attempts The attempts before parking.
	 * @param int $lease    The lease.
	 */
	public function test_bounds_out_of_range_are_refused( int $budget, int $batch, int $attempts, int $lease ): void {
		$this->expectException( \InvalidArgumentException::class );

		new DrainOptions( $budget, $batch, $attempts, $lease );
	}
}
