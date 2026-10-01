<?php
/**
 * Evaluator: turns the promotions that apply into the intents the calculation carries out
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Promotion\Domain;

use SEOCart\Pricing\Domain\Intent\DiscountLines;
use SEOCart\Pricing\Domain\Intent\DiscountOrder;
use SEOCart\Pricing\Domain\Intent\FreeShipping;
use SEOCart\Pricing\Domain\Intent\PromotionIntent;
use SEOCart\Pricing\Domain\PhaseAView;
use SEOCart\Pricing\Domain\PromotionEffect;
use SEOCart\Pricing\Domain\PromotionEvaluator;
use SEOCart\Pricing\Domain\PromotionFacts;
use SEOCart\Pricing\Domain\Source;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The message reports a broken invariant to the developer; it is never rendered.

/**
 * The promotion module's evaluator: one intent per promotion, in the order promotions apply.
 *
 * Owns one fact: what each kind of promotion asks of the calculation. A percentage becomes a
 * percentage off every line, a fixed amount an amount off the order shared across the lines,
 * and free shipping a discount of the selected rate; each names the promotion as its source.
 * Promotions apply lowest priority first, and promotions of one priority in the order their
 * codes were applied.
 *
 * It is pure: it reads only the view it is given, holds nothing, and imports nothing but the
 * calculation's own domain, so evaluating promotions can never reach storage, a rate or a
 * provider.
 *
 * @since 0.1.0
 */
final class Evaluator implements PromotionEvaluator {

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

		// Sorting is stable, so promotions of one priority keep the order of their codes.
		usort( $promotions, static fn( PromotionFacts $first, PromotionFacts $second ): int => $first->priority <=> $second->priority );

		return array_map( static fn( PromotionFacts $promotion ): PromotionIntent => self::intentOf( $promotion ), $promotions );
	}

	/**
	 * Returns what one promotion asks of the calculation.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException When an effect is of another kind or lacks its figure, which PromotionEffect never allows.
	 *
	 * @param PromotionFacts $promotion The promotion.
	 * @return PromotionIntent Its intent.
	 */
	private static function intentOf( PromotionFacts $promotion ): PromotionIntent {
		$source = Source::promotion( $promotion->uuid );
		$effect = $promotion->effect;

		return match ( $effect->kind ) {
			PromotionEffect::PERCENT       => new DiscountLines( $source, $effect->percentage ?? throw new \LogicException( 'A percent effect has a percentage.' ) ),
			PromotionEffect::FIXED         => new DiscountOrder( $source, $effect->amount ?? throw new \LogicException( 'A fixed effect has an amount.' ) ),
			PromotionEffect::FREE_SHIPPING => new FreeShipping( $source ),
			default                        => throw new \LogicException( sprintf( 'A promotion effect is percent, fixed or free_shipping, never "%s".', $effect->kind ) ),
		};
	}
}
