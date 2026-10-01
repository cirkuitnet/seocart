<?php
/**
 * CheckoutError: the errors the checkout's session, its idempotency keys and an order placement can end in
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
 * The errors of the checkout: an address or a method key it cannot use, a placement request whose idempotency key it cannot honour, and an order that cannot be placed or paid.
 *
 * Owns one fact: how a refused checkout request is reported. A write refused by the cart's
 * version answers with the cart's own codes, and a refusal by stock or by a promotion's limit
 * with theirs; a caller's programming error is an \InvalidArgumentException or a
 * \LogicException, never a row here. A placement refused after its order was created, because
 * the payment was declined or the gateway could not be reached, names the order in its details,
 * so the shopper's client can read its status.
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
	 * A placement request came without its Idempotency-Key header.
	 *
	 * @since 0.1.0
	 */
	case IdempotencyKeyMissing = 'checkout.idempotency_key_missing';

	/**
	 * The cart has no line to place an order for.
	 *
	 * @since 0.1.0
	 */
	case CartEmpty = 'checkout.cart_empty';

	/**
	 * The checkout lacks a detail an order needs. The error's details list the missing fields.
	 *
	 * @since 0.1.0
	 */
	case SessionIncomplete = 'checkout.session_incomplete';

	/**
	 * The cart's totals are not the ones the shopper agreed to. The error's details carry the cart's version and its totals now.
	 *
	 * @since 0.1.0
	 */
	case TotalsChanged = 'checkout.totals_changed';

	/**
	 * A line of the cart cannot be sold now.
	 *
	 * @since 0.1.0
	 */
	case LineUnsellable = 'checkout.line_unsellable';

	/**
	 * The gateway declined the payment. The error's details name the order, which failed.
	 *
	 * @since 0.1.0
	 */
	case PaymentDeclined = 'checkout.payment_declined';

	/**
	 * The gateway could not be reached. The error's details name the order, which waits for the gateway's answer.
	 *
	 * @since 0.1.0
	 */
	case GatewayUnavailable = 'checkout.gateway_unavailable';

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
			new ErrorDefinition(
				self::IdempotencyKeyMissing,
				400,
				static fn(): string => __( 'An order is placed only with an Idempotency-Key header: a new key, such as a UUID, for each attempt, and the same key again when the attempt is retried.', 'seocart' )
			),
			new ErrorDefinition(
				self::CartEmpty,
				422,
				static fn(): string => __( 'The cart has no line, so there is no order to place. Add a line to the cart first.', 'seocart' )
			),
			new ErrorDefinition(
				self::SessionIncomplete,
				422,
				static fn(): string => __( 'The checkout is missing details an order needs: both addresses, an email address on the billing address, and a payment method. The error lists the ones missing.', 'seocart' ),
				details: array( 'missing' )
			),
			new ErrorDefinition(
				self::TotalsChanged,
				409,
				static fn(): string => __( 'The cart\'s totals have changed since you read them, so no order was placed. Check the new totals, then place the order again with them.', 'seocart' ),
				details: array( 'version', 'totals' )
			),
			new ErrorDefinition(
				self::LineUnsellable,
				409,
				static fn(): string =>
					/* translators: 1: The id of a variant. 2: Why it cannot be sold, such as not_published. */
					__( 'The variant %1$s cannot be sold now (%2$s), so no order was placed. Remove it from the cart, then place the order again.', 'seocart' ),
				array( 'variant_id', 'reason' )
			),
			new ErrorDefinition(
				self::PaymentDeclined,
				402,
				static fn(): string => __( 'The payment was declined, so the order was not placed and nothing was charged. Check the payment details, or choose another way to pay, then place the order again.', 'seocart' ),
				details: array( 'order_uuid' )
			),
			new ErrorDefinition(
				self::GatewayUnavailable,
				503,
				static fn(): string => __( 'The payment provider could not be reached. Your order is waiting for its answer: check its status in a few minutes before you try again.', 'seocart' ),
				details: array( 'order_uuid' )
			),
		);
	}
}
