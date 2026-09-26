<?php
/**
 * Tests the international pricing scenarios: both cross-zone policies, both tax rounding modes, and a presentment currency
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Pricing;

use PHPUnit\Framework\TestCase;
use SEOCart\Pricing\Domain\Engine\Engine;
use SEOCart\Pricing\Domain\NoPromotions;
use SEOCart\Pricing\Domain\Quote\Quotes;
use SEOCart\Support\ConversionContext;
use SEOCart\Support\Currency;
use SEOCart\Support\Decimal;
use SEOCart\Support\RoundingMode;
use SEOCart\Tests\Support\MoneyAssertions;
use SEOCart\Tests\Support\Pricing\Inputs;
use SEOCart\Tests\Support\Pricing\ScenarioFixture;
use SEOCart\Tests\Support\Pricing\TotalsInvariants;

/**
 * Runs every scenario of `international/`, and proves what the scenarios cannot say on their own.
 *
 * Every expected figure is derived from the tax-inclusive and multi-currency rules, not from an
 * older system: there was none to mine.
 *
 * Planted violations, each shown red and removed:
 *
 * - in TaxStep::taxedAmount(), round the fixed-net amount `a ÷ (1 + r_ref)` before working out
 *   the gross at the destination: the home price of 9.99 comes to 10.00;
 * - in TaxStep::taxGroup(), tax each line on its own and add the lines up instead of taxing the
 *   group once: the two rounding modes no longer differ;
 * - in BaseEquivalentStep::pooled(), convert each member on its own instead of the pool: three
 *   lines of 0.05 EUR come to 0.15 USD, not the 0.16 their sum converts to;
 * - in Rounder::splitTaxed(), split the net and the tax of a gross amount as of a net one: the
 *   three gross-split scenarios price their shares a minor unit off (0.04, 0.03 and 0.02; 10.01
 *   and 9.99; discounts of 0.51 and 0.49);
 * - in TaxStep::keptBasis(), keep the gross of every gross-authored amount, a converted gross
 *   under fixed-net abroad included: the extreme-rate scenario's first line comes to a net of
 *   -0.01, and the real-rate scenario's lines to 0.03 and 0.84;
 * - in TaxStep::taxLines(), group lines per subtotal by tax class alone: the gross line of the
 *   grouping scenario is charged 2.40 of tax on top of its price;
 * - in BaseEquivalentStep::authoredToBase() and TotalsAssembly, convert the lines' subtotals and
 *   every adjustment as a pool of their own again, and take a line's base subtotal from that
 *   pool: the presentment cart's base subtotal, discount and shipping stop adding up to its base
 *   net, 42.72 against 42.73.
 *
 * @group international
 *
 * @since 0.1.0
 */
final class InternationalScenariosTest extends TestCase {

	use MoneyAssertions;

	/**
	 * Tests that a scenario comes to its expected figures, and that its figures add up.
	 *
	 * @since 0.1.0
	 *
	 * @dataProvider data_scenarios
	 *
	 * @param ScenarioFixture $scenario The scenario.
	 */
	public function test_the_scenario_comes_to_its_expected_figures( ScenarioFixture $scenario ): void {
		$totals = $scenario->run();

		$this->assertSame( array(), $scenario->differences( $totals ), $scenario->document['scenario'] );
		$this->assertSame( array(), TotalsInvariants::problems( $totals ), $scenario->document['scenario'] );
	}

	/**
	 * Provides the scenarios.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{ScenarioFixture}> The scenarios, by file name.
	 */
	public static function data_scenarios(): array {
		return ScenarioFixture::inFamily( 'international' );
	}

	/**
	 * Tests that the two tax rounding modes differ by one minor unit on a cart built for it, each keeping its figures whole.
	 *
	 * @since 0.1.0
	 */
	public function test_the_two_rounding_modes_differ_by_one_minor_unit(): void {
		$scenarios = self::data_scenarios();
		$perLine   = $scenarios['rounding-per-line'][0]->run();
		$perGroup  = $scenarios['rounding-per-subtotal'][0]->run();

		$this->assertSame( 1, $perGroup->summary->tax->minorUnits() - $perLine->summary->tax->minorUnits() );
		$this->assertSame( 1, $perGroup->summary->grand->minorUnits() - $perLine->summary->grand->minorUnits() );
		$this->assertSame( array(), TotalsInvariants::problems( $perGroup ) );
	}

	/**
	 * Tests that both cross-zone policies give the price as authored at home, and differ only abroad.
	 *
	 * @since 0.1.0
	 */
	public function test_both_policies_agree_at_home_and_differ_abroad(): void {
		$scenarios = self::data_scenarios();
		$home      = $scenarios['fixed-net-home'][0];

		$this->assertSame( $home->run()->toArray()['lines'], $home->run( array( 'policy' => 'fixed_gross' ) )->toArray()['lines'] );
		$this->assertMoneyEquals( Inputs::money( '9.99', 'GBP' ), $home->run()->summary->grand );

		$abroad = $scenarios['fixed-net-abroad'][0];

		$this->assertMoneyEquals( Inputs::money( '10.41', 'GBP' ), $abroad->run()->summary->grand );
		$this->assertMoneyEquals( Inputs::money( '9.99', 'GBP' ), $abroad->run( array( 'policy' => 'fixed_gross' ) )->summary->grand );
	}

	/**
	 * Tests the presentment scenario's base figures: converted in pools, so they add up, and reproducible from the context.
	 *
	 * @since 0.1.0
	 */
	public function test_a_presentment_cart_converts_once_per_pool_and_replays_exactly(): void {
		$scenario = self::data_scenarios()['presentment-frozen-rate'][0];
		$totals   = $scenario->run();
		$context  = $totals->conversionContext;

		$this->assertSame( 912, $totals->lines[1]->line->unitPrice->amount->minorUnits(), 'The converted unit price is 10.00 USD × 0.91230, rounded once.' );

		$summary = $totals->summary;

		$this->assertSame( $summary->baseNet->minorUnits(), $summary->baseSubtotal->add( $summary->baseDiscountTotal )->add( $summary->baseShippingTotal )->add( $summary->baseFeeTotal )->minorUnits(), 'Every amount is net, so the base subtotal, discount, shipping and fees add up to the base net.' );
		$this->assertSame( $summary->baseGrand->minorUnits(), $summary->baseNet->add( $summary->baseTax )->minorUnits(), 'The base net and base tax add up to the base grand total.' );
		$this->assertMoneyEquals( $context->convertToBaseMoney( $totals->summary->net, RoundingMode::HalfUp ), $totals->summary->baseNet, 'Every net is positive, so the base net is the net converted once.' );
		$this->assertMoneyEquals( $context->convertToBaseMoney( $totals->summary->tax, RoundingMode::HalfUp ), $totals->summary->baseTax );
		$this->assertSame( json_encode( $totals->toArray() ), json_encode( $scenario->run()->toArray() ), 'A replay with the same context is identical.' );
		$this->assertSame( json_encode( $totals->trace->toArray() ), json_encode( $scenario->run()->trace->toArray() ) );

		$atPar = $scenario->run(
			array(
				'context' => array(
					'base'    => 'USD',
					'rate'    => '1.00000',
					'scale'   => 5,
					'version' => 4,
				),
			)
		);

		$this->assertNotSame( $totals->summary->toArray(), $atPar->summary->toArray(), 'Another rate is another calculation.' );
		$this->assertSame( 1000, $atPar->lines[1]->line->unitPrice->amount->minorUnits() );
	}

	/**
	 * Tests, on every scenario of both families, that the base figures add up across the summary and each line.
	 *
	 * @since 0.1.0
	 *
	 * @dataProvider data_every_scenario
	 *
	 * @param ScenarioFixture $scenario The scenario.
	 */
	public function test_base_figures_add_up_across_the_summary_and_each_line( ScenarioFixture $scenario ): void {
		$this->assertSame( array(), TotalsInvariants::baseProblems( $scenario->run() ), $scenario->document['scenario'] );
	}

	/**
	 * Tests, on every scenario of both families, that no line, adjustment or component has a figure against its scope's sign.
	 *
	 * @since 0.1.0
	 *
	 * @dataProvider data_every_scenario
	 *
	 * @param ScenarioFixture $scenario The scenario.
	 */
	public function test_no_share_has_a_figure_against_its_scopes_sign( ScenarioFixture $scenario ): void {
		$this->assertSame( array(), TotalsInvariants::signProblems( $scenario->run() ), $scenario->document['scenario'] );
	}

	/**
	 * Provides every scenario of both families.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{ScenarioFixture}> The scenarios.
	 */
	public static function data_every_scenario(): array {
		return ScenarioFixture::inFamily( 'legacy-informed' ) + self::data_scenarios();
	}

	/**
	 * Tests that three small lines converted as one pool add up to the pool converted, where converted one by one they would not.
	 *
	 * @since 0.1.0
	 */
	public function test_base_figures_are_converted_in_pools_not_row_by_row(): void {
		$usd     = Currency::of( 'USD' );
		$context = new ConversionContext( $usd, Currency::of( 'EUR' ), ConversionContext::DIRECTION_BASE_TO_QUOTE, Decimal::of( '0.91230' ), 5, 'manual', 3, new \DateTimeImmutable( Inputs::AT ) );
		$lines   = array(
			Inputs::line( 'a', '0.05', taxClass: 'zero', currency: 'EUR' ),
			Inputs::line( 'b', '0.05', taxClass: 'zero', currency: 'EUR' ),
			Inputs::line( 'c', '0.05', taxClass: 'zero', currency: 'EUR' ),
		);
		$engine  = new Engine();
		$phaseA  = $engine->phaseA( Inputs::input( $lines, 'EUR', destination: null, context: $context ), new NoPromotions() );
		$totals  = $engine->phaseB( $phaseA, new Quotes( array(), Inputs::taxQuote( array( 'zero' => array() ) ), $phaseA->packagesFingerprint() ) );

		$this->assertSame( '0.16', $totals->summary->baseNet->toDecimal()->toString(), '0.15 EUR ÷ 0.91230 = 0.1644 USD, converted once.' );
		$this->assertSame( array( 6, 5, 5 ), array_map( static fn( $line ): int => $line->base->net()->minorUnits(), $totals->lines ) );
		$this->assertSame( array(), TotalsInvariants::problems( $totals ) );
	}

	/**
	 * Tests that with the identity context of a base-currency cart every base figure is the cart's figure.
	 *
	 * @since 0.1.0
	 *
	 * @dataProvider data_base_currency_scenarios
	 *
	 * @param ScenarioFixture $scenario A scenario in the base currency.
	 */
	public function test_the_identity_context_makes_every_base_figure_the_cart_figure( ScenarioFixture $scenario ): void {
		$totals = $scenario->run();

		foreach ( $totals->toArray()['lines'] as $line ) {
			$this->assertSame( array( $line['net_minor'], $line['tax_minor'], $line['gross_minor'], $line['line_subtotal_minor'], $line['line_discount_minor'] ), array( $line['base_net_minor'], $line['base_tax_minor'], $line['base_gross_minor'], $line['base_line_subtotal_minor'], $line['base_line_discount_minor'] ) );
		}

		foreach ( $totals->toArray()['adjustments'] as $adjustment ) {
			$this->assertSame( array( $adjustment['net_minor'], $adjustment['tax_minor'], $adjustment['gross_minor'], $adjustment['amount_minor'] ), array( $adjustment['base_net_minor'], $adjustment['base_tax_minor'], $adjustment['base_gross_minor'], $adjustment['base_amount_minor'] ) );
		}

		foreach ( $totals->components() as $component ) {
			$this->assertTrue( $component->amount->net()->minorUnits() === $component->base->net()->minorUnits() && $component->amount->tax()->minorUnits() === $component->base->tax()->minorUnits() );
		}

		$summary = $totals->summary->toArray();

		foreach ( array( 'subtotal', 'discount_total', 'shipping_total', 'fee_total', 'net', 'tax', 'grand' ) as $figure ) {
			$this->assertSame( $summary[ $figure . '_minor' ], $summary[ 'base_' . $figure . '_minor' ], $figure );
		}
	}

	/**
	 * Provides every scenario priced in the base currency.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{ScenarioFixture}> The scenarios.
	 */
	public static function data_base_currency_scenarios(): array {
		return array_filter( ScenarioFixture::inFamily( 'legacy-informed' ) + self::data_scenarios(), static fn( array $scenario ): bool => ! isset( $scenario[0]->document['input']['context'] ) );
	}
}
