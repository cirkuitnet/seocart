<?php
/**
 * PaymentFailed: a gateway declined a payment intent
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
 * Fires after a decline is committed to the ledger and its intent has failed.
 *
 * Owns one fact: what a listener learns about one applied decline: the intent failed, and why,
 * as the gateway's machine code. The provider's message is never carried.
 * Delivered through the outbox, so a crash after the commit loses nothing. Each payload key is
 * its property's name in snake_case.
 *
 * @since 0.1.0
 */
final readonly class PaymentFailed implements DomainEvent {

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
	 * @param int                $intentId      The intent's internal id.
	 * @param int                $orderId       The order it pays for.
	 * @param int                $amountMinor   The amount the gateway declined, in minor units.
	 * @param string             $currency      The currency of the amount, ISO 4217.
	 * @param int                $transactionId The ledger row the decline was recorded in.
	 * @param string|null        $machineCode   The gateway's machine code for the decline, for example `card_declined`, or null when it gave none.
	 * @param \DateTimeImmutable $occurredAt    When it happened.
	 */
	public function __construct(
		public int $intentId,
		public int $orderId,
		public int $amountMinor,
		public string $currency,
		public int $transactionId,
		public ?string $machineCode,
		public \DateTimeImmutable $occurredAt
	) {
	}

	/**
	 * Returns the event's name.
	 *
	 * @since 0.1.0
	 *
	 * @return string `payment_failed`; the action is `seocart_payment_failed`.
	 */
	public static function eventName(): string {
		return 'payment_failed';
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
			(int) $payload['order_id'],
			(int) $payload['amount_minor'],
			(string) $payload['currency'],
			(int) $payload['transaction_id'],
			null === $payload['machine_code'] ? null : (string) $payload['machine_code'],
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
			'intent_id'      => $this->intentId,
			'order_id'       => $this->orderId,
			'amount_minor'   => $this->amountMinor,
			'currency'       => $this->currency,
			'transaction_id' => $this->transactionId,
			'machine_code'   => $this->machineCode,
		);
	}
}
