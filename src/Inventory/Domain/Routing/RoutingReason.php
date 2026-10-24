<?php
/**
 * RoutingReason: why a plan puts an order's units where it does
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Inventory\Domain\Routing;

defined( 'ABSPATH' ) || exit;

/**
 * The rule of the router that made a plan, recorded with it.
 *
 * Owns one fact: the four ways an order is routed. The order's trace and its audit name the
 * reason, so a person reading an order can tell why it ships from where it does.
 *
 * @since 0.2.0
 */
enum RoutingReason: string {

	/**
	 * The shopper collects the order at the location they chose.
	 *
	 * @since 0.2.0
	 */
	case Pickup = 'pickup';

	/**
	 * One location ships the whole order: the highest-priority one that can.
	 *
	 * @since 0.2.0
	 */
	case WholeOrder = 'whole_order';

	/**
	 * No one location could, so the order ships from several, the highest-priority ones first.
	 *
	 * @since 0.2.0
	 */
	case Split = 'split'; // phpcs:ignore PHPCompatibility.FunctionUse.RemovedFunctions.splitDeprecatedRemoved -- An enum case named Split, not a call of the removed split().

	/**
	 * No one location could, so the order waits at its destination for units claimed where they are.
	 *
	 * @since 0.2.0
	 */
	case Transfer = 'transfer';
}
