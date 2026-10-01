<?php
/**
 * StoredAmount: an amount as an order stored it, in both currencies
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
 * The net, tax and gross an order stored for a line or a tax component, their base twins, and the basis the amount was authored in.
 *
 * Owns one fact: what a refund gives back a share of. The figures are the stored row's, read
 * back, never worked out again: an order keeps the amounts it was placed at whatever rate,
 * price or tax rate holds today.
 *
 * @since 0.1.0
 */
final readonly class StoredAmount {

	/**
	 * Records the amount.
	 *
	 * @since 0.1.0
	 *
	 * @param AmountBasis $basis  Whether the amount was authored net or gross.
	 * @param TaxedMoney  $amount Its net, tax and gross, in the order's currency.
	 * @param TaxedMoney  $base   The same in the base currency, at the order's frozen rate.
	 */
	public function __construct(
		public AmountBasis $basis,
		public TaxedMoney $amount,
		public TaxedMoney $base
	) {
	}
}
