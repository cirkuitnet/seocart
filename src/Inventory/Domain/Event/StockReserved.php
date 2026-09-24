<?php
/**
 * StockReserved: a checkout hold was taken
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
 * Fires after a checkout hold is committed, naming every variant it holds and the units of each.
 *
 * Owns one fact: what a listener learns about one hold. The lines are two parallel lists,
 * `variant_ids` and `quantities`, the same length and in ascending variant order, because an
 * event's payload holds only scalars and flat lists of scalars. Untracked variants need no hold
 * and are not listed. Delivered in the same request right after the commit; its loss is
 * tolerable, because the hold rows themselves are the record. Its aggregate id is the lowest
 * variant id of the hold. Each payload key is its property's name in snake_case.
 *
 * @since 0.1.0
 */
final readonly class StockReserved implements DomainEvent {

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
	 * @param string             $holdId     The hold's id, which releases it again.
	 * @param int|null           $cartId     The cart the hold was taken for, or null.
	 * @param int|null           $orderId    The order the hold was taken for, or null.
	 * @param int[]              $variantIds The variants held, ascending.
	 * @param int[]              $quantities The units held of each, in the same order.
	 * @param string             $expiresAt  When the hold expires, UTC, `Y-m-d H:i:s`.
	 * @param \DateTimeImmutable $occurredAt When the hold was taken.
	 *
	 * @phpstan-param list<int> $variantIds
	 * @phpstan-param list<int> $quantities
	 */
	public function __construct(
		public string $holdId,
		public ?int $cartId,
		public ?int $orderId,
		public array $variantIds,
		public array $quantities,
		public string $expiresAt,
		public \DateTimeImmutable $occurredAt
	) {
		if ( array() === $variantIds || count( $variantIds ) !== count( $quantities ) ) {
			throw new \InvalidArgumentException( 'A reservation lists at least one variant, and one quantity per variant.' );
		}
	}

	/**
	 * Returns the event's name.
	 *
	 * @since 0.1.0
	 *
	 * @return string `stock_reserved`; the action is `seocart_stock_reserved`.
	 */
	public static function eventName(): string {
		return 'stock_reserved';
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
			null === $payload['cart_id'] ? null : (int) $payload['cart_id'],
			null === $payload['order_id'] ? null : (int) $payload['order_id'],
			array_values( array_map( 'intval', (array) $payload['variant_ids'] ) ),
			array_values( array_map( 'intval', (array) $payload['quantities'] ) ),
			(string) $payload['expires_at'],
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
	 * @return int The lowest variant id of the hold.
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
			'cart_id'     => $this->cartId,
			'order_id'    => $this->orderId,
			'variant_ids' => $this->variantIds,
			'quantities'  => $this->quantities,
			'expires_at'  => $this->expiresAt,
		);
	}
}
