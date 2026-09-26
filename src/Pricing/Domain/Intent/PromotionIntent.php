<?php
/**
 * PromotionIntent: what a promotion asks the calculation to do
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Pricing\Domain\Intent;

use SEOCart\Pricing\Domain\Source;

defined( 'ABSPATH' ) || exit;

/**
 * A declarative request from a promotion: take a percentage off, take an amount off, ship free, add a line.
 *
 * Owns one fact: that a promotion never changes a total itself. It only states an intent, and
 * the calculation applies it in its declared order, so a promotion cannot read a rate, call a
 * provider or depend on another promotion's arithmetic. Every intent names its source, which
 * every adjustment it causes carries.
 *
 * @since 0.1.0
 */
interface PromotionIntent {

	/**
	 * Returns the kind of intent, for the trace.
	 *
	 * @since 0.1.0
	 *
	 * @return string Such as `discount_lines`.
	 */
	public function type(): string;

	/**
	 * Returns what the intent comes from.
	 *
	 * @since 0.1.0
	 *
	 * @return Source Such as `promotion:<uuid>`.
	 */
	public function source(): Source;
}
