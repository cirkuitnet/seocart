<?php
/**
 * EventEnvelope: what a listener is told about the delivery of an event
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Events;

use SEOCart\Support\Events\DomainEvent;

defined( 'ABSPATH' ) || exit;

/**
 * The delivery metadata of one event, handed to every listener next to the event itself.
 *
 * Owns one fact: how one delivery of an event is described to a listener. A bridged event
 * fires `do_action( $envelope->hook, $envelope->event, $envelope )`: the first argument is the
 * readonly event, the second this readonly envelope. Those two arguments are the whole public
 * contract of a bridged event.
 *
 * Delivery from the outbox is at least once. A listener with a side effect keys it on
 * `$envelope->outboxId`, which is the same on every delivery of the same stored event, and
 * treats `$envelope->attempt` above 1 as a sign that it may have seen the event before. An
 * after-commit event has no outbox id and is delivered once, or not at all.
 *
 * @since 0.1.0
 */
final readonly class EventEnvelope {

	/**
	 * What every bridged action's name starts with.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const HOOK_PREFIX = 'seocart_';

	/**
	 * The id of the stored event, or null for an after-commit event.
	 *
	 * @since 0.1.0
	 *
	 * @var int|null
	 */
	public ?int $outboxId;

	/**
	 * The event's name, for example `stock_adjusted`.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public string $eventName;

	/**
	 * The action the event fires, for example `seocart_stock_adjusted`.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public string $hook;

	/**
	 * The correlation id of the request that published the event, or null when none was stored.
	 *
	 * @since 0.1.0
	 *
	 * @var string|null
	 */
	public ?string $correlationId;

	/**
	 * Which delivery attempt this is, from 1.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public int $attempt;

	/**
	 * When the event happened.
	 *
	 * @since 0.1.0
	 *
	 * @var \DateTimeImmutable
	 */
	public \DateTimeImmutable $occurredAt;

	/**
	 * The event.
	 *
	 * @since 0.1.0
	 *
	 * @var DomainEvent
	 */
	public DomainEvent $event;

	/**
	 * Describes one delivery. Use inMemory() or fromRow().
	 *
	 * @since 0.1.0
	 *
	 * @param int|null    $outboxId      The id of the stored event, or null.
	 * @param DomainEvent $event         The event.
	 * @param string|null $correlationId The correlation id of the request that published it, or null.
	 * @param int         $attempt       The delivery attempt, from 1.
	 */
	private function __construct( ?int $outboxId, DomainEvent $event, ?string $correlationId, int $attempt ) {
		$this->outboxId      = $outboxId;
		$this->eventName     = $event::eventName();
		$this->hook          = self::hookFor( $this->eventName );
		$this->correlationId = $correlationId;
		$this->attempt       = $attempt;
		$this->occurredAt    = $event->occurredAt();
		$this->event         = $event;
	}

	/**
	 * Describes the delivery of an after-commit event, straight from memory.
	 *
	 * @since 0.1.0
	 *
	 * @param DomainEvent $event         The event.
	 * @param string      $correlationId The correlation id of the request publishing it.
	 * @return self The envelope, with no outbox id and attempt 1.
	 */
	public static function inMemory( DomainEvent $event, string $correlationId ): self {
		return new self( null, $event, $correlationId, 1 );
	}

	/**
	 * Describes the delivery of a stored event.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $row   The claimed `outbox` row: its id, its correlation id, and in
	 *                                    `attempts` the number of this delivery.
	 * @param DomainEvent          $event The event, rebuilt from the row's payload.
	 * @return self The envelope.
	 */
	public static function fromRow( array $row, DomainEvent $event ): self {
		$correlationId = $row['correlation_id'] ?? null;

		return new self( (int) $row['id'], $event, null === $correlationId ? null : (string) $correlationId, (int) $row['attempts'] );
	}

	/**
	 * Returns the action an event name fires.
	 *
	 * @since 0.1.0
	 *
	 * @param string $eventName The event's name, for example `stock_adjusted`.
	 * @return string The action, for example `seocart_stock_adjusted`.
	 */
	public static function hookFor( string $eventName ): string {
		return self::HOOK_PREFIX . $eventName;
	}
}
