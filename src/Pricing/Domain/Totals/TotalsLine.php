<?php
/**
 * TotalsLine: one line of a calculation's result
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Pricing\Domain\Totals;

use SEOCart\Pricing\Domain\AuthoredAmount;
use SEOCart\Pricing\Domain\InputLine;
use SEOCart\Support\Money;
use SEOCart\Support\TaxedMoney;

defined( 'ABSPATH' ) || exit;

/**
 * A priced line: what it was before discounts, what its discounts took off, and what it costs with its tax.
 *
 * Owns one fact: a line as an order snapshots it into `order_lines`. The subtotal is the unit
 * price times the quantity, as authored; the discount is the signed sum of the line's discount
 * adjustments in the same basis, so zero or negative. The amount is the line after its
 * discounts, taxed once: tax is always worked out on the discounted amount. Its components
 * share out exactly its tax. The unit gross is the line's gross divided by its quantity, for
 * display only; the line is never recomputed from it.
 *
 * The base twins of the subtotal and the discount are what an order's line keeps in
 * `base_line_discount_minor` and adds into the order's base subtotal, for reports that sum in
 * the base currency and never convert again. They are derived, not converted on their own: the
 * base discount is the sum of the line's discounts' base amounts, and the base subtotal is the
 * base twin of the line after discounts less that base discount. So the base subtotal and the
 * base discount add up to the line's base net, or its base gross for a gross price, as the cart
 * figures do.
 *
 * @since 0.1.0
 */
final readonly class TotalsLine {

	/**
	 * Holds the line.
	 *
	 * @since 0.1.0
	 *
	 * @param InputLine      $line                The line as it was priced: key, variant, quantity, unit price, source, class.
	 * @param AuthoredAmount $lineSubtotal        Unit price times quantity, before discounts.
	 * @param Money          $lineDiscount        The line's discounts, signed: zero or negative.
	 * @param TaxedMoney     $amount              The line after discounts: net, tax and gross.
	 * @param TaxedMoney     $base                The same, in the base currency.
	 * @param Money          $baseSubtotal        The subtotal in the base currency: the line's base amount after discounts less its base discount, which the order's base subtotal adds up.
	 * @param Money          $baseDiscount        The discount in the base currency: the sum of the line's discounts' base amounts, for `base_line_discount_minor`.
	 * @param Money          $unitGrossForDisplay The gross of one unit, for display only.
	 * @param array          $components          The tax components of the line.
	 *
	 * @phpstan-param list<TaxComponent> $components
	 */
	public function __construct(
		public InputLine $line,
		public AuthoredAmount $lineSubtotal,
		public Money $lineDiscount,
		public TaxedMoney $amount,
		public TaxedMoney $base,
		public Money $baseSubtotal,
		public Money $baseDiscount,
		public Money $unitGrossForDisplay,
		public array $components
	) {
	}

	/**
	 * Returns the line as an array, for the totals' array.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed> The line.
	 */
	public function toArray(): array {
		return array(
			'key'                          => $this->line->key,
			'variant_id'                   => $this->line->variantId,
			'quantity'                     => $this->line->quantity,
			'unit_price_minor'             => $this->line->unitPrice->amount->minorUnits(),
			'unit_amount_basis'            => $this->line->unitPrice->basis->value,
			'price_source'                 => $this->line->priceSource->value,
			'tax_class'                    => $this->line->taxClass,
			'auto_added'                   => $this->line->autoAdded,
			'line_subtotal_minor'          => $this->lineSubtotal->amount->minorUnits(),
			'line_discount_minor'          => $this->lineDiscount->minorUnits(),
			'base_line_subtotal_minor'     => $this->baseSubtotal->minorUnits(),
			'base_line_discount_minor'     => $this->baseDiscount->minorUnits(),
			'unit_gross_for_display_minor' => $this->unitGrossForDisplay->minorUnits(),
		) + Totals::figures( $this->amount ) + Totals::figures( $this->base, 'base_' ) + array(
			'components' => array_map( static fn( TaxComponent $component ): array => $component->toArray(), $this->components ),
		);
	}
}
