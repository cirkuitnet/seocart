<?php
/**
 * ShippingPortion: what a refund returns of an order's shipping
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Payment\Domain\Refund;

use SEOCart\Support\Money;
use SEOCart\Support\TaxedMoney;

defined( 'ABSPATH' ) || exit;

/**
 * What is left of an order's shipping, returned whole, with the shares of its tax components.
 *
 * Owns one fact: what a refund that gives back the shipping returns of it, beside what the order
 * stored of it and what earlier refunds returned, which the refund is capped by.
 *
 * @since 0.1.0
 */
final readonly class ShippingPortion {

	/**
	 * Records the portion.
	 *
	 * @since 0.1.0
	 *
	 * @param TaxedMoney         $stored          The order's shipping adjustments together, as stored.
	 * @param TaxedMoney         $storedBase      The same in the base currency.
	 * @param Money              $returnedNet     The shipping's net earlier refunds returned.
	 * @param Money              $returnedBaseNet The same in the base currency.
	 * @param Share              $share           What the refund returns of the shipping.
	 * @param ComponentPortion[] $components      What it returns of each of the shipping's tax components.
	 *
	 * @phpstan-param list<ComponentPortion> $components
	 */
	public function __construct(
		public TaxedMoney $stored,
		public TaxedMoney $storedBase,
		public Money $returnedNet,
		public Money $returnedBaseNet,
		public Share $share,
		public array $components
	) {
	}
}
