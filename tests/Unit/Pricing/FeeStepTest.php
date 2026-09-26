<?php
/**
 * Tests the fee step: a fee on its declared base, taxed only when it says so
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Pricing;

use PHPUnit\Framework\TestCase;
use SEOCart\Pricing\Domain\AdjustmentBase;
use SEOCart\Pricing\Domain\AmountBasis;
use SEOCart\Pricing\Domain\FeeDefinition;
use SEOCart\Pricing\Domain\PromotionEffect;
use SEOCart\Pricing\Domain\PromotionFacts;
use SEOCart\Pricing\Domain\Taxability;
use SEOCart\Support\Percentage;
use SEOCart\Tests\Support\Pricing\Inputs;

/**
 * Proves that a percentage fee follows its base, a fixed fee is charged as authored, and an untaxed fee has no tax.
 *
 * @since 0.1.0
 */
final class FeeStepTest extends TestCase {

	/**
	 * Tests a percentage of the shipping rate, which follows the rate after its discount.
	 *
	 * @since 0.1.0
	 */
	public function test_a_percentage_fee_on_shipping_follows_the_rate_after_its_discount(): void {
		$fee   = new FeeDefinition( 'insurance', Percentage::fromString( '10' ), AdjustmentBase::SelectedShippingRate, Taxability::taxable( 'standard' ) );
		$rates = array( Inputs::shippingRate( 'ground', '7.50' ) );
		$line  = array( Inputs::line( 'a', '10.00' ) );

		$charged = Inputs::calculate( Inputs::input( $line, fees: array( $fee ) ), $rates );
		$free    = Inputs::calculate( Inputs::input( $line, fees: array( $fee ), promotions: array( new PromotionFacts( 1, 'p', '', PromotionEffect::freeShipping(), 10 ) ) ), $rates );

		$this->assertSame( 75, $charged->adjustments[1]->adjustment->authoredAmount->amount->minorUnits() );
		$this->assertSame( AdjustmentBase::SelectedShippingRate, $charged->adjustments[1]->adjustment->calculationBase );
		$this->assertSame( 75, $charged->summary->feeTotal->minorUnits() );
		$this->assertCount( 2, $free->adjustments, 'A fee of nothing makes no adjustment.' );
	}

	/**
	 * Tests that a fixed fee that is not taxable carries no tax and no component, whatever the destination.
	 *
	 * @since 0.1.0
	 */
	public function test_an_untaxed_fixed_fee_carries_no_tax(): void {
		$fee    = new FeeDefinition( 'handling', Inputs::amount( '2.00' ), AdjustmentBase::SubtotalAfterDiscounts, Taxability::notTaxable() );
		$totals = Inputs::calculate( Inputs::input( array( Inputs::line( 'a', '10.00' ) ), destination: null, fees: array( $fee ) ) );
		$charge = $totals->adjustments[0];

		$this->assertSame( array( 200, 0, 200 ), array( $charge->amount->net()->minorUnits(), $charge->amount->tax()->minorUnits(), $charge->amount->gross()->minorUnits() ) );
		$this->assertSame( array(), $charge->components );
		$this->assertSame( 'fee:handling', $charge->source()->toString() );
	}

	/**
	 * Tests that a percentage of lines authored in both bases is refused: they have no common sum.
	 *
	 * @since 0.1.0
	 */
	public function test_a_percentage_fee_on_lines_of_both_bases_is_refused(): void {
		$fee = new FeeDefinition( 'service', Percentage::fromString( '2' ), AdjustmentBase::SubtotalAfterDiscounts, Taxability::notTaxable() );

		$this->expectException( \LogicException::class );

		Inputs::calculate( Inputs::input( array( Inputs::line( 'a', '10.00' ), Inputs::line( 'b', '10.00', basis: AmountBasis::Gross ) ), destination: null, fees: array( $fee ) ) );
	}
}
