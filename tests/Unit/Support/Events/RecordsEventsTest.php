<?php
/**
 * Tests how an aggregate records events and hands them over
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Support\Events;

use PHPUnit\Framework\TestCase;
use SEOCart\Support\Events\RecordsEvents;
use SEOCart\Tests\Support\Events\ThingHappened;
use SEOCart\Tests\Support\Events\ThingNoticed;

/**
 * Recorded events come out once, in order, and releasing clears them.
 *
 * @since 0.1.0
 */
final class RecordsEventsTest extends TestCase {

	/**
	 * An aggregate hands over what it recorded, once.
	 *
	 * @since 0.1.0
	 */
	public function test_recorded_events_are_released_once_in_order(): void {
		$aggregate = new class() {

			use RecordsEvents;

			/**
			 * Changes state and records what happened.
			 *
			 * @param int $id The id.
			 */
			public function change( int $id ): void {
				$this->recordThat( new ThingHappened( $id ) );
				$this->recordThat( new ThingNoticed( $id ) );
			}
		};

		$this->assertSame( array(), $aggregate->releaseEvents() );

		$aggregate->change( 4 );

		$released = $aggregate->releaseEvents();

		$this->assertCount( 2, $released );
		$this->assertInstanceOf( ThingHappened::class, $released[0] );
		$this->assertInstanceOf( ThingNoticed::class, $released[1] );
		$this->assertSame( array(), $aggregate->releaseEvents(), 'Releasing clears the list, so nothing is published twice.' );
	}
}
