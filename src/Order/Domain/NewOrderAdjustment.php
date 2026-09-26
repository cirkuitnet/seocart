<?php
/**
 * NewOrderAdjustment: one signed change to an order's totals, as the calculation produced it
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Order\Domain;

use SEOCart\Support\Money;
use SEOCart\Support\TaxedMoney;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- These exceptions report a caller's programming error to the developer; they are never HTML.

/**
 * One `order_adjustments` row with its tax components, before any of them has an id.
 *
 * Owns one fact: what an order records about one discount, shipping charge or fee. Every
 * adjustment names the source that made it, so "which promotion or plugin changed this total"
 * is always answered by the row. The amounts are copied from the calculation, never computed
 * here.
 *
 * @since 0.1.0
 */
final readonly class NewOrderAdjustment {

	/**
	 * Records the adjustment, refusing one without a source.
	 *
	 * @since 0.1.0
	 *
	 * @throws \InvalidArgumentException When the source is empty.
	 *
	 * @param string            $scope               What it changes: `line`, `shipping` or `order`.
	 * @param string            $type                What it is: `discount`, `shipping` or `fee`.
	 * @param string            $source              What made it, for example `promotion:<uuid>`.
	 * @param string            $label               The label shown on the order, in the order's locale.
	 * @param AmountBasis       $authoredAmountBasis Whether the amount was authored net or gross.
	 * @param Money             $amount              The amount as authored, signed.
	 * @param TaxedMoney        $taxed               Net, tax and gross, signed.
	 * @param Money             $baseAmount          The authored amount in the base currency.
	 * @param TaxedMoney        $baseTaxed           Net, tax and gross in the base currency.
	 * @param string|null       $calculationBase     What a percentage adjustment was calculated on, or null for a fixed amount.
	 * @param string|null       $taxClass            The tax class it was taxed under, or null for the standard class.
	 * @param bool              $isTaxable           Whether it was taxed.
	 * @param string|null       $lineKey             The key of the line a line-scoped adjustment changes, or null.
	 * @param NewTaxComponent[] $taxComponents       Its tax, per jurisdiction.
	 */
	public function __construct(
		public string $scope,
		public string $type,
		public string $source,
		public string $label,
		public AmountBasis $authoredAmountBasis,
		public Money $amount,
		public TaxedMoney $taxed,
		public Money $baseAmount,
		public TaxedMoney $baseTaxed,
		public ?string $calculationBase,
		public ?string $taxClass,
		public bool $isTaxable,
		public ?string $lineKey = null,
		public array $taxComponents = array()
	) {
		if ( '' === $source ) {
			throw new \InvalidArgumentException( sprintf( 'Every adjustment names the source that made it; a %s adjustment was given none.', $type ) );
		}
	}

	/**
	 * Returns every amount of the adjustment in the order's currency, its components' included.
	 *
	 * @since 0.1.0
	 *
	 * @return list<Money|TaxedMoney> The amounts.
	 */
	public function amounts(): array {
		return array_merge( array( $this->amount, $this->taxed ), array_map( static fn( NewTaxComponent $component ): TaxedMoney => $component->amount, $this->taxComponents ) );
	}

	/**
	 * Returns every amount of the adjustment in the base currency, its components' included.
	 *
	 * @since 0.1.0
	 *
	 * @return list<Money|TaxedMoney> The amounts.
	 */
	public function baseAmounts(): array {
		return array_merge( array( $this->baseAmount, $this->baseTaxed ), array_map( static fn( NewTaxComponent $component ): TaxedMoney => $component->base, $this->taxComponents ) );
	}
}
