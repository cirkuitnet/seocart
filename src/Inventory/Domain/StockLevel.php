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
 * A stock item as it was read: what is on hand at its location, what is promised and what is held there.
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
	 * The location whose units the item counts.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	public int $locationId;

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
	 * The variant's units on hand at every location, read with the item under its lock; null for a level read without it.
	 *
	 * @since 0.2.0
	 *
	 * @var int|null
	 */
	public ?int $variantOnHand;

	/**
	 * The variant's on_hand − allocated − held at every location, read with the item under its lock; null for a level read without it.
	 *
	 * @since 0.2.0
	 *
	 * @var int|null
	 */
	public ?int $variantAvailable;

	/**
	 * Records a level.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 The location, and the variant's totals.
	 *
	 * @param int             $variantId        The variant.
	 * @param int             $locationId       The location.
	 * @param int             $onHand           Units in stock.
	 * @param int             $allocated        Units promised to orders.
	 * @param int             $held             Units held by checkouts.
	 * @param bool            $track            Whether stock is counted.
	 * @param BackorderPolicy $backorderPolicy  The backorder policy.
	 * @param int|null        $variantOnHand    Optional. The variant's units in stock at every location. Default null, not read.
	 * @param int|null        $variantAvailable Optional. The variant's available units at every location. Default null, not read.
	 */
	public function __construct( int $variantId, int $locationId, int $onHand, int $allocated, int $held, bool $track, BackorderPolicy $backorderPolicy, ?int $variantOnHand = null, ?int $variantAvailable = null ) {
		$this->variantId        = $variantId;
		$this->locationId       = $locationId;
		$this->onHand           = $onHand;
		$this->allocated        = $allocated;
		$this->held             = $held;
		$this->track            = $track;
		$this->backorderPolicy  = $backorderPolicy;
		$this->variantOnHand    = $variantOnHand;
		$this->variantAvailable = $variantAvailable;
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
