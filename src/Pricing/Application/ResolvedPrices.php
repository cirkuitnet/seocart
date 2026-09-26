<?php
/**
 * ResolvedPrices: the lines a price was found for, and the lines without one
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Pricing\Application;

use SEOCart\Pricing\Domain\InputLine;

defined( 'ABSPATH' ) || exit;

/**
 * What price resolution found: every requested line, either priced or reported unpriced.
 *
 * Owns one fact: the outcome of one price lookup. Every requested line is in exactly one of the
 * two lists, each in request order.
 *
 * @since 0.1.0
 */
final readonly class ResolvedPrices {

	/**
	 * Holds the outcome.
	 *
	 * @since 0.1.0
	 *
	 * @param array $lines    The priced lines, in request order.
	 * @param array $unpriced The lines without a price, in request order.
	 *
	 * @phpstan-param list<InputLine>    $lines
	 * @phpstan-param list<UnpricedLine> $unpriced
	 */
	public function __construct(
		public array $lines,
		public array $unpriced
	) {
	}
}
