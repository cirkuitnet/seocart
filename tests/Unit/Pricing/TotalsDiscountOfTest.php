<?php
/**
 * Tests Totals::discountOf(): the net amount each source took off, and its base twin
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Pricing;

use PHPUnit\Framework\TestCase;
use SEOCart\Pricing\Domain\AmountBasis;
use SEOCart\Pricing\Domain\Engine\Engine;
use SEOCart\Pricing\Domain\Source;
use SEOCart\Pricing\Domain\Totals\AdjustmentType;
use SEOCart\Pricing\Domain\Totals\Totals;
use SEOCart\Tests\Support\Pricing\CartB;
use SEOCart\Tests\Support\Pricing\FactsEvaluator;
use SEOCart\Tests\Support\Pricing\ScenarioFixture;

/**
 * The discount of each source is the sum of its discounts' taxed nets, and their base nets: every share and a free-shipping discount included, whatever basis each was authored in.
 *
 * Planted violations, each shown red and removed:
 *
 * - in Totals::summarise(), add a discount to its source's figure only when it is line-scoped:
 *   the free-shipping promotion then took nothing off;
 * - in Totals::summarise(), add the discounts' authored amounts instead of their nets: the
 *   gross-authored amounts off differ from their nets, and a converted cart's authored base
 *   differs from its base net.
 *
 * @since 0.1.0
 */
final class TotalsDiscountOfTest extends TestCase {

	/**
	 * Returns Cart B and every scenario with a promotion.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{\Closure(): Totals}> Each calculation, by name.
	 */
	public static function data_calculations(): array {
		$calculations = array(
			'cart-b' => array(
				static function (): Totals {
					$engine = new Engine();
					$phaseA = $engine->phaseA( CartB::input(), new FactsEvaluator() );

					return $engine->phaseB( $phaseA, CartB::quotes( $phaseA ) );
				},
			),
		);

		foreach ( array( 'legacy-informed', 'international' ) as $family ) {
			foreach ( ScenarioFixture::inFamily( $family ) as $name => $scenario ) {
				if ( array() !== ( $scenario[0]->document['input']['promotions'] ?? array() ) ) {
					$calculations[ $family . '/' . $name ] = array( static fn(): Totals => $scenario[0]->run() );
				}
			}
		}

		return $calculations;
	}

	/**
	 * Tests that each source's discount is the sum of its discounts' taxed nets, and its base twin the sum of their base nets.
	 *
	 * @since 0.1.0
	 *
	 * @dataProvider data_calculations
	 *
	 * @param \Closure $calculate Returns the totals.
	 *
	 * @phpstan-param \Closure(): Totals $calculate
	 */
	public function test_each_source_gives_the_nets_of_its_own_discounts( \Closure $calculate ): void {
		$totals   = $calculate();
		$expected = array();
		$net      = true;

		foreach ( $totals->adjustments as $adjustment ) {
			if ( AdjustmentType::Discount === $adjustment->adjustment->type ) {
				$source              = $adjustment->source()->toString();
				$expected[ $source ] = array(
					( $expected[ $source ][0] ?? 0 ) + $adjustment->amount->net()->minorUnits(),
					( $expected[ $source ][1] ?? 0 ) + $adjustment->base->net()->minorUnits(),
				);
				$net                 = $net && AmountBasis::Net === $adjustment->adjustment->authoredAmount->basis;
			}
		}

		$this->assertNotSame( array(), $expected, 'The calculation takes nothing off, so it proves nothing.' );

		$actual = array();

		foreach ( array_keys( $expected ) as $source ) {
			$discount          = $totals->discountOf( new Source( $source ) );
			$actual[ $source ] = array( $discount->amount->minorUnits(), $discount->base->minorUnits() );
		}

		$this->assertSame( $expected, $actual );

		if ( $net ) {
			$this->assertSame( $totals->summary->discountTotal->minorUnits(), array_sum( array_column( $actual, 0 ) ), 'Every discount was authored net, so the sources add up to the summary\'s discount total.' );
		}
	}

	/**
	 * Tests that a gross amount off gives its net, not the amount authored.
	 *
	 * @since 0.1.0
	 */
	public function test_a_gross_amount_off_gives_its_net_not_its_gross(): void {
		$totals   = self::data_calculations()['international/gross-split-two-discounts-per-line'][0]();
		$authored = 0;
		$sources  = array();

		foreach ( $totals->adjustments as $adjustment ) {
			if ( AdjustmentType::Discount === $adjustment->adjustment->type ) {
				$authored                                    += $adjustment->adjustment->authoredAmount->amount->minorUnits();
				$sources[ $adjustment->source()->toString() ] = true;
			}
		}

		$taken = 0;

		foreach ( array_keys( $sources ) as $source ) {
			$taken += $totals->discountOf( new Source( $source ) )->amount->minorUnits();
		}

		$this->assertLessThan( 0, $taken );
		$this->assertGreaterThan( $authored, $taken, 'The amounts off were authored gross; what the sources took off is their net, smaller in size.' );
	}

	/**
	 * Tests that Cart B's free-shipping promotion took off the selected rate's net, and that a source that took nothing off gives zero.
	 *
	 * @since 0.1.0
	 */
	public function test_free_shipping_gives_the_rate_and_a_source_without_discounts_gives_zero(): void {
		$totals   = self::data_calculations()['cart-b'][0]();
		$shipping = $totals->discountOf( Source::promotion( CartB::promotions()[1]->uuid ) );
		$none     = $totals->discountOf( Source::promotion( '00000000-0000-7000-8000-000000000000' ) );

		$this->assertSame( array( -695, -695 ), array( $shipping->amount->minorUnits(), $shipping->base->minorUnits() ), 'The ground rate of 6.95 net, taken off.' );
		$this->assertSame( array( 0, 0 ), array( $none->amount->minorUnits(), $none->base->minorUnits() ) );
		$this->assertSame( 'USD', $none->base->currency()->code() );
	}
}
