<?php
/**
 * ProductDeleted: a product and its commerce rows were deleted
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Catalog\Domain\Event;

use SEOCart\Support\Events\DeliveryMode;
use SEOCart\Support\Events\DomainEvent;

defined( 'ABSPATH' ) || exit;

/**
 * A product was deleted: its source post went, and its commerce rows with it.
 *
 * Owns one fact: what a listener learns when a product is deleted. The product, the post whose
 * deletion removed it, and the SKUs its variants had, so a listener that keeps an outside system
 * in step can find what to remove there after the rows are gone. It is stored in the outbox by
 * the transaction that deletes the rows, and fires `seocart_product_deleted` after the commit.
 * Each payload key is its property's name in snake_case.
 *
 * @since 0.1.0
 */
final readonly class ProductDeleted implements DomainEvent {

	/**
	 * Records the event.
	 *
	 * @since 0.1.0
	 *
	 * @param int                $productId  The product that was deleted.
	 * @param int                $postId     The source post whose deletion removed the product.
	 * @param string[]           $skus       The SKUs the product's variants had, as a list.
	 * @param \DateTimeImmutable $occurredAt When the deletion happened, from the service's Clock.
	 */
	public function __construct(
		public int $productId,
		public int $postId,
		public array $skus,
		public \DateTimeImmutable $occurredAt
	) {
	}

	/**
	 * Returns the event's name.
	 *
	 * @since 0.1.0
	 *
	 * @return string `product_deleted`; the action is `seocart_product_deleted`.
	 */
	public static function eventName(): string {
		return 'product_deleted';
	}

	/**
	 * Returns how the event is delivered.
	 *
	 * @since 0.1.0
	 *
	 * @return DeliveryMode Outbox: the SKUs exist nowhere else once the rows are gone.
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
		return new self( (int) $payload['product_id'], (int) $payload['post_id'], array_values( array_map( 'strval', (array) ( $payload['skus'] ?? array() ) ) ), $occurredAt );
	}

	/**
	 * Returns the aggregate type.
	 *
	 * @since 0.1.0
	 *
	 * @return string `product`.
	 */
	public function aggregateType(): string {
		return 'product';
	}

	/**
	 * Returns the product's id.
	 *
	 * @since 0.1.0
	 *
	 * @return int The id.
	 */
	public function aggregateId(): int {
		return $this->productId;
	}

	/**
	 * Returns when the deletion happened.
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
			'product_id' => $this->productId,
			'post_id'    => $this->postId,
			'skus'       => $this->skus,
		);
	}
}
