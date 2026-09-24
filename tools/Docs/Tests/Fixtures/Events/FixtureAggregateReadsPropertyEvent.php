<?php
/**
 * FixtureAggregateReadsPropertyEvent: a fixture event whose aggregateType() reads a property
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
 * A fixture event whose aggregateType() is deliberately wrong: it reads a property instead of
 * returning a constant.
 *
 * Fixture only: proves HooksReference refuses such an event, naming the class, rather than
 * crashing on an uninitialized-property error.
 *
 * @since 0.1.0
 */
final readonly class FixtureAggregateReadsPropertyEvent implements DomainEvent {

	/**
	 * Records the event.
	 *
	 * @since 0.1.0
	 *
	 * @param string             $kind       The kind, wrongly read back by aggregateType().
	 * @param \DateTimeImmutable $occurredAt When it happened.
	 */
	public function __construct(
		public string $kind,
		public \DateTimeImmutable $occurredAt
	) {
	}

	/**
	 * Returns the event's name.
	 *
	 * @since 0.1.0
	 *
	 * @return string `fixture_aggregate_reads_property`.
	 */
	public static function eventName(): string {
		return 'fixture_aggregate_reads_property';
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

		return new self( (string) $payload['kind'], $occurredAt );
	}

	/**
	 * Returns the aggregate type. Deliberately wrong: reads a property instead of a constant.
	 *
	 * @since 0.1.0
	 *
	 * @return string $this->kind.
	 */
	public function aggregateType(): string {
		return $this->kind;
	}

	/**
	 * Returns the aggregate id.
	 *
	 * @since 0.1.0
	 *
	 * @return int Always 1.
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
	 * Returns the event's fields as plain data.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed> The payload.
	 */
	public function toPayload(): array {
		return array( 'kind' => $this->kind );
	}
}
