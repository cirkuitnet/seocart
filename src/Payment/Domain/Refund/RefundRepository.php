<?php
/**
 * RefundRepository: the statements of a refund, named by what they do
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Payment\Domain\Refund;

use SEOCart\Support\Currency;
use SEOCart\Support\Money;

defined( 'ABSPATH' ) || exit;

/**
 * The refund service's port to the payment tables: what earlier refunds returned, and the refund document.
 *
 * Owns one fact: which statements a refund sends. The reads are plain and batched, one per kind
 * of row whatever the number of lines, and decide nothing for good. The writes run only inside
 * the caller's transaction, each carrying a cap in its WHERE clause: the document is written
 * only while what it was worked out from still holds.
 *
 * @since 0.1.0
 */
interface RefundRepository {

	/**
	 * Reads an order's captured intent, which a refund gives money back through, and whether the ledger holds a result of it applied to nothing, without a lock.
	 *
	 * @since 0.1.0
	 *
	 * @param int $orderId The order.
	 * @return RefundableIntent|null The first captured or partly refunded intent, or null when the order has none.
	 */
	public function refundableIntent( int $orderId ): ?RefundableIntent;

	/**
	 * Adds up what earlier refunds returned of some order lines.
	 *
	 * @since 0.1.0
	 *
	 * @param int[]    $lineIds      The order lines.
	 * @param Currency $currency     The order's currency.
	 * @param Currency $baseCurrency The base currency.
	 * @return array<int, Share> What was returned, by order line id; a line nothing was returned of is absent.
	 *
	 * @phpstan-param list<int> $lineIds
	 */
	public function returnedOfLines( array $lineIds, Currency $currency, Currency $baseCurrency ): array;

	/**
	 * Adds up what earlier refunds returned of some tax components.
	 *
	 * @since 0.1.0
	 *
	 * @param int[]    $componentIds The order's tax components.
	 * @param Currency $currency     The order's currency.
	 * @param Currency $baseCurrency The base currency.
	 * @return array<int, Share> What was returned, by component id; a component nothing was returned of is absent.
	 *
	 * @phpstan-param list<int> $componentIds
	 */
	public function returnedOfComponents( array $componentIds, Currency $currency, Currency $baseCurrency ): array;

	/**
	 * Adds up the shipping earlier refunds of an order returned, before tax.
	 *
	 * @since 0.1.0
	 *
	 * @param int      $orderId      The order.
	 * @param Currency $currency     The order's currency.
	 * @param Currency $baseCurrency The base currency.
	 * @return array{0: Money, 1: Money} The shipping returned, and the same in the base currency.
	 */
	public function returnedShipping( int $orderId, Currency $currency, Currency $baseCurrency ): array;

	/**
	 * Inserts the refund's document, while the shipping the order's refunds returned, with this one's, stays within the stored shipping.
	 *
	 * @since 0.1.0
	 *
	 * @param RefundPlan $plan          The refund.
	 * @param int        $transactionId The ledger row of the gateway's refund.
	 * @param string     $actorType     `user` or `system`.
	 * @param int|null   $actorId       The user who refunds, or null.
	 * @return int|null The refund's id; null when the shipping's cap refused it.
	 */
	public function insertRefund( RefundPlan $plan, int $transactionId, string $actorType, ?int $actorId ): ?int;

	/**
	 * Inserts the refund's lines, in one statement; sends nothing when it returns no line.
	 *
	 * @since 0.1.0
	 *
	 * @param int        $refundId The refund.
	 * @param RefundPlan $plan     The refund.
	 */
	public function insertLines( int $refundId, RefundPlan $plan ): void;

	/**
	 * Inserts the shares of the tax components the refund returns, in one statement, each while it fits what is left of its component; sends nothing when there is none.
	 *
	 * @since 0.1.0
	 *
	 * @param int        $refundId The refund, whose lines are written.
	 * @param RefundPlan $plan     The refund.
	 * @return bool True when every share was written; false when a component's cap refused any.
	 */
	public function insertComponents( int $refundId, RefundPlan $plan ): bool;

	/**
	 * Reads the refund document that states a ledger row.
	 *
	 * @since 0.1.0
	 *
	 * @param int $transactionId The ledger row.
	 * @return Refund|null The refund, or null when the row has no document.
	 */
	public function findByTransaction( int $transactionId ): ?Refund;
}
