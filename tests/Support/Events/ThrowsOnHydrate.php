<?php
/**
 * ThrowsOnHydrate: a fixture event that cannot be rebuilt from its stored payload
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
 * An outbox event whose delivery fails outside any listener, every time: the retry and parking path.
 *
 * @since 0.1.0
 */
final readonly class ThrowsOnHydrate implements DomainEvent {

	/**
	 * The aggregate's id.
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
		return 'test_throws_on_hydrate';
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
	 * Fails to rebuild the event, always.
	 *
	 * @since 0.1.0
	 *
	 * @throws \RuntimeException Always.
	 *
	 * @param array<string, mixed> $payload    The stored fields.
	 * @param \DateTimeImmutable   $occurredAt When it happened.
	 * @param int                  $version    The stored payload version.
	 * @return never
	 */
	public static function fromPayload( array $payload, \DateTimeImmutable $occurredAt, int $version ): never {
		throw new \RuntimeException( 'This fixture cannot be rebuilt.' );
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
