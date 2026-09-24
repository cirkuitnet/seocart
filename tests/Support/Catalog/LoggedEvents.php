<?php
/**
 * LoggedEvents: an event publisher that writes each published event to a step log
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support\Catalog;

use SEOCart\Platform\Events\EventPublisher;
use SEOCart\Support\Events\DomainEvent;

/**
 * Records `publish: <event name>` for each event, then hands the events to another publisher.
 *
 * Owns one fact: how a product save's events appear in the step log, at the moment they are
 * published rather than when their transaction commits.
 *
 * @since 0.1.0
 */
final class LoggedEvents implements EventPublisher {

	/**
	 * The publisher the events go to.
	 *
	 * @since 0.1.0
	 *
	 * @var EventPublisher
	 */
	private EventPublisher $inner;

	/**
	 * The step log.
	 *
	 * @since 0.1.0
	 *
	 * @var StepLog
	 */
	private StepLog $log;

	/**
	 * Wraps a publisher.
	 *
	 * @since 0.1.0
	 *
	 * @param EventPublisher $inner The publisher the events go to.
	 * @param StepLog        $log   The step log.
	 */
	public function __construct( EventPublisher $inner, StepLog $log ) {
		$this->inner = $inner;
		$this->log   = $log;
	}

	/**
	 * Records the events, then publishes them.
	 *
	 * @since 0.1.0
	 *
	 * @param DomainEvent ...$events The events.
	 */
	public function publish( DomainEvent ...$events ): void {
		foreach ( $events as $event ) {
			$this->log->record( 'publish: ' . $event::eventName() );
		}

		$this->inner->publish( ...$events );
	}
}
