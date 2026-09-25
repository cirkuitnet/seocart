<?php
/**
 * Tests QueryPlan::keepsRuleComfortably(): the margin that keeps a boundary estimate from flipping a run
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Support\QueryPlan;

use PHPUnit\Framework\TestCase;
use SEOCart\Tests\Support\QueryPlan\QueryPlan;
use SEOCart\Tests\Support\QueryPlan\Statement;

/**
 * QueryPlan::keepsRuleComfortably() is what QueryPlanTest asks before it lets a run's plan mark
 * an allow-list entry stale: not "does this plan keep the rule" alone, which a boundary estimate
 * can answer differently between two runs of the same query on the same data, but "does it keep
 * the rule with STALE_MARGIN of MOST_ROWS to spare."
 *
 * Runs with no WordPress and no database. judge() takes EXPLAIN's rows as a plain array, and a
 * table's row count from a callable, both fabricated here.
 *
 * @since 0.1.0
 */
final class QueryPlanTest extends TestCase {

	/**
	 * Builds a judged plan for one access to a 10000-row table, `LARGE_TABLE`'s own threshold.
	 *
	 * @since 0.1.0
	 *
	 * @param string $type The access's EXPLAIN type.
	 * @param int    $rows The access's estimated rows.
	 * @return QueryPlan The judged plan.
	 */
	private static function planFor( string $type, int $rows ): QueryPlan {
		$statement = new Statement( 'SELECT id FROM wp_seocart_products WHERE id > 5', 'wp_' );

		return QueryPlan::judge(
			$statement,
			array(
				array(
					'table' => 'wp_seocart_products',
					'type'  => $type,
					'key'   => 'PRIMARY',
					'rows'  => $rows,
				),
			),
			static function ( string $table ): int {
				unset( $table );

				return 10000;
			}
		);
	}

	/**
	 * Tests that a plan whose estimate breaks the rule outright never counts as comfortable.
	 *
	 * @since 0.1.0
	 */
	public function test_a_plan_that_breaks_the_rule_is_not_comfortable(): void {
		$plan = self::planFor( 'range', QueryPlan::MOST_ROWS + 1 );

		$this->assertNotSame( array(), $plan->breaches );
		$this->assertFalse( $plan->keepsRuleComfortably() );
	}

	/**
	 * Tests the boundary the margin exists for: a plan whose estimate keeps the rule, but by so
	 * little that InnoDB's own run-to-run variance could put the next run's estimate over it, is
	 * not comfortable either.
	 *
	 * Plant: keepsRuleComfortably() without the margin — returning true whenever breaches is
	 * empty — would call this plan comfortable, and a run where the same query's next estimate
	 * lands at MOST_ROWS + 1 would report its allow-list entry stale on this run for no reason.
	 *
	 * @since 0.1.0
	 */
	public function test_a_plan_that_barely_keeps_the_rule_is_not_comfortable(): void {
		$plan = self::planFor( 'range', QueryPlan::MOST_ROWS );

		$this->assertSame( array(), $plan->breaches, 'This plan keeps the rule outright.' );
		$this->assertFalse( $plan->keepsRuleComfortably(), 'Plant: without the margin, this plan reads as comfortable, and its allow-list entry would be reported stale.' );
	}

	/**
	 * Tests that a plan whose estimate keeps the rule with real room to spare is comfortable.
	 *
	 * @since 0.1.0
	 */
	public function test_a_plan_well_under_the_rule_is_comfortable(): void {
		$plan = self::planFor( 'range', (int) ( QueryPlan::MOST_ROWS * QueryPlan::STALE_MARGIN ) - 1 );

		$this->assertSame( array(), $plan->breaches );
		$this->assertTrue( $plan->keepsRuleComfortably() );
	}

	/**
	 * Tests that a full scan is never comfortable, however few rows EXPLAIN estimates for it.
	 *
	 * @since 0.1.0
	 */
	public function test_a_full_scan_is_never_comfortable(): void {
		$plan = self::planFor( 'ALL', 1 );

		$this->assertNotSame( array(), $plan->breaches );
		$this->assertFalse( $plan->keepsRuleComfortably() );
	}
}
