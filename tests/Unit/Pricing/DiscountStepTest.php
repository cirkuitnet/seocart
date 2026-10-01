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
 * - in DiscountStep, drop both caps: a code of 100.00 on a 9.99 cart takes the grand total to -90.01;
 * - in DiscountStep::discountOrder(), weigh the lines by their amounts as authored, whatever their
 *   basis: 2.00 net off a 12.00 gross and a 10.00 net line comes to 1.31 and 0.91, not 1.20 and 1.00.
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
	 * Tests that a net amount off takes the same net off a gross line as off a net line, each share in its line's basis, for a taxed and an exempt customer.
	 *
	 * @since 0.1.0
	 */
	public function test_an_amount_off_takes_the_same_net_off_lines_of_either_basis(): void {
		$lines  = array( Inputs::line( 'a', '12.00', basis: AmountBasis::Gross ), Inputs::line( 'b', '10.00' ) );
		$tax    = Inputs::taxQuote( array( 'standard' => array( Inputs::rate( '20' ) ) ) );
		$taxed  = Inputs::calculate( Inputs::input( $lines, destination: null, promotions: array( self::fixed( '2.00' ) ) ), taxQuote: $tax );
		$exempt = Inputs::calculate( Inputs::input( $lines, destination: null, promotions: array( self::fixed( '2.00' ) ), exempt: true ), taxQuote: $tax );

		$this->assertSame( array( -120, -100 ), self::amounts( $taxed ), 'The lines weigh 10.00 net each, so each takes 1.00 net: 1.20 off the gross price, 1.00 off the net one.' );
		$this->assertSame( array( 'gross', 'net' ), array_map( static fn( TotalsAdjustment $adjustment ): string => $adjustment->adjustment->authoredAmount->basis->value, $taxed->adjustments ) );
		$this->assertSame( array( 900, 900 ), array_map( static fn( $line ): int => $line->amount->net()->minorUnits(), $taxed->lines ) );
		$this->assertSame( 1800, $exempt->summary->grand->minorUnits(), 'The exempt customer gets the same 2.00 off the 20.00 it pays.' );
		$this->assertSame( array(), TotalsInvariants::problems( $taxed ) );
		$this->assertSame( array(), TotalsInvariants::problems( $exempt ) );
	}

	/**
	 * Tests that an amount off that covers lines of both bases takes each whole, with no conversion to round it.
	 *
	 * @since 0.1.0
	 */
	public function test_an_amount_off_covering_lines_of_both_bases_takes_each_whole(): void {
		$lines  = array( Inputs::line( 'a', '9.99', basis: AmountBasis::Gross ), Inputs::line( 'b', '10.00' ) );
		$totals = Inputs::calculate( Inputs::input( $lines, destination: null, promotions: array( self::fixed( '100.00' ) ) ), taxQuote: Inputs::taxQuote( array( 'standard' => array( Inputs::rate( '20' ) ) ) ) );

		$this->assertSame( array( -999, -1000 ), self::amounts( $totals ) );
		$this->assertSame( 0, $totals->summary->grand->minorUnits() );
		$this->assertSame( array(), TotalsInvariants::problems( $totals ) );
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
