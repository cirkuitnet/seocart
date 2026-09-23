<?php
/**
 * RecordsEvents: how an aggregate collects the events it records until a service publishes them
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Support\Events;

defined( 'ABSPATH' ) || exit;

/**
 * Lets an aggregate record events and hand them over once.
 *
 * Owns one fact: that an aggregate keeps its recorded events until they are released, and
 * releases each one exactly once. The aggregate calls recordThat() from the method that
 * changes its state; the application service calls releaseEvents() inside the unit of work,
 * after the repository write, and publishes what it gets. Releasing clears the list, so a
 * service that forgets to release publishes nothing rather than publishing twice.
 *
 * @since 0.1.0
 */
trait RecordsEvents {

	/**
	 * The events recorded since the last release, in order.
	 *
	 * @since 0.1.0
	 *
	 * @var list<DomainEvent>
	 */
	private array $recordedEvents = array();

	/**
	 * Records an event.
	 *
	 * @since 0.1.0
	 *
	 * @param DomainEvent $event The event.
	 */
	protected function recordThat( DomainEvent $event ): void {
		$this->recordedEvents[] = $event;
	}

	/**
	 * Returns the recorded events and forgets them.
	 *
	 * @since 0.1.0
	 *
	 * @return list<DomainEvent> The events recorded since the last release, in the order they were recorded.
	 */
	public function releaseEvents(): array {
		$events = $this->recordedEvents;

		$this->recordedEvents = array();

		return $events;
	}
}
