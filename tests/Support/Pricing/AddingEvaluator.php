<?php
/**
 * AddingEvaluator: a promotion evaluator that asks to add a line every time it is called
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support\Pricing;

use SEOCart\Pricing\Domain\AmountBasis;
use SEOCart\Pricing\Domain\Intent\AddLine;
use SEOCart\Pricing\Domain\Intent\DiscountLines;
use SEOCart\Pricing\Domain\PhaseAView;
use SEOCart\Pricing\Domain\PromotionEvaluator;
use SEOCart\Pricing\Domain\Source;
use SEOCart\Support\Percentage;

/**
 * Returns a gift line and a ten percent discount on every call, and counts the calls and the lines it saw.
 *
 * Owns one fact: the worst evaluator the re-entry bound must survive, one that would add a line
 * forever if it were asked forever. It is also the second implementation of the evaluator port
 * besides the plugin's own.
 *
 * @since 0.1.0
 */
final class AddingEvaluator implements PromotionEvaluator {

	/**
	 * How many times it was asked.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public int $calls = 0;

	/**
	 * How many lines it saw on each call.
	 *
	 * @since 0.1.0
	 *
	 * @var list<int>
	 */
	public array $linesSeen = array();

	/**
	 * Asks for a gift line and ten percent off.
	 *
	 * @since 0.1.0
	 *
	 * @param PhaseAView $view The promotions and the lines.
	 * @return list<\SEOCart\Pricing\Domain\Intent\PromotionIntent> The intents.
	 */
	public function evaluate( PhaseAView $view ): array {
		++$this->calls;
		$this->linesSeen[] = count( $view->lines );

		$source = Source::promotion( 'gift' );

		return array(
			new AddLine( $source, 900, 2, Inputs::amount( '0.50', AmountBasis::Net ), 'standard' ),
			new DiscountLines( $source, Percentage::fromString( '10' ) ),
		);
	}
}
