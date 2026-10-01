<?php
/**
 * Tests the kernel's calculator with stored promotions: the codes' discounts, and what the codes cost in queries
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Promotion;

use SEOCart\Pricing\Application\CalculationRequest;
use SEOCart\Pricing\Application\Calculator;
use SEOCart\Pricing\Application\LineRequest;
use SEOCart\Pricing\Domain\PromotionEvaluator;
use SEOCart\Pricing\Domain\Source;
use SEOCart\Promotion\Domain\Evaluator;
use SEOCart\Promotion\Infrastructure\PromotionTables;
use SEOCart\Support\Address;
use SEOCart\Support\Currency;
use SEOCart\Tests\Support\KernelContainer;
use SEOCart\Tests\Support\Pricing\PricingTestCase;
use SEOCart\Tests\Support\Promotion\PlantsPromotions;

/**
 * The kernel wires the promotion module into the calculator: a stored code takes its discount off, and codes cost one query however many there are, none when there are none.
 *
 * The plugin ships a flat shipping rate of 5.00 and a tax rate of 20 % on every class.
 *
 * Planted violations, each shown red and removed:
 *
 * - in Modules::pricingRegister(), give the calculator no promotion codes: the stored code is
 *   traced as unknown, the cart keeps its full price, and the first test fails;
 * - in PromotionResolver::forCodes(), read the promotions one code at a time: three codes cost
 *   three queries, and the budget test fails.
 *
 * @group performance
 *
 * @since 0.1.0
 */
final class PromotionCalculationTest extends PricingTestCase {

	use PlantsPromotions;

	/**
	 * Creates the promotion tables beside the catalog's.
	 *
	 * @since 0.1.0
	 */
	public function set_up(): void {
		parent::set_up();

		$this->createPromotionTables();
	}

	/**
	 * Tests that the kernel evaluates promotions with the promotion module, and that a stored code and free shipping take their discounts off.
	 *
	 * @since 0.1.0
	 */
	public function test_the_kernel_calculator_applies_stored_codes(): void {
		$container = KernelContainer::build( $this->db, $this->reporter() );
		$variant   = $this->pricedVariant( 'CODED', '20.00' );
		$percent   = $this->plantPromotion( 'SAVE10' );
		$shipping  = $this->plantPromotion(
			'SHIPFREE',
			array(
				'effect_kind'                 => 'free_shipping',
				'effect_percent_micropercent' => null,
			)
		);

		$this->assertInstanceOf( Evaluator::class, $container->get( PromotionEvaluator::class ) );

		$totals = $this->calculator()->calculate( new CalculationRequest( Currency::of( 'USD' ), array( new LineRequest( 'line', $variant, 1 ) ), new Address( 'US' ), array( 'SAVE10', 'SHIPFREE' ) ) )->totals;

		$this->assertSame( array( -200, -500 ), array( $totals->discountOf( Source::promotion( $this->uuidOf( $percent ) ) )->amount->minorUnits(), $totals->discountOf( Source::promotion( $this->uuidOf( $shipping ) ) )->amount->minorUnits() ) );
		$this->assertSame( -700, $totals->summary->discountTotal->minorUnits() );
		$this->assertSame( 2160, $totals->amountDue()->minorUnits(), '18.00 and 3.60 tax; shipping 5.00 and its free-shipping discount net to zero.' );
	}

	/**
	 * Tests that a cart without codes costs no query for promotions, and one with three codes and three lines costs one.
	 *
	 * @since 0.1.0
	 */
	public function test_codes_cost_one_query_however_many_and_none_without(): void {
		$calculator = $this->calculator();
		$lines      = array(
			new LineRequest( 'a', $this->pricedVariant( 'BUDGET-A', '10.00' ), 1 ),
			new LineRequest( 'b', $this->pricedVariant( 'BUDGET-B', '20.00' ), 2 ),
			new LineRequest( 'c', $this->pricedVariant( 'BUDGET-C', '30.00' ), 3 ),
		);

		$this->plantPromotion( 'ONE' );
		$this->plantPromotion( 'TWO', array( 'effect_percent_micropercent' => 5000000 ) );
		$this->plantPromotion(
			'THREE',
			array(
				'effect_kind'                 => 'free_shipping',
				'effect_percent_micropercent' => null,
			)
		);

		$without = new CalculationRequest( Currency::of( 'USD' ), $lines, new Address( 'US' ) );
		$with    = new CalculationRequest( Currency::of( 'USD' ), $lines, new Address( 'US' ), array( 'ONE', 'TWO', 'THREE' ) );

		// The base currency is read once, on the first calculation; the budget is what each one costs after.
		$calculator->calculate( $without );

		$plain = $this->captureQueries( static fn() => $calculator->calculate( $without ) );
		$coded = $this->captureQueries( static fn() => $calculator->calculate( $with ) );

		$this->assertQueryCount( 1, $plain, 'Queries for a calculation without codes: the prices' );
		$this->assertQueryCount( 2, $coded, 'Queries for a calculation with three codes: the prices and the promotions' );
		$this->assertQueryCount( 1, $coded->forTable( $this->db->table( PromotionTables::PROMOTIONS ) ), 'Queries of the promotions for three codes' );
	}

	/**
	 * Returns the calculator as the kernel wires it, over this test's connection.
	 *
	 * @since 0.1.0
	 *
	 * @return Calculator The calculator.
	 */
	private function calculator(): Calculator {
		$calculator = KernelContainer::build( $this->db, $this->reporter() )->get( Calculator::class );

		$this->assertInstanceOf( Calculator::class, $calculator );

		return $calculator;
	}

	/**
	 * Returns a planted promotion's uuid.
	 *
	 * @since 0.1.0
	 *
	 * @param int $promotionId The promotion.
	 * @return string The uuid.
	 */
	private function uuidOf( int $promotionId ): string {
		return (string) $this->db->fetchValue( 'SELECT uuid FROM %i WHERE id = %d', $this->db->table( PromotionTables::PROMOTIONS ), $promotionId );
	}
}
