<?php
/**
 * Tests what a gateway result refuses to say: a negative amount, an approved refund of nothing, a reference the ledger cannot hold
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Payment;

use PHPUnit\Framework\TestCase;
use SEOCart\Payment\Domain\Gateway\GatewayResult;
use SEOCart\Payment\Domain\Operation;
use SEOCart\Payment\Domain\Outcome;
use SEOCart\Support\Currency;
use SEOCart\Support\Money;

/**
 * A result that cannot be a fact about money is refused when it is made, before anything applies it.
 *
 * An approved refund gives back something: one of nothing would move a captured intent to
 * partially refunded with nothing refunded, so it is refused here, before the ledger sees it,
 * and the refund's statement requires a positive amount as well.
 *
 * Planted violation, shown red and removed: in GatewayResult's constructor, drop the refusal of
 * an approved refund of nothing: the refund of nothing is made.
 *
 * @since 0.1.0
 */
final class GatewayResultTest extends TestCase {

	/**
	 * Returns what is refused, each with the words its message names.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{\Closure(): GatewayResult, string}> The result to make, and part of its refusal.
	 */
	public static function refusals(): array {
		return array(
			'an approved refund of nothing' => array( static fn(): GatewayResult => self::result( Operation::Refund, Outcome::Approved, 0 ), 'refund of nothing' ),
			'a negative amount'             => array( static fn(): GatewayResult => self::result( Operation::Capture, Outcome::Approved, -1 ), 'never negative' ),
			'an empty object id'            => array( static fn(): GatewayResult => self::result( Operation::Capture, Outcome::Approved, 1, '' ), 'provider object id' ),
			'an object id too long'         => array( static fn(): GatewayResult => self::result( Operation::Capture, Outcome::Approved, 1, str_repeat( 'x', 192 ) ), 'provider object id' ),
		);
	}

	/**
	 * Tests each refusal.
	 *
	 * @since 0.1.0
	 *
	 * @dataProvider refusals
	 *
	 * @param \Closure $make    Makes the result.
	 * @param string   $message Part of the refusal's message.
	 *
	 * @phpstan-param \Closure(): GatewayResult $make
	 */
	public function test_a_result_that_cannot_be_a_money_fact_is_refused( \Closure $make, string $message ): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( $message );

		$make();
	}

	/**
	 * Tests that nothing is refused that a gateway may say: a declined refund of nothing, an approval of any positive amount.
	 *
	 * @since 0.1.0
	 */
	public function test_what_a_gateway_may_say_is_made(): void {
		$this->assertTrue( self::result( Operation::Refund, Outcome::Declined, 0 )->amount->isZero() );
		$this->assertSame( 1, self::result( Operation::Refund, Outcome::Approved, 1 )->amount->minorUnits() );
		$this->assertSame( 191, strlen( (string) self::result( Operation::Capture, Outcome::Approved, 1, str_repeat( 'x', 191 ) )->providerObjectId ) );
	}

	/**
	 * Builds a result of the stub provider.
	 *
	 * @since 0.1.0
	 *
	 * @param Operation $operation The operation.
	 * @param Outcome   $outcome   The outcome.
	 * @param int       $minor     The amount, in minor units of USD.
	 * @param string    $objectId  Optional. The provider's object. Default `stub-x`.
	 * @return GatewayResult The result.
	 */
	private static function result( Operation $operation, Outcome $outcome, int $minor, string $objectId = 'stub-x' ): GatewayResult {
		return new GatewayResult( 'stub', $operation, $outcome, '01928c3e-7b3c-7d1e-9a2b-3c4d5e6f7a8c', Money::of( $minor, Currency::of( 'USD' ) ), $objectId );
	}
}
