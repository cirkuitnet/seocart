<?php
/**
 * Tests the price resolver: one read, an authored price used as it is, a base price converted only where allowed, and no line priced by a guess
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
use SEOCart\Pricing\Application\PresentmentCurrency;
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
 * Planted violations, each shown red and removed:
 *
 * - in PriceResolver::resolve(), try the converted price before the explicit one, so a variant
 *   with a price in the cart's currency is converted from its base price too. Its 10.00 EUR becomes
 *   11.00 USD × 0.91230 = 10.04 EUR, and the explicit-wins test fails on that line;
 * - in PriceResolver::converted(), ignore whether the currency allows conversion: the base-only
 *   variant is priced at 9.12 EUR, and the without-conversion test fails.
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
			Calculators::presentment( 'EUR', '0.91230', false )
		);

		$this->assertSame( array( array( array( 12, 11, 13, 14, 11 ), array( 'EUR', 'USD' ) ) ), $prices->askedPrices );
		$this->assertSame(
			array(
				array( 'b', 999, 'gross', 'class_7', 'explicit' ),
				array( 'a', 1000, 'net', 'standard', 'explicit' ),
				array( 'e', 1000, 'net', 'standard', 'explicit' ),
			),
			self::summary( $resolved->lines )
		);
		$this->assertSame( 'EUR', $resolved->lines[0]->unitPrice->currency()->code() );
		$this->assertEquals( array( new UnpricedLine( 'c', 13, UnpricedLine::NO_PRICE_IN_CURRENCY ), new UnpricedLine( 'd', 14, UnpricedLine::UNKNOWN_VARIANT ) ), $resolved->unpriced );
		$this->assertSame( AmountBasis::Gross, $resolved->lines[0]->unitPrice->basis );
		$this->assertSame( PriceSource::Explicit, $resolved->lines[1]->priceSource );
	}

	/**
	 * Tests that a price authored in the cart's currency wins, and a variant priced only in the base currency is converted at the rate, rounded once, in its own basis and class.
	 *
	 * @group international
	 *
	 * @since 0.1.0
	 */
	public function test_an_explicit_price_wins_and_a_base_price_is_converted_at_the_rate(): void {
		$prices         = new FixedFactsRepository();
		$prices->prices = array(
			Calculators::price( 21, '10.00', 'EUR' ),
			Calculators::price( 21, '11.00' ),
			Calculators::price( 22, '10.00' ),
			Calculators::price( 23, '9.99', 'USD', 7, VariantPrice::GROSS ),
		);
		$resolved       = ( new PriceResolver( $prices ) )->resolve(
			array( new LineRequest( 'explicit', 21, 1 ), new LineRequest( 'converted', 22, 2 ), new LineRequest( 'gross', 23, 1 ), new LineRequest( 'unknown', 24, 1 ) ),
			Calculators::presentment( 'EUR', '0.91230' )
		);

		$this->assertSame( array( array( array( 21, 22, 23, 24 ), array( 'EUR', 'USD' ) ) ), $prices->askedPrices, 'Both currencies in one read.' );
		$this->assertSame(
			array(
				array( 'explicit', 1000, 'net', 'standard', 'explicit' ),
				// 10.00 × 0.91230 = 9.1230, rounded half up: 9.12.
				array( 'converted', 912, 'net', 'standard', 'converted' ),
				// 9.99 × 0.91230 = 9.113877: 9.11, still gross and still in its class.
				array( 'gross', 911, 'gross', 'class_7', 'converted' ),
			),
			self::summary( $resolved->lines )
		);
		$this->assertSame( array( 'EUR', 'EUR', 'EUR' ), array_map( static fn( InputLine $line ): string => $line->unitPrice->currency()->code(), $resolved->lines ) );
		$this->assertEquals( array( new UnpricedLine( 'unknown', 24, UnpricedLine::UNKNOWN_VARIANT ) ), $resolved->unpriced );
	}

	/**
	 * Tests that a converted price is rounded by the currency's rule first, then to its cash step.
	 *
	 * @group international
	 *
	 * @since 0.1.0
	 */
	public function test_a_converted_price_is_rounded_to_the_cash_step(): void {
		$prices         = new FixedFactsRepository();
		$prices->prices = array( Calculators::price( 31, '10.00' ), Calculators::price( 32, '10.03' ) );
		$resolved       = ( new PriceResolver( $prices ) )->resolve(
			array( new LineRequest( 'down', 31, 1 ), new LineRequest( 'up', 32, 1 ) ),
			Calculators::presentment( 'CHF', '0.88660', true, 5 )
		);

		// 10.00 × 0.88660 = 8.8660 → 8.87 → 8.85; 10.03 × 0.88660 = 8.892598 → 8.89 → 8.90.
		$this->assertSame( array( 885, 890 ), array_map( static fn( InputLine $line ): int => $line->unitPrice->amount->minorUnits(), $resolved->lines ) );
	}

	/**
	 * Tests that a currency that allows no conversion leaves a variant priced only in the base currency unpriced.
	 *
	 * @group international
	 *
	 * @since 0.1.0
	 */
	public function test_without_conversion_a_base_price_leaves_the_line_unpriced(): void {
		$prices         = new FixedFactsRepository();
		$prices->prices = array( Calculators::price( 21, '10.00', 'EUR' ), Calculators::price( 22, '10.00' ) );
		$resolved       = ( new PriceResolver( $prices ) )->resolve(
			array( new LineRequest( 'explicit', 21, 1 ), new LineRequest( 'base-only', 22, 1 ) ),
			Calculators::presentment( 'EUR', '0.91230', false )
		);

		$this->assertSame( array( array( 'explicit', 1000, 'net', 'standard', 'explicit' ) ), self::summary( $resolved->lines ) );
		$this->assertEquals( array( new UnpricedLine( 'base-only', 22, UnpricedLine::NO_PRICE_IN_CURRENCY ) ), $resolved->unpriced );
	}

	/**
	 * Tests that no line means no read.
	 *
	 * @since 0.1.0
	 */
	public function test_no_line_reads_nothing(): void {
		$prices   = new FixedFactsRepository();
		$resolved = ( new PriceResolver( $prices ) )->resolve( array(), PresentmentCurrency::base( Currency::of( 'USD' ) ) );

		$this->assertSame( array(), $prices->askedPrices );
		$this->assertSame( array(), $resolved->lines );
		$this->assertSame( array(), $resolved->unpriced );
	}

	/**
	 * Describes priced lines by key, unit price, basis, tax class and source.
	 *
	 * @since 0.1.0
	 *
	 * @param InputLine[] $lines The lines.
	 * @return list<array{0: string, 1: int, 2: string, 3: string, 4: string}> One row per line.
	 *
	 * @phpstan-param list<InputLine> $lines
	 */
	private static function summary( array $lines ): array {
		return array_map( static fn( InputLine $line ): array => array( $line->key, $line->unitPrice->amount->minorUnits(), $line->unitPrice->basis->value, $line->taxClass, $line->priceSource->value ), $lines );
	}
}
