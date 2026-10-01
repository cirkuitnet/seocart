<?php
/**
 * Tests that two promotion codes tried at once on one cart cannot both pass the cart's cap
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Cart;

use SEOCart\Cart\Application\PromotionCodeLimits;
use SEOCart\Promotion\Infrastructure\MysqlPromotionRepository;
use SEOCart\Support\Error\CodedException;
use SEOCart\Tests\Support\Cart\CartTestCase;
use SEOCart\Tests\Support\Doubles\FakeCartTokens;

/**
 * Of two codes tried at once on a cart one short of its cap, only one is looked up.
 *
 * Connection A is the cart service over wpdb. Connection B is a second request for the same cart:
 * the whole service over a second connection, presenting the same token. The cart has had nine
 * codes tried and refused. A tries a code and is held just before it looks the code up; B tries
 * another code from start to end in that moment; then A goes on. Nothing waits on the clock.
 *
 * Looking at the counts first and counting a code only once it has been refused lets both
 * through: each sees nine, both are looked up, and the cart ends at eleven. Counting each code
 * before it is looked at, and comparing the count the counting statement returns, lets A through
 * as the tenth and refuses B as the eleventh, before B looks anything up.
 *
 * Planted violation: in CartService::applyPromotionCode(), compare the counters' current counts
 * (peek()) before looking the code up, and count only a refused code afterwards: B is then looked
 * up too, and is refused as an invalid code instead of as limited.
 *
 * @since 0.1.0
 *
 * @group concurrency
 */
final class PromotionCodeRaceTest extends CartTestCase {

	/**
	 * Tests that the tenth and the eleventh code tried at once are counted in order, and only the tenth is looked up.
	 *
	 * @since 0.1.0
	 */
	public function test_two_codes_tried_at_once_cannot_both_pass_the_cap(): void {
		$shirt = self::variant();

		$this->price( array( $shirt => 1000 ) );
		$this->startCart( array( $shirt => 1 ) );

		for ( $code = 1; $code < PromotionCodeLimits::CART_LIMIT; $code++ ) {
			$this->assertSame( 'promotion.code_invalid', $this->outcome( fn() => $this->service->applyPromotionCode( 'NOPE' . $code, 1, self::guest() ) ) );
		}

		$tokens            = new FakeCartTokens();
		$tokens->presented = $this->tokens->presented;
		$b                 = $this->secondService( $tokens );
		$outcomes          = array();

		$raced = $this->beforeStatement(
			self::shapeOf( MysqlPromotionRepository::FIND_BY_CODES . '( %s )' ),
			function () use ( $b, &$outcomes ): void {
				$outcomes['B'] = $this->outcome( static fn() => $b->applyPromotionCode( 'BNOPE', 1, self::guest() ) );
			}
		);

		$outcomes['A'] = $this->outcome( fn() => $this->service->applyPromotionCode( 'ANOPE', 1, self::guest() ) );

		$this->assertTrue( $raced->fired, 'A never looked its code up.' );
		$this->assertSame(
			array(
				'B' => 'store_api.rate_limited',
				'A' => 'promotion.code_invalid',
			),
			$outcomes,
			'Two codes tried at once on a cart one short of its cap were both looked up.'
		);
	}

	/**
	 * Runs an attempt that must be refused, and returns the code it was refused with.
	 *
	 * @since 0.1.0
	 *
	 * @param callable $attempt The attempt.
	 * @return string The refusal's code.
	 */
	private function outcome( callable $attempt ): string {
		try {
			$attempt();
		} catch ( CodedException $refused ) {
			return (string) $refused->errorCode()->value;
		}

		$this->fail( 'The attempt was not refused.' );
	}
}
