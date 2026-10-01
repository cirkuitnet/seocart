<?php
/**
 * Tests the presentment-currency scenario on prices and a rate read from storage
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Pricing;

use SEOCart\Platform\Authorization\Actor;
use SEOCart\Pricing\Application\LineRequest;
use SEOCart\Pricing\Application\PriceResolver;
use SEOCart\Pricing\Domain\InputLine;
use SEOCart\Pricing\Infrastructure\MysqlPresentmentCurrencies;
use SEOCart\Support\Currency;
use SEOCart\Tests\Support\Pricing\PricesInCurrencies;
use SEOCart\Tests\Support\Pricing\PricingTestCase;
use SEOCart\Tests\Support\Pricing\ScenarioFixture;

/**
 * Stores the scenario's prices and its rate, reads them back as a calculation does, and requires the scenario's figures.
 *
 * The scenario prices one line explicitly in EUR and converts the other from its USD price at
 * 1 USD = 0.91230 EUR, quoted at five places in the third rate version. Here the explicit and the
 * base price are rows of the catalog's price table, the rate is the current version's row saved by
 * the real writer after two earlier versions, and both are read by the real resolver and the real
 * reader: no context is built by hand. The engine then runs with the scenario's own quotes and
 * promotion, and every expected figure must hold.
 *
 * @group international
 *
 * @since 0.1.0
 */
final class PresentmentScenarioTest extends PricingTestCase {

	use PricesInCurrencies;

	/**
	 * The current exchange-rate version, as the installation record would hold it.
	 *
	 * @since 0.1.0
	 *
	 * @var int|null
	 */
	private ?int $version = null;

	/**
	 * Creates the pricing tables.
	 *
	 * @since 0.1.0
	 */
	public function set_up(): void {
		parent::set_up();

		$this->createRateTables();
	}

	/**
	 * Tests that the scenario's figures hold on stored prices and the stored current rate.
	 *
	 * @since 0.1.0
	 */
	public function test_the_presentment_scenario_holds_on_stored_prices_and_rates(): void {
		$scenario = ScenarioFixture::inFamily( 'international' )['presentment-frozen-rate'][0];
		$input    = $scenario->document['input'];
		$rates    = self::ratesOver(
			$this->db,
			function ( int $version ): void {
				$this->version = $version;
			}
		);

		$this->enableCurrency( 'EUR' );

		foreach ( array( '0.90000', '0.95000', $input['context']['rate'] ) as $rate ) {
			$rates->saveVersion( array( self::rateTo( 'EUR', $rate ) ), Actor::user( 7 ) );
		}

		$lines = array();

		foreach ( $input['lines'] as $line ) {
			// A converted line's price is authored in the base currency only; an explicit one's in EUR.
			$authoredIn = isset( $line['priceSource'] ) && 'converted' === $line['priceSource'] ? 'USD' : 'EUR';

			$this->addPrice( (int) $line['variant'], $line['unit']['amount'], $authoredIn );

			$lines[] = new LineRequest( $line['key'], (int) $line['variant'], (int) $line['quantity'] );
		}

		$euro = ( new MysqlPresentmentCurrencies( $this->db, fn(): ?int => $this->version ) )->find( Currency::of( 'USD' ), Currency::of( 'EUR' ) );

		$this->assertNotNull( $euro );
		$this->assertSame(
			array( $input['context']['rate'], $input['context']['scale'], $input['context']['version'] ),
			array( $euro->context->rate()->toString(), $euro->context->rateScale(), $euro->context->sourceVersion() ),
			'The rate read is the current version\'s, at the scale it was quoted at.'
		);

		$prices = ( new PriceResolver( $this->products ) )->resolve( $lines, $euro );

		$this->assertSame( array(), $prices->unpriced );
		$this->assertSame(
			array(
				'explicit'  => array( 2000, 'explicit' ),
				'converted' => array( 912, 'converted' ),
			),
			array_combine(
				array_map( static fn( InputLine $line ): string => $line->key, $prices->lines ),
				array_map( static fn( InputLine $line ): array => array( $line->unitPrice->amount->minorUnits(), $line->priceSource->value ), $prices->lines )
			)
		);

		$totals = $scenario->runPricedAs( $euro, $prices->lines );

		$this->assertSame( array(), $scenario->differences( $totals ), $scenario->document['scenario'] );
		$this->assertSame( $euro->context->fingerprint(), $totals->toArray()['conversion_context'] );
	}
}
