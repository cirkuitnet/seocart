<?php
/**
 * ComponentShare: one rate's share of an amount's tax, before its base-currency twin is known
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Pricing\Domain\Engine;

use SEOCart\Pricing\Domain\Quote\TaxRateComponent;
use SEOCart\Support\TaxedMoney;

defined( 'ABSPATH' ) || exit;

/**
 * A tax component as the tax step leaves it: the rate, the amount charged on with this rate's tax, and the residual.
 *
 * Owns one fact: a component in the cart's currency. The base-currency step adds its twin, and
 * the totals then carry it as a TaxComponent.
 *
 * @since 0.1.0
 */
final readonly class ComponentShare {

	/**
	 * Holds the share.
	 *
	 * @since 0.1.0
	 *
	 * @param TaxRateComponent $rate          The rate.
	 * @param TaxedMoney       $amount        The amount charged on, this rate's tax, and their sum.
	 * @param int              $residualMinor The minor unit the split gave this share beyond its exact proportion.
	 */
	public function __construct(
		public TaxRateComponent $rate,
		public TaxedMoney $amount,
		public int $residualMinor
	) {
	}
}
