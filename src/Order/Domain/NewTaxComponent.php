<?php
/**
 * NewTaxComponent: the tax one jurisdiction takes of a line or an adjustment, as the calculation allocated it
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Order\Domain;

use SEOCart\Support\TaxedMoney;

defined( 'ABSPATH' ) || exit;

/**
 * One `order_tax_components` row as the calculation produced it, before it has an id.
 *
 * Owns one fact: what an order records about one jurisdiction's tax on one line or adjustment.
 * It belongs to the line or adjustment that carries it, which is how the row learns what it
 * taxes. Every figure is copied from the calculation, never computed here.
 *
 * @since 0.1.0
 */
final readonly class NewTaxComponent {

	/**
	 * Records the component.
	 *
	 * @since 0.1.0
	 *
	 * @param string      $jurisdictionCode    The jurisdiction the tax is owed to.
	 * @param int|null    $taxRateId           The tax rate applied, or null when the rate came from a quote.
	 * @param string      $rateName            The rate's name.
	 * @param int         $rateMicropercent    The rate, in millionths of a percent.
	 * @param bool        $isCompound          Whether the rate applies on top of the rates before it.
	 * @param int         $priority            The order the rate was applied in among compound rates.
	 * @param AmountBasis $authoredAmountBasis Whether the taxed amount was authored net or gross.
	 * @param TaxedMoney  $amount              Net, tax and gross, in the order's currency.
	 * @param TaxedMoney  $base                The same, in the base currency.
	 * @param int         $residualMinor       What the allocation added to or took from the exact share, in minor units.
	 */
	public function __construct(
		public string $jurisdictionCode,
		public ?int $taxRateId,
		public string $rateName,
		public int $rateMicropercent,
		public bool $isCompound,
		public int $priority,
		public AmountBasis $authoredAmountBasis,
		public TaxedMoney $amount,
		public TaxedMoney $base,
		public int $residualMinor
	) {
	}
}
