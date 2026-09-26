<?php
/**
 * Allocation: the shares of an amount split by largest remainder, and where the leftover units went
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Pricing\Domain\Engine;

use SEOCart\Support\Money;

defined( 'ABSPATH' ) || exit;

/**
 * The result of splitting an amount: each share, and each share's residual.
 *
 * Owns one fact: what an allocation hands back. A share's residual is the share minus its exact
 * proportion rounded toward zero: 0 for most shares, and one minor unit, with the amount's sign,
 * for a share that received a leftover unit. A tax component persists it, so an order records
 * which component the residual minor unit went to.
 *
 * @since 0.1.0
 */
final readonly class Allocation {

	/**
	 * Holds the shares and their residuals.
	 *
	 * @since 0.1.0
	 *
	 * @param array $shares    The shares, keyed as the ratios were.
	 * @param array $residuals Each share's residual in minor units, keyed the same way.
	 *
	 * @phpstan-param array<int|string, Money> $shares
	 * @phpstan-param array<int|string, int>   $residuals
	 */
	public function __construct(
		public array $shares,
		public array $residuals
	) {
	}
}
