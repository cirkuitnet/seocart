<?php
/**
 * EventPublisher: the port through which application services publish domain events
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
 * Hands the events an aggregate recorded to the platform, which delivers them after the commit.
 *
 * Owns one fact: the contract of publishing as application code sees it. An application
 * service calls publish() inside the unit of work that changed the aggregate, after the
 * repository write, with what the aggregate's releaseEvents() returned:
 *
 *     return $this->tx->transaction( function () use ( $command ) {
 *         $item = $this->stock->lockAndFind( $command->variantId );
 *         $item->adjust( $command->delta, $command->reason, $this->clock->now() );
 *         $this->stock->save( $item );
 *         $this->events->publish( ...$item->releaseEvents() );
 *
 *         return $item->snapshot();
 *     } );
 *
 * An event that must not be lost is stored in the same transaction, so a rollback removes it
 * with the change it describes. No listener runs before the commit. Publisher is the
 * implementation; unit tests of services use tests/Support/Doubles/RecordingEventPublisher.
 *
 * @since 0.1.0
 */
interface EventPublisher {

	/**
	 * Publishes events, in order.
	 *
	 * Every event is checked before any is stored. A check that fails is a programming error
	 * and throws a \LogicException: an event missing from the event catalog, a class that is not
	 * readonly, a payload holding anything but ids and plain values, an event its own listener
	 * publishes again for the same aggregate, or an outbox event published outside a transaction.
	 *
	 * @since 0.1.0
	 *
	 * @param DomainEvent ...$events The events.
	 */
	public function publish( DomainEvent ...$events ): void;
}
