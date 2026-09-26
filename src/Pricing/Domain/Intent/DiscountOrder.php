<?php
/**
 * DiscountOrder: a fixed amount off the order, shared across its lines
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Pricing\Domain\Intent;

use SEOCart\Pricing\Domain\AuthoredAmount;
use SEOCart\Pricing\Domain\Source;

defined( 'ABSPATH' ) || exit;

/**
 * Takes a fixed amount off the order, shared across the lines in proportion to what they cost.
 *
 * Owns one fact: the amount-off intent. The amount is never negative, and it is never more than
 * the lines cost: the calculation caps it, so a total never goes below zero.
 *
 * @since 0.1.0
 */
final readonly class DiscountOrder implements PromotionIntent {

	/**
	 * Checks and holds the intent.
	 *
	 * @since 0.1.0
	 *
	 * @throws \InvalidArgumentException When the amount is negative.
	 *
	 * @param Source         $source What the intent comes from.
	 * @param AuthoredAmount $amount The amount off, with its basis.
	 */
	public function __construct(
		private Source $source,
		public AuthoredAmount $amount
	) {
		if ( $amount->amount->isNegative() ) {
			throw new \InvalidArgumentException( 'A discount takes an amount off, never adds one.' );
		}
	}

	/**
	 * Returns the kind of intent.
	 *
	 * @since 0.1.0
	 *
	 * @return string `discount_order`.
	 */
	public function type(): string {
		return 'discount_order';
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
