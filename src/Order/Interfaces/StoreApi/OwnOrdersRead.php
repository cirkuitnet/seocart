<?php
/**
 * OwnOrdersRead: answers the Store API's list of a customer's own orders
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Order\Interfaces\StoreApi;

use SEOCart\Order\Application\OrderAccessPolicy;
use SEOCart\Order\Domain\OrderSummary;
use SEOCart\Platform\Authorization\Actor;

defined( 'ABSPATH' ) || exit;

/**
 * The service behind `order.list_own`.
 *
 * Owns one fact: what the list answers. OrderAccessPolicy decides whose orders they are, by the
 * same owner test a single order is shown by, and reads the page; this service only names each
 * order's row on the wire. An actor who is no customer gets an empty page: no refusal says
 * whether anyone has orders. It writes nothing and sets no cookie.
 *
 * @since 0.2.0
 */
final class OwnOrdersRead {

	/**
	 * Decides whose orders an actor may list, and reads them.
	 *
	 * @since 0.2.0
	 *
	 * @var OrderAccessPolicy
	 */
	private OrderAccessPolicy $policy;

	/**
	 * Creates the service. Sends nothing.
	 *
	 * @since 0.2.0
	 *
	 * @param OrderAccessPolicy $policy Decides whose orders an actor may list, and reads them.
	 */
	public function __construct( OrderAccessPolicy $policy ) {
		$this->policy = $policy;
	}

	/**
	 * Returns a page of the orders of the customer the actor is, newest first.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $input The prepared input: the page and how many orders it holds.
	 * @param Actor                $actor The user WordPress authenticated the request as, 0 for a visitor.
	 * @return array{orders: list<array<string, int|string>>, has_more: bool} The page, keyed by wire name.
	 */
	public function list( array $input, Actor $actor ): array {
		$page = $this->policy->ownOrders(
			$actor,
			(int) ( $input['page'] ?? 1 ),
			(int) ( $input['per_page'] ?? OrderStoreOperations::DEFAULT_PER_PAGE )
		);

		return array(
			'orders'   => array_map( self::summary( ... ), $page['orders'] ),
			'has_more' => $page['has_more'],
		);
	}

	/**
	 * Names one order's row on the wire.
	 *
	 * @since 0.2.0
	 *
	 * @param OrderSummary $order The order.
	 * @return array<string, int|string> The row, keyed by wire name.
	 */
	private static function summary( OrderSummary $order ): array {
		return array(
			'uuid'               => $order->uuid,
			'order_number'       => $order->orderNumber,
			'placed_at'          => $order->placedAt->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d\TH:i:s\Z' ),
			'status'             => $order->status->value,
			'payment_status'     => $order->paymentStatus->value,
			'fulfillment_status' => $order->fulfillmentStatus->value,
			'currency'           => $order->grandTotal->currency()->code(),
			'grand_total_minor'  => $order->grandTotal->minorUnits(),
		);
	}
}
