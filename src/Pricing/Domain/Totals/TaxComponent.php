<?php
/**
 * TaxComponent: the tax one rate charged on one line or adjustment
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Pricing\Domain\Totals;

use SEOCart\Pricing\Domain\AmountBasis;
use SEOCart\Pricing\Domain\Quote\TaxRateComponent;
use SEOCart\Support\TaxedMoney;

defined( 'ABSPATH' ) || exit;

/**
 * One rate's share of the tax on one line or one adjustment, in the cart's currency and the base currency.
 *
 * Owns one fact: the persisted allocation of tax to jurisdictions, one row of
 * `order_tax_components`. The components of an amount share out exactly the tax of that
 * amount: their taxes add up to it, and the residual says which component received a leftover
 * minor unit. The net of a component is the amount the rate was charged on, so each of two
 * rates on one line shows the line's net. A refund returns components, never recomputes them.
 *
 * @since 0.1.0
 */
final readonly class TaxComponent {

	/**
	 * Holds the component.
	 *
	 * @since 0.1.0
	 *
	 * @param string           $componentKey            Unique within the totals: `line:<key>:<n>` or `adjustment:<position>:<n>`.
	 * @param string|null      $ownerLineKey            The line taxed, or null for an adjustment.
	 * @param int|null         $ownerAdjustmentPosition The adjustment taxed, or null for a line.
	 * @param TaxRateComponent $rate                    The rate, with its jurisdiction.
	 * @param AmountBasis      $authoredBasis           The basis the taxed amount was authored in.
	 * @param TaxedMoney       $amount                  The amount charged on, this rate's tax, and their sum.
	 * @param TaxedMoney       $base                    The same, in the base currency.
	 * @param int              $residualMinor           The minor unit the split gave this component beyond its exact share: 0, or 1 with the tax's sign.
	 */
	public function __construct(
		public string $componentKey,
		public ?string $ownerLineKey,
		public ?int $ownerAdjustmentPosition,
		public TaxRateComponent $rate,
		public AmountBasis $authoredBasis,
		public TaxedMoney $amount,
		public TaxedMoney $base,
		public int $residualMinor
	) {
	}

	/**
	 * Returns the component as an array, for the totals' array.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, int|string|bool|null> The component.
	 */
	public function toArray(): array {
		return array(
			'component_key'             => $this->componentKey,
			'owner_line_key'            => $this->ownerLineKey,
			'owner_adjustment_position' => $this->ownerAdjustmentPosition,
			'jurisdiction_code'         => $this->rate->jurisdictionCode,
			'rate_ref'                  => $this->rate->rateRef,
			'rate_name'                 => $this->rate->name,
			'rate_micropercent'         => $this->rate->rate->micropercent(),
			'is_compound'               => $this->rate->isCompound,
			'priority'                  => $this->rate->priority,
			'authored_basis'            => $this->authoredBasis->value,
			'residual_minor'            => $this->residualMinor,
		) + Totals::figures( $this->amount ) + Totals::figures( $this->base, 'base_' );
	}
}
