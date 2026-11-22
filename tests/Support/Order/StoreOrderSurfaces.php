<?php
/**
 * StoreOrderSurfaces: the storefront operations that name an order, and who guards each
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support\Order;

use SEOCart\Application\Operations\OperationDefinition;
use SEOCart\Application\Operations\OperationRegistry;
use SEOCart\Support\Error\ErrorCode;
use SEOCart\Support\Error\ErrorDefinition;
use SEOCart\Support\Schema\FieldSpec;

/**
 * The list of storefront order surfaces the five-case test runs on, and the cart's surfaces that answer an order's uuid under the cart's token instead.
 *
 * Owns one fact for the tests: which Store API operations show an order, and how each is guarded.
 * An operation names an order when it belongs to the order module (its id begins `order.`), when
 * any field it takes or answers, at any depth, is an order's uuid (`order_uuid`), or when any
 * error it declares may carry an order's uuid in its details. Each is either
 * guarded by the access policy, and then the route test runs the five cases on it, or is one of the
 * cart's own surfaces, listed with why. A unit test compares this list with the registry both
 * ways, so a later operation that shows an order cannot ship without its cases.
 *
 * @since 0.2.0
 */
final class StoreOrderSurfaces {

	/**
	 * The storefront operations the access policy guards: OrderStatusRouteTest runs the five cases on each.
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	public const FIVE_CASES = array(
		'order.get_status',
		'order.list_own',
		'order.get_own',
		'order.claim_order',
	);

	/**
	 * The storefront operations that answer an order's uuid to the request holding the cart's token, which are the cart's and not the policy's, each with why.
	 *
	 * @since 0.2.0
	 *
	 * @var array<string, string>
	 */
	public const CART_SURFACES = array(
		'checkout.place_order'     => 'The placement answers the order it placed, its key once, to the request that holds the cart\'s token; a kept answer for a customer\'s order is given back with the idempotency key to that customer alone. Its refusal `cart.not_open` names the order the cart is placing to whoever holds the token for a guest\'s order, and to the order\'s customer alone for a customer\'s; its refusals after the order is written name the order to the request that placed it.',
		'checkout.change_currency' => 'Its refusal `cart.not_open` names the order the cart is placing to whoever holds the cart\'s token for a guest\'s order, and to the order\'s customer alone for a customer\'s.',
		'checkout.resume_payment'  => 'Its refusal `checkout.gateway_unavailable` names the order of the payment resumed, to the request that holds the token of the cart that placed it and the payment\'s uuid, which only that placement\'s answer gave out.',
		'checkout.update_session'  => 'Its refusal `cart.not_open` is the cart\'s own, which names the cart\'s status alone: it never names the order.',
		'cart.add_lines'           => 'Its refusal `cart.not_open` is the cart\'s own, which names the cart\'s status alone: it never names the order.',
		'cart.update_line'         => 'Its refusal `cart.not_open` is the cart\'s own, which names the cart\'s status alone: it never names the order.',
		'cart.apply_code'          => 'Its refusal `cart.not_open` is the cart\'s own, which names the cart\'s status alone: it never names the order.',
		'cart.remove_code'         => 'Its refusal `cart.not_open` is the cart\'s own, which names the cart\'s status alone: it never names the order.',
	);

	/**
	 * Cannot be called: the list is constants and a static function.
	 *
	 * @since 0.2.0
	 */
	private function __construct() {}

	/**
	 * Returns the Store API operations of a registry that take or answer an order's uuid.
	 *
	 * @since 0.2.0
	 *
	 * @param OperationRegistry $registry The operations.
	 * @return list<string> Their ids, in registry order.
	 */
	public static function inRegistry( OperationRegistry $registry ): array {
		$found = array();

		foreach ( $registry->all() as $definition ) {
			if ( true === $definition->rest()?->isStore() && self::namesAnOrder( $definition ) ) {
				$found[] = $definition->id();
			}
		}

		return $found;
	}

	/**
	 * Tells whether an operation takes or answers an order's uuid.
	 *
	 * @since 0.2.0
	 *
	 * @param OperationDefinition $definition The operation.
	 * @return bool True for an operation of the order module, or one with an `order_uuid` field.
	 */
	private static function namesAnOrder( OperationDefinition $definition ): bool {
		return str_starts_with( $definition->id(), 'order.' )
			|| self::hasOrderUuid( $definition->input() )
			|| self::hasOrderUuid( $definition->output()->fields() )
			|| self::errorsNameAnOrder( $definition->errors() );
	}

	/**
	 * Tells whether any of an operation's declared errors may carry an order's uuid in its details.
	 *
	 * @since 0.2.0
	 *
	 * @param ErrorCode[] $errors The errors the operation declares.
	 * @return bool True when one declares the detail `order_uuid`.
	 */
	private static function errorsNameAnOrder( array $errors ): bool {
		foreach ( $errors as $error ) {
			if ( in_array( 'order_uuid', ErrorDefinition::of( $error )->detailKeys(), true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Tells whether fields, at any depth, hold an order's uuid.
	 *
	 * @since 0.2.0
	 *
	 * @param FieldSpec[] $fields The fields.
	 * @return bool True when one is named `order_uuid`.
	 */
	private static function hasOrderUuid( array $fields ): bool {
		foreach ( $fields as $field ) {
			if ( 'order_uuid' === $field->name() || self::hasOrderUuid( $field->fields() ) ) {
				return true;
			}
		}

		return false;
	}
}
