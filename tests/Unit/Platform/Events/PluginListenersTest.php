<?php
/**
 * Tests how the plugin's own listeners are put on their actions
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Platform\Events;

use Brain\Monkey;
use Brain\Monkey\Actions;
use PHPUnit\Framework\TestCase;
use SEOCart\Platform\Events\HookBridge;
use SEOCart\Platform\Events\EventEnvelope;
use SEOCart\Platform\Events\PluginListeners;
use SEOCart\Tests\Support\Events\ThingHappened;
use SEOCart\Tests\Support\Events\ThingNoticed;
use SEOCart\Tests\Unit\Platform\Events\Fixtures\FirstListener;
use SEOCart\Tests\Unit\Platform\Events\Fixtures\SecondListener;

/**
 * PluginListeners resolves each listener and puts its `on` method on the action of every event it
 * hears, once; the hook bridge calls the attach callable it is given once, before its first
 * dispatch, and again after a call that failed. WordPress's hook functions are Brain Monkey's.
 *
 * Planted violations, each shown red and removed: in PluginListeners::attach(), leave out the
 * `$this->attached = true;` line: the second call adds every listener again, and the expectation
 * of one addition each fails; in HookBridge::dispatch(), call attachListeners() after do_action():
 * the log reads the first dispatch before the attach.
 *
 * @since 0.2.0
 */
final class PluginListenersTest extends TestCase {

	/**
	 * Sets up Brain Monkey, which stands in for WordPress's hook functions.
	 *
	 * @since 0.2.0
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	/**
	 * Tears Brain Monkey down.
	 *
	 * @since 0.2.0
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Tests that attach() resolves each listener once and adds its method to each of its events' actions once, whatever how often it is called.
	 *
	 * @since 0.2.0
	 */
	public function test_each_listener_is_resolved_and_added_to_each_of_its_events_once(): void {
		$first    = new FirstListener();
		$second   = new SecondListener();
		$resolved = array();
		$services = array(
			FirstListener::class  => $first,
			SecondListener::class => $second,
		);

		$listeners = new PluginListeners(
			array(
				FirstListener::class  => array( ThingHappened::class, ThingNoticed::class ),
				SecondListener::class => array( ThingHappened::class ),
			),
			static function ( string $listener ) use ( $services, &$resolved ): object {
				$resolved[] = $listener;

				return $services[ $listener ];
			}
		);

		$this->assertSame( array(), $resolved, 'Creating the list resolves no listener.' );

		Actions\expectAdded( 'seocart_test_thing_happened' )->once()->with( array( $first, 'on' ), 10, 2 );
		Actions\expectAdded( 'seocart_test_thing_noticed' )->once()->with( array( $first, 'on' ), 10, 2 );
		Actions\expectAdded( 'seocart_test_thing_happened' )->once()->with( array( $second, 'on' ), 10, 2 );

		$listeners->attach();
		$listeners->attach();

		$this->assertSame( array( FirstListener::class, SecondListener::class ), $resolved, 'Each listener is resolved once, at the first call.' );
	}

	/**
	 * Tests that the bridge runs its attach callable once, before its first dispatch fires anything, and never at construction.
	 *
	 * @since 0.2.0
	 */
	public function test_the_bridge_attaches_once_before_its_first_dispatch(): void {
		$log    = array();
		$bridge = new HookBridge(
			static function (): void {},
			null,
			HookBridge::DEFAULT_SLOW_MILLISECONDS,
			static function () use ( &$log ): void {
				$log[] = 'attach';
			}
		);

		Actions\expectDone( 'seocart_test_thing_happened' )->twice()->whenHappen(
			static function () use ( &$log ): void {
				$log[] = 'dispatch';
			}
		);

		$this->assertSame( array(), $log, 'Creating the bridge attaches nothing.' );

		$bridge->dispatch( EventEnvelope::inMemory( new ThingHappened( 1 ), 'correlation' ) );
		$bridge->dispatch( EventEnvelope::inMemory( new ThingHappened( 2 ), 'correlation' ) );
		$bridge->attachListeners();

		$this->assertSame( array( 'attach', 'dispatch', 'dispatch' ), $log );
	}

	/**
	 * Tests that an attach that threw fails that dispatch, before anything is fired, and is tried again at the next one.
	 *
	 * @since 0.2.0
	 */
	public function test_an_attach_that_threw_is_tried_again_at_the_next_dispatch(): void {
		$calls  = 0;
		$bridge = new HookBridge(
			static function (): void {},
			null,
			HookBridge::DEFAULT_SLOW_MILLISECONDS,
			static function () use ( &$calls ): void {
				if ( 1 === ++$calls ) {
					throw new \RuntimeException( 'Not yet.' );
				}
			}
		);

		// The failed dispatch fires nothing; the two after it fire the action.
		Actions\expectDone( 'seocart_test_thing_happened' )->twice();

		try {
			$bridge->dispatch( EventEnvelope::inMemory( new ThingHappened( 1 ), 'correlation' ) );
			$this->fail( 'The failed attach did not fail the dispatch.' );
		} catch ( \RuntimeException $failure ) {
			$this->assertSame( 'Not yet.', $failure->getMessage() );
		}

		$bridge->dispatch( EventEnvelope::inMemory( new ThingHappened( 1 ), 'correlation' ) );
		$bridge->dispatch( EventEnvelope::inMemory( new ThingHappened( 1 ), 'correlation' ) );

		$this->assertSame( 2, $calls, 'The attach ran again after it failed, and not after it succeeded.' );
	}
}
