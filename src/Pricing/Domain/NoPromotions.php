<?php
/**
 * NoPromotions: the evaluator of a store without promotions
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Pricing\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Evaluates every promotion to nothing.
 *
 * Owns one fact: the calculation of a store that runs no promotions. It is the evaluator the
 * plugin binds until a promotion module replaces it, and the baseline every scenario without a
 * promotion runs against.
 *
 * @since 0.1.0
 */
final class NoPromotions implements PromotionEvaluator {

	/**
	 * Returns no intent.
	 *
	 * @since 0.1.0
	 *
	 * @param PhaseAView $view The promotions and the lines; unused.
	 * @return list<\SEOCart\Pricing\Domain\Intent\PromotionIntent> Always empty.
	 */
	public function evaluate( PhaseAView $view ): array {
		return array();
	}
}
