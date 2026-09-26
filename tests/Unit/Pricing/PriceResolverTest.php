<?php
/**
 * Tests the price resolver: one read, an authored price used as it is, and no line priced by a guess
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Pricing;

use PHPUnit\Framework\TestCase;
use SEOCart\Catalog\Domain\VariantPrice;
use SEOCart\Pricing\Application\LineRequest;
use SEOCart\Pricing\Application\PriceResolver;
use SEOCart\Pricing\Application\UnpricedLine;
use SEOCart\Pricing\Domain\AmountBasis;
use SEOCart\Pricing\Domain\InputLine;
use SEOCart\Pricing\Domain\PriceSource;
use SEOCart\Support\Currency;
use SEOCart\Tests\Support\Catalog\FixedFactsRepository;
use SEOCart\Tests\Support\Pricing\Calculators;

/**
 * Proves how each line gets its unit price, basis and tax class, or is reported unpriced.
 *
 * @since 0.1.0
 */
final class PriceResolverTest extends TestCase {

	/**
	 * Tests that every line is priced from one read of both currencies, in request order.
	 *
	 * @since 0.1.0
	 */
	public function test_the_lines_are_priced_from_one_read(): void {
		$prices         = new FixedFactsRepository();
		$prices->prices = array(
			Calculators::price( 11, '10.00', 'EUR' ),
			Calculators::price( 11, '11.00' ),
			Calculators::price( 12, '9.99', 'EUR', 7, VariantPrice::GROSS ),
			Calculators::price( 13, '4.00' ),
		);
		$resolved       = ( new PriceResolver( $prices ) )->resolve(
			array( new LineRequest( 'b', 12, 1 ), new LineRequest( 'a', 11, 3 ), new LineRequest( 'c', 13, 1 ), new LineRequest( 'd', 14, 1 ), new LineRequest( 'e', 11, 1 ) ),
			Currency::of( 'EUR' ),
			Currency::of( 'USD' )
		);

		$this->assertSame( array( array( array( 12, 11, 13, 14, 11 ), array( 'EUR', 'USD' ) ) ), $prices->askedPrices );
		$this->assertSame(
			array(
				array( 'b', 999, 'gross', 'class_7', 'explicit' ),
				array( 'a', 1000, 'net', 'standard', 'explicit' ),
				array( 'e', 1000, 'net', 'standard', 'explicit' ),
			),
			array_map( static fn( InputLine $line ): array => array( $line->key, $line->unitPrice->amount->minorUnits(), $line->unitPrice->basis->value, $line->taxClass, $line->priceSource->value ), $resolved->lines )
		);
		$this->assertSame( 'EUR', $resolved->lines[0]->unitPrice->currency()->code() );
		$this->assertEquals( array( new UnpricedLine( 'c', 13, UnpricedLine::NO_PRICE_IN_CURRENCY ), new UnpricedLine( 'd', 14, UnpricedLine::UNKNOWN_VARIANT ) ), $resolved->unpriced );
		$this->assertSame( AmountBasis::Gross, $resolved->lines[0]->unitPrice->basis );
		$this->assertSame( PriceSource::Explicit, $resolved->lines[1]->priceSource );
	}

	/**
	 * Tests that no line means no read.
	 *
	 * @since 0.1.0
	 */
	public function test_no_line_reads_nothing(): void {
		$prices   = new FixedFactsRepository();
		$resolved = ( new PriceResolver( $prices ) )->resolve( array(), Currency::of( 'USD' ), Currency::of( 'USD' ) );

		$this->assertSame( array(), $prices->askedPrices );
		$this->assertSame( array(), $resolved->lines );
		$this->assertSame( array(), $resolved->unpriced );
	}
}
