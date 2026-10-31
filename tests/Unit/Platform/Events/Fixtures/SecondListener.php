<?php
/**
 * SecondListener: another listener fixture for the test of the plugin's listeners
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
 * A second listener that does nothing, so a test can tell two listeners of one event apart.
 *
 * @since 0.2.0
 */
final class SecondListener {

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
