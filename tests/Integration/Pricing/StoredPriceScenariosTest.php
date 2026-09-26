<?php
/**
 * Tests legacy-informed scenarios priced end to end: from prices the catalog stored, through the calculator
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
use SEOCart\Pricing\Application\PriceResolver;
use SEOCart\Pricing\Domain\NoPromotions;
use SEOCart\Support\Address;
use SEOCart\Support\Currency;
use SEOCart\Tests\Support\Doubles\FrozenClock;
use SEOCart\Tests\Support\Doubles\PoisonedQuoters;
use SEOCart\Tests\Support\Pricing\Inputs;
use SEOCart\Tests\Support\Pricing\PricingTestCase;
use SEOCart\Tests\Support\Pricing\ScenarioFixture;

/**
 * Stores each line of a scenario as a product at its unit price, then asks the calculator for the cart.
 *
 * The scenarios are the ones a calculation without promotions or fees can express; the shipping
 * and tax quotes are the scenario's own. The figures must be the ones the engine alone comes to,
 * so reading the prices from storage and quoting between the phases change nothing.
 *
 * @group reference-fixture
 *
 * @since 0.1.0
 */
final class StoredPriceScenariosTest extends PricingTestCase {

	/**
	 * Tests that a scenario priced from stored prices comes to its expected figures.
	 *
	 * @since 0.1.0
	 *
	 * @dataProvider data_scenarios
	 *
	 * @param string $name The scenario's file name.
	 */
	public function test_a_scenario_priced_from_stored_prices_comes_to_its_figures( string $name ): void {
		$scenario = ScenarioFixture::inFamily( 'legacy-informed' )[ $name ][0];
		$input    = $scenario->document['input'];
		$lines    = array();

		foreach ( $input['lines'] as $line ) {
			$lines[] = new LineRequest( $line['key'], $this->pricedVariant( 'SCENARIO-' . $line['key'], $line['unit']['amount'] ), (int) $line['quantity'] );
		}

		$quoters     = new PoisonedQuoters( $scenario->shippingRates(), $scenario->taxQuote() );
		$calculator  = new Calculator( new PriceResolver( $this->products ), $quoters->shipping(), $quoters->tax(), new NoPromotions(), $this->db, FrozenClock::at( Inputs::AT ), static fn(): Currency => Currency::of( self::BASE_CURRENCY ) );
		$destination = $input['shipping']['destination'] ?? null;
		$calculation = $calculator->calculate( new CalculationRequest( Currency::of( $input['currency'] ), $lines, null === $destination ? null : new Address( $destination ), array(), $input['shipping']['selected'] ?? null ) );

		$this->assertSame( array(), $calculation->unpricedLines );
		$this->assertSame( array(), $scenario->differences( $calculation->totals ), $scenario->document['scenario'] );
	}

	/**
	 * Provides the scenarios without promotions, fees or a second tax class: a stored price's class is the standard one.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{string}> The scenarios' file names.
	 */
	public static function data_scenarios(): array {
		return array(
			'one product, quantity 3' => array( '01-one-product-quantity-three' ),
			'a quoted rate of zero'   => array( '34-quoted-zero-rate' ),
		);
	}
}
