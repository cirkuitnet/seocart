<?php
/**
 * ApplicationKind: what applying a gateway result did
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Payment\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * The kinds of Application a caller branches on.
 *
 * Owns one fact: the outcomes of applying one gateway result. Checkout settles stock, promotion
 * usage and the cart by the kind, in the same transaction.
 *
 * @since 0.1.0
 */
enum ApplicationKind: string {

	/**
	 * An approval matched the order and moved the money.
	 *
	 * @since 0.1.0
	 */
	case Applied = 'applied';

	/**
	 * The result was applied before; nothing changed.
	 *
	 * @since 0.1.0
	 */
	case Duplicate = 'duplicate';

	/**
	 * An approval did not match the intent or the order; it was recorded, moved nothing, and the order is parked for a person.
	 *
	 * @since 0.1.0
	 */
	case Mismatch = 'mismatch';

	/**
	 * The gateway declined; the intent failed.
	 *
	 * @since 0.1.0
	 */
	case Declined = 'declined';

	/**
	 * The customer must act; the intent waits for them.
	 *
	 * @since 0.1.0
	 */
	case RequiresAction = 'requires_action';

	/**
	 * The gateway is still deciding; the intent waits for it.
	 *
	 * @since 0.1.0
	 */
	case Pending = 'pending';
}
