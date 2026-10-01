<?php
/**
 * PromotionCodes: finds the promotions behind the codes a customer entered
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Pricing\Application;

use SEOCart\Support\Currency;

defined( 'ABSPATH' ) || exit;

/**
 * The port through which the calculator turns the codes entered into the promotions it applies, before its first phase.
 *
 * Owns one fact: the contract of resolving codes. An implementation reads the stored promotions
 * once for all the codes, whatever their number, and decides for each code whether its
 * promotion applies to a calculation in the currency at the instant. A code that does not is
 * returned with the reason, which only the trace records. It never counts or claims a use: a
 * usage limit is claimed when an order is placed, not checked while a cart is priced. The
 * promotion module implements it, so the calculator names no promotion class.
 *
 * @since 0.1.0
 */
interface PromotionCodes {

	/**
	 * Resolves codes into the promotions that apply, with one read.
	 *
	 * @since 0.1.0
	 *
	 * @param array              $codes    The codes, as the cart stores them, in the order they were applied.
	 * @param Currency           $currency The calculation's currency.
	 * @param \DateTimeImmutable $now      The instant the calculation is asked for.
	 * @return ResolvedPromotions The promotions that apply, in the order of their codes, and the codes that do not.
	 *
	 * @phpstan-param list<string> $codes
	 */
	public function forCodes( array $codes, Currency $currency, \DateTimeImmutable $now ): ResolvedPromotions;
}
