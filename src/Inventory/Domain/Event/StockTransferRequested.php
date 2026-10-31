<?php
/**
 * StockTransferRequested: the system asked for a transfer an order waits for
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Inventory\Domain\Event;

use SEOCart\Inventory\Domain\StockLines;
use SEOCart\Support\Events\DeliveryMode;
use SEOCart\Support\Events\DomainEvent;

defined( 'ABSPATH' ) || exit;

/**
 * Fires in the transaction that requests a transfer for an order whose units are at another location, naming every variant it is to bring and its units.
 *
 * Owns one fact: what a listener learns about one requested transfer, such as the message that
 * tells the store's staff a parcel is to be sent. One is fired per transfer: per location the
 * order's units are brought from. `variant_ids` and `quantities` name each variant once,
 * ascending, with all its units on the transfer (StockLines). It travels through the outbox.
 * Its aggregate is the transfer. Each payload key is its property's name in snake_case.
 *
 * @since 0.2.0
 */
final readonly class StockTransferRequested implements DomainEvent {

	/**
	 * The kind of aggregate the event happens to: the transfer.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const AGGREGATE = 'transfer';

	/**
	 * Records the event.
	 *
	 * @since 0.2.0
	 *
	 * @throws \InvalidArgumentException When the lines do not agree (StockLines::agree()).
	 *
	 * @param int                $transferId            The transfer requested.
	 * @param int                $orderId               The order that waits for it.
	 * @param int                $sourceLocationId      The location its units are to leave.
	 * @param int                $destinationLocationId The order's location, where they are to arrive.
	 * @param int[]              $variantIds            The variants, ascending, each once.
	 * @param int[]              $quantities            The units of each, in the same order.
	 * @param \DateTimeImmutable $occurredAt            When it was requested.
	 *
	 * @phpstan-param list<int> $variantIds
	 * @phpstan-param list<int> $quantities
	 */
	public function __construct(
		public int $transferId,
		public int $orderId,
		public int $sourceLocationId,
		public int $destinationLocationId,
		public array $variantIds,
		public array $quantities,
		public \DateTimeImmutable $occurredAt
	) {
		if ( ! StockLines::agree( $variantIds, $quantities, array(), array(), array() ) ) {
			throw new \InvalidArgumentException( 'A requested transfer lists at least one variant once with its units.' );
		}
	}

	/**
	 * Returns the event's name.
	 *
	 * @since 0.2.0
	 *
	 * @return string `stock_transfer_requested`; the action is `seocart_stock_transfer_requested`.
	 */
	public static function eventName(): string {
		return 'stock_transfer_requested';
	}

	/**
	 * Returns how the event is delivered.
	 *
	 * @since 0.2.0
	 *
	 * @return DeliveryMode Outbox.
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
	 * Rebuilds the event from its payload.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $payload    The fields.
	 * @param \DateTimeImmutable   $occurredAt When it happened.
	 * @param int                  $version    The payload version.
	 * @return self The event.
	 */
	public static function fromPayload( array $payload, \DateTimeImmutable $occurredAt, int $version ): self {
		return new self(
			(int) $payload['transfer_id'],
			(int) $payload['order_id'],
			(int) $payload['source_location_id'],
			(int) $payload['destination_location_id'],
			array_values( array_map( 'intval', (array) $payload['variant_ids'] ) ),
			array_values( array_map( 'intval', (array) $payload['quantities'] ) ),
			$occurredAt
		);
	}

	/**
	 * Returns the aggregate type.
	 *
	 * @since 0.2.0
	 *
	 * @return string `transfer`.
	 */
	public function aggregateType(): string {
		return self::AGGREGATE;
	}

	/**
	 * Returns the aggregate id.
	 *
	 * @since 0.2.0
	 *
	 * @return int The transfer's id.
	 */
	public function aggregateId(): int {
		return $this->transferId;
	}

	/**
	 * Returns when it happened.
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
	 * @return array<string, mixed> The fields, keyed by their properties' names in snake_case.
	 */
	public function toPayload(): array {
		return array(
			'transfer_id'             => $this->transferId,
			'order_id'                => $this->orderId,
			'source_location_id'      => $this->sourceLocationId,
			'destination_location_id' => $this->destinationLocationId,
			'variant_ids'             => $this->variantIds,
			'quantities'              => $this->quantities,
		);
	}
}
