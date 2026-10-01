<?php
/**
 * Share: net, tax and gross in the order's currency, and their base twins
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Payment\Domain\Refund;

use SEOCart\Support\TaxedMoney;

defined( 'ABSPATH' ) || exit;

/**
 * A part of a stored amount, in both currencies: what a refund returns of it, or what earlier refunds returned.
 *
 * Owns one fact: that a refund's figures always travel with their base twins. Both were
 * allocated from the order's stored figures; neither was converted.
 *
 * @since 0.1.0
 */
final readonly class Share {

	/**
	 * Records the share.
	 *
	 * @since 0.1.0
	 *
	 * @param TaxedMoney $amount Net, tax and gross in the order's currency.
	 * @param TaxedMoney $base   The same in the base currency, at the order's frozen rate.
	 */
	public function __construct(
		public TaxedMoney $amount,
		public TaxedMoney $base
	) {
	}

	/**
	 * Tells whether the share is nothing at all: every figure zero, in both currencies.
	 *
	 * @since 0.1.0
	 *
	 * @return bool True when it returns nothing.
	 */
	public function isNothing(): bool {
		foreach ( array( $this->amount, $this->base ) as $figures ) {
			if ( ! $figures->net()->isZero() || ! $figures->tax()->isZero() || ! $figures->gross()->isZero() ) {
				return false;
			}
		}

		return true;
	}
}
