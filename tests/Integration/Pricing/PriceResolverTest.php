<?php
/**
 * Tests the price resolver on stored prices: one query for any number of lines, and the cart's currency first
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Pricing;

use SEOCart\Pricing\Application\LineRequest;
use SEOCart\Pricing\Application\PriceResolver;
use SEOCart\Pricing\Application\UnpricedLine;
use SEOCart\Pricing\Domain\InputLine;
use SEOCart\Support\Currency;
use SEOCart\Tests\Support\Pricing\PricingTestCase;

/**
 * Prices lines from the catalog's price table through the one repository read.
 *
 * Planted violation, shown red and removed: in PriceResolver::resolve(), read each line's price on
 * its own. Ten lines then cost ten queries, and the first test fails on the count.
 *
 * @since 0.1.0
 */
final class PriceResolverTest extends PricingTestCase {

	/**
	 * Tests that ten lines are priced with one query, which writes nothing.
	 *
	 * @since 0.1.0
	 */
	public function test_ten_lines_are_priced_with_one_query_that_changes_nothing(): void {
		$lines = array();

		foreach ( range( 1, 10 ) as $n ) {
			$lines[] = new LineRequest( 'line-' . $n, $this->pricedVariant( 'PRICE-' . $n, $n . '.99' ), $n );
		}

		$resolver = new PriceResolver( $this->products );
		$before   = $this->pricesFingerprint();
		$resolved = null;
		$log      = $this->captureQueries(
			static function () use ( $resolver, $lines, &$resolved ): void {
				$resolved = $resolver->resolve( $lines, Currency::of( 'USD' ), Currency::of( 'USD' ) );
			}
		);

		$this->assertQueryCount( 1, $log, 'Queries to price ten lines' );
		$this->assertNotNull( $resolved );
		$this->assertSame( array(), $resolved->unpriced );
		$this->assertSame(
			array( 199, 299, 399, 499, 599, 699, 799, 899, 999, 1099 ),
			array_map( static fn( InputLine $line ): int => $line->unitPrice->amount->minorUnits(), $resolved->lines )
		);
		$this->assertSame( $before, $this->pricesFingerprint(), 'Pricing writes nothing.' );
	}

	/**
	 * Tests that a price authored in the cart's currency is used, and that a variant priced only in the base currency is reported, not converted.
	 *
	 * @group international
	 *
	 * @since 0.1.0
	 */
	public function test_a_price_in_the_carts_currency_is_used_and_none_is_derived(): void {
		$both     = $this->pricedVariant( 'BOTH', '11.00' );
		$baseOnly = $this->pricedVariant( 'BASE-ONLY', '7.00' );

		$this->addPrice( $both, '10.00', 'EUR' );

		$resolved = ( new PriceResolver( $this->products ) )->resolve(
			array( new LineRequest( 'both', $both, 1 ), new LineRequest( 'base-only', $baseOnly, 1 ), new LineRequest( 'gone', $baseOnly + 1000, 1 ) ),
			Currency::of( 'EUR' ),
			Currency::of( 'USD' )
		);

		$this->assertCount( 1, $resolved->lines );
		$this->assertSame( 'EUR 1000', $resolved->lines[0]->unitPrice->amount->currency()->code() . ' ' . $resolved->lines[0]->unitPrice->amount->minorUnits() );
		$this->assertEquals(
			array( new UnpricedLine( 'base-only', $baseOnly, UnpricedLine::NO_PRICE_IN_CURRENCY ), new UnpricedLine( 'gone', $baseOnly + 1000, UnpricedLine::UNKNOWN_VARIANT ) ),
			$resolved->unpriced
		);
	}
}
