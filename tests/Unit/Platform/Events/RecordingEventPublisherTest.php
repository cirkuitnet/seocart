<?php
/**
 * Tests the publisher double that unit tests of application services use
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Platform\Events;

use PHPUnit\Framework\TestCase;
use SEOCart\Tests\Support\Doubles\FakeTransactionManager;
use SEOCart\Tests\Support\Doubles\RecordingEventPublisher;
use SEOCart\Tests\Support\Events\AnyPayload;
use SEOCart\Tests\Support\Events\MutableThing;
use SEOCart\Tests\Support\Events\ThingHappened;
use SEOCart\Tests\Support\Events\ThingNoticed;

/**
 * The double refuses what the real publisher refuses, and records only what a commit would deliver.
 *
 * @since 0.1.0
 */
final class RecordingEventPublisherTest extends TestCase {

	/**
	 * Events of a committed unit of work are recorded; events of a rolled-back one, or of a rolled-back level, are not.
	 *
	 * @since 0.1.0
	 */
	public function test_only_committed_events_are_recorded(): void {
		$tx        = new FakeTransactionManager();
		$publisher = new RecordingEventPublisher( $tx );

		$tx->transaction(
			static function () use ( $tx, $publisher ): void {
				$publisher->publish( new ThingHappened( 1 ), new ThingNoticed( 1 ) );

				try {
					$tx->transaction(
						static function () use ( $publisher ): void {
							$publisher->publish( new ThingHappened( 2 ) );

							throw new \RuntimeException( 'The inner level fails.' );
						}
					);
				} catch ( \RuntimeException $caught ) {
					unset( $caught );
				}
			}
		);

		try {
			$tx->transaction(
				static function () use ( $publisher ): void {
					$publisher->publish( new ThingHappened( 3 ) );

					throw new \RuntimeException( 'The unit of work fails.' );
				}
			);
		} catch ( \RuntimeException $caught ) {
			unset( $caught );
		}

		$this->assertSame( array( 1 ), array_map( static fn( ThingHappened $event ): int => $event->thingId, $publisher->publishedOf( ThingHappened::class ) ) );
		$this->assertCount( 1, $publisher->publishedOf( ThingNoticed::class ) );
		$this->assertCount( 2, $publisher->published() );
	}

	/**
	 * Refused like the real publisher: an outbox event outside a transaction, a mutable class, an object in the payload.
	 *
	 * @since 0.1.0
	 */
	public function test_it_refuses_what_the_publisher_refuses(): void {
		$publisher = new RecordingEventPublisher( new FakeTransactionManager() );
		$refused   = array(
			'outside a transaction' => new ThingHappened( 1 ),
			'a mutable class'       => new MutableThing( 1 ),
			'an object'             => new AnyPayload( array( 'total' => new \stdClass() ) ),
		);

		foreach ( $refused as $case => $event ) {
			try {
				$publisher->publish( $event );
				$this->fail( $case . ' must be refused.' );
			} catch ( \LogicException $expected ) {
				$this->assertNotSame( '', $expected->getMessage(), $case );
			}
		}

		$this->assertSame( array(), $publisher->published() );
	}

	/**
	 * Without a transaction manager, every event is recorded at once.
	 *
	 * @since 0.1.0
	 */
	public function test_without_a_transaction_manager_events_are_recorded_at_once(): void {
		$publisher = new RecordingEventPublisher();

		$publisher->publish( new ThingHappened( 5 ) );

		$this->assertCount( 1, $publisher->published() );
	}
}
