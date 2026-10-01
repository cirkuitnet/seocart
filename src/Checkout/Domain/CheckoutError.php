<?php
/**
 * CheckoutError: the errors the checkout's session and its idempotency keys can end in
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Checkout\Domain;

use SEOCart\Support\Error\ErrorCode;
use SEOCart\Support\Error\ErrorDefinition;

defined( 'ABSPATH' ) || exit;

/**
 * The errors of the checkout: an address or a method key it cannot use, and a placement request whose idempotency key it cannot honour.
 *
 * Owns one fact: how a refused checkout request is reported. A write refused by the cart's
 * version answers with the cart's own codes; a caller's programming error is an
 * \InvalidArgumentException or a \LogicException, never a row here.
 *
 * @since 0.1.0
 */
enum CheckoutError: string implements ErrorCode {

	/**
	 * An address the checkout was given has a field it cannot use.
	 *
	 * @since 0.1.0
	 */
	case InvalidAddress = 'checkout.invalid_address';

	/**
	 * A method key the checkout was given is not one.
	 *
	 * @since 0.1.0
	 */
	case InvalidMethodKey = 'checkout.invalid_method_key';

	/**
	 * The idempotency key names an order placed from a different request.
	 *
	 * @since 0.1.0
	 */
	case IdempotencyKeyReused = 'checkout.idempotency_key_reused';

	/**
	 * Another request with the same idempotency key is placing its order now.
	 *
	 * @since 0.1.0
	 */
	case PlacementInProgress = 'checkout.placement_in_progress';

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
				self::InvalidAddress,
				422,
				static fn(): string =>
					/* translators: %1$s: The address field that is not valid, such as shipping_address.country. */
					__( 'The address field %1$s is not valid. A country is a two-letter code, such as GB, and an email address looks like name@example.com.', 'seocart' ),
				array( 'field' )
			),
			new ErrorDefinition(
				self::InvalidMethodKey,
				422,
				static fn(): string =>
					/* translators: %1$s: The field that is not a method key, such as shipping_method_key. */
					__( 'The %1$s is not a method key. A method key uses only lower-case letters, digits, dots, colons, hyphens and underscores, such as flat.', 'seocart' ),
				array( 'field' )
			),
			new ErrorDefinition(
				self::IdempotencyKeyReused,
				422,
				static fn(): string => __( 'This idempotency key was already used to place an order from a different request. Send a new key with a new request.', 'seocart' )
			),
			new ErrorDefinition(
				self::PlacementInProgress,
				409,
				static fn(): string => __( 'An order is being placed with this idempotency key right now. Wait a moment, then send the same request again.', 'seocart' )
			),
		);
	}
}
