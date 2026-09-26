<?php
/**
 * Calculation: the totals of a request, and the lines they leave out
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Pricing\Application;

use SEOCart\Pricing\Domain\Totals\Totals;

defined( 'ABSPATH' ) || exit;

/**
 * What the calculator answers: the totals of every priced line, and the lines that could not be priced.
 *
 * Owns one fact: the result a cart shows and an order is placed from. A caller maps it without
 * arithmetic; while any line is unpriced, an order must not be placed.
 *
 * @since 0.1.0
 */
final readonly class Calculation {

	/**
	 * Holds the result.
	 *
	 * @since 0.1.0
	 *
	 * @param Totals $totals        The totals of the priced lines.
	 * @param array  $unpricedLines The lines left out, in cart order.
	 *
	 * @phpstan-param list<UnpricedLine> $unpricedLines
	 */
	public function __construct(
		public Totals $totals,
		public array $unpricedLines
	) {
	}
}
