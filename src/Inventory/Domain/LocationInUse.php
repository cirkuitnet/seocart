<?php
/**
 * LocationInUse: why a location cannot be deleted
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Inventory\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * What still refers to a location, so that deleting it would leave a record that names nothing.
 *
 * Owns one fact: the reasons a location is kept, each the value a refused deletion reports. The
 * stock ledger is permanent, so a location that has ever had a movement is kept for good; it can
 * be deactivated instead.
 *
 * @since 0.2.0
 */
enum LocationInUse: string {

	/**
	 * It is the store's default location.
	 *
	 * @since 0.2.0
	 */
	case IsDefault = 'default';

	/**
	 * The stock ledger has an entry at it.
	 *
	 * @since 0.2.0
	 */
	case Ledger = 'ledger';

	/**
	 * One of its stock items has units on hand, allocated or held.
	 *
	 * @since 0.2.0
	 */
	case Stock = 'stock';

	/**
	 * A checkout holds units of one of its stock items.
	 *
	 * @since 0.2.0
	 */
	case Holds = 'holds';

	/**
	 * An order's allocation, open or posted, names it.
	 *
	 * @since 0.2.0
	 */
	case Allocations = 'allocations';

	/**
	 * A transfer sends units from it or to it.
	 *
	 * @since 0.2.0
	 */
	case Transfers = 'transfers';
}
