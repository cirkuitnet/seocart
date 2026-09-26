<?php
/**
 * Tests the discount step: shares that add up, and totals that never go below zero
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Pricing;

use PHPUnit\Framework\TestCase;
use SEOCart\Pricing\Domain\AmountBasis;
use SEOCart\Pricing\Domain\PromotionEffect;
use SEOCart\Pricing\Domain\PromotionFacts;
use SEOCart\Pricing\Domain\Totals\Totals;
use SEOCart\Pricing\Domain\Totals\TotalsAdjustment;
use SEOCart\Support\Percentage;
use SEOCart\Tests\Support\Pricing\Inputs;
use SEOCart\Tests\Support\Pricing\TotalsInvariants;

/**
 * Proves that an amount off is split by largest remainder and capped, and that a percentage is capped at the line.
 *
 * Planted violations, each shown red and removed:
 *
 * - in DiscountStep::discountOrder(), round each line's share of the amount on its own instead of
 *   splitting it: 1.00 over three equal lines comes to 0.99;
 * - in DiscountStep, drop both caps: a code of 100.00 on a 9.99 cart takes the grand total to -90.01.
 *
 * @since 0.1.0
 */
final class DiscountStepTest extends TestCase {

	/**
	 * Tests that an amount off is shared by largest remainder, the leftover cent to the first line on a tie.
	 *
	 * @since 0.1.0
	 */
	public function test_an_amount_off_is_shared_by_largest_remainder(): void {
		$totals = Inputs::calculate( Inputs::input( array( Inputs::line( 'a', '3.00' ), Inputs::line( 'b', '3.00' ), Inputs::line( 'c', '3.00' ) ), destination: null, promotions: array( self::fixed( '1.00' ) ) ) );

		$this->assertSame( array( -34, -33, -33 ), self::amounts( $totals ) );
		$this->assertSame( -100, $totals->summary->discountTotal->minorUnits() );
		$this->assertSame( array(), TotalsInvariants::problems( $totals ) );
	}

	/**
	 * Tests that an amount off larger than the cart takes it to zero and no further.
	 *
	 * @since 0.1.0
	 */
	public function test_an_amount_off_never_takes_the_total_below_zero(): void {
		$totals = Inputs::calculate( Inputs::input( array( Inputs::line( 'a', '9.99' ) ), destination: null, promotions: array( self::fixed( '100.00' ) ) ) );

		$this->assertSame( array( -999 ), self::amounts( $totals ) );
		$this->assertSame( 0, $totals->summary->grand->minorUnits() );
		$this->assertSame( 0, $totals->amountDue()->minorUnits() );
	}

	/**
	 * Tests that a percentage above one hundred takes a line to zero and no further.
	 *
	 * @since 0.1.0
	 */
	public function test_a_percentage_above_a_hundred_takes_a_line_to_zero(): void {
		$promotion = new PromotionFacts( 1, 'p', 'MOST', PromotionEffect::percent( Percentage::fromString( '150' ) ), 10 );
		$totals    = Inputs::calculate( Inputs::input( array( Inputs::line( 'a', '9.99' ) ), destination: null, promotions: array( $promotion ) ) );

		$this->assertSame( array( -999 ), self::amounts( $totals ) );
		$this->assertSame( 0, $totals->summary->grand->minorUnits() );
	}

	/**
	 * Tests that a discount that rounds to nothing makes no adjustment.
	 *
	 * @since 0.1.0
	 */
	public function test_a_discount_that_comes_to_nothing_makes_no_adjustment(): void {
		$promotion = new PromotionFacts( 1, 'p', 'TEN', PromotionEffect::percent( Percentage::fromString( '10' ) ), 10 );
		$totals    = Inputs::calculate( Inputs::input( array( Inputs::line( 'a', '0.04' ) ), destination: null, promotions: array( $promotion ) ) );

		$this->assertSame( array(), $totals->adjustments );
		$this->assertSame( 0, $totals->lines[0]->lineDiscount->minorUnits() );
	}

	/**
	 * Tests that an amount off is not shared with a line authored in the other basis.
	 *
	 * @since 0.1.0
	 */
	public function test_an_amount_off_is_not_shared_across_bases(): void {
		$this->expectException( \LogicException::class );

		Inputs::calculate( Inputs::input( array( Inputs::line( 'a', '9.99', basis: AmountBasis::Gross ) ), destination: null, promotions: array( self::fixed( '1.00' ) ) ) );
	}

	/**
	 * Returns a promotion taking a fixed net amount off.
	 *
	 * @since 0.1.0
	 *
	 * @param string $amount The amount.
	 * @return PromotionFacts The promotion.
	 */
	private static function fixed( string $amount ): PromotionFacts {
		return new PromotionFacts( 1, 'p', 'OFF', PromotionEffect::fixed( Inputs::amount( $amount ) ), 10 );
	}

	/**
	 * Returns the authored amount of each adjustment, in minor units.
	 *
	 * @since 0.1.0
	 *
	 * @param Totals $totals The totals.
	 * @return list<int> The amounts.
	 */
	private static function amounts( Totals $totals ): array {
		return array_map( static fn( TotalsAdjustment $adjustment ): int => $adjustment->adjustment->authoredAmount->amount->minorUnits(), $totals->adjustments );
	}
}
