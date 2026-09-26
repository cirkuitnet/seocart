<?php
/**
 * Tests the legacy-informed pricing scenarios: each comes to the figures chosen for it
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Pricing;

use PHPUnit\Framework\TestCase;
use SEOCart\Tests\Support\Pricing\ScenarioFixture;
use SEOCart\Tests\Support\Pricing\TotalsInvariants;

/**
 * Runs every scenario of `legacy-informed/` through the engine and compares every expected figure.
 *
 * The expected figures were worked out by hand for correctness, never copied from an older
 * system's output; where they deliberately differ from what it produced, the scenario's `note`
 * says so in one line.
 *
 * Planted violations, each shown red and removed:
 *
 * - in Rounder, round with TowardZero instead of the calculation's mode: the first scenario's
 *   line tax, 4.947525, comes to 4.94 instead of 4.95, and every scenario with a half-up
 *   rounding fails;
 * - in DiscountStep::discountOrder(), share an amount off by what the lines cost before their
 *   discounts: a minor unit of it lands on a line a percentage already took to zero.
 *
 * @group reference-fixture
 *
 * @since 0.1.0
 */
final class LegacyInformedScenariosTest extends TestCase {

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
	 * Tests that every scenario names, in words, the older behaviour it is informed by.
	 *
	 * @since 0.1.0
	 *
	 * @dataProvider data_scenarios
	 *
	 * @param ScenarioFixture $scenario The scenario.
	 */
	public function test_the_scenario_names_its_source_in_words( ScenarioFixture $scenario ): void {
		$this->assertStringStartsWith( 'research scenario: ', $scenario->document['source'] );
		$this->assertStringNotContainsString( '§', $scenario->document['source'] );
	}

	/**
	 * Provides the scenarios.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{ScenarioFixture}> The scenarios, by file name.
	 */
	public static function data_scenarios(): array {
		return ScenarioFixture::inFamily( 'legacy-informed' );
	}
}
