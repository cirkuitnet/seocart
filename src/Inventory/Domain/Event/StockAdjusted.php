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
 * fields are the entry and the level right after it; `available` may be negative when the
 * adjustment counted fewer units than are promised or held. Delivered through the outbox, so a
 * crash after the commit loses nothing. Each payload key is its property's name in snake_case.
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
	 * @param int                $variantId     The variant whose stock moved.
	 * @param int                $delta         The change of on_hand, positive or negative; 0 on a deleted variant's final entry.
	 * @param int                $onHand        The units on hand after the change.
	 * @param int                $available     on_hand − allocated − held after the change; may be negative.
	 * @param string             $reason        Why the stock moved, for example `received` or `variant_deleted`.
	 * @param string             $actorType     `user` for a person acting in person, `system` for a process acting on a user's authority.
	 * @param int|null           $actorId       The WordPress user on whose authority it moved, or null for none.
	 * @param int                $ledgerEntryId The id of the ledger entry that records the change.
	 * @param \DateTimeImmutable $occurredAt    When it happened.
	 */
	public function __construct(
		public int $variantId,
		public int $delta,
		public int $onHand,
		public int $available,
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
			(int) $payload['delta'],
			(int) $payload['on_hand'],
			(int) $payload['available'],
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
			'variant_id'      => $this->variantId,
			'delta'           => $this->delta,
			'on_hand'         => $this->onHand,
			'available'       => $this->available,
			'reason'          => $this->reason,
			'actor_type'      => $this->actorType,
			'actor_id'        => $this->actorId,
			'ledger_entry_id' => $this->ledgerEntryId,
		);
	}
}
