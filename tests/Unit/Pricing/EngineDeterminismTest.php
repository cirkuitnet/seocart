<?php
/**
 * Tests that the engine is a function: the same input and quotes give the same totals, trace included
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Pricing;

use PHPUnit\Framework\TestCase;
use SEOCart\Pricing\Domain\CalculationInput;
use SEOCart\Pricing\Domain\Engine\Engine;
use SEOCart\Pricing\Domain\Totals\Totals;
use SEOCart\Tests\Support\Pricing\CartB;
use SEOCart\Tests\Support\Pricing\FactsEvaluator;

/**
 * Runs the reference cart through the engine more than once and compares the results byte for byte.
 *
 * Planted violation, shown red and removed: in Engine::phaseB(), record `hrtime( true )` in the
 * quote step's trace entry. Two runs of one input then differ, and the first test fails.
 *
 * @group contract
 *
 * @since 0.1.0
 */
final class EngineDeterminismTest extends TestCase {

	/**
	 * Tests that one input and one set of quotes give byte-identical totals and trace.
	 *
	 * @since 0.1.0
	 */
	public function test_the_same_input_and_quotes_give_the_same_totals(): void {
		$first  = self::calculateCartB( CartB::input() );
		$second = self::calculateCartB( CartB::input() );

		$this->assertSame( json_encode( $first->toArray() ), json_encode( $second->toArray() ) );
		$this->assertSame( json_encode( $first->trace->toArray() ), json_encode( $second->trace->toArray() ) );
		$this->assertCount( 12, $first->adjustments, 'Cart B has a discount on each of its ten lines, a rate and its free-shipping discount.' );
	}

	/**
	 * Tests that asking later changes nothing but the recorded instant.
	 *
	 * @since 0.1.0
	 */
	public function test_asking_later_changes_only_the_recorded_instant(): void {
		$now   = self::calculateCartB( CartB::input( '2026-01-15T10:00:00+00:00' ) );
		$later = self::calculateCartB( CartB::input( '2026-03-01T08:30:00+00:00' ) );

		$this->assertNotSame( $now->toArray()['calculated_at'], $later->toArray()['calculated_at'] );
		$this->assertSame( self::withoutInstant( $now->toArray() ), self::withoutInstant( $later->toArray() ) );
		$this->assertSame( self::withoutInstant( $now->trace->toArray() ), self::withoutInstant( $later->trace->toArray() ) );
	}

	/**
	 * Runs both phases on the reference cart.
	 *
	 * @since 0.1.0
	 *
	 * @param CalculationInput $input The input.
	 * @return Totals The totals.
	 */
	private static function calculateCartB( CalculationInput $input ): Totals {
		$engine = new Engine();
		$phaseA = $engine->phaseA( $input, new FactsEvaluator() );

		return $engine->phaseB( $phaseA, CartB::quotes( $phaseA ) );
	}

	/**
	 * Removes every `calculated_at`, at any depth.
	 *
	 * @since 0.1.0
	 *
	 * @param array<mixed> $values An array.
	 * @return array<mixed> The array without it.
	 */
	private static function withoutInstant( array $values ): array {
		unset( $values['calculated_at'] );

		return array_map( static fn( $value ) => is_array( $value ) ? self::withoutInstant( $value ) : $value, $values );
	}
}
