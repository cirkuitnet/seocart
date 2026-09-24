<?php
/**
 * FixtureCartAbandoned: a fixture after-commit domain event HooksReference is proven against
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
 * A fixture cart was abandoned.
 *
 * Fixture only: proves an after-commit event and a hook name that sorts before another fixture
 * event's are both documented correctly.
 *
 * @since 0.1.0
 */
final readonly class FixtureCartAbandoned implements DomainEvent {

	/**
	 * Records the event.
	 *
	 * @since 0.1.0
	 *
	 * @param int                $cartId     The cart that was abandoned.
	 * @param \DateTimeImmutable $occurredAt When it happened.
	 */
	public function __construct(
		public int $cartId,
		public \DateTimeImmutable $occurredAt
	) {
	}

	/**
	 * Returns the event's name.
	 *
	 * @since 0.1.0
	 *
	 * @return string `cart_abandoned`.
	 */
	public static function eventName(): string {
		return 'cart_abandoned';
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
	 * @return int 2.
	 */
	public static function payloadVersion(): int {
		return 2;
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

		return new self( (int) $payload['cart_id'], $occurredAt );
	}

	/**
	 * Returns the aggregate type.
	 *
	 * @since 0.1.0
	 *
	 * @return string `cart`.
	 */
	public function aggregateType(): string {
		return 'cart';
	}

	/**
	 * Returns the aggregate id.
	 *
	 * @since 0.1.0
	 *
	 * @return int The cart id.
	 */
	public function aggregateId(): int {
		return $this->cartId;
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
		return array( 'cart_id' => $this->cartId );
	}
}
