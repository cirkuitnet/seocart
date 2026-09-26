<?php
/**
 * TotalsSnapshot: the totals the calculation produced for an order, with what they were calculated under
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Order\Domain;

use SEOCart\Support\Money;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- These exceptions report a caller's programming error to the developer; they are never HTML.

/**
 * One `order_totals` row, before it has an id, and the totals the order row copies from it.
 *
 * Owns one fact: the totals an order is placed with. Only the calculation produces totals, so
 * every figure here is its figure, copied; the order adds nothing up. The trace is the
 * calculation's own account of how it reached them, stored as it was given.
 *
 * @since 0.1.0
 */
final readonly class TotalsSnapshot {

	/**
	 * Records the snapshot.
	 *
	 * @since 0.1.0
	 *
	 * @throws \InvalidArgumentException When the rate version is below 1.
	 *
	 * @param Money                $subtotal          The lines before discounts.
	 * @param Money                $discountTotal     Every discount.
	 * @param Money                $shippingTotal     Shipping.
	 * @param Money                $feeTotal          Every fee.
	 * @param Money                $taxTotal          Every tax.
	 * @param Money                $grandTotal        The grand total.
	 * @param Money                $amountDue         What the customer is to pay, which the order starts with as its due amount.
	 * @param Money                $baseSubtotal      The subtotal, in the base currency.
	 * @param Money                $baseDiscountTotal The discounts, in the base currency.
	 * @param Money                $baseShippingTotal Shipping, in the base currency.
	 * @param Money                $baseFeeTotal      The fees, in the base currency.
	 * @param Money                $baseTaxTotal      The tax, in the base currency.
	 * @param Money                $baseGrandTotal    The grand total, in the base currency.
	 * @param string               $taxRoundingMode   The tax rounding mode it was calculated under.
	 * @param string               $priceEntryMode    Whether the store's prices are entered net or gross of tax.
	 * @param string               $crossZonePolicy   The cross-zone tax policy it was calculated under.
	 * @param string|null          $taxDisplayMode    Whether prices were shown with or without tax, or null when not recorded.
	 * @param int|null             $rateVersion       The exchange-rate version the calculation read, 1 or more; null when it converted nothing.
	 * @param array<string, mixed> $trace             The calculation trace: scalars and lists of scalars, stored as JSON.
	 */
	public function __construct(
		public Money $subtotal,
		public Money $discountTotal,
		public Money $shippingTotal,
		public Money $feeTotal,
		public Money $taxTotal,
		public Money $grandTotal,
		public Money $amountDue,
		public Money $baseSubtotal,
		public Money $baseDiscountTotal,
		public Money $baseShippingTotal,
		public Money $baseFeeTotal,
		public Money $baseTaxTotal,
		public Money $baseGrandTotal,
		public string $taxRoundingMode,
		public string $priceEntryMode,
		public string $crossZonePolicy,
		public ?string $taxDisplayMode,
		public ?int $rateVersion,
		public array $trace
	) {
		if ( null !== $rateVersion && $rateVersion < 1 ) {
			throw new \InvalidArgumentException( sprintf( 'An exchange-rate version is 1 or more, or null when nothing was converted; %d was given.', $rateVersion ) );
		}
	}

	/**
	 * Returns every amount in the order's currency.
	 *
	 * @since 0.1.0
	 *
	 * @return list<Money> The amounts.
	 */
	public function amounts(): array {
		return array( $this->subtotal, $this->discountTotal, $this->shippingTotal, $this->feeTotal, $this->taxTotal, $this->grandTotal, $this->amountDue );
	}

	/**
	 * Returns every amount in the base currency.
	 *
	 * @since 0.1.0
	 *
	 * @return list<Money> The amounts.
	 */
	public function baseAmounts(): array {
		return array( $this->baseSubtotal, $this->baseDiscountTotal, $this->baseShippingTotal, $this->baseFeeTotal, $this->baseTaxTotal, $this->baseGrandTotal );
	}
}
