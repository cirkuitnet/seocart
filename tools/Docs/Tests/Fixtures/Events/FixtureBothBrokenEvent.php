<?php
/**
 * FixtureBothBrokenEvent: a fixture event with a missing @param sentence AND a broken aggregateType()
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
 * A fixture event broken two ways at once: $a has no @param sentence, and aggregateType() reads a
 * property instead of returning a constant.
 *
 * Fixture only: proves HooksReference reports the event-properties failure, not the aggregate-type
 * one, because it reads the properties first — a later failure never masks an earlier one.
 *
 * @since 0.1.0
 */
final readonly class FixtureBothBrokenEvent implements DomainEvent {

	// phpcs:disable Squiz.Commenting.FunctionComment -- $a is deliberately undocumented, which throws off every positional check in this sniff; that is the point of this fixture.
	/**
	 * Records the event. $a is deliberately undocumented.
	 *
	 * @since 0.1.0
	 *
	 * @param \DateTimeImmutable $occurredAt When it happened.
	 */
	public function __construct(
		public int $a,
		public \DateTimeImmutable $occurredAt
	) {
	}
	// phpcs:enable Squiz.Commenting.FunctionComment

	/**
	 * Returns the event's name.
	 *
	 * @since 0.1.0
	 *
	 * @return string `fixture_both_broken`.
	 */
	public static function eventName(): string {
		return 'fixture_both_broken';
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

		return new self( (int) $payload['a'], $occurredAt );
	}

	/**
	 * Returns the aggregate type. Deliberately wrong: reads a property instead of a constant.
	 *
	 * @since 0.1.0
	 *
	 * @return string A string built from $this->a.
	 */
	public function aggregateType(): string {
		return (string) $this->a;
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
		return array( 'a' => $this->a );
	}
}
