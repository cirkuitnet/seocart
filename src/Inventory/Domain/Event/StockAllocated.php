<?php
/**
 * StockAllocated: an accepted order's units were allocated to it
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
 * Fires in the transaction that allocates an order's units, naming every variant allocated and the units of each.
 *
 * Owns one fact: what a listener learns about one order's allocation. The lines are two parallel
 * lists, `variant_ids` and `quantities`, the same length and in ascending variant order, because
 * an event's payload holds only scalars and flat lists of scalars; a variant two lines of the
 * order sell is listed once per line. Untracked variants are not allocated and not listed. It
 * travels through the outbox, so it survives a crash between the commit and its delivery. Its
 * aggregate is the order. Each payload key is its property's name in snake_case.
 *
 * @since 0.1.0
 */
final readonly class StockAllocated implements DomainEvent {

	/**
	 * The kind of aggregate the event happens to: the order the units are promised to.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const AGGREGATE = 'order';

	/**
	 * Records the event.
	 *
	 * @since 0.1.0
	 *
	 * @throws \InvalidArgumentException When no variant is listed, or the two lists differ in length.
	 *
	 * @param int                $orderId    The order the units are allocated to.
	 * @param int[]              $variantIds The variants allocated, ascending.
	 * @param int[]              $quantities The units allocated of each, in the same order.
	 * @param \DateTimeImmutable $occurredAt When the units were allocated.
	 *
	 * @phpstan-param list<int> $variantIds
	 * @phpstan-param list<int> $quantities
	 */
	public function __construct(
		public int $orderId,
		public array $variantIds,
		public array $quantities,
		public \DateTimeImmutable $occurredAt
	) {
		if ( array() === $variantIds || count( $variantIds ) !== count( $quantities ) ) {
			throw new \InvalidArgumentException( 'An allocation lists at least one variant, and one quantity per variant.' );
		}
	}

	/**
	 * Returns the event's name.
	 *
	 * @since 0.1.0
	 *
	 * @return string `stock_allocated`; the action is `seocart_stock_allocated`.
	 */
	public static function eventName(): string {
		return 'stock_allocated';
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
	 * Rebuilds the event from its payload.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $payload    The fields.
	 * @param \DateTimeImmutable   $occurredAt When it happened.
	 * @param int                  $version    The payload version.
	 * @return self The event.
	 */
	public static function fromPayload( array $payload, \DateTimeImmutable $occurredAt, int $version ): self {
		return new self(
			(int) $payload['order_id'],
			array_values( array_map( 'intval', (array) $payload['variant_ids'] ) ),
			array_values( array_map( 'intval', (array) $payload['quantities'] ) ),
			$occurredAt
		);
	}

	/**
	 * Returns the aggregate type.
	 *
	 * @since 0.1.0
	 *
	 * @return string `order`.
	 */
	public function aggregateType(): string {
		return self::AGGREGATE;
	}

	/**
	 * Returns the aggregate id.
	 *
	 * @since 0.1.0
	 *
	 * @return int The order's id.
	 */
	public function aggregateId(): int {
		return $this->orderId;
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
			'order_id'    => $this->orderId,
			'variant_ids' => $this->variantIds,
			'quantities'  => $this->quantities,
		);
	}
}
