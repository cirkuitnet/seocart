<?php
/**
 * ResolvedPromotions: the promotions a calculation applies, and the codes it turned away
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Pricing\Application;

use SEOCart\Pricing\Domain\PromotionFacts;
use SEOCart\Pricing\Domain\RejectedCode;

defined( 'ABSPATH' ) || exit;

/**
 * What resolving the codes entered came to: every code is either a promotion that applies or a rejected code.
 *
 * Owns one fact: the answer of PromotionCodes, which becomes the promotions and the rejected
 * codes of the calculation's input.
 *
 * @since 0.1.0
 */
final readonly class ResolvedPromotions {

	/**
	 * Holds the answer.
	 *
	 * @since 0.1.0
	 *
	 * @param array $facts    The promotions that apply, in the order of their codes.
	 * @param array $rejected The codes that do not apply, each with the reason.
	 *
	 * @phpstan-param list<PromotionFacts> $facts
	 * @phpstan-param list<RejectedCode>   $rejected
	 */
	public function __construct(
		public array $facts,
		public array $rejected
	) {
	}

	/**
	 * Returns every code turned away as unknown: the answer of a store without a promotion module.
	 *
	 * @since 0.1.0
	 *
	 * @param array $codes The codes entered.
	 * @return self No promotion, and each code rejected as unknown.
	 *
	 * @phpstan-param list<string> $codes
	 */
	public static function unknown( array $codes ): self {
		return new self( array(), array_map( static fn( string $code ): RejectedCode => new RejectedCode( $code, RejectedCode::UNKNOWN ), $codes ) );
	}
}
