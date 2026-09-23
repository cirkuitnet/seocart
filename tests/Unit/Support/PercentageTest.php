<?php
/**
 * Tests Percentage: the exact storage form of a rate and its factor
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use SEOCart\Support\Percentage;

/**
 * Proves that a percentage is exact to a millionth of a percent and refuses anything finer.
 *
 * @since 0.1.0
 */
final class PercentageTest extends TestCase {

	/**
	 * Tests reading a decimal string of percent into the storage form.
	 *
	 * @since 0.1.0
	 *
	 * @dataProvider data_percentages
	 *
	 * @param string $percent      The percentage in percent.
	 * @param int    $micropercent The storage form.
	 * @param string $factor       The factor.
	 */
	public function test_from_string_is_exact_and_gives_the_factor( string $percent, int $micropercent, string $factor ): void {
		$rate = Percentage::fromString( $percent );

		$this->assertSame( $micropercent, $rate->micropercent() );
		$this->assertSame( $factor, $rate->toFactor()->toString() );
		$this->assertTrue( $rate->equals( Percentage::fromMicropercent( $micropercent ) ) );
	}

	/**
	 * Provides percentages, their storage forms and their factors.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{string, int, string}> Test cases.
	 */
	public static function data_percentages(): array {
		return array(
			'twenty percent'            => array( '20', 20000000, '0.20000000' ),
			'a fraction of a percent'   => array( '7.25', 7250000, '0.07250000' ),
			'a millionth of a percent'  => array( '0.000001', 1, '0.00000001' ),
			'zero'                      => array( '0', 0, '0.00000000' ),
			'one hundred percent'       => array( '100', 100000000, '1.00000000' ),
			'negative, for a markdown'  => array( '-5', -5000000, '-0.05000000' ),
			'trailing zeros beyond six' => array( '20.50000000', 20500000, '0.20500000' ),
		);
	}

	/**
	 * Tests that a percentage finer than a millionth of a percent is refused, not rounded.
	 *
	 * @since 0.1.0
	 */
	public function test_a_percentage_finer_than_the_storage_form_is_refused(): void {
		$this->expectException( \InvalidArgumentException::class );

		Percentage::fromString( '0.0000001' );
	}

	/**
	 * Tests that a string that is not a decimal is refused.
	 *
	 * @since 0.1.0
	 */
	public function test_a_malformed_percentage_is_refused(): void {
		$this->expectException( \InvalidArgumentException::class );

		Percentage::fromString( '20%' );
	}

	/**
	 * Tests equality.
	 *
	 * @since 0.1.0
	 */
	public function test_equality_is_by_storage_form(): void {
		$this->assertTrue( Percentage::fromString( '19' )->equals( Percentage::fromMicropercent( 19000000 ) ) );
		$this->assertFalse( Percentage::fromString( '19' )->equals( Percentage::fromString( '19.000001' ) ) );
	}
}
