<?php
/**
 * Tests Rounder: every rounding boundary rounds once and leaves one trace entry
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Pricing;

use PHPUnit\Framework\TestCase;
use SEOCart\Pricing\Domain\Engine\Rounder;
use SEOCart\Pricing\Domain\Engine\TraceBuilder;
use SEOCart\Pricing\Domain\Totals\TraceEntry;
use SEOCart\Support\ConversionContext;
use SEOCart\Support\Currency;
use SEOCart\Support\Decimal;
use SEOCart\Support\Percentage;
use SEOCart\Support\RoundingMode;
use SEOCart\Support\TaxedMoney;
use SEOCart\Tax\Domain\EffectiveRate;
use SEOCart\Tests\Support\MoneyAssertions;
use SEOCart\Tests\Support\Pricing\Inputs;

/**
 * Proves the figures of each boundary, and that each writes exactly one rounding entry a replay can check.
 *
 * @since 0.1.0
 */
final class RounderTest extends TestCase {

	use MoneyAssertions;

	/**
	 * Tests a percentage of an amount: rounded half up, the exact product recorded.
	 *
	 * @since 0.1.0
	 */
	public function test_an_exact_amount_is_rounded_once_and_recorded(): void {
		$trace = new TraceBuilder( RoundingMode::HalfUp );
		$trace->enter( 'b1.discounts' );

		$rounded = ( new Rounder() )->money( $trace, 'line:a', Inputs::money( '9.99' )->multiply( Percentage::fromString( '15' ) ), Currency::of( 'USD' ) );

		$this->assertMoneyEquals( Inputs::money( '1.50' ), $rounded );
		$this->assertSame(
			array(
				array(
					'step' => 'b1.discounts',
					'kind' => TraceEntry::ROUNDING,
					'data' => array(
						'subject' => 'line:a',
						'exact'   => '1.4985000000',
						'rounded' => '1.50',
						'mode'    => 'half_up',
					),
				),
			),
			$trace->freeze()->toArray()
		);
	}

	/**
	 * Tests a quotient: rounded once, recorded to twelve places past the cent toward zero.
	 *
	 * @since 0.1.0
	 */
	public function test_a_quotient_is_recorded_to_twelve_places_past_the_minor_unit(): void {
		$trace   = new TraceBuilder( RoundingMode::HalfUp );
		$rounded = ( new Rounder() )->quotient( $trace, 'line:a:unit_gross', Decimal::of( '10.00' ), Decimal::of( '3' ), Currency::of( 'USD' ), true );
		$entry   = $trace->freeze()->entries[0];

		$this->assertMoneyEquals( Inputs::money( '3.33' ), $rounded );
		$this->assertSame( '3.33333333333333', $entry->data['exact'] );
		$this->assertTrue( $entry->data['display_only'] );
	}

	/**
	 * Tests that tax added to a net amount and taken out of a gross one each round once.
	 *
	 * @since 0.1.0
	 */
	public function test_tax_on_net_and_in_gross_round_once(): void {
		$trace   = new TraceBuilder( RoundingMode::HalfUp );
		$rounder = new Rounder();
		$twenty  = EffectiveRate::additive( Percentage::fromString( '20' ) );

		$fromNet   = $rounder->taxOnNet( $trace, 'line:a', Inputs::money( '8.33' ), $twenty );
		$fromGross = $rounder->taxInGross( $trace, 'line:b', Inputs::money( '9.99' ), $twenty );
		$entries   = $trace->freeze()->entries;

		$this->assertTaxedMoneyEquals( new TaxedMoney( Inputs::money( '8.33' ), Inputs::money( '1.67' ), Inputs::money( '10.00' ) ), $fromNet );
		$this->assertTaxedMoneyEquals( new TaxedMoney( Inputs::money( '8.32' ), Inputs::money( '1.67' ), Inputs::money( '9.99' ) ), $fromGross );
		$this->assertSame( '1.6660000000', $entries[0]->data['exact'] );
		$this->assertSame( '1.66500000000000', $entries[1]->data['exact'] );
		$this->assertCount( 2, $entries );
	}

	/**
	 * Tests a split: shares add up, the leftover unit goes to the largest remainder, and the residuals say where.
	 *
	 * @since 0.1.0
	 */
	public function test_a_split_adds_up_and_names_its_residuals(): void {
		$trace      = new TraceBuilder( RoundingMode::HalfUp );
		$allocation = ( new Rounder() )->split(
			$trace,
			'order:promotion:x',
			Inputs::money( '10.00' ),
			array(
				'a' => 1,
				'b' => 1,
				'c' => 1,
			)
		);

		$this->assertSame( array( 'a', 'b', 'c' ), array_keys( $allocation->shares ) );
		$this->assertSame( array( 334, 333, 333 ), array_values( array_map( static fn( $share ): int => $share->minorUnits(), $allocation->shares ) ) );
		$this->assertSame(
			array(
				'a' => 1,
				'b' => 0,
				'c' => 0,
			),
			$allocation->residuals
		);
		$this->assertSame( array( '3.34', '3.33', '3.33' ), $trace->freeze()->entries[0]->data['rounded'] );
		$this->assertSame( 'largest_remainder', $trace->freeze()->entries[0]->data['mode'] );

		$negative = ( new Rounder() )->split( $trace, 'x', Inputs::money( '-10.00' ), array( 1, 1, 1 ) );

		$this->assertSame( array( -334, -333, -333 ), array_map( static fn( $share ): int => $share->minorUnits(), $negative->shares ) );
		$this->assertSame( array( -1, 0, 0 ), $negative->residuals, 'A negative amount\'s leftover unit is negative too.' );
	}

	/**
	 * Tests a conversion to the base currency: the identity context changes nothing, a real rate rounds once.
	 *
	 * @since 0.1.0
	 */
	public function test_a_conversion_to_the_base_currency_rounds_once(): void {
		$trace   = new TraceBuilder( RoundingMode::HalfUp );
		$rounder = new Rounder();
		$usd     = Currency::of( 'USD' );
		$rate    = new ConversionContext( $usd, Currency::of( 'EUR' ), ConversionContext::DIRECTION_BASE_TO_QUOTE, Decimal::of( '0.91230' ), 5, 'manual', 3, new \DateTimeImmutable( Inputs::AT ) );

		$this->assertMoneyEquals( Inputs::money( '12.34' ), $rounder->toBase( $trace, 'pool', Inputs::money( '12.34' ), ConversionContext::identity( $usd ) ) );
		$this->assertMoneyEquals( Inputs::money( '0.16' ), $rounder->toBase( $trace, 'pool', Inputs::money( '0.15', 'EUR' ), $rate ) );
		$this->assertSame( '0.16441959881617', $trace->freeze()->entries[1]->data['exact'] );
	}
}
