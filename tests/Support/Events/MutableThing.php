<?php
/**
 * MutableThing: a fixture event that is deliberately not readonly
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support\Events;

use SEOCart\Support\Events\DeliveryMode;
use SEOCart\Support\Events\DomainEvent;

/**
 * A catalogued event whose class a listener could change: the publisher must refuse it.
 *
 * @since 0.1.0
 */
final class MutableThing implements DomainEvent {

	/**
	 * The aggregate's id; writable, which is the defect.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public int $thingId;

	/**
	 * Records the event.
	 *
	 * @since 0.1.0
	 *
	 * @param int $thingId The aggregate's id.
	 */
	public function __construct( int $thingId ) {
		$this->thingId = $thingId;
	}

	/**
	 * Returns the event's name.
	 *
	 * @since 0.1.0
	 *
	 * @return string The name.
	 */
	public static function eventName(): string {
		return 'test_mutable_thing';
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
	 * Rebuilds the event.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $payload    The stored fields.
	 * @param \DateTimeImmutable   $occurredAt When it happened.
	 * @param int                  $version    The stored payload version.
	 * @return self The event.
	 */
	public static function fromPayload( array $payload, \DateTimeImmutable $occurredAt, int $version ): self {
		return new self( (int) $payload['thing_id'] );
	}

	/**
	 * Returns the aggregate type.
	 *
	 * @since 0.1.0
	 *
	 * @return string `thing`.
	 */
	public function aggregateType(): string {
		return 'thing';
	}

	/**
	 * Returns the aggregate id.
	 *
	 * @since 0.1.0
	 *
	 * @return int The id.
	 */
	public function aggregateId(): int {
		return $this->thingId;
	}

	/**
	 * Returns when it happened.
	 *
	 * @since 0.1.0
	 *
	 * @return \DateTimeImmutable A fixed instant.
	 */
	public function occurredAt(): \DateTimeImmutable {
		return new \DateTimeImmutable( '2026-09-23 10:00:00', new \DateTimeZone( 'UTC' ) );
	}

	/**
	 * Returns the fields.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed> The fields.
	 */
	public function toPayload(): array {
		return array( 'thing_id' => $this->thingId );
	}
}
