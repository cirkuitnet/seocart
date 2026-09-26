<?php
/**
 * Tests that the second phase refuses quotes taken for other lines
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Pricing;

use PHPUnit\Framework\TestCase;
use SEOCart\Pricing\Domain\Engine\Engine;
use SEOCart\Pricing\Domain\NoPromotions;
use SEOCart\Pricing\Domain\Quote\Quotes;
use SEOCart\Tests\Support\Pricing\AddingEvaluator;
use SEOCart\Tests\Support\Pricing\Inputs;

/**
 * Proves that quotes taken for other lines, or rates quoted in another currency, never reach a total.
 *
 * Planted violation, shown red and removed: in Engine::assertQuotesFit(), skip the fingerprint
 * comparison. The rate quoted for the cart before a promotion added a line is then applied to
 * the cart with it, silently, and the first test fails.
 *
 * @since 0.1.0
 */
final class PackagesFingerprintTest extends TestCase {

	/**
	 * Tests that quotes taken for the lines before a promotion added one are refused.
	 *
	 * @since 0.1.0
	 */
	public function test_quotes_taken_for_other_lines_are_refused(): void {
		$engine = new Engine();
		$input  = Inputs::input( array( Inputs::line( 'a', '10.00' ) ) );
		$before = $engine->phaseA( $input, new NoPromotions() );
		$after  = $engine->phaseA( $input, new AddingEvaluator() );
		$quotes = new Quotes( array( Inputs::shippingRate( 'ground', '4.00' ) ), Inputs::taxQuote( array( 'standard' => array( Inputs::rate( '8.25' ) ) ) ), $before->packagesFingerprint() );

		$this->assertNotSame( $before->packagesFingerprint(), $after->packagesFingerprint() );
		$this->assertSame( 1, count( $engine->phaseB( $before, $quotes )->adjustments ) );

		$this->expectException( \LogicException::class );

		$engine->phaseB( $after, $quotes );
	}

	/**
	 * Tests that a shipping rate quoted in another currency is refused.
	 *
	 * @since 0.1.0
	 */
	public function test_a_rate_in_another_currency_is_refused(): void {
		$engine = new Engine();
		$phaseA = $engine->phaseA( Inputs::input( array( Inputs::line( 'a', '10.00' ) ) ), new NoPromotions() );

		$this->expectException( \LogicException::class );

		$engine->phaseB( $phaseA, new Quotes( array( Inputs::shippingRate( 'ground', '4.00', currency: 'EUR' ) ), Inputs::taxQuote( array( 'standard' => array() ) ), $phaseA->packagesFingerprint() ) );
	}
}
