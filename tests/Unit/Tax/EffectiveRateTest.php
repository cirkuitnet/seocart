<?php
/**
 * Tests EffectiveRate: how several rates of one class compose
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Tax;

use PHPUnit\Framework\TestCase;
use SEOCart\Support\Percentage;
use SEOCart\Tax\Domain\EffectiveRate;

/**
 * Proves that rates charged on the net amount add up exactly, and that no rate is a rate of zero.
 *
 * @since 0.1.0
 */
final class EffectiveRateTest extends TestCase {

	/**
	 * Tests the factor and the multiplier of composed rates.
	 *
	 * @since 0.1.0
	 *
	 * @dataProvider data_rates
	 *
	 * @param string[] $rates      The rates, in percent.
	 * @param string   $factor     The factor they compose to.
	 * @param string   $multiplier One plus the factor.
	 */
	public function test_rates_charged_on_the_net_amount_add_up( array $rates, string $factor, string $multiplier ): void {
		$rate = EffectiveRate::additive( ...array_map( static fn( string $percent ): Percentage => Percentage::fromString( $percent ), $rates ) );

		$this->assertSame( $factor, $rate->factor()->toString() );
		$this->assertSame( $multiplier, $rate->multiplier()->toString() );
	}

	/**
	 * Provides rates and what they compose to.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{list<string>, string, string}> Test cases.
	 */
	public static function data_rates(): array {
		return array(
			'no rate is zero'           => array( array(), '0', '1' ),
			'one rate'                  => array( array( '20' ), '0.20000000', '1.20000000' ),
			'a state and a county rate' => array( array( '6.25', '2' ), '0.08250000', '1.08250000' ),
			'three rates, exactly'      => array( array( '0.000001', '0.000002', '7' ), '0.07000003', '1.07000003' ),
		);
	}

	/**
	 * Tests that a negative rate is refused.
	 *
	 * @since 0.1.0
	 */
	public function test_a_negative_rate_is_refused(): void {
		$this->expectException( \InvalidArgumentException::class );

		EffectiveRate::additive( Percentage::fromString( '20' ), Percentage::fromString( '-1' ) );
	}
}
