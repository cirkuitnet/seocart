<?php
/**
 * DomainEvent: the contract of an event a module records when its state changes
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Support\Events;

defined( 'ABSPATH' ) || exit;

/**
 * Something that happened to one aggregate, told in the past tense, as plain data.
 *
 * Owns one fact: what every domain event says about itself, so that the platform can store
 * it, deliver it and rebuild it without knowing its class.
 *
 * An event is a `final readonly class` in the owning module's Domain namespace, for example
 * `SEOCart\Inventory\Domain\Event\StockAdjusted`. It is pure data: constructor-promoted
 * readonly properties, no WordPress call, no repository, no aggregate and no Money object.
 * Its payload holds ids, integers, strings, booleans, null and flat lists of those; an amount
 * is written as `amount_minor` plus `currency`. The platform refuses an event that breaks
 * these rules when it is published, not when it is delivered.
 *
 * The instant it happened comes from the Clock the application service holds, passed into
 * the aggregate method that records the event, so the Domain never reads the wall clock. The
 * request's correlation id is not part of the event: the platform carries it next to the
 * event, for every event alike.
 *
 * @since 0.1.0
 */
interface DomainEvent {

	/**
	 * Returns the event's name, which the WordPress action it fires is named after.
	 *
	 * @since 0.1.0
	 *
	 * @return string Lowercase snake_case in the past tense, at most 64 characters, for example
	 *                `stock_adjusted`. The action is `seocart_` followed by the name.
	 */
	public static function eventName(): string;

	/**
	 * Returns how the event is delivered.
	 *
	 * @since 0.1.0
	 *
	 * @return DeliveryMode Outbox for an event that must not be lost, AfterCommit for one whose loss is tolerable.
	 */
	public static function deliveryMode(): DeliveryMode;

	/**
	 * Returns the version of the payload this class writes.
	 *
	 * It starts at 1 and goes up when a field is removed or changes type. Adding a field does
	 * not change it. fromPayload() must accept every version the class has ever written.
	 *
	 * @since 0.1.0
	 *
	 * @return int The version, 1 or more.
	 */
	public static function payloadVersion(): int;

	/**
	 * Rebuilds an event from a stored payload.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $payload    What toPayload() returned when the event was stored.
	 * @param \DateTimeImmutable   $occurredAt When the event happened, as stored with it, in UTC.
	 * @param int                  $version    The payload version it was stored with; never newer
	 *                                         than payloadVersion().
	 * @return self The event, an instance of the class it is called on. An event class is final
	 *              and declares `self` as its return type.
	 */
	public static function fromPayload( array $payload, \DateTimeImmutable $occurredAt, int $version ): self;

	/**
	 * Returns the kind of aggregate the event happened to.
	 *
	 * @since 0.1.0
	 *
	 * @return string Lowercase snake_case, at most 32 characters, for example `variant` or `order`.
	 */
	public function aggregateType(): string;

	/**
	 * Returns the id of the aggregate the event happened to.
	 *
	 * @since 0.1.0
	 *
	 * @return int The id, 0 or more.
	 */
	public function aggregateId(): int;

	/**
	 * Returns when the event happened.
	 *
	 * @since 0.1.0
	 *
	 * @return \DateTimeImmutable The instant, from the Clock of the service that recorded the event.
	 */
	public function occurredAt(): \DateTimeImmutable;

	/**
	 * Returns the event's fields as plain data.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed> Field name => an int, a string, a bool, null, or a list of those.
	 */
	public function toPayload(): array;
}
