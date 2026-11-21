<?php
/**
 * OrderFieldValues: reads the checkout field values an order was placed with, for staff
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Order\Application;

use SEOCart\Contracts\Checkout\CheckoutField;
use SEOCart\Order\Domain\OrderFieldValue;
use SEOCart\Order\Domain\OrderRepository;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Platform\Authorization\Authorizer;
use SEOCart\Support\Error\CodedException;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- A coded error is shaped and escaped by the error translator, never printed here.

/**
 * Performs `order.list_field_values`: an order's checkout field values as the shopper gave them, each with its words then and its label now.
 *
 * Owns one fact: how staff read an order's field values. Two statements: the order's id by its
 * uuid, and its values (OrderRepository::fieldValuesOf(), the read the export and the messages
 * share). The labels kept are the shopper's; `current_label` is today's declaration's, resolved in
 * the request's language, and null for a key no field declares any more. Personal data is left out
 * of the answer for a user who may not see it by the answer's own serializer.
 *
 * @since 0.2.0
 */
final class OrderFieldValues {

	/**
	 * Returns the declared checkout fields, read the first time a read needs them.
	 *
	 * @since 0.2.0
	 *
	 * @var \Closure(): list<CheckoutField>
	 */
	private \Closure $declared;

	/**
	 * Creates the service. Reads nothing.
	 *
	 * @since 0.2.0
	 *
	 * @param OrderRepository $orders     The orders.
	 * @param Authorizer      $authorizer Checks the reader's capability.
	 * @param callable        $declared   Returns the checkout fields declared today, in form order.
	 *
	 * @phpstan-param callable(): list<CheckoutField> $declared
	 */
	public function __construct( private OrderRepository $orders, private Authorizer $authorizer, callable $declared ) {
		$this->declared = \Closure::fromCallable( $declared );
	}

	/**
	 * Performs `order.list_field_values`.
	 *
	 * @since 0.2.0
	 *
	 * @throws CodedException `authorization.denied` without `seocart_view_orders`; `order.not_found`
	 *                        when there is no such order.
	 *
	 * @param array<string, mixed> $input The prepared input: order_uuid.
	 * @param Actor                $actor Who reads.
	 * @return array{field_values: list<array{key: string, section: string, type: string, label: string, current_label: string|null, field_value: string|null, value_label: string|null, locale: string}>} The values, in the order the form showed their fields.
	 */
	public function list( array $input, Actor $actor ): array {
		$this->authorizer->authorize( $actor, OrderOperations::VIEW_CAPABILITY );

		$orderId = $this->orders->idOf( (string) $input['order_uuid'] ) ?? CodedException::raise( OrderError::NotFound );
		$labels  = array();

		foreach ( ( $this->declared )() as $field ) {
			$labels[ $field->key ] = $field->label;
		}

		$values = array();

		foreach ( $this->orders->fieldValuesOf( array( $orderId ) )[ $orderId ] ?? array() as $row ) {
			$current  = $labels[ $row['key'] ] ?? null;
			$values[] = array(
				'key'           => $row['key'],
				'section'       => $row['section'],
				'type'          => $row['type'],
				'label'         => $row['label'],
				'current_label' => null === $current ? null : OrderFieldValue::kept( $current() ),
				'field_value'   => $row['field_value'],
				'value_label'   => $row['value_label'],
				'locale'        => $row['locale'],
			);
		}

		return array( 'field_values' => $values );
	}
}
