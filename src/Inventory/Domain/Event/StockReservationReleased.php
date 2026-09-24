<?php
/**
 * StockReservationReleased: a checkout hold gave its units back before it expired
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
 * Fires after the rows of a checkout hold are released, naming the variants given back and the units of each.
 *
 * Owns one fact: what a listener learns about one release. A release gives back the hold's rows
 * that were still there: a row already reclaimed after its expiry is not released twice, so the
 * lists may name fewer variants than the hold had. A deleted or trashed product releases every
 * hold of its variants, one event per hold. The lines are two parallel lists in ascending
 * variant order. Delivered in the same request right after the commit. Its aggregate id is the
 * lowest variant id released. Each payload key is its property's name in snake_case.
 *
 * @since 0.1.0
 */
final readonly class StockReservationReleased implements DomainEvent {

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
	 * @throws \InvalidArgumentException When no variant is listed, or the two lists differ in length.
	 *
	 * @param string             $holdId     The id of the hold released.
	 * @param string             $reason     Why it was released, for example `payment_declined` or `variant_deleted`.
	 * @param int[]              $variantIds The variants given back, ascending.
	 * @param int[]              $quantities The units given back of each, in the same order.
	 * @param \DateTimeImmutable $occurredAt When it was released.
	 *
	 * @phpstan-param list<int> $variantIds
	 * @phpstan-param list<int> $quantities
	 */
	public function __construct(
		public string $holdId,
		public string $reason,
		public array $variantIds,
		public array $quantities,
		public \DateTimeImmutable $occurredAt
	) {
		if ( array() === $variantIds || count( $variantIds ) !== count( $quantities ) ) {
			throw new \InvalidArgumentException( 'A release lists at least one variant, and one quantity per variant.' );
		}
	}

	/**
	 * Returns the event's name.
	 *
	 * @since 0.1.0
	 *
	 * @return string `stock_reservation_released`; the action is `seocart_stock_reservation_released`.
	 */
	public static function eventName(): string {
		return 'stock_reservation_released';
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
			(string) $payload['hold_id'],
			(string) $payload['reason'],
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
	 * @return int The lowest variant id released.
	 */
	public function aggregateId(): int {
		return $this->variantIds[0];
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
			'reason'      => $this->reason,
			'variant_ids' => $this->variantIds,
			'quantities'  => $this->quantities,
		);
	}
}
