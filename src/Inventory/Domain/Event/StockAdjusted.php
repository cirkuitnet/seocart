<?php
/**
 * StockAdjusted: an item's on_hand was changed and recorded in the ledger
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
 * Fires after a stock adjustment is committed: a merchant's change of on_hand, or the final entry of a deleted variant.
 *
 * Owns one fact: what a listener learns about one ledger entry that changed on_hand. The
 * fields are the entry and the levels right after it. `on_hand` and `available` are the
 * variant's, added up over every location it has stock at, as they were when a variant had one
 * stock item; `location_id`, `location_on_hand` and `location_available` are the item's at the
 * location where the stock moved. Either `available` may be negative when an adjustment counted
 * fewer units than are promised or held. The item is read under its lock, and the other
 * locations' rows as last committed, without a lock, so an adjustment committed at another
 * location at the same moment may be counted only in the next event's totals. An entry recorded
 * before stock was kept per location has none of the three location fields, and each is null.
 * Delivered through the outbox, so a crash after the commit loses nothing. Each payload key is
 * its property's name in snake_case.
 *
 * @since 0.1.0
 */
final readonly class StockAdjusted implements DomainEvent {

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
	 * @param int                $variantId         The variant whose stock moved.
	 * @param int|null           $locationId        The location where it moved; null for an entry recorded before locations.
	 * @param int                $delta             The change of on_hand, positive or negative; 0 on a deleted variant's final entry.
	 * @param int                $onHand            The variant's units on hand after the change, at every location.
	 * @param int                $available         The variant's on_hand − allocated − held after the change, at every location; may be negative.
	 * @param int|null           $locationOnHand    The units on hand where it moved, after the change; null for an entry recorded before locations.
	 * @param int|null           $locationAvailable on_hand − allocated − held where it moved, after the change; may be negative; null for an entry recorded before locations.
	 * @param string             $reason            Why the stock moved, for example `received` or `variant_deleted`.
	 * @param string             $actorType         `user` for a person acting in person, `system` for a process acting on a user's authority.
	 * @param int|null           $actorId           The WordPress user on whose authority it moved, or null for none.
	 * @param int                $ledgerEntryId     The id of the ledger entry that records the change.
	 * @param \DateTimeImmutable $occurredAt        When it happened.
	 */
	public function __construct(
		public int $variantId,
		public ?int $locationId,
		public int $delta,
		public int $onHand,
		public int $available,
		public ?int $locationOnHand,
		public ?int $locationAvailable,
		public string $reason,
		public string $actorType,
		public ?int $actorId,
		public int $ledgerEntryId,
		public \DateTimeImmutable $occurredAt
	) {
	}

	/**
	 * Returns the event's name.
	 *
	 * @since 0.1.0
	 *
	 * @return string `stock_adjusted`; the action is `seocart_stock_adjusted`.
	 */
	public static function eventName(): string {
		return 'stock_adjusted';
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
		return new self(
			(int) $payload['variant_id'],
			isset( $payload['location_id'] ) ? (int) $payload['location_id'] : null,
			(int) $payload['delta'],
			(int) $payload['on_hand'],
			(int) $payload['available'],
			isset( $payload['location_on_hand'] ) ? (int) $payload['location_on_hand'] : null,
			isset( $payload['location_available'] ) ? (int) $payload['location_available'] : null,
			(string) $payload['reason'],
			(string) $payload['actor_type'],
			null === $payload['actor_id'] ? null : (int) $payload['actor_id'],
			(int) $payload['ledger_entry_id'],
			$occurredAt
		);
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
			'variant_id'         => $this->variantId,
			'location_id'        => $this->locationId,
			'delta'              => $this->delta,
			'on_hand'            => $this->onHand,
			'available'          => $this->available,
			'location_on_hand'   => $this->locationOnHand,
			'location_available' => $this->locationAvailable,
			'reason'             => $this->reason,
			'actor_type'         => $this->actorType,
			'actor_id'           => $this->actorId,
			'ledger_entry_id'    => $this->ledgerEntryId,
		);
	}
}
