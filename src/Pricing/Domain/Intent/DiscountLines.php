<?php
/**
 * DiscountLines: a percentage off every line
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Pricing\Domain\Intent;

use SEOCart\Pricing\Domain\Source;
use SEOCart\Support\Percentage;

defined( 'ABSPATH' ) || exit;

/**
 * Takes a percentage off each line, once per line, on what the line costs after earlier discounts.
 *
 * Owns one fact: the percent-off intent. The percentage is never negative.
 *
 * @since 0.1.0
 */
final readonly class DiscountLines implements PromotionIntent {

	/**
	 * Checks and holds the intent.
	 *
	 * @since 0.1.0
	 *
	 * @throws \InvalidArgumentException When the percentage is negative.
	 *
	 * @param Source     $source     What the intent comes from.
	 * @param Percentage $percentage The percentage off.
	 */
	public function __construct(
		private Source $source,
		public Percentage $percentage
	) {
		if ( $percentage->micropercent() < 0 ) {
			throw new \InvalidArgumentException( 'A discount takes a percentage off, never adds one.' );
		}
	}

	/**
	 * Returns the kind of intent.
	 *
	 * @since 0.1.0
	 *
	 * @return string `discount_lines`.
	 */
	public function type(): string {
		return 'discount_lines';
	}

	/**
	 * Returns what the intent comes from.
	 *
	 * @since 0.1.0
	 *
	 * @return Source The source.
	 */
	public function source(): Source {
		return $this->source;
	}
}
