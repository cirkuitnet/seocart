<?php
/**
 * Tests that a calculation's trace records each rounding boundary it crossed
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Pricing;

use PHPUnit\Framework\TestCase;
use SEOCart\Pricing\Domain\Totals\TraceEntry;
use SEOCart\Tests\Support\Pricing\ScenarioFixture;

/**
 * Counts the rounding entries of a known calculation, step by step, and checks the display-only one.
 *
 * The first legacy-informed scenario has one line and one shipping rate, both taxed at one rate.
 * Its tax step crosses five boundaries: the tax of the line and of the shipping, the split of
 * each into its one component, and the line's unit gross for display. Its base step crosses
 * six: the positive nets and the positive taxes, each converted once and split back, and each
 * owner's components' base taxes. Its authored amounts are all net, so each takes the base net
 * it equals and crosses no boundary of its own.
 *
 * Planted violation, shown red and removed: in TaxStep::apply(), work out the unit gross without
 * the Rounder. The tax step then records four roundings, not five.
 *
 * @since 0.1.0
 */
final class TraceCompletenessTest extends TestCase {

	/**
	 * Tests the rounding entries of a known calculation.
	 *
	 * @since 0.1.0
	 */
	public function test_every_rounding_boundary_has_an_entry(): void {
		$totals   = ScenarioFixture::inFamily( 'legacy-informed' )['01-one-product-quantity-three'][0]->run();
		$rounding = array_values( array_filter( $totals->trace->entries, static fn( TraceEntry $entry ): bool => TraceEntry::ROUNDING === $entry->kind ) );
		$byStep   = array_count_values( array_map( static fn( TraceEntry $entry ): string => $entry->step, $rounding ) );

		$this->assertSame(
			array(
				'b6.tax'  => 5,
				'b8.base' => 6,
			),
			$byStep
		);

		$display = array_values( array_filter( $rounding, static fn( TraceEntry $entry ): bool => true === ( $entry->data['display_only'] ?? false ) ) );

		$this->assertCount( 1, $display );
		$this->assertSame(
			array(
				'subject'      => 'line:l1:unit_gross',
				'exact'        => '21.64000000000000',
				'rounded'      => '21.64',
				'mode'         => 'half_up',
				'display_only' => true,
			),
			$display[0]->data
		);
	}
}
