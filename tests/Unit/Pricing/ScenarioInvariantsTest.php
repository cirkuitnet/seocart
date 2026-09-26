<?php
/**
 * Tests what every scenario's result must satisfy: sources, shares that add up, and a complete trace
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Pricing;

use PHPUnit\Framework\TestCase;
use SEOCart\Pricing\Domain\Totals\AdjustmentScope;
use SEOCart\Pricing\Domain\Totals\Totals;
use SEOCart\Pricing\Domain\Totals\TraceEntry;
use SEOCart\Support\Money;
use SEOCart\Tests\Support\Pricing\Inputs;
use SEOCart\Tests\Support\Pricing\ScenarioFixture;

/**
 * Runs every scenario of both families and checks what holds for any of them.
 *
 * - Every adjustment carries a well-formed source.
 * - An amount off the order is shared across the lines so that its shares add up to the amount,
 *   capped at what the lines cost when it was applied.
 * - The trace has one adjustment entry per adjustment, with its source, and one rounding entry
 *   per rounding boundary with the value before and after; totals and trace read back from JSON
 *   unchanged.
 *
 * Planted violations, each shown red and removed:
 *
 * - in ShippingStep::apply(), give the shipping adjustment the source `new Source( '' )`: the
 *   constructor refuses it and every scenario with shipping fails;
 * - in DiscountStep::discountOrder(), round each line's share on its own instead of splitting the
 *   amount by largest remainder: the amounts off stop adding up.
 *
 * @since 0.1.0
 */
final class ScenarioInvariantsTest extends TestCase {

	/**
	 * Tests that every adjustment's source is well-formed.
	 *
	 * @since 0.1.0
	 *
	 * @dataProvider data_scenarios
	 *
	 * @param ScenarioFixture $scenario The scenario.
	 */
	public function test_every_adjustment_carries_a_source( ScenarioFixture $scenario ): void {
		$malformed = array();

		foreach ( $scenario->run()->adjustments as $adjustment ) {
			if ( 1 !== preg_match( '/^[a-z]+(?::[a-z0-9_.\/-]{1,80})?\z/', $adjustment->source()->toString() ) ) {
				$malformed[] = $adjustment->source()->toString();
			}
		}

		$this->assertSame( array(), $malformed );
	}

	/**
	 * Tests that the shares of each amount off the order add up to the amount, capped at what the lines cost then.
	 *
	 * @since 0.1.0
	 *
	 * @dataProvider data_scenarios
	 *
	 * @param ScenarioFixture $scenario The scenario.
	 */
	public function test_the_shares_of_an_amount_off_add_up_to_it( ScenarioFixture $scenario ): void {
		$totals = $scenario->run();
		$fixed  = array_filter( $scenario->document['input']['promotions'] ?? array(), static fn( array $promotion ): bool => 'fixed' === $promotion['effect']['kind'] );

		foreach ( $fixed as $promotion ) {
			$source = 'promotion:' . $promotion['uuid'];
			$shares = Money::zero( $totals->currency );
			$first  = null;

			foreach ( $totals->adjustments as $adjustment ) {
				if ( $source === $adjustment->source()->toString() ) {
					$shares  = $shares->add( $adjustment->adjustment->authoredAmount->amount->negate() );
					$first ??= $adjustment->adjustment->position;
				}
			}

			$this->assertNotNull( $first, "{$source} took nothing off." );
			$this->assertTrue( $shares->equals( self::cap( $totals, $first, Inputs::money( $promotion['effect']['amount'], $totals->currency->code() ) ) ), "The shares of {$source} add up to " . $shares->toDecimal()->toString() . '.' );
		}

		$this->addToAssertionCount( 1 );
	}

	/**
	 * Tests that the trace records every adjustment and every rounding, and that totals and trace read back from JSON unchanged.
	 *
	 * @since 0.1.0
	 *
	 * @dataProvider data_scenarios
	 *
	 * @param ScenarioFixture $scenario The scenario.
	 */
	public function test_the_trace_records_every_adjustment_and_rounding( ScenarioFixture $scenario ): void {
		$totals  = $scenario->run();
		$entries = $totals->trace->entries;
		$traced  = array_values( array_filter( $entries, static fn( TraceEntry $entry ): bool => TraceEntry::ADJUSTMENT === $entry->kind ) );

		$this->assertSame(
			array_map( static fn( $adjustment ): string => $adjustment->source()->toString(), $totals->adjustments ),
			array_map( static fn( TraceEntry $entry ): string => $entry->data['source'], $traced )
		);

		foreach ( $entries as $entry ) {
			if ( TraceEntry::ROUNDING === $entry->kind ) {
				$this->assertSame( array( 'subject', 'exact', 'rounded', 'mode' ), array_slice( array_keys( $entry->data ), 0, 4 ) );
			}
		}

		$this->assertSame( $totals->toArray(), json_decode( (string) json_encode( $totals->toArray() ), true ) );
		$this->assertSame( $totals->trace->toArray(), json_decode( (string) json_encode( $totals->trace->toArray() ), true ) );
	}

	/**
	 * Provides every scenario of both families.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{ScenarioFixture}> The scenarios.
	 */
	public static function data_scenarios(): array {
		return ScenarioFixture::inFamily( 'legacy-informed' ) + ScenarioFixture::inFamily( 'international' );
	}

	/**
	 * Returns what an amount off may take: the amount, or what the lines cost before its first share, if less.
	 *
	 * @since 0.1.0
	 *
	 * @param Totals $totals The totals.
	 * @param int    $first  The position of the amount off's first share.
	 * @param Money  $amount The amount off.
	 * @return Money The cap.
	 */
	private static function cap( Totals $totals, int $first, Money $amount ): Money {
		$lines = Money::zero( $totals->currency );

		foreach ( $totals->lines as $line ) {
			$lines = $lines->add( $line->lineSubtotal->amount );
		}

		foreach ( $totals->adjustments as $adjustment ) {
			if ( AdjustmentScope::Line === $adjustment->adjustment->scope && $adjustment->adjustment->position < $first ) {
				$lines = $lines->add( $adjustment->adjustment->authoredAmount->amount );
			}
		}

		return $amount->compare( $lines ) > 0 ? $lines : $amount;
	}
}
