<?php
/**
 * Tests the base-currency share of a capture of part of an intent's amount
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Payment;

use PHPUnit\Framework\TestCase;
use SEOCart\Contracts\Payment\Mode;
use SEOCart\Payment\Domain\CaptureShare;
use SEOCart\Payment\Domain\IntentStatus;
use SEOCart\Payment\Domain\PaymentIntent;
use SEOCart\Support\Currency;
use SEOCart\Support\Money;

/**
 * A capture of the whole amount adds the whole frozen base amount; a capture of part adds its share by largest remainder, within one minor unit of its exact proportion, a tie going to the capture.
 *
 * Planted violation, shown red and removed: in CaptureShare::baseOf(), take the share rounded
 * toward zero instead of by largest remainder: a third of 2000 is 666, not 667.
 *
 * @since 0.2.0
 */
final class CaptureShareTest extends TestCase {

	/**
	 * Returns the cases: the intent's amount in EUR, its base amount in USD, and the capture.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, array{int, int, int, int}> The amount, the base amount, the capture, and its expected share.
	 */
	public static function cases(): array {
		return array(
			'the whole amount'           => array( 3080, 2464, 3080, 2464 ),
			'a third'                    => array( 3000, 2000, 1000, 667 ),
			'two thirds'                 => array( 3000, 2000, 2000, 1333 ),
			'one unit of three'          => array( 3, 2, 1, 1 ),
			'two units of three'         => array( 3, 2, 2, 1 ),
			'a capture worth nothing'    => array( 3080, 2464, 1, 1 ),
			'a tie, to the capture'      => array( 2, 1, 1, 1 ),
			'a base worth more than all' => array( 100, 10000, 33, 3300 ),
		);
	}

	/**
	 * Tests each case, and that the share is within one minor unit of its exact proportion of the frozen base amount.
	 *
	 * @since 0.2.0
	 *
	 * @dataProvider cases
	 *
	 * @param int $amount   The intent's amount, in minor units of EUR.
	 * @param int $base     Its frozen base amount, in minor units of USD.
	 * @param int $captured The capture, in minor units of EUR.
	 * @param int $share    The share expected, in minor units of USD.
	 */
	public function test_the_share_is_the_captures_proportion_of_the_frozen_base( int $amount, int $base, int $captured, int $share ): void {
		$taken = CaptureShare::baseOf( self::intent( $amount, $base ), self::eur( $captured ) );

		$this->assertSame( array( $share, 'USD' ), array( $taken->minorUnits(), $taken->currency()->code() ) );
		$this->assertLessThanOrEqual( $amount, abs( $taken->minorUnits() * $amount - $base * $captured ), 'Within one minor unit of the exact proportion.' );
	}

	/**
	 * Tests that a capture of more than the intent's amount has no share.
	 *
	 * @since 0.2.0
	 */
	public function test_a_capture_of_more_than_the_amount_has_no_share(): void {
		$this->expectException( \InvalidArgumentException::class );

		CaptureShare::baseOf( self::intent( 3080, 2464 ), self::eur( 3081 ) );
	}

	/**
	 * Builds an authorized intent in EUR with a USD base amount.
	 *
	 * @since 0.2.0
	 *
	 * @param int $amount Its amount, in minor units.
	 * @param int $base   Its base amount, in minor units.
	 * @return PaymentIntent The intent.
	 */
	private static function intent( int $amount, int $base ): PaymentIntent {
		return new PaymentIntent( 11, '01928c3e-7b3c-7d1e-9a2b-3c4d5e6f7a8c', 7, 'stub', Mode::Test, IntentStatus::Authorized, self::eur( $amount ), Money::of( $base, Currency::of( 'USD' ) ), 1, self::eur( $amount ), self::eur( 0 ), self::eur( 0 ), null );
	}

	/**
	 * Returns an amount in EUR.
	 *
	 * @since 0.2.0
	 *
	 * @param int $minor The minor units.
	 * @return Money The amount.
	 */
	private static function eur( int $minor ): Money {
		return Money::of( $minor, Currency::of( 'EUR' ) );
	}
}
