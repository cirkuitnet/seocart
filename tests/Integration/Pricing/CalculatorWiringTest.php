<?php
/**
 * Tests the calculator as the kernel wires it: stored prices, the shipped providers, one query
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Pricing;

use SEOCart\Pricing\Application\CalculationRequest;
use SEOCart\Pricing\Application\Calculator;
use SEOCart\Pricing\Application\LineRequest;
use SEOCart\Pricing\Domain\PricingError;
use SEOCart\Support\Address;
use SEOCart\Support\Currency;
use SEOCart\Support\Error\CodedException;
use SEOCart\Tests\Support\KernelContainer;
use SEOCart\Tests\Support\Pricing\PricingTestCase;

/**
 * Prices a stored product with the container's calculator, and checks what it costs and when it refuses.
 *
 * The plugin ships a flat shipping rate of 5.00 in the base currency and a tax rate of 20 % on
 * every class, so a 19.99 product shipped anywhere costs 19.99 + 4.00 tax, and 5.00 + 1.00 tax
 * for shipping.
 *
 * @since 0.1.0
 */
final class CalculatorWiringTest extends PricingTestCase {

	/**
	 * Tests the figures of a stored product, and that a calculation costs one query.
	 *
	 * @since 0.1.0
	 */
	public function test_the_kernel_calculator_prices_a_stored_product_with_one_query(): void {
		$calculator  = $this->calculator();
		$request     = new CalculationRequest( Currency::of( 'USD' ), array( new LineRequest( 'line', $this->pricedVariant( 'WIRED', '19.99' ), 1 ) ), new Address( 'US' ) );
		$calculation = $calculator->calculate( $request );

		$log = $this->captureQueries(
			static function () use ( $calculator, $request ): void {
				$calculator->calculate( $request );
			}
		);

		$this->assertQueryCount( 1, $log, 'Queries for a calculation, once the base currency has been read' );

		$totals = $calculation->totals;

		$this->assertSame( array(), $calculation->unpricedLines );
		$this->assertSame( array( 1999, 400, 2399 ), array( $totals->lines[0]->amount->net()->minorUnits(), $totals->lines[0]->amount->tax()->minorUnits(), $totals->lines[0]->amount->gross()->minorUnits() ) );
		$this->assertSame( 'shipping:flat', $totals->adjustments[0]->source()->toString() );
		$this->assertSame( array( 500, 100 ), array( $totals->adjustments[0]->amount->net()->minorUnits(), $totals->adjustments[0]->amount->tax()->minorUnits() ) );
		$this->assertSame( 2999, $totals->amountDue()->minorUnits() );
		$this->assertSame( 'stub', $totals->components()[0]->rate->jurisdictionCode );
	}

	/**
	 * Tests that the kernel's calculator refuses to run inside a transaction, and a cart in another currency.
	 *
	 * @since 0.1.0
	 */
	public function test_the_kernel_calculator_refuses_a_transaction_and_another_currency(): void {
		$calculator = $this->calculator();
		$variant    = $this->pricedVariant( 'GUARDED', '1.00' );

		try {
			$this->db->transaction( static fn() => $calculator->calculate( new CalculationRequest( Currency::of( 'USD' ), array( new LineRequest( 'line', $variant, 1 ) ) ) ) );
			$this->fail( 'A calculation ran inside a transaction.' );
		} catch ( \LogicException $refused ) {
			$this->assertStringContainsString( 'transaction', $refused->getMessage() );
		}

		try {
			$calculator->calculate( new CalculationRequest( Currency::of( 'EUR' ), array( new LineRequest( 'line', $variant, 1 ) ) ) );
			$this->fail( 'A cart in EUR was priced in a USD store.' );
		} catch ( CodedException $refused ) {
			$this->assertSame( PricingError::CurrencyNotEnabled, $refused->errorCode() );
		}
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
}
