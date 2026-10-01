<?php
/**
 * PromotionUsage: the usage ledger, through which placing an order claims, commits and releases promotion uses
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Promotion\Application;

use SEOCart\Platform\Database\TransactionManager;
use SEOCart\Support\Error\CodedException;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The message reports a caller's programming error to the developer; it is never rendered.

/**
 * Claims the uses of an order's promotions, and later commits or releases them, inside the caller's transaction.
 *
 * Owns one fact: that a promotion's `used` always equals its reserved and committed usage rows,
 * and never passes its limit. A claim counts the use with one conditional update whose WHERE
 * clause carries the limit and inserts the usage row under that update's row lock, so of two
 * orders racing for the last use one gets it and the other is refused. A release gives each use
 * back with one conditional update that refuses to go below zero; a count that would, is
 * reported as corrupt, never repaired.
 *
 * Every method runs inside the caller's transaction and refuses to run outside one: an order
 * and its uses commit together or not at all. Placing an order claims its uses after inserting
 * the order, whose id the rows name, so its locks are taken in this order: stock, the order
 * number and the order's rows, then promotions by ascending id. The ledger sorts the claims
 * itself. Settling the order commits the uses when it is accepted and releases them when it is
 * declined; settling locks the order before the promotions.
 *
 * @since 0.1.0
 */
final class PromotionUsage {

	/**
	 * Creates the ledger. Sends nothing.
	 *
	 * @since 0.1.0
	 *
	 * @param PromotionRepository $promotions   The promotion statements.
	 * @param TransactionManager  $transactions Tells whether a transaction is open.
	 */
	public function __construct(
		private PromotionRepository $promotions,
		private TransactionManager $transactions
	) {
	}

	/**
	 * Claims one use of each promotion for an order, in ascending promotion id: two statements per promotion.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException When no transaction is open, before any statement.
	 * @throws CodedException  With PromotionError::LimitReached, and no context, when a promotion is used
	 *                         up or no longer active. The caller's transaction then rolls back every use
	 *                         claimed before it.
	 *
	 * @param UsageClaim[] $claims The uses, one per promotion, in any order.
	 *
	 * @phpstan-param list<UsageClaim> $claims
	 */
	public function claim( array $claims ): void {
		$this->requireTransaction( __FUNCTION__ );

		usort( $claims, static fn( UsageClaim $first, UsageClaim $second ): int => $first->promotionId <=> $second->promotionId );

		foreach ( $claims as $claim ) {
			if ( ! $this->promotions->claim( $claim->promotionId ) ) {
				CodedException::raise( PromotionError::LimitReached );
			}

			$this->promotions->insertUsage( $claim );
		}
	}

	/**
	 * Commits the uses an order reserved, once the order is accepted: one statement.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException When no transaction is open, before any statement.
	 *
	 * @param int $orderId The order.
	 */
	public function commit( int $orderId ): void {
		$this->requireTransaction( __FUNCTION__ );

		$this->promotions->commitUsage( $orderId );
	}

	/**
	 * Gives back the uses an order reserved, once the order is declined; an order without reserved uses changes nothing.
	 *
	 * Locks the order's reserved rows first, then gives each promotion its use back in ascending
	 * id, then marks the rows released: one statement, one per promotion, and one more.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException When no transaction is open, before any statement.
	 * @throws CodedException  With PromotionError::UsageCorrupt, naming the promotion, when its count is
	 *                         already zero. The caller's transaction then rolls back.
	 *
	 * @param int $orderId The order.
	 */
	public function release( int $orderId ): void {
		$this->requireTransaction( __FUNCTION__ );

		foreach ( $this->promotions->reservedPromotionIds( $orderId ) as $promotionId ) {
			if ( ! $this->promotions->giveBack( $promotionId ) ) {
				CodedException::raise( PromotionError::UsageCorrupt, array( 'promotion_id' => $promotionId ) );
			}
		}

		$this->promotions->releaseUsage( $orderId );
	}

	/**
	 * Refuses to change a use outside a transaction.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException Outside a transaction.
	 *
	 * @param string $method The method called.
	 */
	private function requireTransaction( string $method ): void {
		if ( 0 === $this->transactions->depth() ) {
			throw new \LogicException( sprintf( 'PromotionUsage::%s() runs only inside the transaction that places or settles the order: a use must commit with its order, or not at all.', $method ) );
		}
	}
}
