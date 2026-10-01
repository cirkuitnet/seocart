<?php
/**
 * FixedPromotionCodes: resolves codes from a fixed list of promotions, and records every call
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support\Pricing;

use SEOCart\Pricing\Application\PromotionCodes;
use SEOCart\Pricing\Application\ResolvedPromotions;
use SEOCart\Pricing\Domain\PromotionFacts;
use SEOCart\Pricing\Domain\RejectedCode;
use SEOCart\Support\Currency;

/**
 * The unit-test stand-in for the promotion module's resolver, as the calculator sees it.
 *
 * Owns one fact: which codes a unit test's store has. A code it was given is its promotion; any
 * other code is unknown. Every call is recorded with its codes, currency and instant, so a test
 * can assert how often, and when, the calculator asked.
 *
 * @since 0.1.0
 */
final class FixedPromotionCodes implements PromotionCodes {

	/**
	 * Every call, in order.
	 *
	 * @since 0.1.0
	 *
	 * @var list<array{codes: list<string>, currency: string, now: string}>
	 */
	public array $calls = array();

	/**
	 * The store's promotions.
	 *
	 * @since 0.1.0
	 *
	 * @var list<PromotionFacts>
	 */
	private array $promotions;

	/**
	 * Holds the store's promotions.
	 *
	 * @since 0.1.0
	 *
	 * @param PromotionFacts ...$promotions The promotions, each found by its code.
	 */
	public function __construct( PromotionFacts ...$promotions ) {
		$this->promotions = array_values( $promotions );
	}

	/**
	 * Returns the promotions of the codes it knows, in the order of the codes, and every other code as unknown.
	 *
	 * @since 0.1.0
	 *
	 * @param array              $codes    The codes.
	 * @param Currency           $currency The calculation's currency.
	 * @param \DateTimeImmutable $now      The instant.
	 * @return ResolvedPromotions The answer.
	 *
	 * @phpstan-param list<string> $codes
	 */
	public function forCodes( array $codes, Currency $currency, \DateTimeImmutable $now ): ResolvedPromotions {
		$this->calls[] = array(
			'codes'    => $codes,
			'currency' => $currency->code(),
			'now'      => $now->format( DATE_ATOM ),
		);

		$facts    = array();
		$rejected = array();

		foreach ( $codes as $code ) {
			$found = array_values( array_filter( $this->promotions, static fn( PromotionFacts $promotion ): bool => $code === $promotion->code ) );

			if ( array() === $found ) {
				$rejected[] = new RejectedCode( $code, RejectedCode::UNKNOWN );
			} else {
				$facts[] = $found[0];
			}
		}

		return new ResolvedPromotions( $facts, $rejected );
	}
}
