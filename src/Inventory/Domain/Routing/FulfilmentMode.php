<?php
/**
 * FulfilmentMode: what the store does when no one location can ship a whole order
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Inventory\Domain\Routing;

defined( 'ABSPATH' ) || exit;

/**
 * The merchant's answer, in the setting `inventory.multi_location_fulfilment`, to one question: when no single location can ship the whole order, what happens.
 *
 * Owns one fact: the three answers. An order one location can ship whole ships from it in every
 * mode; the mode decides only the other orders, and the figure a shopper is shown, so that the
 * figure is one the mode can honour.
 *
 * @since 0.2.0
 */
enum FulfilmentMode: string {

	/**
	 * Ship from several locations, the highest-priority ones first: several parcels, one shipping charge.
	 *
	 * @since 0.2.0
	 */
	case Split = 'split'; // phpcs:ignore PHPCompatibility.FunctionUse.RemovedFunctions.splitDeprecatedRemoved -- An enum case named Split, not a call of the removed split().

	/**
	 * Accept the order at the highest-priority location, and bring its missing units there from the other locations before it ships.
	 *
	 * @since 0.2.0
	 */
	case Transfer = 'transfer';

	/**
	 * Refuse the order at checkout, saying why: an item exceeds what any one location has, or the items are at different locations.
	 *
	 * @since 0.2.0
	 */
	case Refuse = 'refuse';
}
