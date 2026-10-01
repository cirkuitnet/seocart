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

use SEOCart\Platform\Kernel\Container;
use SEOCart\Platform\Settings\InternationalSettings;
use SEOCart\Platform\Settings\SettingsStore;
use SEOCart\Pricing\Application\CalculationRequest;
use SEOCart\Pricing\Application\Calculator;
use SEOCart\Pricing\Application\LineRequest;
use SEOCart\Pricing\Domain\PricingError;
use SEOCart\Support\Address;
use SEOCart\Support\Currency;
use SEOCart\Support\Error\CodedException;
use SEOCart\Tax\Domain\CrossZonePolicy;
use SEOCart\Tax\Domain\TaxRoundingMode;
use SEOCart\Tests\Support\KernelContainer;
use SEOCart\Tests\Support\Pricing\PricingTestCase;

/**
 * Prices a stored product with the container's calculator, and checks what it costs and when it refuses.
 *
 * The plugin ships a flat shipping rate of 5.00 in the base currency and a tax rate of 20 % on
 * every class, so a 19.99 product shipped anywhere costs 19.99 + 4.00 tax, and 5.00 + 1.00 tax
 * for shipping.
 *
 * The calculator reads the store's base currency, cross-zone policy and tax rounding mode from
 * their settings on every calculation. They are one group, so the first read primes all three
 * with one query and every later calculation in the request reads none: a calculation costs two
 * queries when the group is not primed yet, the settings and the prices, and one once it is.
 *
 * Planted violations, each shown red and removed:
 *
 * - in Calculator::calculate(), pass CrossZonePolicy::FixedNet instead of the setting's reader:
 *   the totals stay fixed-net after fixed-gross is saved;
 * - in InternationalSettings::settings(), put the tax rounding mode in a group of its own: the
 *   first calculation costs three queries, the second group primed on its own.
 *
 * @since 0.1.0
 */
final class CalculatorWiringTest extends PricingTestCase {

	/**
	 * Deletes the tax settings a test saved: this test case's writes are committed.
	 *
	 * @since 0.1.0
	 */
	public function tear_down(): void {
		$this->db->execute( 'DELETE FROM %i WHERE option_name IN ( %s, %s )', $this->db->prefix() . 'options', 'seocart_international_cross_zone_policy', 'seocart_international_tax_rounding_mode' );
		wp_cache_flush();

		parent::tear_down();
	}

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
	 * Tests that the kernel's calculator follows the cross-zone policy and the tax rounding mode stored in their settings, and what reading them costs.
	 *
	 * @group international
	 *
	 * @since 0.1.0
	 */
	public function test_the_kernel_calculator_follows_the_tax_settings(): void {
		$kernel     = $this->kernel();
		$calculator = $kernel->get( Calculator::class );
		$settings   = $kernel->get( SettingsStore::class );
		$request    = new CalculationRequest( Currency::of( 'USD' ), array( new LineRequest( 'line', $this->pricedVariant( 'SETTINGS', '19.99' ), 1 ) ), new Address( 'US' ) );

		$this->assertInstanceOf( Calculator::class, $calculator );
		$this->assertInstanceOf( SettingsStore::class, $settings );

		$defaults = $calculator->calculate( $request )->totals;

		$this->assertSame( array( CrossZonePolicy::FixedNet, TaxRoundingMode::PerLine ), array( $defaults->crossZonePolicy, $defaults->taxRoundingMode ), 'A store that never saved them calculates with the defaults.' );

		$settings->writeScalars(
			array(
				InternationalSettings::CROSS_ZONE_POLICY => CrossZonePolicy::FixedGross->value,
				InternationalSettings::TAX_ROUNDING_MODE => TaxRoundingMode::PerSubtotal->value,
			)
		);
		// A request starts with an empty cache and WordPress's autoloaded options already loaded.
		wp_cache_flush();
		wp_load_alloptions();

		$cold  = $this->captureQueries(
			static function () use ( $calculator, $request ): void {
				$calculator->calculate( $request );
			}
		);
		$warm  = $this->captureQueries(
			static function () use ( $calculator, $request ): void {
				$calculator->calculate( $request );
			}
		);
		$saved = $calculator->calculate( $request )->totals;

		$this->assertSame( array( CrossZonePolicy::FixedGross, TaxRoundingMode::PerSubtotal ), array( $saved->crossZonePolicy, $saved->taxRoundingMode ), 'The calculation follows the saved settings.' );
		$this->assertSame( array( 'fixed_gross', 'per_subtotal' ), array( $saved->toArray()['cross_zone_policy'], $saved->toArray()['tax_rounding_mode'] ) );
		$this->assertQueryCount( 2, $cold, 'Queries for a calculation before the settings group is primed: the group, then the prices' );
		$this->assertQueryCount( 1, $warm, 'Queries for a calculation once the settings group is primed: the prices alone' );
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
		$calculator = $this->kernel()->get( Calculator::class );

		$this->assertInstanceOf( Calculator::class, $calculator );

		return $calculator;
	}

	/**
	 * Returns the kernel's container, over this test's connection.
	 *
	 * @since 0.1.0
	 *
	 * @return Container The container.
	 */
	private function kernel(): Container {
		return KernelContainer::build( $this->db, $this->reporter() );
	}
}
