<?php
/**
 * TaxRateComponent: one tax rate of one jurisdiction, as quoted
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Pricing\Domain\Quote;

use SEOCart\Support\Percentage;

defined( 'ABSPATH' ) || exit;

/**
 * A single rate that applies to a tax class: a state's rate, a county's, a country's VAT.
 *
 * Owns one fact: what the calculation knows of one quoted rate. Each one becomes a tax
 * component of every amount of its class, which an order persists and a refund returns.
 *
 * @since 0.1.0
 */
final readonly class TaxRateComponent {

	/**
	 * Holds the rate.
	 *
	 * @since 0.1.0
	 *
	 * @param string     $rateRef          The provider's reference for the rate.
	 * @param string     $name             The rate's name, as the provider gives it.
	 * @param Percentage $rate             The rate.
	 * @param bool       $isCompound       Whether the rate is charged on the amount including the rates before it.
	 * @param int        $priority         The order compound rates apply in, lowest first.
	 * @param string     $jurisdictionCode The jurisdiction that levies the rate.
	 */
	public function __construct(
		public string $rateRef,
		public string $name,
		public Percentage $rate,
		public bool $isCompound,
		public int $priority,
		public string $jurisdictionCode
	) {
	}
}
