<?php
/**
 * OrderLineView: one line of an order, as the order recorded it
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

/**
 * A line read back from its snapshots, for showing the order.
 *
 * Owns one fact: what showing an order says about one of its lines. Every word comes from the
 * line's snapshot columns and every amount from its own row, so the line reads the same however
 * the catalog changes after the sale.
 *
 * @since 0.1.0
 */
final readonly class OrderLineView {

	/**
	 * Records the line.
	 *
	 * @since 0.1.0
	 *
	 * @param string       $lineUuid        The line's public identifier.
	 * @param string       $sku             The SKU when it was sold.
	 * @param string       $title           The product title when it was sold.
	 * @param string       $variantLabel    The variant's label when it was sold.
	 * @param int          $quantity        Units sold.
	 * @param AmountBasis  $unitAmountBasis Whether the unit price was authored net or gross.
	 * @param Money        $unitPrice       The unit price as authored.
	 * @param Money        $lineSubtotal    The line before discounts.
	 * @param Money        $lineDiscount    The discounts applied to the line.
	 * @param TaxedMoney   $amount          The line after discounts: net, tax and gross.
	 * @param Money        $lineTotal       The line total shown on the order.
	 * @param LineOption[] $options         The options it was sold with, in their order.
	 */
	public function __construct(
		public string $lineUuid,
		public string $sku,
		public string $title,
		public string $variantLabel,
		public int $quantity,
		public AmountBasis $unitAmountBasis,
		public Money $unitPrice,
		public Money $lineSubtotal,
		public Money $lineDiscount,
		public TaxedMoney $amount,
		public Money $lineTotal,
		public array $options
	) {
	}
}
