<?php
/**
 * ProductBindingPromoted: another of a product's posts became its source post
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
 * A product's source binding moved: another of its posts, in another locale, became the post that controls its existence.
 *
 * Owns one fact: what a listener learns when a product's source post changes. The product, the
 * post and locale that were the source, the ones that are now, and on whose authority: the user
 * in person, a named process acting for a user, or no one, when WordPress's own cron deleted the
 * source post. A listener that keys anything on the source post, a search index or a feed, moves
 * it. It is stored in the outbox by the transaction that moves the binding, and fires
 * `seocart_product_binding_promoted` after the commit. Each payload key is its property's name in
 * snake_case.
 *
 * @since 0.1.0
 */
final readonly class ProductBindingPromoted implements DomainEvent {

	/**
	 * Records the event.
	 *
	 * @since 0.1.0
	 *
	 * @param int                $productId  The product.
	 * @param int                $fromPostId The post that was the source.
	 * @param string             $fromLocale Its locale, such as en_US.
	 * @param int                $toPostId   The post that is the source now.
	 * @param string             $toLocale   Its locale.
	 * @param string             $actorType  `user` for a user in person, `system` for a process acting for one.
	 * @param int|null           $actorId    The user, or null when no one was logged in.
	 * @param \DateTimeImmutable $occurredAt When the source moved, from the service's Clock.
	 */
	public function __construct(
		public int $productId,
		public int $fromPostId,
		public string $fromLocale,
		public int $toPostId,
		public string $toLocale,
		public string $actorType,
		public ?int $actorId,
		public \DateTimeImmutable $occurredAt
	) {
	}

	/**
	 * Returns the event's name.
	 *
	 * @since 0.1.0
	 *
	 * @return string `product_binding_promoted`; the action is `seocart_product_binding_promoted`.
	 */
	public static function eventName(): string {
		return 'product_binding_promoted';
	}

	/**
	 * Returns how the event is delivered.
	 *
	 * @since 0.1.0
	 *
	 * @return DeliveryMode Outbox: a listener must learn of every move, and only of the ones that committed.
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
			(int) $payload['from_post_id'],
			(string) $payload['from_locale'],
			(int) $payload['to_post_id'],
			(string) $payload['to_locale'],
			(string) $payload['actor_type'],
			null === $payload['actor_id'] ? null : (int) $payload['actor_id'],
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
	 * Returns when the source moved.
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
			'product_id'   => $this->productId,
			'from_post_id' => $this->fromPostId,
			'from_locale'  => $this->fromLocale,
			'to_post_id'   => $this->toPostId,
			'to_locale'    => $this->toLocale,
			'actor_type'   => $this->actorType,
			'actor_id'     => $this->actorId,
		);
	}
}
