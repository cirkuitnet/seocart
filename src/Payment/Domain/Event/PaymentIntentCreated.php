<?php
/**
 * PaymentIntentCreated: a payment intent was created for an order
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
 * Fires after an intent created with its order is committed.
 *
 * Owns one fact: what a listener learns about a new attempt to pay for an order: which gateway it
 * goes through, and its frozen amount. Published in the transaction that places the order, so
 * the intent and its order are committed together or not at all.
 * Delivered through the outbox, so a crash after the commit loses nothing. Each payload key is
 * its property's name in snake_case.
 *
 * @since 0.1.0
 */
final readonly class PaymentIntentCreated implements DomainEvent {

	/**
	 * The kind of aggregate a payment intent event happens to.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const AGGREGATE = 'payment_intent';

	/**
	 * Records the event.
	 *
	 * @since 0.1.0
	 *
	 * @param int                $intentId    The intent's internal id.
	 * @param string             $intentUuid  The intent's public identifier.
	 * @param int                $orderId     The order it pays for.
	 * @param string             $gatewayId   The gateway it is paid through, for example `stub`.
	 * @param int                $amountMinor Its frozen amount, in minor units.
	 * @param string             $currency    The currency of the amount, ISO 4217.
	 * @param \DateTimeImmutable $occurredAt  When it happened.
	 */
	public function __construct(
		public int $intentId,
		public string $intentUuid,
		public int $orderId,
		public string $gatewayId,
		public int $amountMinor,
		public string $currency,
		public \DateTimeImmutable $occurredAt
	) {
	}

	/**
	 * Returns the event's name.
	 *
	 * @since 0.1.0
	 *
	 * @return string `payment_intent_created`; the action is `seocart_payment_intent_created`.
	 */
	public static function eventName(): string {
		return 'payment_intent_created';
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
			(int) $payload['intent_id'],
			(string) $payload['intent_uuid'],
			(int) $payload['order_id'],
			(string) $payload['gateway_id'],
			(int) $payload['amount_minor'],
			(string) $payload['currency'],
			$occurredAt
		);
	}

	/**
	 * Returns the aggregate type.
	 *
	 * @since 0.1.0
	 *
	 * @return string `payment_intent`.
	 */
	public function aggregateType(): string {
		return self::AGGREGATE;
	}

	/**
	 * Returns the aggregate id.
	 *
	 * @since 0.1.0
	 *
	 * @return int The intent id.
	 */
	public function aggregateId(): int {
		return $this->intentId;
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
			'intent_id'    => $this->intentId,
			'intent_uuid'  => $this->intentUuid,
			'order_id'     => $this->orderId,
			'gateway_id'   => $this->gatewayId,
			'amount_minor' => $this->amountMinor,
			'currency'     => $this->currency,
		);
	}
}
