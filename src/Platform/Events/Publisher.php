<?php
/**
 * Publisher: stores or schedules domain events inside the caller's unit of work
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Events;

use SEOCart\Platform\Database\TransactionManager;
use SEOCart\Platform\Logging\CorrelationId;
use SEOCart\Support\Events\DeliveryMode;
use SEOCart\Support\Events\DomainEvent;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- A refused event is a programming error; the message names a class for the developer, never HTML.

/**
 * Publishes events so that none is delivered before, or without, the commit it describes.
 *
 * Owns one fact: what happens to an event between publish() and its delivery. Every event is
 * checked first; only then is anything stored:
 *
 * 1. its class is in the event catalog;
 * 2. its class is readonly, so no listener can change it;
 * 3. no listener of the same event for the same aggregate is running: an event is never
 *    published again from inside its own delivery, which would loop;
 * 4. its payload keeps to ids and plain values within the size cap (Outbox::encode());
 * 5. an outbox event is published inside a transaction. Storing it in a transaction of its
 *    own would make the row durable without the change it describes: the very lie the outbox
 *    exists to prevent. A service that publishes one runs its write in transaction().
 *
 * Then an outbox event becomes a row, inserted in the caller's transaction at the caller's
 * savepoint level, so a rollback at any level removes exactly that level's rows. An
 * after-commit event becomes an after-commit callback at the current level, which fires its
 * action once the outermost level has committed, or at once outside a transaction; a rolled
 * back level drops it. Once per call that stored a row, the wake is registered after the
 * commit too; it costs nothing when it runs. The kernel binds Jobs\EventWake, which only notes
 * the site and delivers at the end of the request, after the response has ended, or hands the
 * delivery to the job runner.
 *
 * Every event carries the correlation id of the request that publishes it.
 *
 * @since 0.1.0
 */
final class Publisher implements EventPublisher {

	/**
	 * The longest encoded payload stored by default, in bytes. A policy: the column holds far more.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const DEFAULT_PAYLOAD_CAP_BYTES = 16384;

	/**
	 * The unit of work events are published in.
	 *
	 * @since 0.1.0
	 *
	 * @var TransactionManager
	 */
	private TransactionManager $tx;

	/**
	 * Stores outbox events.
	 *
	 * @since 0.1.0
	 *
	 * @var Outbox
	 */
	private Outbox $outbox;

	/**
	 * Fires after-commit events, and knows which deliveries are in progress.
	 *
	 * @since 0.1.0
	 *
	 * @var HookBridge
	 */
	private HookBridge $bridge;

	/**
	 * The events that may be published.
	 *
	 * @since 0.1.0
	 *
	 * @var EventCatalog
	 */
	private EventCatalog $catalog;

	/**
	 * The correlation id every event carries.
	 *
	 * @since 0.1.0
	 *
	 * @var CorrelationId
	 */
	private CorrelationId $correlation;

	/**
	 * Wakes a drainer after a commit that stored events.
	 *
	 * @since 0.1.0
	 *
	 * @var callable(): mixed
	 */
	private $wake;

	/**
	 * The longest encoded payload, in bytes.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private int $payloadCapBytes;

	/**
	 * Creates the publisher. Sends nothing and registers nothing.
	 *
	 * @since 0.1.0
	 *
	 * @param TransactionManager $tx              The unit of work.
	 * @param Outbox             $outbox          Stores outbox events.
	 * @param HookBridge         $bridge          Fires after-commit events.
	 * @param EventCatalog       $catalog         The events that may be published.
	 * @param CorrelationId      $correlation     The correlation id events carry.
	 * @param callable           $wake            Runs after a commit that stored events: the
	 *                                            kernel binds Jobs\EventWake.
	 * @param int                $payloadCapBytes Optional. The longest encoded payload, in bytes.
	 *                                            Default DEFAULT_PAYLOAD_CAP_BYTES.
	 */
	public function __construct(
		TransactionManager $tx,
		Outbox $outbox,
		HookBridge $bridge,
		EventCatalog $catalog,
		CorrelationId $correlation,
		callable $wake,
		int $payloadCapBytes = self::DEFAULT_PAYLOAD_CAP_BYTES
	) {
		$this->tx              = $tx;
		$this->outbox          = $outbox;
		$this->bridge          = $bridge;
		$this->catalog         = $catalog;
		$this->correlation     = $correlation;
		$this->wake            = $wake;
		$this->payloadCapBytes = $payloadCapBytes;
	}

	/**
	 * Publishes events, in order, after checking every one of them.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException When an event breaks a rule of the class description; nothing is
	 *                         stored or scheduled then, not even for the events before it.
	 *
	 * @param DomainEvent ...$events The events.
	 */
	public function publish( DomainEvent ...$events ): void {
		$checked = array();

		foreach ( $events as $event ) {
			$checked[] = array( $event, $this->check( $event ) );
		}

		$stored = false;

		foreach ( $checked as $entry ) {
			list( $event, $payloadJson ) = $entry;

			if ( DeliveryMode::Outbox === $event::deliveryMode() ) {
				$this->outbox->insert( $event, $payloadJson, $this->correlation->current() );

				$stored = true;

				continue;
			}

			$envelope = EventEnvelope::inMemory( $event, $this->correlation->current() );

			$this->tx->afterCommit( fn() => $this->bridge->dispatch( $envelope ) );
		}

		if ( $stored ) {
			$this->tx->afterCommit( $this->wake );
		}
	}

	/**
	 * Checks one event and returns its encoded payload.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException When the event breaks a rule.
	 *
	 * @param DomainEvent $event The event.
	 * @return string The payload as the outbox stores it.
	 */
	private function check( DomainEvent $event ): string {
		$class = get_class( $event );

		if ( null === $this->catalog->nameOf( $class ) ) {
			throw new \LogicException( sprintf( 'Event %s is not in the event catalog; register it with its module before publishing it.', $class ) );
		}

		if ( ! ( new \ReflectionClass( $event ) )->isReadOnly() ) {
			throw new \LogicException( sprintf( 'Event %s must be a readonly class: listeners receive the event itself, and must not be able to change it.', $class ) );
		}

		if ( $this->bridge->isDispatching( $event ) ) {
			throw new \LogicException( sprintf( 'Event %s for %s %d is being delivered; publishing it again from one of its own listeners would loop.', $class, $event->aggregateType(), $event->aggregateId() ) );
		}

		$payloadJson = Outbox::encode( $event, $this->payloadCapBytes );

		if ( DeliveryMode::Outbox === $event::deliveryMode() && 0 === $this->tx->depth() ) {
			throw new \LogicException( sprintf( 'Event %s: outbox events are recorded inside the transaction that changes the state they describe. Publish it from inside transaction().', $class ) );
		}

		return $payloadJson;
	}
}
