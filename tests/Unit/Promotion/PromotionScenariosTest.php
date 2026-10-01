<?php
/**
 * Tests the legacy-informed promotion scenarios through the promotion module's own evaluator
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Promotion;

use PHPUnit\Framework\TestCase;
use SEOCart\Promotion\Domain\Evaluator;
use SEOCart\Tests\Support\Pricing\ScenarioFixture;
use SEOCart\Tests\Support\Pricing\TotalsInvariants;

/**
 * Every legacy-informed scenario with a promotion comes to its expected figures through Evaluator, byte for byte as through the scenario suite's own evaluator.
 *
 * The scenario suite prices its promotions with FactsEvaluator, which predates the promotion
 * module. Here each scenario with a promotion runs once more through the module's Evaluator: it
 * must meet every expected figure, and its totals and trace must be identical to the suite's.
 *
 * Planted violation, shown red and removed: in Evaluator::evaluate(), sort by priority the other
 * way round. The scenario with a percentage and an amount off applies them in the other order,
 * and its figures and its trace differ.
 *
 * @group reference-fixture
 *
 * @since 0.1.0
 */
final class PromotionScenariosTest extends TestCase {

	/**
	 * The research scenarios the promotion scenarios are informed by, by number.
	 *
	 * @since 0.1.0
	 *
	 * @var list<int>
	 */
	private const NUMBERS = array( 10, 11, 12, 17, 22, 26, 28 );

	/**
	 * Tests that a scenario comes to its expected figures through Evaluator, identical to the suite's run.
	 *
	 * @since 0.1.0
	 *
	 * @dataProvider data_scenarios
	 *
	 * @param ScenarioFixture $scenario The scenario.
	 */
	public function test_the_evaluator_meets_the_scenario_as_the_suite_does( ScenarioFixture $scenario ): void {
		$totals = $scenario->run( array(), new Evaluator() );
		$suite  = $scenario->run();

		$this->assertSame( array(), $scenario->differences( $totals ), $scenario->document['scenario'] );
		$this->assertSame( array(), TotalsInvariants::problems( $totals ), $scenario->document['scenario'] );
		$this->assertSame( (string) json_encode( $suite->toArray() ), (string) json_encode( $totals->toArray() ), 'The totals differ from the suite\'s.' );
		$this->assertSame( (string) json_encode( $suite->trace->toArray() ), (string) json_encode( $totals->trace->toArray() ), 'The trace differs from the suite\'s.' );
	}

	/**
	 * Tests that the scenarios with a promotion are exactly the ones informed by the promotion research scenarios.
	 *
	 * @since 0.1.0
	 */
	public function test_the_promotion_scenarios_are_the_expected_ones(): void {
		$numbers = array_map( static fn( string $name ): int => (int) $name, array_keys( self::data_scenarios() ) );

		$this->assertSame( self::NUMBERS, array_values( array_unique( $numbers ) ) );
	}

	/**
	 * Provides the legacy-informed scenarios that apply a promotion.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{ScenarioFixture}> The scenarios, by file name.
	 */
	public static function data_scenarios(): array {
		return array_filter(
			ScenarioFixture::inFamily( 'legacy-informed' ),
			static fn( array $scenario ): bool => array() !== ( $scenario[0]->document['input']['promotions'] ?? array() )
		);
	}
}
