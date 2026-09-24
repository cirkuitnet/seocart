<?php
/**
 * FixtureNonPromotedEvent: a fixture event with a non-promoted constructor parameter
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
 * A fixture event whose $binId is assigned in the constructor body instead of being promoted.
 *
 * Fixture only: proves HooksReference refuses an event whose payload fields are not all
 * constructor-promoted properties.
 *
 * @since 0.1.0
 */
final readonly class FixtureNonPromotedEvent implements DomainEvent {

	/**
	 * The bin.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public int $binId;

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
	 * @param int                $binId      The bin.
	 * @param \DateTimeImmutable $occurredAt When it happened.
	 */
	public function __construct( int $binId, \DateTimeImmutable $occurredAt ) {
		$this->binId      = $binId;
		$this->occurredAt = $occurredAt;
	}

	/**
	 * Returns the event's name.
	 *
	 * @since 0.1.0
	 *
	 * @return string `fixture_non_promoted`.
	 */
	public static function eventName(): string {
		return 'fixture_non_promoted';
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

		return new self( (int) $payload['bin_id'], $occurredAt );
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
		return array( 'bin_id' => $this->binId );
	}
}
