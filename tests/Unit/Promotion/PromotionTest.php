<?php
/**
 * Tests when a promotion found by its code applies to a calculation
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Promotion;

use PHPUnit\Framework\TestCase;
use SEOCart\Pricing\Domain\AmountBasis;
use SEOCart\Pricing\Domain\PromotionEffect;
use SEOCart\Promotion\Domain\Promotion;
use SEOCart\Support\Currency;
use SEOCart\Tests\Support\Pricing\Inputs;
use SEOCart\Tests\Support\Promotion\Promotions;

/**
 * A promotion applies while active, inside its window, below its usage limit, and, for a fixed amount off, only in that amount's currency.
 *
 * Planted violations, shown red and removed: in Promotion::rejectionFor(), drop the end of the
 * window. The ended promotion then applies, and the window test fails. The limit test names its own.
 *
 * @since 0.1.0
 */
final class PromotionTest extends TestCase {

	/**
	 * The instant every case is judged at.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const NOW = '2026-09-24 12:00:00';

	/**
	 * Tests the window: it applies from its start to its end, both included, and not a moment outside.
	 *
	 * @since 0.1.0
	 */
	public function test_it_applies_inside_its_window_and_not_outside(): void {
		$now = self::instant( self::NOW );

		$this->assertNull( Promotions::of( 1, 'OPEN' )->rejectionFor( Currency::of( 'USD' ), $now ), 'No start and no end.' );
		$this->assertNull( Promotions::of( 1, 'EDGES', startsAt: $now, endsAt: $now )->rejectionFor( Currency::of( 'USD' ), $now ), 'Its first and last instant.' );
		$this->assertSame( Promotion::NOT_STARTED, Promotions::of( 1, 'SOON', startsAt: self::instant( '2026-09-24 12:00:01' ) )->rejectionFor( Currency::of( 'USD' ), $now ) );
		$this->assertSame( Promotion::ENDED, Promotions::of( 1, 'GONE', endsAt: self::instant( '2026-09-24 11:59:59' ) )->rejectionFor( Currency::of( 'USD' ), $now ) );
	}

	/**
	 * Tests that only an active promotion applies, whatever its window.
	 *
	 * @since 0.1.0
	 */
	public function test_only_an_active_promotion_applies(): void {
		foreach ( array( 'draft', 'paused', 'archived' ) as $status ) {
			$this->assertSame( Promotion::NOT_ACTIVE, Promotions::of( 1, 'CODE', status: $status )->rejectionFor( Currency::of( 'USD' ), self::instant( self::NOW ) ), $status );
		}
	}

	/**
	 * Tests that a promotion applies while its uses are below its limit, and never once they reach it; one without a limit always does.
	 *
	 * Planted violation: in Promotion::rejectionFor(), compare the uses with `>` instead of `>=`:
	 * the promotion used once of once then applies, and this test fails.
	 *
	 * @since 0.1.0
	 */
	public function test_a_promotion_used_up_to_its_limit_no_longer_applies(): void {
		$now = self::instant( self::NOW );

		$this->assertNull( Promotions::of( 1, 'LEFT', used: 0, usageLimit: 1 )->rejectionFor( Currency::of( 'USD' ), $now ), 'Used none of once.' );
		$this->assertSame( Promotion::USED_UP, Promotions::of( 1, 'ONCE', used: 1, usageLimit: 1 )->rejectionFor( Currency::of( 'USD' ), $now ), 'Used once of once.' );
		$this->assertSame( Promotion::USED_UP, Promotions::of( 1, 'OVER', used: 3, usageLimit: 2 )->rejectionFor( Currency::of( 'USD' ), $now ), 'Used beyond its limit.' );
		$this->assertNull( Promotions::of( 1, 'NOLIMIT', used: 500 )->rejectionFor( Currency::of( 'USD' ), $now ), 'No limit.' );
	}

	/**
	 * Tests that a fixed amount off applies only in its currency, and a percentage and free shipping in any.
	 *
	 * @since 0.1.0
	 */
	public function test_a_fixed_amount_applies_only_in_its_currency(): void {
		$now   = self::instant( self::NOW );
		$fixed = Promotions::of( 1, 'TENGBP', PromotionEffect::fixed( Inputs::amount( '10.00', AmountBasis::Gross, 'GBP' ) ) );

		$this->assertNull( $fixed->rejectionFor( Currency::of( 'GBP' ), $now ) );
		$this->assertSame( Promotion::OTHER_CURRENCY, $fixed->rejectionFor( Currency::of( 'EUR' ), $now ) );
		$this->assertNull( Promotions::of( 2, 'PERCENT' )->rejectionFor( Currency::of( 'EUR' ), $now ) );
		$this->assertNull( Promotions::of( 3, 'SHIP', PromotionEffect::freeShipping() )->rejectionFor( Currency::of( 'JPY' ), $now ) );
	}

	/**
	 * Tests the facts a calculation is told: the promotion's id, uuid, code, effect and priority.
	 *
	 * @since 0.1.0
	 */
	public function test_the_facts_are_the_promotions_own(): void {
		$promotion = Promotions::of( 4, 'FACTS', priority: 7 );
		$facts     = $promotion->facts();

		$this->assertSame( array( 4, Promotions::uuid( 4 ), 'FACTS', 7 ), array( $facts->id, $facts->uuid, $facts->code, $facts->priority ) );
		$this->assertSame( $promotion->effect, $facts->effect );
	}

	/**
	 * Returns a UTC instant.
	 *
	 * @since 0.1.0
	 *
	 * @param string $at The date and time, UTC.
	 * @return \DateTimeImmutable The instant.
	 */
	private static function instant( string $at ): \DateTimeImmutable {
		return new \DateTimeImmutable( $at, new \DateTimeZone( 'UTC' ) );
	}
}
