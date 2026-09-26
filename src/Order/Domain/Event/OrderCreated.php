<?php
/**
 * OrderCreated: an order was written, and waits for its payment
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Order\Domain\Event;

use SEOCart\Support\Events\DeliveryMode;
use SEOCart\Support\Events\DomainEvent;

defined( 'ABSPATH' ) || exit;

/**
 * Fires after an order is committed in `pending_payment`, before its payment is known.
 *
 * Owns one fact: what a listener learns about a new order. It is not an accepted order: that is
 * OrderPlaced. Delivered through the outbox, so a crash after the commit loses nothing. Each
 * payload key is its property's name in snake_case.
 *
 * @since 0.1.0
 */
final readonly class OrderCreated implements DomainEvent {

	/**
	 * The kind of aggregate every order event happens to.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const AGGREGATE = 'order';

	/**
	 * Records the event.
	 *
	 * @since 0.1.0
	 *
	 * @param int                $orderId         The order's internal id.
	 * @param string             $orderUuid       The order's public identifier.
	 * @param string             $orderNumber     The number shown to people.
	 * @param string             $channel         Where the order came from, for example `storefront`.
	 * @param string             $currency        The order's currency, ISO 4217.
	 * @param int                $grandTotalMinor The grand total, in minor units of the currency.
	 * @param \DateTimeImmutable $occurredAt      When it happened.
	 */
	public function __construct(
		public int $orderId,
		public string $orderUuid,
		public string $orderNumber,
		public string $channel,
		public string $currency,
		public int $grandTotalMinor,
		public \DateTimeImmutable $occurredAt
	) {
	}

	/**
	 * Returns the event's name.
	 *
	 * @since 0.1.0
	 *
	 * @return string `order_created`; the action is `seocart_order_created`.
	 */
	public static function eventName(): string {
		return 'order_created';
	}

	/**
	 * Returns how the event is delivered.
	 *
	 * @since 0.1.0
	 *
	 * @return DeliveryMode Outbox.
	 */
	public static function deliveryMode(): DeliveryMode {
		return DeliveryMode::Outbox;
	}

	/**
	 * Returns the payload version.
	 *
	 * @since 0.1.0
	 *
	 * @return int 1.
	 */
	public static function payloadVersion(): int {
		return 1;
	}

	/**
	 * Rebuilds the event from its stored payload.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $payload    The stored fields.
	 * @param \DateTimeImmutable   $occurredAt When it happened.
	 * @param int                  $version    The stored payload version.
	 * @return self The event.
	 */
	public static function fromPayload( array $payload, \DateTimeImmutable $occurredAt, int $version ): self {
		return new self(
			(int) $payload['order_id'],
			(string) $payload['order_uuid'],
			(string) $payload['order_number'],
			(string) $payload['channel'],
			(string) $payload['currency'],
			(int) $payload['grand_total_minor'],
			$occurredAt
		);
	}

	/**
	 * Returns the aggregate type.
	 *
	 * @since 0.1.0
	 *
	 * @return string `order`.
	 */
	public function aggregateType(): string {
		return self::AGGREGATE;
	}

	/**
	 * Returns the aggregate id.
	 *
	 * @since 0.1.0
	 *
	 * @return int The order id.
	 */
	public function aggregateId(): int {
		return $this->orderId;
	}

	/**
	 * Returns when it happened.
	 *
	 * @since 0.1.0
	 *
	 * @return \DateTimeImmutable The instant.
	 */
	public function occurredAt(): \DateTimeImmutable {
		return $this->occurredAt;
	}

	/**
	 * Returns the fields.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed> The fields, keyed by their properties' names in snake_case.
	 */
	public function toPayload(): array {
		return array(
			'order_id'          => $this->orderId,
			'order_uuid'        => $this->orderUuid,
			'order_number'      => $this->orderNumber,
			'channel'           => $this->channel,
			'currency'          => $this->currency,
			'grand_total_minor' => $this->grandTotalMinor,
		);
	}
}
