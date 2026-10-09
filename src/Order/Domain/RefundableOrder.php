<?php
/**
 * RefundableOrder: what a refund of an order reads from it
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Order\Domain;

use SEOCart\Support\Currency;
use SEOCart\Support\TaxedMoney;

defined( 'ABSPATH' ) || exit;

/**
 * An order as a refund reads it: its currencies and frozen rate, the lines asked for, its shipping, their persisted tax components, and when a person last cleared its unreconciled money.
 *
 * Owns one fact: the stored figures a refund's shares are allocated from. Everything is read
 * from the order's own rows at its current totals version; nothing is priced, taxed or
 * converted again. The clearance travels as a value, so the payment module, which a refund's
 * money is read from, never reads an order table to learn it.
 *
 * @since 0.1.0
 * @since 0.2.0 When a person last cleared the order's unreconciled money.
 */
final readonly class RefundableOrder {

	/**
	 * Records the order.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 When a person last cleared the order's unreconciled money.
	 *
	 * @param int                           $id                  The order's internal id.
	 * @param string                        $uuid                Its public identifier.
	 * @param Currency                      $currency            Its currency.
	 * @param Currency                      $baseCurrency        The store's base currency when it was placed.
	 * @param int                           $conversionContextId Its frozen rate.
	 * @param int                           $totalsVersion       The version of its current totals, which the components are read at.
	 * @param array<string, RefundableLine> $lines               The lines asked for that the order has, by line uuid.
	 * @param TaxedMoney|null               $shipping            Its shipping adjustments together, net of a free-shipping discount; null when not asked for or there is none.
	 * @param TaxedMoney|null               $baseShipping        The same in the base currency.
	 * @param StoredTaxComponent[]          $components          The tax components of the lines asked for, and of the shipping when asked for.
	 * @param string|null                   $moneyReconciledAt   Optional. When a person last cleared the order's unreconciled money, UTC, as the database
	 *                                                           clock wrote it to the microsecond; a payment result applied to nothing before
	 *                                                           it no longer holds a refund back. Default null, for never.
	 *
	 * @phpstan-param list<StoredTaxComponent> $components
	 */
	public function __construct(
		public int $id,
		public string $uuid,
		public Currency $currency,
		public Currency $baseCurrency,
		public int $conversionContextId,
		public int $totalsVersion,
		public array $lines,
		public ?TaxedMoney $shipping,
		public ?TaxedMoney $baseShipping,
		public array $components,
		public ?string $moneyReconciledAt = null
	) {
	}
}
