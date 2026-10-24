<?php
/**
 * StockHoldExpired: an expired hold row gave its units back
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Inventory\Domain\Event;

use SEOCart\Support\Events\DeliveryMode;
use SEOCart\Support\Events\DomainEvent;

defined( 'ABSPATH' ) || exit;

/**
 * Fires after an expired checkout hold of one variant was reclaimed and its units given back.
 *
 * Owns one fact: what a listener learns about one reclaimed hold row. Reclamation works one
 * item, a variant at a location, at a time, and the event names the one row reclaimed: its
 * variant, its location and its units, so `quantity` is that row's, as it always was. A hold of
 * several items expires as one event per item, all with the same hold id; a hold of one variant
 * at two locations so expires as two events naming the same variant, each with its own location
 * and units, which together are the variant's. A row reclaimed before stock was kept per location
 * has no `location_id`, and it is null. It fires whoever reclaimed the row: a checkout
 * that needed the units, or the sweep. Delivered through the outbox. Each payload key is its
 * property's name in snake_case.
 *
 * @since 0.1.0
 */
final readonly class StockHoldExpired implements DomainEvent {

	/**
	 * The kind of aggregate every stock event happens to.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const AGGREGATE = 'variant';

	/**
	 * Records the event.
	 *
	 * @since 0.1.0
	 *
	 * @param string             $holdId     The id of the hold the row belonged to.
	 * @param int                $variantId  The variant whose units were held.
	 * @param int|null           $locationId The location whose units were held; null for a row reclaimed before locations.
	 * @param int                $quantity   The units given back.
	 * @param string             $expiredAt  When the hold expired, UTC, `Y-m-d H:i:s`.
	 * @param \DateTimeImmutable $occurredAt When the row was reclaimed.
	 */
	public function __construct(
		public string $holdId,
		public int $variantId,
		public ?int $locationId,
		public int $quantity,
		public string $expiredAt,
		public \DateTimeImmutable $occurredAt
	) {
	}

	/**
	 * Returns the event's name.
	 *
	 * @since 0.1.0
	 *
	 * @return string `stock_hold_expired`; the action is `seocart_stock_hold_expired`.
	 */
	public static function eventName(): string {
		return 'stock_hold_expired';
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
		return new self( (string) $payload['hold_id'], (int) $payload['variant_id'], isset( $payload['location_id'] ) ? (int) $payload['location_id'] : null, (int) $payload['quantity'], (string) $payload['expired_at'], $occurredAt );
	}

	/**
	 * Returns the aggregate type.
	 *
	 * @since 0.1.0
	 *
	 * @return string `variant`.
	 */
	public function aggregateType(): string {
		return self::AGGREGATE;
	}

	/**
	 * Returns the aggregate id.
	 *
	 * @since 0.1.0
	 *
	 * @return int The variant id.
	 */
	public function aggregateId(): int {
		return $this->variantId;
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
	 * @return array<string, mixed> The fields, keyed by their properties' names in snake_case.
	 */
	public function toPayload(): array {
		return array(
			'hold_id'     => $this->holdId,
			'variant_id'  => $this->variantId,
			'location_id' => $this->locationId,
			'quantity'    => $this->quantity,
			'expired_at'  => $this->expiredAt,
		);
	}
}
