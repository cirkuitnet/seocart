<?php
/**
 * ProductSaved: a product and its post were saved together
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
 * A product was saved: its post and its commerce rows, in one unit of work.
 *
 * Owns one fact: what a listener learns when a product is saved. The product, its source post,
 * the names of the fields the save changed, and a summary of the default variant: its SKU and
 * its price in the store's base currency, which are null for a product saved without them. It is
 * stored in the outbox by the transaction that saves the product, so it exists exactly when the
 * save committed, and fires `seocart_product_saved` after the commit. Each payload key is its
 * property's name in snake_case.
 *
 * @since 0.1.0
 */
final readonly class ProductSaved implements DomainEvent {

	/**
	 * Records the event.
	 *
	 * @since 0.1.0
	 *
	 * @param int                $productId     The product that was saved.
	 * @param int                $postId        The product's source post, the one that controls whether it exists.
	 * @param string[]           $changedFields The names of the fields the save changed, as a list.
	 * @param string|null        $sku           The default variant's SKU, or null for a product saved without one.
	 * @param int|null           $priceMinor    The default variant's price in the store's base currency, in minor units, or null for none.
	 * @param string|null        $currency      The ISO 4217 code of that price's currency, or null when there is no price.
	 * @param \DateTimeImmutable $occurredAt    When the save happened, from the service's Clock.
	 */
	public function __construct(
		public int $productId,
		public int $postId,
		public array $changedFields,
		public ?string $sku,
		public ?int $priceMinor,
		public ?string $currency,
		public \DateTimeImmutable $occurredAt
	) {
	}

	/**
	 * Returns the event's name.
	 *
	 * @since 0.1.0
	 *
	 * @return string `product_saved`; the action is `seocart_product_saved`.
	 */
	public static function eventName(): string {
		return 'product_saved';
	}

	/**
	 * Returns how the event is delivered.
	 *
	 * @since 0.1.0
	 *
	 * @return DeliveryMode Outbox: a listener that keeps an outside system in step must not miss a save.
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
			(int) $payload['product_id'],
			(int) $payload['post_id'],
			array_values( array_map( 'strval', (array) ( $payload['changed_fields'] ?? array() ) ) ),
			isset( $payload['sku'] ) ? (string) $payload['sku'] : null,
			isset( $payload['price_minor'] ) ? (int) $payload['price_minor'] : null,
			isset( $payload['currency'] ) ? (string) $payload['currency'] : null,
			$occurredAt
		);
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
	 * Returns when the save happened.
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
			'product_id'     => $this->productId,
			'post_id'        => $this->postId,
			'changed_fields' => $this->changedFields,
			'sku'            => $this->sku,
			'price_minor'    => $this->priceMinor,
			'currency'       => $this->currency,
		);
	}
}
