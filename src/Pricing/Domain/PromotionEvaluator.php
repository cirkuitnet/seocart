<?php
/**
 * PromotionEvaluator: turns the promotions that apply into intents
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Pricing\Domain;

use SEOCart\Pricing\Domain\Intent\PromotionIntent;

defined( 'ABSPATH' ) || exit;

/**
 * The port through which the calculation learns what the promotions want, in its first phase.
 *
 * Owns one fact: the contract of promotion evaluation. An implementation is pure domain code: it
 * reads only the view it is given, the promotions resolved before the calculation and the lines
 * as the first phase resolved them, and returns intents in the order they apply. It never reads
 * storage, a rate or a provider, and never changes a total.
 *
 * @since 0.1.0
 */
interface PromotionEvaluator {

	/**
	 * Returns the intents of the promotions that apply to the lines.
	 *
	 * @since 0.1.0
	 *
	 * @param PhaseAView $view The promotions and the lines.
	 * @return list<PromotionIntent> The intents, in the order they apply.
	 */
	public function evaluate( PhaseAView $view ): array;
}
