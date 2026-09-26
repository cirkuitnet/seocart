<?php
/**
 * LineResolution: works out each line's amount
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Pricing\Domain\Engine;

use SEOCart\Pricing\Domain\InputLine;
use SEOCart\Support\Decimal;
use SEOCart\Support\Money;

defined( 'ABSPATH' ) || exit;

/**
 * The first step of the first phase: every line's amount is its unit price times its quantity.
 *
 * Owns one fact: how a line's amount is found. The product of a whole number of minor units and a
 * whole quantity is a whole number of minor units, so nothing is rounded; a product too large for
 * an integer throws rather than turning into a float.
 *
 * @since 0.1.0
 */
final class LineResolution {

	/**
	 * Resolves lines, in the order given.
	 *
	 * @since 0.1.0
	 *
	 * @param InputLine[] $lines The lines.
	 * @return list<ResolvedLine> Each line with its amount.
	 *
	 * @phpstan-param list<InputLine> $lines
	 */
	public function resolve( array $lines ): array {
		return array_map(
			static function ( InputLine $line ): ResolvedLine {
				$unit   = $line->unitPrice->amount;
				$amount = $unit->multiply( Decimal::ofUnscaled( $line->quantity, 0 ) );

				return new ResolvedLine( $line, $line->unitPrice->withAmount( Money::of( $amount->toUnscaledInt(), $unit->currency() ) ) );
			},
			$lines
		);
	}
}
