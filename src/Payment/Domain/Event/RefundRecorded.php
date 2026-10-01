<?php
/**
 * RefundRecorded: a refund of an order was recorded
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
 * Fires after a refund the gateway made, and its document, are committed.
 *
 * Owns one fact: what a listener learns about a refund: the order, the ledger row, and what it
 * returned in the order's currency and in the base currency, at the order's own frozen rate.
 * Delivered through the outbox, so a crash after the commit loses nothing. Each payload key is
 * its property's name in snake_case.
 *
 * @since 0.1.0
 */
final readonly class RefundRecorded implements DomainEvent {

	/**
	 * The kind of aggregate the event happens to: the refund.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const AGGREGATE = 'refund';

	/**
	 * Records the event.
	 *
	 * @since 0.1.0
	 *
	 * @param int                $refundId       The refund's internal id.
	 * @param string             $refundUuid     Its public identifier.
	 * @param int                $orderId        The order refunded.
	 * @param int                $transactionId  The ledger row of the gateway's refund.
	 * @param int                $totalMinor     What it returned, tax included, in minor units.
	 * @param int                $taxMinor       The tax it returned, in minor units.
	 * @param string             $currency       The order's currency, ISO 4217.
	 * @param int                $baseTotalMinor The total in the base currency, in minor units.
	 * @param string             $baseCurrency   The base currency, ISO 4217.
	 * @param string             $reasonCode     Why the order was refunded.
	 * @param \DateTimeImmutable $occurredAt     When it happened.
	 */
	public function __construct(
		public int $refundId,
		public string $refundUuid,
		public int $orderId,
		public int $transactionId,
		public int $totalMinor,
		public int $taxMinor,
		public string $currency,
		public int $baseTotalMinor,
		public string $baseCurrency,
		public string $reasonCode,
		public \DateTimeImmutable $occurredAt
	) {
	}

	/**
	 * Returns the event's name.
	 *
	 * @since 0.1.0
	 *
	 * @return string `refund_recorded`; the action is `seocart_refund_recorded`.
	 */
	public static function eventName(): string {
		return 'refund_recorded';
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
			(int) $payload['refund_id'],
			(string) $payload['refund_uuid'],
			(int) $payload['order_id'],
			(int) $payload['transaction_id'],
			(int) $payload['total_minor'],
			(int) $payload['tax_minor'],
			(string) $payload['currency'],
			(int) $payload['base_total_minor'],
			(string) $payload['base_currency'],
			(string) $payload['reason_code'],
			$occurredAt
		);
	}

	/**
	 * Returns the aggregate type.
	 *
	 * @since 0.1.0
	 *
	 * @return string `refund`.
	 */
	public function aggregateType(): string {
		return self::AGGREGATE;
	}

	/**
	 * Returns the aggregate id.
	 *
	 * @since 0.1.0
	 *
	 * @return int The refund id.
	 */
	public function aggregateId(): int {
		return $this->refundId;
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
			'refund_id'        => $this->refundId,
			'refund_uuid'      => $this->refundUuid,
			'order_id'         => $this->orderId,
			'transaction_id'   => $this->transactionId,
			'total_minor'      => $this->totalMinor,
			'tax_minor'        => $this->taxMinor,
			'currency'         => $this->currency,
			'base_total_minor' => $this->baseTotalMinor,
			'base_currency'    => $this->baseCurrency,
			'reason_code'      => $this->reasonCode,
		);
	}
}
