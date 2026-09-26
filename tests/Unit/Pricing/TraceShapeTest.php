<?php
/**
 * Tests the shape of a calculation's trace: scalars and flat lists, which read back as written
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Pricing;

use PHPUnit\Framework\TestCase;
use SEOCart\Pricing\Domain\Engine\TraceBuilder;
use SEOCart\Pricing\Domain\Totals\TraceEntry;
use SEOCart\Support\RoundingMode;
use SEOCart\Tests\Support\Pricing\Inputs;

/**
 * Proves that a trace entry holds nothing an order could not store as JSON and read back unchanged.
 *
 * Planted violation, shown red and removed: in TraceEntry::isTraceValue(), accept any array. The
 * nested and keyed cases below are then accepted and the test fails on each.
 *
 * @since 0.1.0
 */
final class TraceShapeTest extends TestCase {

	/**
	 * Tests that a value that is not a string, an integer, a boolean, null or a flat list of those is refused.
	 *
	 * @since 0.1.0
	 *
	 * @dataProvider data_values_a_trace_cannot_hold
	 *
	 * @param mixed $value The value.
	 */
	public function test_a_value_a_trace_cannot_hold_is_refused( mixed $value ): void {
		$this->expectException( \InvalidArgumentException::class );

		new TraceEntry( 'b6.tax', TraceEntry::ROUNDING, array( 'value' => $value ) );
	}

	/**
	 * Provides values a trace entry must refuse.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{mixed}> Test cases.
	 */
	public static function data_values_a_trace_cannot_hold(): array {
		return array(
			'an object'           => array( Inputs::money( '1.00' ) ),
			'a float'             => array( 1.5 ),
			'a nested list'       => array( array( array( '1.00' ) ) ),
			'a keyed array'       => array( array( 'net' => '1.00' ) ),
			'a list with a float' => array( array( '1.00', 0.5 ) ),
		);
	}

	/**
	 * Tests that an unknown kind of entry is refused.
	 *
	 * @since 0.1.0
	 */
	public function test_an_unknown_kind_is_refused(): void {
		$this->expectException( \InvalidArgumentException::class );

		new TraceEntry( 'b6.tax', 'guess', array() );
	}

	/**
	 * Tests that a trace, the input's entries included, reads back from JSON as the array it was written from.
	 *
	 * @since 0.1.0
	 */
	public function test_a_trace_reads_back_from_json_unchanged(): void {
		$trace = new TraceBuilder( RoundingMode::HalfUp );

		$trace->input( Inputs::input( array( Inputs::line( 'a', '9.99', 3 ), Inputs::line( 'b', '0.01' ) ), shippingMethodKey: 'ground' ) );
		$trace->enter( 'b1.discounts' );
		$trace->record(
			TraceEntry::ROUNDING,
			array(
				'rounded'      => array( '1.00', '2.00' ),
				'display_only' => true,
				'none'         => null,
			)
		);

		$array = $trace->freeze()->toArray();

		$this->assertSame( $array, json_decode( (string) json_encode( $array ), true ) );
		$this->assertSame( array( 'calculation', 'line', 'line' ), array_column( array_column( array_slice( $array, 0, 3 ), 'data' ), 'record' ) );
		$this->assertSame( 'input', $array[0]['step'] );
		$this->assertSame( 'b1.discounts', $array[3]['step'] );
	}
}
