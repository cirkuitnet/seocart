<?php
/**
 * Refund: a refund document, as recorded
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Payment\Domain\Refund;

use SEOCart\Support\Money;

defined( 'ABSPATH' ) || exit;

/**
 * A `refunds` row: one refund of an order, the money it returned and the ledger row it states.
 *
 * Owns one fact: what a refund that was recorded returned, in the order's currency and at the
 * order's own frozen rate. The total includes tax; the shipping and fees are before tax, as an
 * order's are.
 *
 * @since 0.1.0
 */
final readonly class Refund {

	/**
	 * Records the refund.
	 *
	 * @since 0.1.0
	 *
	 * @param int    $id                  The refund's internal id.
	 * @param string $uuid                Its public identifier.
	 * @param int    $orderId             The order refunded.
	 * @param int    $transactionId       The ledger row of the gateway's refund.
	 * @param int    $conversionContextId The order's frozen rate, which every base amount is at.
	 * @param Money  $total               What it returned, tax included.
	 * @param Money  $tax                 The tax it returned.
	 * @param Money  $shipping            The shipping it returned, before tax.
	 * @param Money  $baseTotal           The total in the base currency.
	 * @param Money  $baseTax             The tax in the base currency.
	 * @param Money  $baseShipping        The shipping in the base currency.
	 * @param string $reasonCode          Why the order was refunded.
	 */
	public function __construct(
		public int $id,
		public string $uuid,
		public int $orderId,
		public int $transactionId,
		public int $conversionContextId,
		public Money $total,
		public Money $tax,
		public Money $shipping,
		public Money $baseTotal,
		public Money $baseTax,
		public Money $baseShipping,
		public string $reasonCode
	) {
	}
}
