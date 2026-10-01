<?php
/**
 * PaymentStatusChanged: an order's payment status changed
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Payment\Domain\Event;

use SEOCart\Support\Events\DeliveryMode;
use SEOCart\Support\Events\DomainEvent;

defined( 'ABSPATH' ) || exit;

/**
 * Fires after a change of an order's payment status is committed.
 *
 * Owns one fact: what a listener learns about the order's payment projection when its status
 * changes: from what to what, and the amounts it now shows. It mirrors the `order_events` row of
 * the payment machine written in the same transaction.
 * Delivered through the outbox, so a crash after the commit loses nothing. Each payload key is
 * its property's name in snake_case.
 *
 * @since 0.1.0
 */
final readonly class PaymentStatusChanged implements DomainEvent {

	/**
	 * The kind of aggregate the event happens to: the order whose payment status changed.
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
	 * @param string             $from            The payment status before.
	 * @param string             $to              The payment status after.
	 * @param int                $authorizedMinor Authorized so far, in minor units.
	 * @param int                $paidMinor       Captured so far, in minor units.
	 * @param int                $refundedMinor   Refunded so far, in minor units.
	 * @param int                $dueMinor        Still to be paid, in minor units.
	 * @param string             $currency        The order's currency, ISO 4217.
	 * @param \DateTimeImmutable $occurredAt      When it happened.
	 */
	public function __construct(
		public int $orderId,
		public string $from,
		public string $to,
		public int $authorizedMinor,
		public int $paidMinor,
		public int $refundedMinor,
		public int $dueMinor,
		public string $currency,
		public \DateTimeImmutable $occurredAt
	) {
	}

	/**
	 * Returns the event's name.
	 *
	 * @since 0.1.0
	 *
	 * @return string `payment_status_changed`; the action is `seocart_payment_status_changed`.
	 */
	public static function eventName(): string {
		return 'payment_status_changed';
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
			(int) $payload['authorized_minor'],
			(int) $payload['paid_minor'],
			(int) $payload['refunded_minor'],
			(int) $payload['due_minor'],
			(string) $payload['currency'],
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
			'order_id'         => $this->orderId,
			'from'             => $this->from,
			'to'               => $this->to,
			'authorized_minor' => $this->authorizedMinor,
			'paid_minor'       => $this->paidMinor,
			'refunded_minor'   => $this->refundedMinor,
			'due_minor'        => $this->dueMinor,
			'currency'         => $this->currency,
		);
	}
}
