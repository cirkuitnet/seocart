<?php
/**
 * OrderStatusRead: answers the Store API's order-status read
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Order\Interfaces\StoreApi;

use SEOCart\Order\Application\OrderAccessPolicy;
use SEOCart\Order\Domain\OrderLineView;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Support\Error\CodedException;

defined( 'ABSPATH' ) || exit;

/**
 * The service behind `order.get_status`.
 *
 * Owns one fact: what the status read answers, and with which key it asks. The key is the one
 * the request header OrderStoreOperations::KEY_HEADER carries, as it arrived over HTTP, else the
 * one in the input OrderStoreOperations::KEY, the query parameter of the emailed link; an empty
 * value is no key. OrderAccessPolicy decides whether the actor may see the order and reads it,
 * its lines included, so the answer costs no read of its own; this service only names the order's
 * figures and its lines' snapshots on the wire. It writes nothing and sets no cookie.
 *
 * @since 0.1.0
 */
final class OrderStatusRead {

	/**
	 * Decides who may see an order, and reads it.
	 *
	 * @since 0.1.0
	 *
	 * @var OrderAccessPolicy
	 */
	private OrderAccessPolicy $policy;

	/**
	 * Creates the service. Sends nothing.
	 *
	 * @since 0.1.0
	 *
	 * @param OrderAccessPolicy $policy Decides who may see an order, and reads it.
	 */
	public function __construct( OrderAccessPolicy $policy ) {
		$this->policy = $policy;
	}

	/**
	 * Returns an order's number, statuses, totals and lines to an actor the policy lets see it.
	 *
	 * @since 0.1.0
	 *
	 * @throws CodedException `order.not_found` for every refusal.
	 *
	 * @param array<string, mixed> $input The prepared input: the order's uuid, and the key when the query carries one.
	 * @param Actor                $actor The user WordPress authenticated the request as, 0 for a visitor.
	 * @return array<string, mixed> The order, keyed by wire name.
	 */
	public function read( array $input, Actor $actor ): array {
		$order = $this->policy->authorize( (string) $input['uuid'], $actor, self::presentedKey( $input ) );

		return array(
			'uuid'                 => $order->uuid,
			'order_number'         => $order->orderNumber,
			'status'               => $order->status->value,
			'payment_status'       => $order->paymentStatus->value,
			'currency'             => $order->currency->code(),
			'subtotal_minor'       => $order->subtotal->minorUnits(),
			'discount_total_minor' => $order->discountTotal->minorUnits(),
			'shipping_total_minor' => $order->shippingTotal->minorUnits(),
			'fee_total_minor'      => $order->feeTotal->minorUnits(),
			'tax_total_minor'      => $order->taxTotal->minorUnits(),
			'grand_total_minor'    => $order->grandTotal->minorUnits(),
			'paid_minor'           => $order->paid->minorUnits(),
			'refunded_minor'       => $order->refunded->minorUnits(),
			'due_minor'            => $order->due->minorUnits(),
			'lines'                => array_map( self::line( ... ), $order->lines ),
		);
	}

	/**
	 * Names one line's snapshot on the wire.
	 *
	 * @since 0.1.0
	 *
	 * @param OrderLineView $line The line, as the order recorded it.
	 * @return array<string, int|string> The line, keyed by wire name.
	 */
	private static function line( OrderLineView $line ): array {
		return array(
			'line_uuid'           => $line->lineUuid,
			'title'               => $line->title,
			'variant_label'       => $line->variantLabel,
			'sku'                 => $line->sku,
			'quantity'            => $line->quantity,
			'unit_price_minor'    => $line->unitPrice->minorUnits(),
			'unit_amount_basis'   => $line->unitAmountBasis->value,
			'line_subtotal_minor' => $line->lineSubtotal->minorUnits(),
			'line_discount_minor' => $line->lineDiscount->minorUnits(),
			'line_tax_minor'      => $line->amount->tax()->minorUnits(),
			'line_total_minor'    => $line->lineTotal->minorUnits(),
		);
	}

	/**
	 * Returns the key the client presented: the header's, else the query parameter's.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $input The prepared input.
	 * @return string|null The key, or null when neither carries a non-empty one.
	 */
	private static function presentedKey( array $input ): ?string {
		$variable = 'HTTP_' . strtoupper( str_replace( '-', '_', OrderStoreOperations::KEY_HEADER ) );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- The key is never shown or stored: AccessKeys::verify() compares it with the order's hash, and nothing else reads it.
		$header = isset( $_SERVER[ $variable ] ) && is_string( $_SERVER[ $variable ] ) ? trim( wp_unslash( $_SERVER[ $variable ] ) ) : '';
		$query  = isset( $input[ OrderStoreOperations::KEY ] ) && is_string( $input[ OrderStoreOperations::KEY ] ) ? trim( $input[ OrderStoreOperations::KEY ] ) : '';
		$key    = '' !== $header ? $header : $query;

		return '' === $key ? null : $key;
	}
}
