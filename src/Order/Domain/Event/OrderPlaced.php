<?php
/**
 * OrderPlaced: an order's payment was approved and the store accepted it
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
 * Fires after an order is accepted: its payment was approved and it entered its accepted status.
 *
 * Owns one fact: what a listener learns about an accepted order, as a summary of plain values.
 * It is published in one place, when the order is accepted, so a listener that sends the order
 * confirmation or starts fulfilment hears of each order once. Delivered through the outbox, so
 * a crash after the commit loses nothing. Each payload key is its property's name in snake_case.
 *
 * @since 0.1.0
 */
final readonly class OrderPlaced implements DomainEvent {

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
	 * @param int                $orderId             The order's internal id.
	 * @param string             $orderUuid           The order's public identifier.
	 * @param string             $orderNumber         The number shown to people.
	 * @param string             $channel             Where the order came from, for example `storefront`.
	 * @param string             $currency            The order's currency, ISO 4217.
	 * @param int                $grandTotalMinor     The grand total, in minor units of the currency.
	 * @param string             $baseCurrency        The store's base currency when the order was placed.
	 * @param int                $baseGrandTotalMinor The grand total in minor units of the base currency.
	 * @param int|null           $customerId          The WordPress user the order belongs to, or null for a guest order.
	 * @param string             $actorType           Who placed the order: `user` in person, `system` for a process on a user's authority.
	 * @param int|null           $actorId             The WordPress user who placed it, or null for a visitor.
	 * @param \DateTimeImmutable $occurredAt          When it happened.
	 */
	public function __construct(
		public int $orderId,
		public string $orderUuid,
		public string $orderNumber,
		public string $channel,
		public string $currency,
		public int $grandTotalMinor,
		public string $baseCurrency,
		public int $baseGrandTotalMinor,
		public ?int $customerId,
		public string $actorType,
		public ?int $actorId,
		public \DateTimeImmutable $occurredAt
	) {
	}

	/**
	 * Returns the event's name.
	 *
	 * @since 0.1.0
	 *
	 * @return string `order_placed`; the action is `seocart_order_placed`.
	 */
	public static function eventName(): string {
		return 'order_placed';
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
			(string) $payload['base_currency'],
			(int) $payload['base_grand_total_minor'],
			null === $payload['customer_id'] ? null : (int) $payload['customer_id'],
			(string) $payload['actor_type'],
			null === $payload['actor_id'] ? null : (int) $payload['actor_id'],
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
			'order_id'               => $this->orderId,
			'order_uuid'             => $this->orderUuid,
			'order_number'           => $this->orderNumber,
			'channel'                => $this->channel,
			'currency'               => $this->currency,
			'grand_total_minor'      => $this->grandTotalMinor,
			'base_currency'          => $this->baseCurrency,
			'base_grand_total_minor' => $this->baseGrandTotalMinor,
			'customer_id'            => $this->customerId,
			'actor_type'             => $this->actorType,
			'actor_id'               => $this->actorId,
		);
	}
}
