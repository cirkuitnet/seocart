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

use SEOCart\Inventory\Domain\StockLines;
use SEOCart\Support\Events\DeliveryMode;
use SEOCart\Support\Events\DomainEvent;

defined( 'ABSPATH' ) || exit;

/**
 * Fires in the transaction that allocates an order's units, naming every variant allocated and the units of each.
 *
 * Owns one fact: what a listener learns about one order's allocation. An event's payload holds
 * only scalars and flat lists of scalars, so its lines are two sets of parallel lists
 * (StockLines). `variant_ids` and `quantities` name each variant allocated once, in ascending
 * order, with all the units allocated of it at every location. `location_variant_ids`,
 * `location_ids` and `location_quantities` name each item allocated from, a variant at a
 * location, in ascending order of the variant then the location, with the units allocated there;
 * the units of a variant's items add up to the variant's. An allocation recorded before stock was
 * kept per location has none of the three item lists, and each is empty. Untracked variants are
 * not allocated and not listed. It travels through the outbox, so it survives a crash between the
 * commit and its delivery. Its aggregate is the order. Each payload key is its property's name in
 * snake_case.
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
	 * @throws \InvalidArgumentException When the lines do not agree (StockLines::agree()).
	 *
	 * @param int                $orderId            The order the units are allocated to.
	 * @param int[]              $variantIds         The variants allocated, ascending, each once.
	 * @param int[]              $quantities         The units allocated of each, at every location, in the same order.
	 * @param int[]              $locationVariantIds The variant of each item allocated from, ascending by variant then location; empty for an allocation recorded before locations.
	 * @param int[]              $locationIds        The location of each item allocated from, in the same order; empty for an allocation recorded before locations.
	 * @param int[]              $locationQuantities The units allocated from each item, in the same order; empty for an allocation recorded before locations.
	 * @param \DateTimeImmutable $occurredAt         When the units were allocated.
	 *
	 * @phpstan-param list<int> $variantIds
	 * @phpstan-param list<int> $quantities
	 * @phpstan-param list<int> $locationVariantIds
	 * @phpstan-param list<int> $locationIds
	 * @phpstan-param list<int> $locationQuantities
	 */
	public function __construct(
		public int $orderId,
		public array $variantIds,
		public array $quantities,
		public array $locationVariantIds,
		public array $locationIds,
		public array $locationQuantities,
		public \DateTimeImmutable $occurredAt
	) {
		if ( ! StockLines::agree( $variantIds, $quantities, $locationVariantIds, $locationIds, $locationQuantities ) ) {
			throw new \InvalidArgumentException( 'An allocation lists at least one variant once with its units, and, unless recorded before locations, each of its items with units adding up to the variant\'s.' );
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
			array_values( array_map( 'intval', (array) ( $payload['location_variant_ids'] ?? array() ) ) ),
			array_values( array_map( 'intval', (array) ( $payload['location_ids'] ?? array() ) ) ),
			array_values( array_map( 'intval', (array) ( $payload['location_quantities'] ?? array() ) ) ),
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
			'order_id'             => $this->orderId,
			'variant_ids'          => $this->variantIds,
			'quantities'           => $this->quantities,
			'location_variant_ids' => $this->locationVariantIds,
			'location_ids'         => $this->locationIds,
			'location_quantities'  => $this->locationQuantities,
		);
	}
}
