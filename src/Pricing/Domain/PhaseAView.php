<?php
/**
 * PhaseAView: what a promotion evaluator may read
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Pricing\Domain;

use SEOCart\Pricing\Domain\Engine\ResolvedLine;

defined( 'ABSPATH' ) || exit;

/**
 * The read-only view a promotion evaluator is handed: the promotions that apply and the resolved lines.
 *
 * Owns one fact: the whole of what promotion evaluation depends on. Nothing in it can be used to
 * reach storage or a provider.
 *
 * @since 0.1.0
 */
final readonly class PhaseAView {

	/**
	 * Holds the view.
	 *
	 * @since 0.1.0
	 *
	 * @param array $promotions The promotions, as resolved before the calculation.
	 * @param array $lines      The lines, with their amounts, in cart order.
	 *
	 * @phpstan-param list<PromotionFacts> $promotions
	 * @phpstan-param list<ResolvedLine>   $lines
	 */
	public function __construct(
		public array $promotions,
		public array $lines
	) {
	}
}
