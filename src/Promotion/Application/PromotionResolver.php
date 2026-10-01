<?php
/**
 * PromotionResolver: finds the promotions behind codes, for a calculation and for a cart that applies a code
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Promotion\Application;

use SEOCart\Pricing\Application\PromotionCodes;
use SEOCart\Pricing\Application\ResolvedPromotions;
use SEOCart\Pricing\Domain\PromotionFacts;
use SEOCart\Pricing\Domain\RejectedCode;
use SEOCart\Support\Clock;
use SEOCart\Support\Currency;
use SEOCart\Support\Error\CodedException;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves codes into the promotions that apply, with one read for all of them.
 *
 * Owns one fact: which codes become promotions of a calculation. A code no promotion has is
 * unknown; a code whose promotion does not apply now, in this currency, is turned away with
 * Promotion's reason. Codes are compared exactly as stored: whoever receives a code from a
 * customer trims it and upper-cases it first. The reasons are for the trace; a customer who
 * applies a code is told only that it cannot be applied, whatever the reason (require()).
 *
 * @since 0.1.0
 */
final class PromotionResolver implements PromotionCodes {

	/**
	 * Creates the resolver. Sends nothing.
	 *
	 * @since 0.1.0
	 *
	 * @param PromotionRepository $promotions The promotion statements.
	 * @param Clock               $clock      Tells when a customer applies a code.
	 */
	public function __construct( private PromotionRepository $promotions, private Clock $clock ) {
	}

	/**
	 * Resolves codes into the promotions that apply, with one query, or none for no code.
	 *
	 * @since 0.1.0
	 *
	 * @param array              $codes    The codes, in the order they were applied. A repeated code counts once.
	 * @param Currency           $currency The calculation's currency.
	 * @param \DateTimeImmutable $now      The instant the calculation is asked for.
	 * @return ResolvedPromotions The promotions that apply, in the order of their codes, and the codes that do not, with the reason.
	 *
	 * @phpstan-param list<string> $codes
	 */
	public function forCodes( array $codes, Currency $currency, \DateTimeImmutable $now ): ResolvedPromotions {
		$codes = array_values( array_unique( $codes ) );

		if ( array() === $codes ) {
			return new ResolvedPromotions( array(), array() );
		}

		$found = array();

		foreach ( $this->promotions->findByCodes( $codes ) as $promotion ) {
			$found[ $promotion->code ] = $promotion;
		}

		$facts    = array();
		$rejected = array();

		foreach ( $codes as $code ) {
			$promotion = $found[ $code ] ?? null;
			$reason    = null === $promotion ? RejectedCode::UNKNOWN : $promotion->rejectionFor( $currency, $now );

			if ( null !== $reason ) {
				$rejected[] = new RejectedCode( $code, $reason );
			} elseif ( null !== $promotion ) {
				$facts[] = $promotion->facts();
			}
		}

		return new ResolvedPromotions( $facts, $rejected );
	}

	/**
	 * Returns the promotion of a code a customer applies now, or refuses the code with the same answer whatever the reason.
	 *
	 * The code is resolved as a calculation would resolve it at this instant, with one query.
	 *
	 * @since 0.1.0
	 *
	 * @throws CodedException With PromotionError::CodeInvalid, and no context, when the code is unknown or its promotion does not apply.
	 *
	 * @param string   $code     The code, trimmed and upper-cased.
	 * @param Currency $currency The cart's currency.
	 * @return PromotionFacts The promotion.
	 */
	public function require( string $code, Currency $currency ): PromotionFacts {
		$facts = $this->forCodes( array( $code ), $currency, $this->clock->now() )->facts;

		if ( array() === $facts ) {
			CodedException::raise( PromotionError::CodeInvalid );
		}

		return $facts[0];
	}
}
