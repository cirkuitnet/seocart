<?php
/**
 * FixtureValidatingEvent: a fixture event whose constructor validates and throws
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
 * A fixture reservation was made, mirroring the inventory module's validating hold events.
 *
 * Fixture only: its constructor throws when $variantIds is empty, exactly as the real
 * StockReserved and StockReservationReleased events do, to prove HooksReference documents an
 * event without ever constructing it.
 *
 * @since 0.1.0
 */
final readonly class FixtureValidatingEvent implements DomainEvent {

	/**
	 * Records the event.
	 *
	 * @since 0.1.0
	 *
	 * @throws \InvalidArgumentException When $variantIds is empty.
	 *
	 * @param int[]              $variantIds The variants reserved; must not be empty.
	 * @param \DateTimeImmutable $occurredAt When it happened.
	 *
	 * @phpstan-param list<int> $variantIds
	 */
	public function __construct(
		public array $variantIds,
		public \DateTimeImmutable $occurredAt
	) {
		if ( array() === $variantIds ) {
			throw new \InvalidArgumentException( 'A reservation lists at least one variant.' );
		}
	}

	/**
	 * Returns the event's name.
	 *
	 * @since 0.1.0
	 *
	 * @return string `fixture_validated`.
	 */
	public static function eventName(): string {
		return 'fixture_validated';
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

		return new self( array_values( array_map( 'intval', (array) $payload['variant_ids'] ) ), $occurredAt );
	}

	/**
	 * Returns the aggregate type.
	 *
	 * @since 0.1.0
	 *
	 * @return string `variant`.
	 */
	public function aggregateType(): string {
		return 'variant';
	}

	/**
	 * Returns the aggregate id.
	 *
	 * @since 0.1.0
	 *
	 * @return int The lowest variant id.
	 */
	public function aggregateId(): int {
		return $this->variantIds[0];
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
		return array( 'variant_ids' => $this->variantIds );
	}
}
