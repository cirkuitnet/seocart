<?php
/**
 * ResolvedLine: a line with its amount, as the first phase resolved it
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Pricing\Domain\Engine;

use SEOCart\Pricing\Domain\AuthoredAmount;
use SEOCart\Pricing\Domain\InputLine;

defined( 'ABSPATH' ) || exit;

/**
 * An input line and its amount: the unit price times the quantity, exactly, in the unit price's basis.
 *
 * Owns one fact: a line's amount before anything is taken off it. The amount is worked out on the
 * line, never per unit: a per-unit figure is derived from the line for display only.
 *
 * @since 0.1.0
 */
final readonly class ResolvedLine {

	/**
	 * Holds the line and its amount.
	 *
	 * @since 0.1.0
	 *
	 * @param InputLine      $line       The line.
	 * @param AuthoredAmount $lineAmount Unit price times quantity.
	 */
	public function __construct(
		public InputLine $line,
		public AuthoredAmount $lineAmount
	) {
	}
}
