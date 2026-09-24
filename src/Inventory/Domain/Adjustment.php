<?php
/**
 * Adjustment: a stock adjustment as it was recorded
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Inventory\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * The item's level after an adjustment, and the ledger entry that recorded it.
 *
 * Owns one fact: what an applied adjustment returns to its caller. The level is read under the
 * item's lock right after the change, so it is the level the ledger entry and the event record.
 *
 * @since 0.1.0
 */
final readonly class Adjustment {

	/**
	 * The item's level after the change.
	 *
	 * @since 0.1.0
	 *
	 * @var StockLevel
	 */
	public StockLevel $level;

	/**
	 * The id of the ledger entry that recorded the change.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public int $ledgerEntryId;

	/**
	 * Records an adjustment.
	 *
	 * @since 0.1.0
	 *
	 * @param StockLevel $level         The level after the change.
	 * @param int        $ledgerEntryId The ledger entry's id.
	 */
	public function __construct( StockLevel $level, int $ledgerEntryId ) {
		$this->level         = $level;
		$this->ledgerEntryId = $ledgerEntryId;
	}
}
