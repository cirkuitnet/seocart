<?php
/**
 * Tests the retention sweep of the log table
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Logging;

use SEOCart\Platform\Logging\LogRetention;
use SEOCart\Tests\Support\Logging\LogsTestCase;
use SEOCart\Tests\Support\SecondConnection;

/**
 * The sweep deletes lines past the period, oldest first, one bounded batch per call, and nothing younger.
 *
 * Lines are planted through connection B with `created_at` set on the database clock, the
 * clock the sweep compares with.
 *
 * Planted violations, each confirmed red then removed: in LogRetention::sweep(), compare with
 * `>` instead of `<` (the young lines go); drop `LIMIT %d` (the first batch takes all five).
 *
 * @since 0.1.0
 */
final class LogRetentionTest extends LogsTestCase {

	/**
	 * Tests that the default period is the catalog's 30 days, and that the sweep works in batches, oldest first.
	 *
	 * @since 0.1.0
	 */
	public function test_lines_past_thirty_days_go_in_batches_and_younger_ones_stay(): void {
		$b = $this->secondConnection();

		foreach ( array( 45, 40, 35, 32, 31 ) as $days ) {
			$this->plantLine( $b, "old.{$days}", sprintf( 'UTC_TIMESTAMP(6) - INTERVAL %d DAY', $days ) );
		}

		$this->plantLine( $b, 'young.29', 'UTC_TIMESTAMP(6) - INTERVAL 29 DAY' );
		$this->plantLine( $b, 'young.now', 'UTC_TIMESTAMP(6)' );

		$sweep = new LogRetention( $this->db );

		$this->assertSame( 2, $sweep->sweep( 2 ) );
		$this->assertSame( array( 'old.35', 'old.32', 'old.31', 'young.29', 'young.now' ), array_column( $this->lines(), 'machine_code' ), 'The oldest went first.' );
		$this->assertSame( 2, $sweep->sweep( 2 ) );
		$this->assertSame( 1, $sweep->sweep( 2 ), 'A short batch: nothing past the period is left.' );
		$this->assertSame( 0, $sweep->sweep( 2 ) );
		$this->assertSame( array( 'young.29', 'young.now' ), array_column( $this->lines(), 'machine_code' ) );
	}

	/**
	 * Tests that a given period replaces the default, and that a bad period or batch is refused.
	 *
	 * @since 0.1.0
	 */
	public function test_a_given_period_is_used_and_bad_arguments_are_refused(): void {
		$b = $this->secondConnection();

		$this->plantLine( $b, 'old.2h', 'UTC_TIMESTAMP(6) - INTERVAL 2 HOUR' );
		$this->plantLine( $b, 'young.30m', 'UTC_TIMESTAMP(6) - INTERVAL 30 MINUTE' );

		$this->assertSame( 1, ( new LogRetention( $this->db, 'PT1H' ) )->sweep( 100 ) );
		$this->assertSame( array( 'young.30m' ), array_column( $this->lines(), 'machine_code' ) );

		foreach ( array( 'thirty days', 'PT0S' ) as $period ) {
			try {
				new LogRetention( $this->db, $period );
				$this->fail( "The period {$period} was accepted." );
			} catch ( \InvalidArgumentException $refused ) {
				$this->assertStringContainsString( $period, $refused->getMessage() );
			}
		}

		$this->expectException( \InvalidArgumentException::class );

		( new LogRetention( $this->db ) )->sweep( 0 );
	}

	/**
	 * Inserts a line through B.
	 *
	 * @since 0.1.0
	 *
	 * @param SecondConnection $b         Connection B.
	 * @param string           $code      The line's code, which names it in the assertions.
	 * @param string           $createdAt A SQL expression for `created_at`.
	 */
	private function plantLine( SecondConnection $b, string $code, string $createdAt ): void {
		$b->query(
			sprintf(
				"INSERT INTO `%s` ( level, channel, machine_code, message, correlation_id, created_at ) VALUES ( 'info', 'old', '%s', 'Planted.', '00000000-0000-7000-8000-000000000001', %s )",
				$this->logsTable(),
				$code,
				$createdAt
			)
		);
	}
}
