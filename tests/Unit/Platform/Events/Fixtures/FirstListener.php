<?php
/**
 * FirstListener: a listener fixture for the test of the plugin's listeners
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Platform\Events\Fixtures;

use SEOCart\Platform\Events\EventEnvelope;
use SEOCart\Support\Events\DomainEvent;

/**
 * A listener that does nothing, so a test can name it among the actions it is added to.
 *
 * @since 0.2.0
 */
final class FirstListener {

	/**
	 * Hears an event.
	 *
	 * @since 0.2.0
	 *
	 * @param DomainEvent   $event    The event.
	 * @param EventEnvelope $envelope Its delivery.
	 */
	public function on( DomainEvent $event, EventEnvelope $envelope ): void {
		unset( $event, $envelope );
	}
}
