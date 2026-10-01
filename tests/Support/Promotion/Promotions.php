<?php
/**
 * Promotions: builds the promotions unit tests resolve and claim
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support\Promotion;

use SEOCart\Pricing\Domain\PromotionEffect;
use SEOCart\Promotion\Domain\Promotion;
use SEOCart\Support\Percentage;

/**
 * Makes a promotion as a code finds it, active and open-ended unless a test says otherwise.
 *
 * Owns one fact: the promotion a unit test starts from, so each test states only what it is
 * about. Its uuid is derived from its id.
 *
 * @since 0.1.0
 */
final class Promotions {

	/**
	 * Returns a promotion.
	 *
	 * @since 0.1.0
	 *
	 * @param int                     $id         The id.
	 * @param string                  $code       The code.
	 * @param PromotionEffect|null    $effect     Optional. What it does. Default 10 % off.
	 * @param string                  $status     Optional. Its status. Default active.
	 * @param int                     $priority   Optional. Its priority. Default 10.
	 * @param \DateTimeImmutable|null $startsAt   Optional. When it starts. Default no start.
	 * @param \DateTimeImmutable|null $endsAt     Optional. When it ends. Default no end.
	 * @param int                     $used       Optional. How many orders hold a use of it. Default 0.
	 * @param int|null                $usageLimit Optional. How many orders may use it. Default no limit.
	 * @return Promotion The promotion.
	 */
	public static function of( int $id, string $code, ?PromotionEffect $effect = null, string $status = Promotion::ACTIVE, int $priority = 10, ?\DateTimeImmutable $startsAt = null, ?\DateTimeImmutable $endsAt = null, int $used = 0, ?int $usageLimit = null ): Promotion {
		return new Promotion( $id, self::uuid( $id ), $code, $status, $effect ?? PromotionEffect::percent( Percentage::fromString( '10' ) ), $priority, $startsAt, $endsAt, $used, $usageLimit );
	}

	/**
	 * Returns the uuid of the promotion with an id.
	 *
	 * @since 0.1.0
	 *
	 * @param int $id The id.
	 * @return string A version 7 uuid ending in the id.
	 */
	public static function uuid( int $id ): string {
		return sprintf( '00000000-0000-7000-8000-%012d', $id );
	}
}
