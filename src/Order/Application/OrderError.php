<?php
/**
 * OrderError: the error catalog of the order module
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Order\Application;

use SEOCart\Support\Error\ErrorCode;
use SEOCart\Support\Error\ErrorDefinition;

defined( 'ABSPATH' ) || exit;

/**
 * The errors reading or changing an order can end in.
 *
 * Owns one fact: how a refused order read or status change is reported. A refused transition
 * was refused in the database, by the conditional update's WHERE clause; the values in its
 * message come from the locked read before it, which decided nothing. A caller's programming
 * error, such as a call outside the transaction it needs, is a \LogicException, never a row here.
 *
 * `order.not_found` names no order: a storefront answers every refusal to show an order with it,
 * so its message must not tell an order that exists from one that is not the caller's.
 *
 * @since 0.1.0
 */
enum OrderError: string implements ErrorCode {

	/**
	 * No order matches, or the caller may not see it.
	 *
	 * @since 0.1.0
	 */
	case NotFound = 'order.not_found';

	/**
	 * The order status registry does not allow the order to change from its status to the one asked for.
	 *
	 * @since 0.1.0
	 */
	case TransitionIllegal = 'order.transition_illegal';

	/**
	 * Returns the catalog's rows.
	 *
	 * @since 0.1.0
	 *
	 * @return list<ErrorDefinition> One row per case.
	 */
	public static function definitions(): array {
		return array(
			new ErrorDefinition(
				self::NotFound,
				404,
				static fn(): string => __( 'The order was not found.', 'seocart' )
			),
			new ErrorDefinition(
				self::TransitionIllegal,
				409,
				static fn(): string =>
					/* translators: %1$s: The order's status now. %2$s: The status asked for. */
					__( 'An order cannot change from %1$s to %2$s.', 'seocart' ),
				array( 'from', 'to' )
			),
		);
	}
}
