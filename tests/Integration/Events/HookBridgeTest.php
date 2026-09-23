<?php
/**
 * Tests how the hook bridge runs listeners: contained, on immutable data, under the right correlation id
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Events;

use SEOCart\Platform\Events\DrainOptions;
use SEOCart\Platform\Events\EventEnvelope;
use SEOCart\Platform\Events\HookBridge;
use SEOCart\Platform\Events\Outbox;
use SEOCart\Platform\Events\OutboxDrainer;
use SEOCart\Platform\Events\ReportCode;
use SEOCart\Support\Events\DomainEvent;
use SEOCart\Tests\Support\Doubles\SequentialIdGenerator;
use SEOCart\Tests\Support\Events\MutableThing;
use SEOCart\Tests\Support\Events\OutboxTestCase;
use SEOCart\Tests\Support\Events\ThingHappened;
use SEOCart\Tests\Support\Events\ThingNoticed;
use SEOCart\Tests\Support\SecondConnection;

/**
 * Listeners get readonly data, run one at a time whatever the others do, and run under the correlation id of the request that published.
 *
 * Each test names its planted violation, in src/Platform/Events/ unless it says otherwise.
 *
 * @since 0.1.0
 */
final class HookBridgeTest extends OutboxTestCase {

	/**
	 * The action ThingHappened fires.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const HAPPENED = 'seocart_test_thing_happened';

	/**
	 * The correlation id of the request that publishes.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const PUBLISHING_REQUEST = '0199713c-0000-7000-8000-00000000aaaa';

	/**
	 * The correlation id of the request that drains.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const DRAINING_REQUEST = '0199713c-0000-7000-8000-00000000bbbb';

	/**
	 * An event class that is not readonly is refused before any statement.
	 *
	 * Planted violation: in Publisher::check(), drop the isReadOnly() check. The mutable event
	 * is then stored.
	 *
	 * @since 0.1.0
	 */
	public function test_an_event_class_that_is_not_readonly_is_refused_before_any_statement(): void {
		$b       = $this->secondConnection();
		$refused = null;
		$log     = null;

		$this->db->transaction(
			function () use ( &$refused, &$log ): void {
				$log = $this->captureQueries(
					function () use ( &$refused ): void {
						try {
							$this->publisher->publish( new MutableThing( 1 ) );
						} catch ( \LogicException $expected ) {
							$refused = $expected;
						}
					}
				);
			}
		);

		$this->assertInstanceOf( \LogicException::class, $refused );
		$this->assertStringContainsString( 'readonly', $refused->getMessage() );
		$this->assertNotNull( $log );
		$this->assertQueryCount( 0, $log, 'A refused event' );
		$this->assertSame( array(), $this->outboxIds( $b ) );
	}

	/**
	 * A listener receives the readonly event and the readonly envelope: writing to either throws, and a list arrives as a copy.
	 *
	 * Planted violation: remove `readonly` from the EventEnvelope class. Writing to the
	 * envelope then succeeds.
	 *
	 * @since 0.1.0
	 */
	public function test_listeners_receive_data_they_cannot_change(): void {
		$b      = $this->secondConnection();
		$writes = array();

		$this->plantEvent( $b, new ThingHappened( 1, 'noted', array( 4, 5 ) ) );

		add_action(
			self::HAPPENED,
			static function ( $event, $envelope ) use ( &$writes ): void {
				$writes['types'] = array( get_class( $event ), get_class( $envelope ) );

				foreach ( array(
					'event'    => array( $event, 'note', 'changed' ),
					'envelope' => array( $envelope, 'attempt', 99 ),
				) as $what => $write ) {
					list( $target, $property, $value ) = $write;

					try {
						$target->$property = $value;
						$writes[ $what ]   = 'written';
					} catch ( \Error $refused ) {
						$writes[ $what ] = $refused->getMessage();
					}
				}

				$lines   = $event->lineIds;
				$lines[] = 6;

				$writes['copy'] = array( $lines, $event->lineIds );
			},
			10,
			2
		);

		$this->drainer()->drain( DrainOptions::command() );

		$this->assertSame( array( ThingHappened::class, EventEnvelope::class ), $writes['types'] );
		$this->assertSame( 'Cannot modify readonly property ' . ThingHappened::class . '::$note', $writes['event'] );
		$this->assertSame( 'Cannot modify readonly property ' . EventEnvelope::class . '::$attempt', $writes['envelope'] );
		$this->assertSame( array( array( 4, 5, 6 ), array( 4, 5 ) ), $writes['copy'], 'The event\'s list is unchanged by a change to the listener\'s copy.' );
	}

	/**
	 * A listener that throws is contained: the listeners before and after it run, the row is dispatched, the failure is reported naming the closure.
	 *
	 * The action behaves as any action does: it counts once in did_action(), and it is the
	 * current action inside its listeners. After the dispatch the action's WP_Hook is the same
	 * object with the same callbacks as before.
	 *
	 * Planted violation: in HookBridge::dispatch(), neither wrap the callbacks before do_action()
	 * nor add the sentinel (a plain do_action()). The exception then skips priority 20 and ends
	 * the dispatch. Either layer alone still contains it.
	 *
	 * @since 0.1.0
	 */
	public function test_a_listener_that_throws_is_contained_and_the_others_still_run(): void {
		global $wp_filter;

		$b   = $this->secondConnection();
		$id  = $this->plantEvent( $b, new ThingHappened( 1 ) );
		$ran = array();

		add_action(
			self::HAPPENED,
			static function () use ( &$ran ): void {
				$ran[] = array( 5, current_action(), doing_action( self::HAPPENED ) );
			},
			5
		);

		$line = __LINE__ + 3;
		add_action(
			self::HAPPENED,
			static function () use ( &$ran ): void {
				$ran[] = array( 10 );

				throw new \RuntimeException( 'Listener 10 fails.' );
			},
			10
		);
		add_action(
			self::HAPPENED,
			static function () use ( &$ran ): void {
				$ran[] = array( 20 );
			},
			20
		);

		$hook      = $wp_filter[ self::HAPPENED ];
		$callbacks = $hook->callbacks;

		$report = $this->drainer()->drain( DrainOptions::command() );

		$this->assertSame( array( array( 5, self::HAPPENED, true ), array( 10 ), array( 20 ) ), $ran );
		$this->assertSame( 1, $report->dispatched );
		$this->assertSame( Outbox::DISPATCHED, $this->outboxRow( $b, $id )['state'] );
		$this->assertSame( 1, did_action( self::HAPPENED ) );
		$this->assertSame( $hook, $wp_filter[ self::HAPPENED ], 'The same WP_Hook object.' );
		$this->assertSame( $callbacks, $hook->callbacks, 'Every wrapper was taken out again.' );

		$failures = $this->reportsOf( ReportCode::ListenerFailed->value );

		$this->assertCount( 1, $failures );
		$this->assertSame( self::HAPPENED, $failures[0]['hook'] );
		$this->assertSame( $id, $failures[0]['outbox_id'] );
		$this->assertSame( 1, $failures[0]['attempt'] );
		$this->assertSame( sprintf( 'closure at %s:%d', __FILE__, $line ), $failures[0]['callback'] );
		$this->assertSame( \RuntimeException::class, $failures[0]['exception'] );
		$this->assertSame( 'Listener 10 fails.', $failures[0]['message'] );
	}

	/**
	 * A callback added during a dispatch is kept and runs contained; one removed during a dispatch stays removed.
	 *
	 * The first delivery's listener removes a later listener and adds another at a later
	 * priority, which throws. In that delivery the removed one does not run and the added one
	 * does, contained; after it, the hook still has the added one and not the removed one, so
	 * the second delivery runs the same set. Nothing is lost and nothing comes back.
	 *
	 * Planted violation: in HookBridge::dispatch(), keep a copy of the hook's callbacks when the
	 * dispatch starts and put that copy back when it ends. The added listener is then lost and
	 * the removed one comes back.
	 *
	 * @since 0.1.0
	 */
	public function test_a_listener_added_or_removed_during_a_dispatch_is_neither_lost_nor_resurrected(): void {
		$b       = $this->secondConnection();
		$ran     = array();
		$removed = null;

		$this->plantEvent( $b, new ThingHappened( 1 ) );
		$this->plantEvent( $b, new ThingHappened( 2 ) );

		$late   = static function ( DomainEvent $event ) use ( &$ran ): void {
			$ran[] = 'late ' . $event->aggregateId();

			throw new \RuntimeException( 'The late listener fails.' );
		};
		$doomed = static function ( DomainEvent $event ) use ( &$ran ): void {
			$ran[] = 'doomed ' . $event->aggregateId();
		};

		add_action( self::HAPPENED, $doomed, 20 );
		add_action(
			self::HAPPENED,
			static function ( DomainEvent $event ) use ( &$ran, &$removed, $late, $doomed ): void {
				$ran[] = 'first ' . $event->aggregateId();

				if ( 1 === $event->aggregateId() ) {
					$removed = remove_action( self::HAPPENED, $doomed, 20 );

					add_action( self::HAPPENED, $late, 30 );
				}
			},
			10
		);

		$report = $this->drainer()->drain( DrainOptions::command() );

		$this->assertTrue( $removed, 'remove_action() found the listener during the dispatch.' );
		$this->assertSame( array( 'first 1', 'late 1', 'first 2', 'late 2' ), $ran );
		$this->assertSame( 2, $report->dispatched );
		$this->assertSame( 30, has_action( self::HAPPENED, $late ) );
		$this->assertFalse( has_action( self::HAPPENED, $doomed ) );
		$this->assertCount( 2, $this->reportsOf( ReportCode::ListenerFailed->value ), 'The added listener was contained in both deliveries.' );
	}

	/**
	 * A listener an `all` callback adds, at a priority below every other listener, is contained like the others.
	 *
	 * WordPress runs `all` callbacks after the bridge wrapped the action's callbacks and before
	 * the first listener; the listener added there throws.
	 *
	 * Planted violation: in HookBridge::dispatch(), do not add the sentinel. The added listener
	 * then runs unwrapped: its exception ends the action, priorities 10 and 20 never run, and
	 * the row is retried.
	 *
	 * @since 0.1.0
	 */
	public function test_a_listener_an_all_callback_adds_is_contained(): void {
		$b     = $this->secondConnection();
		$id    = $this->plantEvent( $b, new ThingHappened( 1 ) );
		$ran   = array();
		$line  = __LINE__ + 1;
		$early = static function () use ( &$ran ): void {
			$ran[] = -100;

			throw new \RuntimeException( 'The early listener fails.' );
		};

		add_action(
			self::HAPPENED,
			static function () use ( &$ran ): void {
				$ran[] = 10;
			},
			10
		);
		add_action(
			self::HAPPENED,
			static function () use ( &$ran ): void {
				$ran[] = 20;
			},
			20
		);
		add_action(
			'all',
			static function ( $hookName ) use ( $early ): void {
				if ( self::HAPPENED === $hookName ) {
					add_action( self::HAPPENED, $early, -100 );
				}
			}
		);

		$report = $this->drainer()->drain( DrainOptions::command() );

		$this->assertSame( array( -100, 10, 20 ), $ran );
		$this->assertSame( 1, $report->dispatched );
		$this->assertSame( Outbox::DISPATCHED, $this->outboxRow( $b, $id )['state'] );

		$failures = $this->reportsOf( ReportCode::ListenerFailed->value );

		$this->assertCount( 1, $failures );
		$this->assertSame( sprintf( 'closure at %s:%d', __FILE__, $line ), $failures[0]['callback'] );
	}

	/**
	 * A listener an `all` callback adds to an action that had no listener at all is contained too.
	 *
	 * No callback is registered on the action when the dispatch starts. Afterwards the action
	 * keeps the listener the `all` callback added, and nothing of the bridge's.
	 *
	 * Planted violation: as for the test before.
	 *
	 * @since 0.1.0
	 */
	public function test_a_listener_an_all_callback_adds_to_an_action_without_listeners_is_contained(): void {
		global $wp_filter;

		$this->assertArrayNotHasKey( 'seocart_test_thing_noticed', $wp_filter );

		add_action(
			'all',
			static function ( $hookName ): void {
				if ( 'seocart_test_thing_noticed' === $hookName ) {
					add_action(
						'seocart_test_thing_noticed',
						static function (): void {
							throw new \RuntimeException( 'The only listener fails.' );
						}
					);
				}
			}
		);

		$this->bridge->dispatch( EventEnvelope::inMemory( new ThingNoticed( 1 ), self::PUBLISHING_REQUEST ) );

		$this->assertSame( array( 'The only listener fails.' ), array_column( $this->reportsOf( ReportCode::ListenerFailed->value ), 'message' ) );
		$this->assertSame( array( 10 ), array_keys( $wp_filter['seocart_test_thing_noticed']->callbacks ), 'Only the added listener is left on the action.' );
		$this->assertCount( 1, $wp_filter['seocart_test_thing_noticed']->callbacks[10] );
	}

	/**
	 * When an `all` callback throws, the dispatch fails, and afterwards the action's callbacks, WordPress's stack of current actions and the re-entrancy guard are as before.
	 *
	 * The row is retried (a failure outside any listener), and the same event for the same
	 * aggregate may be published again: nothing is left believing it is still being delivered.
	 *
	 * Planted violation: in HookBridge::dispatch(), restore the callbacks and pop the frame
	 * after do_action() instead of in `finally`.
	 *
	 * @since 0.1.0
	 */
	public function test_after_an_all_callback_throws_the_hook_and_the_guard_are_restored(): void {
		global $wp_filter, $wp_current_filter;

		$b  = $this->secondConnection();
		$id = $this->plantEvent( $b, new ThingHappened( 1 ) );

		$this->listen( self::HAPPENED );

		$callbacks = $wp_filter[ self::HAPPENED ]->callbacks;
		$current   = $wp_current_filter;

		add_action(
			'all',
			static function ( $hookName ): void {
				if ( self::HAPPENED === $hookName ) {
					throw new \RuntimeException( 'An all callback fails.' );
				}
			}
		);

		$report = $this->drainer()->drain( DrainOptions::command() );
		$row    = $this->outboxRow( $b, $id );

		$this->assertSame( 1, $report->retried );
		$this->assertSame( Outbox::PENDING, $row['state'] );
		$this->assertStringStartsWith( ReportCode::DispatchFailed->value . ' RuntimeException: An all callback fails.', (string) $row['last_error'] );
		$this->assertSame( $callbacks, $wp_filter[ self::HAPPENED ]->callbacks, 'Every wrapper was taken out again.' );
		$this->assertSame( $current, $wp_current_filter, 'The stack of current actions is as before.' );

		$this->db->transaction( fn() => $this->publisher->publish( new ThingHappened( 1, 'later' ) ) );

		$this->assertSame( '1,1', $b->fetchValue( sprintf( 'SELECT GROUP_CONCAT( aggregate_id ORDER BY id ) FROM `%s`', $this->outboxTable() ) ), 'The same event for the same aggregate is accepted again.' );
	}

	/**
	 * A fatal error while a listener runs puts the row back at once, after the first backoff, naming the action and the file and line.
	 *
	 * PHP cannot be killed inside PHPUnit, so the listener calls the shutdown handler itself,
	 * with the description error_get_last() would give, while its row is in flight.
	 *
	 * Planted violation: in OutboxDrainer::handleShutdown(), return before looking at the rows
	 * in flight. The row is then marked dispatched as if nothing happened.
	 *
	 * @since 0.1.0
	 */
	public function test_a_fatal_error_in_a_listener_puts_the_row_back_naming_the_listener(): void {
		$b    = $this->secondConnection();
		$id   = $this->plantEvent( $b, new ThingHappened( 1 ) );
		$line = $this->fatalListener( E_ERROR );

		$this->drainer()->drain( DrainOptions::command() );

		$row = $this->outboxRow( $b, $id );

		$this->assertSame( Outbox::PENDING, $row['state'] );
		$this->assertNull( $row['claim_token'] );
		$this->assertNull( $row['claimed_until'] );
		$this->assertEqualsWithDelta( 60, $this->secondsUntilDue( $b, $id ), 5 );
		$this->assertSame( sprintf( '%s %s %s:%d', ReportCode::ListenerFatal->value, self::HAPPENED, __FILE__, $line ), $row['last_error'] );

		$fatal = $this->reportsOf( ReportCode::ListenerFatal->value );

		$this->assertCount( 1, $fatal );
		$this->assertTrue( $fatal[0]['released'] );
		$this->assertFalse( $fatal[0]['parked'] );
	}

	/**
	 * A fatal error on the last attempt parks the row.
	 *
	 * @since 0.1.0
	 */
	public function test_a_fatal_error_on_the_last_attempt_parks_the_row(): void {
		$b  = $this->secondConnection();
		$id = $this->plantEvent( $b, new ThingHappened( 1 ), array( 'attempts' => '4' ) );

		$this->fatalListener( E_USER_ERROR );
		$this->drainer()->drain( DrainOptions::command() );

		$row = $this->outboxRow( $b, $id );

		$this->assertSame( Outbox::FAILED, $row['state'] );
		$this->assertSame( '5', $row['attempts'] );
		$this->assertStringStartsWith( ReportCode::ListenerFatal->value . ' ' . self::HAPPENED . ' ', (string) $row['last_error'] );
	}

	/**
	 * An error that does not end the process changes nothing: the row is delivered as usual.
	 *
	 * @since 0.1.0
	 */
	public function test_an_error_that_is_not_fatal_changes_nothing(): void {
		$b  = $this->secondConnection();
		$id = $this->plantEvent( $b, new ThingHappened( 1 ) );

		$this->fatalListener( E_WARNING );
		$this->drainer()->drain( DrainOptions::command() );

		$this->assertSame( Outbox::DISPATCHED, $this->outboxRow( $b, $id )['state'] );
		$this->assertSame( array(), $this->reportsOf( ReportCode::ListenerFatal->value ) );
	}

	/**
	 * A listener runs under the correlation id of the request that published the event; afterwards the drainer's own id is back.
	 *
	 * Planted violation: in CorrelationId::scoped(), do not put the previous id back. The
	 * drainer's request then carries the publisher's id after the drain.
	 *
	 * @since 0.1.0
	 */
	public function test_a_listener_runs_under_the_correlation_id_of_the_publishing_request(): void {
		$b = $this->secondConnection();

		$this->correlation->accept( 'not-a-uuid' );

		$this->assertSame( SequentialIdGenerator::nth( 1 ), $this->correlation->current(), 'An id that is not a UUID was ignored.' );

		$this->correlation->accept( self::PUBLISHING_REQUEST );
		$this->db->transaction( fn() => $this->publisher->publish( new ThingHappened( 1 ) ) );

		$this->assertSame( self::PUBLISHING_REQUEST, $b->fetchValue( sprintf( 'SELECT correlation_id FROM `%s`', $this->outboxTable() ) ) );

		// Another request drains it.
		$this->correlation->accept( self::DRAINING_REQUEST );
		$this->listen( self::HAPPENED );
		$this->drainer()->drain( DrainOptions::command() );

		$this->assertSame( self::PUBLISHING_REQUEST, $this->fired[0]['correlation_id'], 'The envelope carries the stored id.' );
		$this->assertSame( self::PUBLISHING_REQUEST, $this->fired[0]['current'], 'The listener runs under it.' );
		$this->assertSame( self::DRAINING_REQUEST, $this->correlation->current(), 'The drainer\'s own id is back.' );
	}

	/**
	 * A listener cannot publish its own event for the same aggregate, which would loop; for another aggregate it can.
	 *
	 * Planted violation: in HookBridge::isDispatching(), compare the event name only. The
	 * listener's event for another aggregate is then refused too.
	 *
	 * @since 0.1.0
	 */
	public function test_a_listener_cannot_publish_its_own_event_for_the_same_aggregate(): void {
		$b = $this->secondConnection();

		$this->plantEvent( $b, new ThingHappened( 1 ) );

		$this->listen(
			self::HAPPENED,
			10,
			function ( DomainEvent $event ): void {
				if ( 1 === $event->aggregateId() ) {
					$this->db->transaction( fn() => $this->publisher->publish( new ThingHappened( 1, 'again' ) ) );
				}
			}
		);
		$this->listen(
			self::HAPPENED,
			20,
			function ( DomainEvent $event ): void {
				if ( 1 === $event->aggregateId() ) {
					$this->db->transaction( fn() => $this->publisher->publish( new ThingHappened( 2 ) ) );
				}
			}
		);

		$report = $this->drainer()->drain( DrainOptions::command() );

		$this->assertSame( array( 1, 1, 2, 2 ), array_column( $this->fired, 'thing' ), 'Both listeners heard aggregate 1, then the event for aggregate 2 in the same drain.' );
		$this->assertSame( 2, $report->dispatched );
		$this->assertSame( '1,2', $b->fetchValue( sprintf( 'SELECT GROUP_CONCAT( aggregate_id ORDER BY id ) FROM `%s`', $this->outboxTable() ) ), 'No second row for aggregate 1.' );
		$this->assertSame( 1, $this->wake->calls() );

		$failures = $this->reportsOf( ReportCode::ListenerFailed->value );

		$this->assertCount( 1, $failures );
		$this->assertSame( \LogicException::class, $failures[0]['exception'] );
		$this->assertStringContainsString( 'would loop', $failures[0]['message'] );
	}

	/**
	 * Doctor's listing names each callback, its priority and what it belongs to, also while the action runs.
	 *
	 * @since 0.1.0
	 */
	public function test_the_listing_names_each_callback_its_priority_and_owner(): void {
		$during = null;

		add_action( self::HAPPENED, 'wp_ob_end_flush_all', 5 );
		add_action( self::HAPPENED, array( self::class, 'staticListener' ), 7 );

		$line = __LINE__ + 3;
		add_action(
			self::HAPPENED,
			function () use ( &$during ): void {
				$during = $this->bridge->listeners( self::HAPPENED );
			},
			15
		);

		$expected = array(
			array(
				'hook'     => self::HAPPENED,
				'callback' => 'wp_ob_end_flush_all()',
				'priority' => 5,
				'plugin'   => 'core',
			),
			array(
				'hook'     => self::HAPPENED,
				'callback' => self::class . '::staticListener',
				'priority' => 7,
				'plugin'   => 'unknown',
			),
			array(
				'hook'     => self::HAPPENED,
				'callback' => sprintf( 'closure at %s:%d', __FILE__, $line ),
				'priority' => 15,
				'plugin'   => 'unknown',
			),
		);

		$this->assertSame( $expected, array_values( array_filter( $this->drainer()->listeners(), static fn( array $listener ): bool => self::HAPPENED === $listener['hook'] ) ) );

		remove_action( self::HAPPENED, 'wp_ob_end_flush_all', 5 );
		remove_action( self::HAPPENED, array( self::class, 'staticListener' ), 7 );
		$this->bridge->dispatch( EventEnvelope::inMemory( new ThingHappened( 1 ), self::PUBLISHING_REQUEST ) );

		$this->assertSame( array( $expected[2] ), $during, 'While the action runs, the listing names the listener, not its wrapper.' );
	}

	/**
	 * A listener slower than the threshold is reported as slow, a fast one is not, and neither is a failure.
	 *
	 * @since 0.1.0
	 */
	public function test_a_slow_listener_is_reported(): void {
		$now    = 0;
		$bridge = new HookBridge(
			$this->reporter(),
			static function () use ( &$now ): int {
				return $now;
			}
		);

		add_action(
			'seocart_test_thing_noticed',
			static function () use ( &$now ): void {
				$now += 1500000000;
			},
			10
		);
		add_action(
			'seocart_test_thing_noticed',
			static function () use ( &$now ): void {
				$now += 1000;
			},
			20
		);

		$bridge->dispatch( EventEnvelope::inMemory( new ThingNoticed( 1 ), self::PUBLISHING_REQUEST ) );

		$slow = $this->reportsOf( ReportCode::ListenerSlow->value );

		$this->assertCount( 1, $slow );
		$this->assertSame( 1500, $slow[0]['milliseconds'] );
		$this->assertNull( $slow[0]['outbox_id'] );
		$this->assertSame( array(), $this->reportsOf( ReportCode::ListenerFailed->value ) );
	}

	/**
	 * A listener for the listing test, never run.
	 *
	 * @since 0.1.0
	 */
	public static function staticListener(): void {
	}

	/**
	 * Adds a listener that reports an error of the given type to the shutdown handler while its row is in flight.
	 *
	 * @since 0.1.0
	 *
	 * @param int $type The error type.
	 * @return int The line the error names.
	 */
	private function fatalListener( int $type ): int {
		$line = __LINE__ + 5;

		add_action(
			self::HAPPENED,
			static function () use ( $type, $line ): void {
				OutboxDrainer::handleShutdown(
					array(
						'type'    => $type,
						'message' => 'Allowed memory size exhausted',
						'file'    => __FILE__,
						'line'    => $line,
					)
				);
			}
		);

		return $line;
	}

	/**
	 * Returns how many seconds from now, on the database clock, a row becomes due.
	 *
	 * @since 0.1.0
	 *
	 * @param SecondConnection $b  Connection B.
	 * @param int              $id The row's id.
	 * @return int The seconds.
	 */
	private function secondsUntilDue( SecondConnection $b, int $id ): int {
		return (int) $b->fetchValue( sprintf( 'SELECT TIMESTAMPDIFF( SECOND, UTC_TIMESTAMP(6), available_at ) FROM `%s` WHERE id = %d', $this->outboxTable(), $id ) );
	}
}
