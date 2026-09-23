<?php
/**
 * InheritedEventBase: a fixture base class through which an event implements DomainEvent
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Platform\Kernel\Fixtures;

use SEOCart\Support\Events\DeliveryMode;
use SEOCart\Support\Events\DomainEvent;

/**
 * An abstract event: the domain-event search must not take it for an event, and must find the
 * class that extends it, whose own declaration never names DomainEvent.
 *
 * @since 0.1.0
 */
abstract readonly class InheritedEventBase implements DomainEvent {

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
	 * @param \DateTimeImmutable $occurredAt When it happened.
	 */
	final public function __construct( \DateTimeImmutable $occurredAt ) {
		$this->occurredAt = $occurredAt;
	}

	/**
	 * Returns the event's name.
	 *
	 * @since 0.1.0
	 *
	 * @return string The name.
	 */
	public static function eventName(): string {
		return 'test_inherited';
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
	 * @return static The event.
	 */
	public static function fromPayload( array $payload, \DateTimeImmutable $occurredAt, int $version ): static {
		return new static( $occurredAt );
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
	 * @return int 1.
	 */
	public function aggregateId(): int {
		return 1;
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
	 * @return array<string, mixed> None.
	 */
	public function toPayload(): array {
		return array();
	}
}
