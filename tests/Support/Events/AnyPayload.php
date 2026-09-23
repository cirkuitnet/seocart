<?php
/**
 * AnyPayload: a fixture event that carries whatever payload a test gives it
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
 * An event whose payload, aggregate type and aggregate id a test chooses, to exercise the payload rules.
 *
 * @since 0.1.0
 */
final readonly class AnyPayload implements DomainEvent {

	/**
	 * What toPayload() returns.
	 *
	 * @since 0.1.0
	 *
	 * @var array<array-key, mixed>
	 */
	public array $payload;

	/**
	 * The aggregate type.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public string $type;

	/**
	 * The aggregate id.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public int $id;

	/**
	 * Records the event.
	 *
	 * @since 0.1.0
	 *
	 * @param array<array-key, mixed> $payload What toPayload() returns.
	 * @param string                  $type    Optional. The aggregate type. Default `thing`.
	 * @param int                     $id      Optional. The aggregate id. Default 1.
	 */
	public function __construct( array $payload, string $type = 'thing', int $id = 1 ) {
		$this->payload = $payload;
		$this->type    = $type;
		$this->id      = $id;
	}

	/**
	 * Returns the event's name.
	 *
	 * @since 0.1.0
	 *
	 * @return string The name.
	 */
	public static function eventName(): string {
		return 'test_any_payload';
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
		return new self( $payload );
	}

	/**
	 * Returns the aggregate type.
	 *
	 * @since 0.1.0
	 *
	 * @return string The type.
	 */
	public function aggregateType(): string {
		return $this->type;
	}

	/**
	 * Returns the aggregate id.
	 *
	 * @since 0.1.0
	 *
	 * @return int The id.
	 */
	public function aggregateId(): int {
		return $this->id;
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
	 * Returns the payload.
	 *
	 * @since 0.1.0
	 *
	 * @return array<array-key, mixed> The payload the test gave.
	 */
	public function toPayload(): array {
		return $this->payload;
	}
}
