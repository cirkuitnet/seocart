<?php
/**
 * StockTransferred: units of a transfer left its source, or arrived at its destination
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
 * Fires in the transaction that sends a transfer, or receives units of it, naming every variant moved and its units.
 *
 * Owns one fact: what a listener learns about one movement of a transfer. `phase` is `sent` when
 * the units left the source and `received` when units arrived at the destination; a transfer is
 * sent once and received once or in parts, and a deactivation's move, which sends and receives
 * each page at once, fires one of each per page. `variant_ids` and `quantities` name each variant
 * once, ascending, with all its units moved in that phase, at the one location the phase is at
 * (StockLines). Units written off as lost arrive nowhere and are not counted. The lines
 * themselves are the transfer's document. It travels through the outbox. Its aggregate is the
 * transfer. Each payload key is its property's name in snake_case.
 *
 * @since 0.2.0
 */
final readonly class StockTransferred implements DomainEvent {

	/**
	 * The kind of aggregate the event happens to: the transfer.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const AGGREGATE = 'transfer';

	/**
	 * The phase of units that left the source.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const SENT = 'sent';

	/**
	 * The phase of units that arrived at the destination.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const RECEIVED = 'received';

	/**
	 * Records the event.
	 *
	 * @since 0.2.0
	 *
	 * @throws \InvalidArgumentException When the phase is neither SENT nor RECEIVED, or the lines do not agree (StockLines::agree()).
	 *
	 * @param int                $transferId            The transfer.
	 * @param int                $sourceLocationId      The location the units left.
	 * @param int                $destinationLocationId The location they go to.
	 * @param string             $phase                 SENT or RECEIVED.
	 * @param int[]              $variantIds            The variants moved, ascending, each once.
	 * @param int[]              $quantities            The units of each, in the same order.
	 * @param \DateTimeImmutable $occurredAt            When the units moved.
	 *
	 * @phpstan-param list<int> $variantIds
	 * @phpstan-param list<int> $quantities
	 */
	public function __construct(
		public int $transferId,
		public int $sourceLocationId,
		public int $destinationLocationId,
		public string $phase,
		public array $variantIds,
		public array $quantities,
		public \DateTimeImmutable $occurredAt
	) {
		if ( ! in_array( $phase, array( self::SENT, self::RECEIVED ), true ) || ! StockLines::agree( $variantIds, $quantities, array(), array(), array() ) ) {
			throw new \InvalidArgumentException( 'A transfer\'s movement is sent or received, and lists at least one variant once with its units.' );
		}
	}

	/**
	 * Returns the event's name.
	 *
	 * @since 0.2.0
	 *
	 * @return string `stock_transferred`; the action is `seocart_stock_transferred`.
	 */
	public static function eventName(): string {
		return 'stock_transferred';
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
			(int) $payload['source_location_id'],
			(int) $payload['destination_location_id'],
			(string) $payload['phase'],
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
			'source_location_id'      => $this->sourceLocationId,
			'destination_location_id' => $this->destinationLocationId,
			'phase'                   => $this->phase,
			'variant_ids'             => $this->variantIds,
			'quantities'              => $this->quantities,
		);
	}
}
