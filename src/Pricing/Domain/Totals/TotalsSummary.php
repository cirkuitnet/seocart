<?php
/**
 * TotalsSummary: the order-level figures of a calculation, and their base-currency twins
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Pricing\Domain\Totals;

use SEOCart\Support\Money;

defined( 'ABSPATH' ) || exit;

/**
 * The summary figures an order stores on its row: subtotal, discounts, shipping, fees, net, tax and grand total.
 *
 * Owns one fact: what the summary holds. The first four are sums of authored amounts: the
 * lines' subtotals (net, gross or mixed, as `subtotalBasis` says), then the adjustments by
 * type, discounts signed. Net, tax and grand are the sums of the taxed lines and of the
 * adjustments outside a line, so `net + tax = grand`. Every figure has its base-currency twin,
 * the sum of the same members' base figures. Totals computes them; this class only holds them.
 *
 * @since 0.1.0
 */
final readonly class TotalsSummary {

	/**
	 * Holds the figures.
	 *
	 * @since 0.1.0
	 *
	 * @param Money  $subtotal          The lines' subtotals, as authored.
	 * @param string $subtotalBasis     `net`, `gross`, or `mixed` when the lines have both.
	 * @param Money  $discountTotal     Every discount, signed: zero or negative.
	 * @param Money  $shippingTotal     The shipping charge, as authored.
	 * @param Money  $feeTotal          Every fee, as authored.
	 * @param Money  $net               The total before tax.
	 * @param Money  $tax               The tax.
	 * @param Money  $grand             The total including tax.
	 * @param Money  $baseSubtotal      The subtotal in the base currency.
	 * @param Money  $baseDiscountTotal The discounts in the base currency.
	 * @param Money  $baseShippingTotal The shipping in the base currency.
	 * @param Money  $baseFeeTotal      The fees in the base currency.
	 * @param Money  $baseNet           The total before tax in the base currency.
	 * @param Money  $baseTax           The tax in the base currency.
	 * @param Money  $baseGrand         The grand total in the base currency.
	 */
	public function __construct(
		public Money $subtotal,
		public string $subtotalBasis,
		public Money $discountTotal,
		public Money $shippingTotal,
		public Money $feeTotal,
		public Money $net,
		public Money $tax,
		public Money $grand,
		public Money $baseSubtotal,
		public Money $baseDiscountTotal,
		public Money $baseShippingTotal,
		public Money $baseFeeTotal,
		public Money $baseNet,
		public Money $baseTax,
		public Money $baseGrand
	) {
	}

	/**
	 * Returns the figures in minor units.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, int|string> The figures.
	 */
	public function toArray(): array {
		return array(
			'subtotal_minor'            => $this->subtotal->minorUnits(),
			'subtotal_basis'            => $this->subtotalBasis,
			'discount_total_minor'      => $this->discountTotal->minorUnits(),
			'shipping_total_minor'      => $this->shippingTotal->minorUnits(),
			'fee_total_minor'           => $this->feeTotal->minorUnits(),
			'net_minor'                 => $this->net->minorUnits(),
			'tax_minor'                 => $this->tax->minorUnits(),
			'grand_minor'               => $this->grand->minorUnits(),
			'base_subtotal_minor'       => $this->baseSubtotal->minorUnits(),
			'base_discount_total_minor' => $this->baseDiscountTotal->minorUnits(),
			'base_shipping_total_minor' => $this->baseShippingTotal->minorUnits(),
			'base_fee_total_minor'      => $this->baseFeeTotal->minorUnits(),
			'base_net_minor'            => $this->baseNet->minorUnits(),
			'base_tax_minor'            => $this->baseTax->minorUnits(),
			'base_grand_minor'          => $this->baseGrand->minorUnits(),
		);
	}
}
