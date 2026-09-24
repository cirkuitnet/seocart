<?php
/**
 * BackorderPolicy: whether an item may be sold past its stock
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Inventory\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * What a stock item allows once its units run out.
 *
 * Owns one fact: the three backorder policies an item can store. Only posting an order to the
 * ledger reads it, and only `allow` lets a posting take on_hand below zero. A hold never reads
 * it: a checkout hold is refused when the units are not available, whatever the policy.
 *
 * @since 0.1.0
 */
enum BackorderPolicy: string {

	/**
	 * No backorders: the item cannot be sold past its stock.
	 *
	 * @since 0.1.0
	 */
	case No = 'no';

	/**
	 * No backorders, and customers may ask to be told when the item is back in stock.
	 *
	 * @since 0.1.0
	 */
	case Notify = 'notify';

	/**
	 * Backorders allowed: an order may be posted although on_hand does not cover it.
	 *
	 * @since 0.1.0
	 */
	case Allow = 'allow';
}
