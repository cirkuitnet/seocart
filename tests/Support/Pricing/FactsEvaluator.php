<?php
/**
 * FactsEvaluator: evaluates promotion facts to their intents, the way a promotion module does
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support\Pricing;

use SEOCart\Pricing\Domain\Intent\DiscountLines;
use SEOCart\Pricing\Domain\Intent\DiscountOrder;
use SEOCart\Pricing\Domain\Intent\FreeShipping;
use SEOCart\Pricing\Domain\Intent\PromotionIntent;
use SEOCart\Pricing\Domain\PhaseAView;
use SEOCart\Pricing\Domain\PromotionEffect;
use SEOCart\Pricing\Domain\PromotionEvaluator;
use SEOCart\Pricing\Domain\PromotionFacts;
use SEOCart\Pricing\Domain\Source;

/**
 * Turns each promotion into its intent, lowest priority first, then in the order given.
 *
 * Owns one fact: the evaluation the scenario suite prices its promotions with, until the plugin
 * has a promotion module of its own: a percentage becomes a percentage off the lines, an amount
 * an amount off the order, free shipping free shipping, each with the promotion's source.
 *
 * @since 0.1.0
 */
final class FactsEvaluator implements PromotionEvaluator {

	/**
	 * Returns the promotions' intents in the order they apply.
	 *
	 * @since 0.1.0
	 *
	 * @param PhaseAView $view The promotions and the lines.
	 * @return list<PromotionIntent> The intents.
	 */
	public function evaluate( PhaseAView $view ): array {
		$promotions = $view->promotions;

		// Sorting is stable, so promotions of one priority keep the order they were given in.
		usort(
			$promotions,
			static fn( PromotionFacts $first, PromotionFacts $second ): int => $first->priority <=> $second->priority
		);

		return array_map(
			static function ( PromotionFacts $promotion ): PromotionIntent {
				$source = Source::promotion( $promotion->uuid );

				return match ( $promotion->effect->kind ) {
					PromotionEffect::PERCENT => new DiscountLines( $source, $promotion->effect->percentage ?? throw new \LogicException( 'A percent effect has a percentage.' ) ),
					PromotionEffect::FIXED   => new DiscountOrder( $source, $promotion->effect->amount ?? throw new \LogicException( 'A fixed effect has an amount.' ) ),
					default                  => new FreeShipping( $source ),
				};
			},
			$promotions
		);
	}
}
