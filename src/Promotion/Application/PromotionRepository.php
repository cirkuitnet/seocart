<?php
/**
 * PromotionRepository: the promotion statements the resolver and the usage ledger send
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Promotion\Application;

use SEOCart\Promotion\Domain\Promotion;

defined( 'ABSPATH' ) || exit;

/**
 * The port to the promotion tables: one read of promotions by code, and the ledger's statements.
 *
 * Owns one fact: the statements the promotion services depend on, each one statement. A use is
 * counted by one conditional update whose WHERE clause carries the limit, so two orders can
 * never both take the last use; a use is given back by one whose WHERE clause refuses to go
 * below zero. Everything but the read runs only inside a transaction, and refuses otherwise:
 * a use counted without its usage row, or a row without its count, would be a lie.
 *
 * @since 0.1.0
 */
interface PromotionRepository {

	/**
	 * Reads the promotions that have any of the codes, with one query.
	 *
	 * @since 0.1.0
	 *
	 * @param array $codes The codes, at least one, each once.
	 * @return list<Promotion> The promotions found, in no particular order.
	 *
	 * @phpstan-param non-empty-list<string> $codes
	 */
	public function findByCodes( array $codes ): array;

	/**
	 * Counts one use of an active promotion, if its limit allows one more.
	 *
	 * @since 0.1.0
	 *
	 * @param int $promotionId The promotion.
	 * @return bool True when the use was counted; false when the promotion is not active, is used up, or is gone.
	 */
	public function claim( int $promotionId ): bool;

	/**
	 * Records a claimed use, reserved, after its count.
	 *
	 * @since 0.1.0
	 *
	 * @param UsageClaim $claim The use.
	 */
	public function insertUsage( UsageClaim $claim ): void;

	/**
	 * Marks an order's reserved uses committed.
	 *
	 * @since 0.1.0
	 *
	 * @param int $orderId The order.
	 */
	public function commitUsage( int $orderId ): void;

	/**
	 * Locks an order's reserved uses, and returns their promotions.
	 *
	 * @since 0.1.0
	 *
	 * @param int $orderId The order.
	 * @return list<int> The promotions' ids, ascending.
	 */
	public function reservedPromotionIds( int $orderId ): array;

	/**
	 * Gives one use of a promotion back, if it has one counted.
	 *
	 * @since 0.1.0
	 *
	 * @param int $promotionId The promotion.
	 * @return bool True when a use was given back; false when its count was already zero.
	 */
	public function giveBack( int $promotionId ): bool;

	/**
	 * Marks an order's reserved uses released.
	 *
	 * @since 0.1.0
	 *
	 * @param int $orderId The order.
	 */
	public function releaseUsage( int $orderId ): void;
}
