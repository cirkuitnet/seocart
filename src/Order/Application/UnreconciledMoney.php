<?php
/**
 * UnreconciledMoney: when an order's newest money a person must reconcile was recorded
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Order\Application;

defined( 'ABSPATH' ) || exit;

/**
 * The order service's port to the ledger of an order's payments: when the newest of their results that moved no money was recorded.
 *
 * Owns one fact: the time a clearance of an order's unreconciled money must come after. Such a
 * result is written while its writer holds the order's lock, before the order is flagged for it,
 * so the clearance, which holds the same lock, finds every one; it is dated after the newest, by
 * the database clock that dated them, so none of them holds the payment's refunds back once the
 * flag is down, whatever that clock did between a result and its flag. The payment module answers
 * it: the order module names no payment table.
 *
 * @since 0.2.0
 */
interface UnreconciledMoney {

	/**
	 * Reads when the newest result of an order's payments that moved no money was recorded, without a lock.
	 *
	 * @since 0.2.0
	 *
	 * @param int $orderId The order's internal id.
	 * @return string|null The time, UTC to the microsecond, as the database clock wrote it; null when the order's payments have no such result.
	 */
	public function newestAt( int $orderId ): ?string;
}
