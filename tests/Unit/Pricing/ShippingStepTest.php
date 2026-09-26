<?php
/**
 * Tests the shipping step: which rate is selected, and when there is no shipping at all
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Pricing;

use PHPUnit\Framework\TestCase;
use SEOCart\Pricing\Domain\PricingError;
use SEOCart\Pricing\Domain\PromotionEffect;
use SEOCart\Pricing\Domain\PromotionFacts;
use SEOCart\Pricing\Domain\Totals\Totals;
use SEOCart\Pricing\Domain\Totals\TraceEntry;
use SEOCart\Support\Error\CodedException;
use SEOCart\Tests\Support\Pricing\Inputs;

/**
 * Proves the selection of a rate, free shipping capped at the rate, and that a destination with no rate is an error, never free.
 *
 * @since 0.1.0
 */
final class ShippingStepTest extends TestCase {

	/**
	 * Tests that the chosen method is selected, the cheapest otherwise, the first quoted on a tie, and that the choice is traced.
	 *
	 * @since 0.1.0
	 *
	 * @dataProvider data_selections
	 *
	 * @param string|null $requested The method asked for.
	 * @param string      $selected  The method selected.
	 * @param string      $reason    Why, as traced.
	 */
	public function test_a_rate_is_selected_and_the_choice_traced( ?string $requested, string $selected, string $reason ): void {
		$rates  = array( Inputs::shippingRate( 'express', '9.00' ), Inputs::shippingRate( 'ground', '4.00' ), Inputs::shippingRate( 'pickup', '4.00' ) );
		$totals = Inputs::calculate( Inputs::input( array( Inputs::line( 'a', '10.00' ) ), shippingMethodKey: $requested ), $rates );

		$this->assertSame( 'shipping:' . $selected, $totals->adjustments[0]->source()->toString() );
		$this->assertSame(
			array(
				'requested' => $requested,
				'selected'  => $selected,
				'reason'    => $reason,
			),
			self::entries( $totals, TraceEntry::SELECTION )[0]->data
		);
	}

	/**
	 * Provides requests and what they select.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{string|null, string, string}> Test cases.
	 */
	public static function data_selections(): array {
		return array(
			'the method asked for'                 => array( 'express', 'express', 'requested' ),
			'the cheapest, the first on a tie'     => array( null, 'ground', 'cheapest' ),
			'the cheapest when the method is gone' => array( 'courier', 'ground', 'requested_not_quoted' ),
		);
	}

	/**
	 * Tests that a cart with no destination, or with no line, is charged no shipping, and says why.
	 *
	 * @since 0.1.0
	 */
	public function test_no_destination_or_no_line_means_no_shipping(): void {
		$rates = array( Inputs::shippingRate( 'ground', '4.00' ) );

		$withoutAddress = Inputs::calculate( Inputs::input( array( Inputs::line( 'a', '10.00' ) ), destination: null ), $rates );
		$withoutLines   = Inputs::calculate( Inputs::input( array() ), $rates );

		$this->assertSame( array(), $withoutAddress->adjustments );
		$this->assertSame( 'no_destination', self::entries( $withoutAddress, TraceEntry::SKIPPED )[0]->data['reason'] );
		$this->assertSame( array(), $withoutLines->adjustments );
		$this->assertSame( 'no_lines', self::entries( $withoutLines, TraceEntry::SKIPPED )[0]->data['reason'] );
		$this->assertSame( 0, $withoutLines->summary->grand->minorUnits() );
	}

	/**
	 * Tests that a destination with no quoted rate cannot be priced, rather than shipping for free.
	 *
	 * @since 0.1.0
	 */
	public function test_a_destination_without_a_rate_is_an_error_not_free_shipping(): void {
		try {
			Inputs::calculate( Inputs::input( array( Inputs::line( 'a', '10.00' ) ) ) );
			$this->fail( 'A destination without a rate was priced.' );
		} catch ( CodedException $refused ) {
			$this->assertSame( PricingError::NoShippingRate, $refused->errorCode() );
		}
	}

	/**
	 * Tests that a negative shipping rate is refused when it is quoted, and so never lowers a total.
	 *
	 * Planted violation, shown red and removed: in ShippingRateQuote's constructor, accept a
	 * negative rate. A quote of -5.00 is then selected and takes 5.00 off the grand total.
	 *
	 * @since 0.1.0
	 */
	public function test_a_negative_rate_is_refused(): void {
		try {
			$totals = Inputs::calculate( Inputs::input( array( Inputs::line( 'a', '10.00' ) ) ), array( Inputs::shippingRate( 'ground', '-5.00' ) ) );
			$this->fail( 'A negative rate was quoted, and the grand total came to ' . $totals->summary->grand->toDecimal()->toString() . '.' );
		} catch ( \InvalidArgumentException $refused ) {
			$this->assertSame( 'A shipping rate is never negative.', $refused->getMessage() );
		}
	}

	/**
	 * Tests that two free-shipping promotions take the rate off once.
	 *
	 * @since 0.1.0
	 */
	public function test_free_shipping_takes_the_rate_off_once(): void {
		$promotions = array(
			new PromotionFacts( 1, 'first', '', PromotionEffect::freeShipping(), 10 ),
			new PromotionFacts( 2, 'second', '', PromotionEffect::freeShipping(), 20 ),
		);
		$totals     = Inputs::calculate( Inputs::input( array( Inputs::line( 'a', '10.00' ) ), promotions: $promotions ), array( Inputs::shippingRate( 'ground', '4.00' ) ) );

		$this->assertCount( 2, $totals->adjustments );
		$this->assertSame( -400, $totals->adjustments[1]->adjustment->authoredAmount->amount->minorUnits() );
		$this->assertSame( 0, $totals->adjustments[0]->amount->tax()->add( $totals->adjustments[1]->amount->tax() )->minorUnits(), 'The tax on shipping nets to zero.' );
		$this->assertSame( 'promotion:second', self::entries( $totals, TraceEntry::SKIPPED )[0]->data['source'] );
	}

	/**
	 * Returns the trace entries of a kind.
	 *
	 * @since 0.1.0
	 *
	 * @param Totals $totals The totals.
	 * @param string $kind   The kind.
	 * @return list<TraceEntry> The entries.
	 */
	private static function entries( Totals $totals, string $kind ): array {
		return array_values( array_filter( $totals->trace->entries, static fn( TraceEntry $entry ): bool => $kind === $entry->kind ) );
	}
}
