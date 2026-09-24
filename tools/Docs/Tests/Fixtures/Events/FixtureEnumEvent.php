<?php
/**
 * FixtureEnumEvent: a fixture event with an enum-typed constructor parameter
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
 * A fixture bin was reserved, for a documented reason.
 *
 * Fixture only: proves an enum-typed promoted property documents cleanly, and that the aggregate
 * type is read without synthesizing a value for it (newInstanceWithoutConstructor() needs none).
 *
 * @since 0.1.0
 */
final readonly class FixtureEnumEvent implements DomainEvent {

	/**
	 * Records the event.
	 *
	 * @since 0.1.0
	 *
	 * @param int                      $binId      The bin reserved.
	 * @param FixtureReservationReason $reason     Why it was reserved.
	 * @param \DateTimeImmutable       $occurredAt When it happened.
	 */
	public function __construct(
		public int $binId,
		public FixtureReservationReason $reason,
		public \DateTimeImmutable $occurredAt
	) {
	}

	/**
	 * Returns the event's name.
	 *
	 * @since 0.1.0
	 *
	 * @return string `fixture_enum`.
	 */
	public static function eventName(): string {
		return 'fixture_enum';
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

		return new self( (int) $payload['bin_id'], FixtureReservationReason::from( (string) $payload['reason'] ), $occurredAt );
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
			'bin_id' => $this->binId,
			'reason' => $this->reason->value,
		);
	}
}
