<?php
/**
 * LedgerReason: why an item's on_hand moved
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Inventory\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * The reason every stock ledger entry records.
 *
 * Owns one fact: the reasons stock may move, and which of them a merchant may give. A merchant
 * adjusts stock with one of merchant()'s reasons; the adjust-stock operation builds its allowed
 * values from that list and from nowhere else. The other reasons are written by the system: a
 * deleted variant's last entry says `variant_deleted`.
 *
 * @since 0.1.0
 */
enum LedgerReason: string {

	/**
	 * Units arrived: a delivery was received.
	 *
	 * @since 0.1.0
	 */
	case Received = 'received';

	/**
	 * A count of the shelf corrected the figure.
	 *
	 * @since 0.1.0
	 */
	case Recount = 'recount';

	/**
	 * Units were damaged or lost and taken out of stock.
	 *
	 * @since 0.1.0
	 */
	case Damaged = 'damaged';

	/**
	 * Units a customer returned were put back into stock.
	 *
	 * @since 0.1.0
	 */
	case Returned = 'returned';

	/**
	 * An earlier entry was wrong, and this one corrects it.
	 *
	 * @since 0.1.0
	 */
	case Correction = 'correction';

	/**
	 * The variant was deleted: its remaining units were written off and its item removed.
	 *
	 * @since 0.1.0
	 */
	case VariantDeleted = 'variant_deleted';

	/**
	 * Returns the reasons a merchant may give for an adjustment.
	 *
	 * @since 0.1.0
	 *
	 * @return list<self> received, recount, damaged, returned and correction, in that order.
	 */
	public static function merchant(): array {
		return array( self::Received, self::Recount, self::Damaged, self::Returned, self::Correction );
	}
}
