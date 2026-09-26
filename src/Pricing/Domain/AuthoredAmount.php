<?php
/**
 * AuthoredAmount: an amount of money together with the basis it was authored in
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Pricing\Domain;

use SEOCart\Support\Currency;
use SEOCart\Support\Money;

defined( 'ABSPATH' ) || exit;

/**
 * An amount as it was typed or quoted: the money, and whether it is net or gross.
 *
 * Owns one fact: that an authored amount never travels without its basis. Every price, rate,
 * fixed discount and fee in a calculation is one of these, so the tax step knows whether to add
 * tax to it or take tax out of it.
 *
 * @since 0.1.0
 */
final readonly class AuthoredAmount {

	/**
	 * Holds the amount and its basis.
	 *
	 * @since 0.1.0
	 *
	 * @param Money       $amount The amount.
	 * @param AmountBasis $basis  Whether the amount is net or gross.
	 */
	public function __construct(
		public Money $amount,
		public AmountBasis $basis
	) {
	}

	/**
	 * Returns another amount in the same basis.
	 *
	 * @since 0.1.0
	 *
	 * @param Money $amount The other amount.
	 * @return self The amount, in this amount's basis.
	 */
	public function withAmount( Money $amount ): self {
		return new self( $amount, $this->basis );
	}

	/**
	 * Returns the currency of the amount.
	 *
	 * @since 0.1.0
	 *
	 * @return Currency The currency.
	 */
	public function currency(): Currency {
		return $this->amount->currency();
	}

	/**
	 * Returns the amount as scalars, for the trace and the totals' array.
	 *
	 * @since 0.1.0
	 *
	 * @return array{amount_minor: int, basis: string} The amount in minor units, and its basis.
	 */
	public function toArray(): array {
		return array(
			'amount_minor' => $this->amount->minorUnits(),
			'basis'        => $this->basis->value,
		);
	}
}
