<?php
/**
 * StockLevel: the counts of one stock item at one moment
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Inventory\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * A stock item as it was read: what is on hand, what is promised and what is held.
 *
 * Owns one fact: what "available" means. Available is on_hand − allocated − held. It may be
 * negative: an adjustment records the physical count, and a count below the units already
 * promised or held leaves a shortfall that the output reports rather than hides. A level is a
 * read, never a decision: a hold is decided by one conditional update, not by this figure.
 *
 * @since 0.1.0
 */
final readonly class StockLevel {

	/**
	 * The variant the item counts.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public int $variantId;

	/**
	 * Units physically in stock.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public int $onHand;

	/**
	 * Units promised to accepted orders.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public int $allocated;

	/**
	 * Units held by checkouts, expired holds included until they are reclaimed.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public int $held;

	/**
	 * Whether stock is counted; an untracked item is always available.
	 *
	 * @since 0.1.0
	 *
	 * @var bool
	 */
	public bool $track;

	/**
	 * What the item allows once its units run out.
	 *
	 * @since 0.1.0
	 *
	 * @var BackorderPolicy
	 */
	public BackorderPolicy $backorderPolicy;

	/**
	 * Records a level.
	 *
	 * @since 0.1.0
	 *
	 * @param int             $variantId       The variant.
	 * @param int             $onHand          Units in stock.
	 * @param int             $allocated       Units promised to orders.
	 * @param int             $held            Units held by checkouts.
	 * @param bool            $track           Whether stock is counted.
	 * @param BackorderPolicy $backorderPolicy The backorder policy.
	 */
	public function __construct( int $variantId, int $onHand, int $allocated, int $held, bool $track, BackorderPolicy $backorderPolicy ) {
		$this->variantId       = $variantId;
		$this->onHand          = $onHand;
		$this->allocated       = $allocated;
		$this->held            = $held;
		$this->track           = $track;
		$this->backorderPolicy = $backorderPolicy;
	}

	/**
	 * Returns the units that may still be promised.
	 *
	 * @since 0.1.0
	 *
	 * @return int on_hand − allocated − held; negative when an adjustment counted fewer units than are promised or held.
	 */
	public function available(): int {
		return $this->onHand - $this->allocated - $this->held;
	}
}
