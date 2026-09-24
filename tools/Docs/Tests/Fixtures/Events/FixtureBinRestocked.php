<?php
/**
 * FixtureBinRestocked: a fixture domain event HooksReference is proven against
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tools\Docs\Tests\Fixtures\Events;

use SEOCart\Support\Events\DeliveryMode;
use SEOCart\Support\Events\DomainEvent;

/**
 * A bin in the fixture warehouse was restocked.
 *
 * Fixture only: proves HooksReference documents an event from its own declaration, with no
 * WordPress loaded.
 *
 * @since 0.1.0
 */
final readonly class FixtureBinRestocked implements DomainEvent {

	/**
	 * Records the event.
	 *
	 * @since 0.1.0
	 *
	 * @param int                $binId      The bin that was restocked.
	 * @param int                $quantity   The number of units added.
	 * @param string             $reason     Why the bin was restocked, for example `delivery`.
	 * @param \DateTimeImmutable $occurredAt When it happened.
	 */
	public function __construct(
		public int $binId,
		public int $quantity,
		public string $reason,
		public \DateTimeImmutable $occurredAt
	) {
	}

	/**
	 * Returns the event's name.
	 *
	 * @since 0.1.0
	 *
	 * @return string `bin_restocked`.
	 */
	public static function eventName(): string {
		return 'bin_restocked';
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
		unset( $version );

		return new self( (int) $payload['bin_id'], (int) $payload['quantity'], (string) $payload['reason'], $occurredAt );
	}

	/**
	 * Returns the aggregate type.
	 *
	 * @since 0.1.0
	 *
	 * @return string `bin`.
	 */
	public function aggregateType(): string {
		return 'bin';
	}

	/**
	 * Returns the aggregate id.
	 *
	 * @since 0.1.0
	 *
	 * @return int The bin id.
	 */
	public function aggregateId(): int {
		return $this->binId;
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
	 * Returns the event's fields as plain data.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed> The payload.
	 */
	public function toPayload(): array {
		return array(
			'bin_id'   => $this->binId,
			'quantity' => $this->quantity,
			'reason'   => $this->reason,
		);
	}
}
