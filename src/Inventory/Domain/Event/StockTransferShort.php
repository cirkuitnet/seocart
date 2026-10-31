<?php
/**
 * StockTransferShort: units an order waited for were lost on the way
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Inventory\Domain\Event;

use SEOCart\Support\Events\DeliveryMode;
use SEOCart\Support\Events\DomainEvent;

defined( 'ABSPATH' ) || exit;

/**
 * Fires in the transaction of a receipt that writes off units an order's allocation travelled with, naming the order, the transfer, the line and the units lost.
 *
 * Owns one fact: what a listener learns about an order left short by a transfer. The order still
 * stands, and its allocation now holds fewer units than its line asked for, so a person must
 * decide what happens to it: the kernel's listener puts it on hold. One is fired per line and
 * receipt that loses units, so the transfer and the line tell a redelivery of the same event from
 * a second shortage. It travels through the outbox. Its aggregate is the order. Each payload key
 * is its property's name in snake_case.
 *
 * @since 0.2.0
 */
final readonly class StockTransferShort implements DomainEvent {

	/**
	 * The kind of aggregate the event happens to: the order left short.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const AGGREGATE = 'order';

	/**
	 * Records the event.
	 *
	 * @since 0.2.0
	 *
	 * @throws \InvalidArgumentException When the units lost are fewer than 1.
	 *
	 * @param int                $orderId    The order left short.
	 * @param int                $transferId The transfer the units were lost from.
	 * @param int                $lineId     The transfer's line.
	 * @param int                $variantId  The variant.
	 * @param int                $quantity   The units lost, 1 or more.
	 * @param \DateTimeImmutable $occurredAt When the loss was recorded.
	 */
	public function __construct(
		public int $orderId,
		public int $transferId,
		public int $lineId,
		public int $variantId,
		public int $quantity,
		public \DateTimeImmutable $occurredAt
	) {
		if ( $quantity < 1 ) {
			throw new \InvalidArgumentException( 'An order is left short by at least one unit.' );
		}
	}

	/**
	 * Returns the event's name.
	 *
	 * @since 0.2.0
	 *
	 * @return string `stock_transfer_fell_short`; the action is `seocart_stock_transfer_fell_short`.
	 */
	public static function eventName(): string {
		return 'stock_transfer_fell_short';
	}

	/**
	 * Returns how the event is delivered.
	 *
	 * @since 0.2.0
	 *
	 * @return DeliveryMode Outbox.
	 */
	public static function deliveryMode(): DeliveryMode {
		return DeliveryMode::Outbox;
	}

	/**
	 * Returns the payload version.
	 *
	 * @since 0.2.0
	 *
	 * @return int 1.
	 */
	public static function payloadVersion(): int {
		return 1;
	}

	/**
	 * Rebuilds the event from its payload.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $payload    The fields.
	 * @param \DateTimeImmutable   $occurredAt When it happened.
	 * @param int                  $version    The payload version.
	 * @return self The event.
	 */
	public static function fromPayload( array $payload, \DateTimeImmutable $occurredAt, int $version ): self {
		return new self(
			(int) $payload['order_id'],
			(int) $payload['transfer_id'],
			(int) $payload['line_id'],
			(int) $payload['variant_id'],
			(int) $payload['quantity'],
			$occurredAt
		);
	}

	/**
	 * Returns the aggregate type.
	 *
	 * @since 0.2.0
	 *
	 * @return string `order`.
	 */
	public function aggregateType(): string {
		return self::AGGREGATE;
	}

	/**
	 * Returns the aggregate id.
	 *
	 * @since 0.2.0
	 *
	 * @return int The order's id.
	 */
	public function aggregateId(): int {
		return $this->orderId;
	}

	/**
	 * Returns when it happened.
	 *
	 * @since 0.2.0
	 *
	 * @return \DateTimeImmutable The instant.
	 */
	public function occurredAt(): \DateTimeImmutable {
		return $this->occurredAt;
	}

	/**
	 * Returns the fields.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, mixed> The fields, keyed by their properties' names in snake_case.
	 */
	public function toPayload(): array {
		return array(
			'order_id'    => $this->orderId,
			'transfer_id' => $this->transferId,
			'line_id'     => $this->lineId,
			'variant_id'  => $this->variantId,
			'quantity'    => $this->quantity,
		);
	}
}
