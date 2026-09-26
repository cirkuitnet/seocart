<?php
/**
 * TotalsAdjustment: one adjustment of a calculation's result, with its tax
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Pricing\Domain\Totals;

use SEOCart\Pricing\Domain\Source;
use SEOCart\Support\Money;
use SEOCart\Support\TaxedMoney;

defined( 'ABSPATH' ) || exit;

/**
 * An adjustment with its taxed figures, in the cart's currency and the base currency.
 *
 * Owns one fact: an adjustment as an order snapshots it into `order_adjustments`. A shipping
 * charge, a free-shipping discount and a fee are taxed as amounts of their own, with their own
 * components. A line-scoped discount is already inside its line, which is taxed once on the
 * discounted amount; its figures are its share of what the discounts changed in the line's net
 * and tax, so the line before discounts and its discounts add up exactly to the line after
 * them, and it has no components of its own.
 *
 * @since 0.1.0
 */
final readonly class TotalsAdjustment {

	/**
	 * Holds the adjustment.
	 *
	 * @since 0.1.0
	 *
	 * @param Adjustment $adjustment         The adjustment as the calculation made it.
	 * @param TaxedMoney $amount             Its net, tax and gross, signed.
	 * @param TaxedMoney $base               The same, in the base currency.
	 * @param Money      $baseAuthoredAmount The authored amount in the base currency, for `order_adjustments.base_amount_minor`
	 *                                       and the order's base discount, shipping and fee totals: the base twin of the taxed figure it equals.
	 * @param array      $components         Its tax components; none for a line-scoped discount or an untaxed amount.
	 *
	 * @phpstan-param list<TaxComponent> $components
	 */
	public function __construct(
		public Adjustment $adjustment,
		public TaxedMoney $amount,
		public TaxedMoney $base,
		public Money $baseAuthoredAmount,
		public array $components
	) {
	}

	/**
	 * Returns what caused the adjustment.
	 *
	 * @since 0.1.0
	 *
	 * @return Source The source.
	 */
	public function source(): Source {
		return $this->adjustment->source;
	}

	/**
	 * Returns the adjustment as an array, for the totals' array.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed> The adjustment.
	 */
	public function toArray(): array {
		return $this->adjustment->toArray() + array(
			'base_amount_minor' => $this->baseAuthoredAmount->minorUnits(),
		) + Totals::figures( $this->amount ) + Totals::figures( $this->base, 'base_' ) + array(
			'components' => array_map( static fn( TaxComponent $component ): array => $component->toArray(), $this->components ),
		);
	}
}
