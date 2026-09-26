<?php
/**
 * TaxRoundingMode: where tax is rounded to the minor unit, per line or per subtotal
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tax\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * The point at which a calculation's tax is rounded to the minor unit.
 *
 * Owns one fact: the two tax rounding modes a store can choose between, spelled as the order
 * snapshots them. Rounded per line, every line's tax is a whole number of minor units on its
 * own. Rounded per subtotal, the lines that share a tax class and an amount basis are taxed as
 * one amount, rounded once, and that tax is then shared out to the lines, so the lines still
 * carry exact figures that add back up to the group's. The two can differ by a minor unit on a
 * total; neither ever breaks `net + tax = gross`.
 *
 * @since 0.1.0
 */
enum TaxRoundingMode: string {

	/**
	 * Every line is taxed and rounded on its own.
	 *
	 * @since 0.1.0
	 */
	case PerLine = 'per_line';

	/**
	 * The lines of one tax class and basis are taxed together, rounded once, and shared out.
	 *
	 * @since 0.1.0
	 */
	case PerSubtotal = 'per_subtotal';
}
