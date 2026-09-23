<?php
/**
 * ThingNoticed: a fixture event delivered after the commit, from memory
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
 * An event whose loss is tolerable, like a cache invalidation hint.
 *
 * @since 0.1.0
 */
final readonly class ThingNoticed implements DomainEvent {

	/**
	 * The aggregate's id.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public int $thingId;

	/**
	 * When it happened.
	 *
	 * @since 0.1.0
	 *
	 * @var \DateTimeImmutable
	 */
	public \DateTimeImmutable $occurredAt;

	/**
	 * Records the event.
	 *
	 * @since 0.1.0
	 *
	 * @param int                     $thingId    The aggregate's id.
	 * @param \DateTimeImmutable|null $occurredAt Optional. When it happened. Default a fixed instant.
	 */
	public function __construct( int $thingId, ?\DateTimeImmutable $occurredAt = null ) {
		$this->thingId    = $thingId;
		$this->occurredAt = $occurredAt ?? new \DateTimeImmutable( '2026-09-23 10:00:00', new \DateTimeZone( 'UTC' ) );
	}

	/**
	 * Returns the event's name.
	 *
	 * @since 0.1.0
	 *
	 * @return string The name.
	 */
	public static function eventName(): string {
		return 'test_thing_noticed';
	}

	/**
	 * Returns how the event is delivered.
	 *
	 * @since 0.1.0
	 *
	 * @return DeliveryMode AfterCommit.
	 */
	public static function deliveryMode(): DeliveryMode {
		return DeliveryMode::AfterCommit;
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
		return new self( (int) $payload['thing_id'], $occurredAt );
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
	 * @return array<string, mixed> The fields.
	 */
	public function toPayload(): array {
		return array( 'thing_id' => $this->thingId );
	}
}
