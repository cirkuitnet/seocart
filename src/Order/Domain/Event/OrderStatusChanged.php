<?php
/**
 * OrderStatusChanged: an order moved from one status to another
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
 * Fires after an order's status change is committed.
 *
 * Owns one fact: what a listener learns about one transition of the order state machine. Every
 * transition publishes it, the one that accepts an order included, and it mirrors the
 * `order_events` row written in the same transaction. Delivered through the outbox, so a crash
 * after the commit loses nothing. Each payload key is its property's name in snake_case.
 *
 * @since 0.1.0
 */
final readonly class OrderStatusChanged implements DomainEvent {

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
	 * @param int                $orderId    The order's internal id.
	 * @param string             $from       The status before the change.
	 * @param string             $to         The status after the change.
	 * @param string             $reason     Why it changed, for example `payment_approved`.
	 * @param string             $actorType  `user` for a person acting in person, `system` for a process acting on a user's authority.
	 * @param int|null           $actorId    The WordPress user on whose authority it changed, or null for a visitor.
	 * @param \DateTimeImmutable $occurredAt When it happened.
	 */
	public function __construct(
		public int $orderId,
		public string $from,
		public string $to,
		public string $reason,
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
	 * @return string `order_status_changed`; the action is `seocart_order_status_changed`.
	 */
	public static function eventName(): string {
		return 'order_status_changed';
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
			(string) $payload['from'],
			(string) $payload['to'],
			(string) $payload['reason'],
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
			'order_id'   => $this->orderId,
			'from'       => $this->from,
			'to'         => $this->to,
			'reason'     => $this->reason,
			'actor_type' => $this->actorType,
			'actor_id'   => $this->actorId,
		);
	}
}
