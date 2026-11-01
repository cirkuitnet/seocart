<?php
/**
 * ExchangeRateVersionChanged: a new version of the store's exchange rates was saved
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Pricing\Domain\Event;

use SEOCart\Support\Events\DeliveryMode;
use SEOCart\Support\Events\DomainEvent;

defined( 'ABSPATH' ) || exit;

/**
 * A rate set was saved as the next version of the store's exchange rates.
 *
 * Owns one fact: what a listener learns when the rates change: the version saved, which every
 * cart priced in another currency than the base one converts at from then on. It is stored in the
 * outbox by the transaction that inserts the set, so it exists exactly when the version does, and
 * fires `seocart_exchange_rate_version_changed` after the commit. The catalog's price projection
 * hears it and reprices the products whose price it converted at an earlier version.
 *
 * @since 0.2.0
 */
final readonly class ExchangeRateVersionChanged implements DomainEvent {

	/**
	 * Records the event.
	 *
	 * @since 0.2.0
	 *
	 * @param int                $version    The version saved.
	 * @param \DateTimeImmutable $occurredAt When it was saved, from the service's Clock.
	 */
	public function __construct(
		public int $version,
		public \DateTimeImmutable $occurredAt
	) {
	}

	/**
	 * Returns the event's name.
	 *
	 * @since 0.2.0
	 *
	 * @return string `exchange_rate_version_changed`; the action is `seocart_exchange_rate_version_changed`.
	 */
	public static function eventName(): string {
		return 'exchange_rate_version_changed';
	}

	/**
	 * Returns how the event is delivered.
	 *
	 * @since 0.2.0
	 *
	 * @return DeliveryMode Outbox: a price kept in another currency must not miss the rate it is converted at.
	 */
	public static function deliveryMode(): DeliveryMode {
		return DeliveryMode::Outbox;
	}

	/**
	 * Returns the payload version.
	 *
	 * @since 0.2.0
	 *
	 * @return int 1.
	 */
	public static function payloadVersion(): int {
		return 1;
	}

	/**
	 * Rebuilds the event from its stored payload.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $payload    The stored fields.
	 * @param \DateTimeImmutable   $occurredAt When it happened.
	 * @param int                  $version    The stored payload version.
	 * @return self The event.
	 */
	public static function fromPayload( array $payload, \DateTimeImmutable $occurredAt, int $version ): self {
		return new self( (int) $payload['version'], $occurredAt );
	}

	/**
	 * Returns the aggregate type.
	 *
	 * @since 0.2.0
	 *
	 * @return string `exchange_rates`.
	 */
	public function aggregateType(): string {
		return 'exchange_rates';
	}

	/**
	 * Returns the version saved, which identifies the rate set.
	 *
	 * @since 0.2.0
	 *
	 * @return int The version.
	 */
	public function aggregateId(): int {
		return $this->version;
	}

	/**
	 * Returns when the version was saved.
	 *
	 * @since 0.2.0
	 *
	 * @return \DateTimeImmutable The instant.
	 */
	public function occurredAt(): \DateTimeImmutable {
		return $this->occurredAt;
	}

	/**
	 * Returns the fields.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, mixed> The version.
	 */
	public function toPayload(): array {
		return array( 'version' => $this->version );
	}
}
