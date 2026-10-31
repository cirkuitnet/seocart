<?php
/**
 * TransferKind: why a transfer moves units between two locations
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Inventory\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Who started a transfer, and so how it begins and ends.
 *
 * Owns one fact: the kinds of transfer, each the value `stock_transfers.kind` holds. A merchant
 * makes a manual transfer, which starts as a draft. The system requests one for an order that
 * waits for units at another location: it carries that order's allocations. A deactivation that
 * moves a location's stock makes one that is in transit from the start and is received page by
 * page.
 *
 * @since 0.2.0
 */
enum TransferKind: string {

	/**
	 * A transfer a merchant made.
	 *
	 * @since 0.2.0
	 */
	case Manual = 'manual';

	/**
	 * A transfer the system requested for an order that waits for its units.
	 *
	 * @since 0.2.0
	 */
	case Order = 'order';

	/**
	 * The move of a deactivated location's available units to another location.
	 *
	 * @since 0.2.0
	 */
	case Deactivation = 'deactivation';
}
