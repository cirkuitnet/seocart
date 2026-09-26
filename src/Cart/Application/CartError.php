<?php
/**
 * CartError: the errors a cart write can end in
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Cart\Application;

use SEOCart\Support\Error\ErrorCode;
use SEOCart\Support\Error\ErrorDefinition;

defined( 'ABSPATH' ) || exit;

/**
 * The errors reading or changing a cart can end in.
 *
 * Owns one fact: how a refused cart write is reported. A refusal of the compare-and-swap happened
 * in its WHERE clause; the values in the message come from one locking read afterwards that
 * classifies the refusal and decides nothing. A caller's programming error, such as a line of
 * zero units, is an \InvalidArgumentException or a \LogicException, never a row here.
 *
 * @since 0.1.0
 */
enum CartError: string implements ErrorCode {

	/**
	 * There is no cart for the token the request carries, or it has expired.
	 *
	 * @since 0.1.0
	 */
	case NotFound = 'cart.not_found';

	/**
	 * The cart has changed since the version the write was based on. The error's details carry the cart's current totals.
	 *
	 * @since 0.1.0
	 */
	case VersionStale = 'cart.version_stale';

	/**
	 * An order is being placed, or was placed, from the cart, so it can no longer be changed.
	 *
	 * @since 0.1.0
	 */
	case NotOpen = 'cart.not_open';

	/**
	 * The cart has no line with the identity the write names.
	 *
	 * @since 0.1.0
	 */
	case LineNotFound = 'cart.line_not_found';

	/**
	 * The write would leave the cart with more lines than a cart may hold.
	 *
	 * @since 0.1.0
	 */
	case TooManyLines = 'cart.too_many_lines';

	/**
	 * The client has started as many carts as it may in a day.
	 *
	 * @since 0.1.0
	 */
	case CreationLimited = 'cart.creation_limited';

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
				static fn(): string => __( 'There is no cart for this request: it was never started, or it has expired. Start a new cart by adding a line.', 'seocart' )
			),
			new ErrorDefinition(
				self::VersionStale,
				409,
				static fn(): string =>
					/* translators: %1$s: The version the cart is at now, a whole number. */
					__( 'The cart has changed since you read it, and is now at version %1$s. Read it again, then repeat your change if you still want it.', 'seocart' ),
				array( 'current_version' ),
				details: array( 'totals' )
			),
			new ErrorDefinition(
				self::NotOpen,
				409,
				static fn(): string =>
					/* translators: %1$s: The cart's status: placing or converted. */
					__( 'The cart can no longer be changed: an order has been placed from it (its status is %1$s). Start a new cart to keep shopping.', 'seocart' ),
				array( 'status' )
			),
			new ErrorDefinition(
				self::LineNotFound,
				404,
				static fn(): string =>
					/* translators: %1$s: The identity of a cart line, 64 hexadecimal characters. */
					__( 'The cart has no line %1$s.', 'seocart' ),
				array( 'line_identity' )
			),
			new ErrorDefinition(
				self::TooManyLines,
				409,
				static fn(): string =>
					/* translators: %1$s: The most lines a cart may hold. */
					__( 'A cart holds at most %1$s lines, so these lines were not added. Remove a line, or place an order and start a new cart.', 'seocart' ),
				array( 'max_lines' )
			),
			new ErrorDefinition(
				self::CreationLimited,
				429,
				static fn(): string => __( 'Too many carts have been started from here today. Try again later.', 'seocart' )
			),
		);
	}
}
