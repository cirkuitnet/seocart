<?php
/**
 * Tests the transactional outbox against real tables and a second connection
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Events;

use SEOCart\Platform\Database\Exception\QueryFailed;
use SEOCart\Platform\Database\Schema\DdlGenerator;
use SEOCart\Platform\Database\Schema\PlatformTables;
use SEOCart\Platform\Database\Schema\SchemaVerifier;
use SEOCart\Platform\Database\SchemaOperations;
use SEOCart\Platform\Events\DrainOptions;
use SEOCart\Platform\Events\Migrations\CreateOutboxMigration;
use SEOCart\Platform\Events\Outbox;
use SEOCart\Platform\Events\Publisher;
use SEOCart\Platform\Events\ReportCode;
use SEOCart\Support\Events\DomainEvent;
use SEOCart\Tests\Support\Events\OutboxTestCase;
use SEOCart\Tests\Support\Events\ThingHappened;
use SEOCart\Tests\Support\Events\ThingNoticed;
use SEOCart\Tests\Support\Events\ThrowsOnHydrate;
use SEOCart\Tests\Support\SecondConnection;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- The multisite test creates and drops a site's tables directly.

/**
 * An event is durable exactly when the change it describes is, and is delivered after the commit, at least once.
 *
 * Connection B sees only what is committed. The wake is a recorder that never drains, which
 * is also how these tests play a process that dies right after COMMIT: delivery then happens
 * only when a test calls drain().
 *
 * Each test names its planted violation, in src/Platform/Events/ unless it says otherwise.
 *
 * @since 0.1.0
 */
final class OutboxTest extends OutboxTestCase {

	/**
	 * The action ThingHappened fires.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const HAPPENED = 'seocart_test_thing_happened';

	/**
	 * The action ThingNoticed fires.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const NOTICED = 'seocart_test_thing_noticed';

	/**
	 * A crash right after COMMIT leaves a committed, unclaimed row, and a later drainer delivers it once.
	 *
	 * The probe is an after-commit callback registered before the event is published, so it
	 * runs after COMMIT and before the wake: the state a process that died at that moment
	 * leaves behind.
	 *
	 * Planted violation: in Publisher::publish(), register the INSERT as an after-commit callback
	 * instead of sending it. The probe then finds no row: durability would depend on code that
	 * runs after COMMIT.
	 *
	 * @since 0.1.0
	 */
	public function test_a_crash_right_after_commit_leaves_a_row_a_later_drainer_delivers_once(): void {
		$b     = $this->secondConnection();
		$probe = null;

		$this->listen( self::HAPPENED );

		$this->db->transaction(
			function () use ( $b, &$probe ): void {
				$this->db->afterCommit(
					function () use ( $b, &$probe ): void {
						$probe = array(
							'rows'      => $this->outboxIds( $b ),
							'unclaimed' => $this->outboxIds( $b, "state = 'pending' AND claim_token IS NULL AND claimed_until IS NULL AND attempts = 0" ),
							'woken'     => $this->wake->calls(),
						);
					}
				);

				$this->publisher->publish( new ThingHappened( 7 ) );
			}
		);

		$this->assertNotNull( $probe, 'The probe ran after COMMIT.' );
		$this->assertCount( 1, $probe['rows'], 'One row is committed before anything runs after COMMIT.' );
		$this->assertSame( $probe['rows'], $probe['unclaimed'], 'It is pending and unclaimed: nothing more is needed to deliver it.' );
		$this->assertSame( 0, $probe['woken'], 'The probe ran before the wake: the process could have died here.' );
		$this->assertSame( 1, $this->wake->calls() );
		$this->assertSame( array(), $this->fired, 'Nothing is delivered at COMMIT.' );

		$report = $this->drainer()->drain( DrainOptions::command() );
		$row    = $this->outboxRow( $b, $probe['rows'][0] );

		$this->assertSame( 1, $report->dispatched );
		$this->assertCount( 1, $this->fired );
		$this->assertSame( $probe['rows'][0], $this->fired[0]['outbox_id'] );
		$this->assertSame( 1, $this->fired[0]['attempt'] );
		$this->assertSame( Outbox::DISPATCHED, $row['state'] );
		$this->assertSame( '1', $row['attempts'] );
		$this->assertNull( $row['claim_token'] );
		$this->assertNotNull( $row['dispatched_at'] );
		$this->assertSame( 0, $this->drainer()->drain( DrainOptions::command() )->claimed, 'A second drain finds nothing due.' );
	}

	/**
	 * A unit of work that rolls back stores no row, wakes no drainer and fires no action.
	 *
	 * Planted violation: in Publisher::publish(), fire an after-commit event's action at once
	 * instead of registering it after the commit. The listener then hears of a change that
	 * never happened.
	 *
	 * @since 0.1.0
	 */
	public function test_a_unit_of_work_that_rolls_back_stores_and_fires_nothing(): void {
		$b = $this->secondConnection();

		$this->listen( self::HAPPENED );
		$this->listen( self::NOTICED );

		try {
			$this->db->transaction(
				function (): void {
					$this->publisher->publish( new ThingHappened( 1 ) );
					$this->publisher->publish( new ThingNoticed( 1 ) );

					throw new \RuntimeException( 'The unit of work fails.' );
				}
			);
			$this->fail( 'The failure must propagate.' );
		} catch ( \RuntimeException $expected ) {
			$this->assertSame( 'The unit of work fails.', $expected->getMessage() );
		}

		$this->assertSame( array(), $this->outboxIds( $b ) );
		$this->assertSame( 0, $this->wake->calls() );
		$this->assertSame( array(), $this->fired );
	}

	/**
	 * An inner level that rolls back takes exactly its own events with it; the outer level's survive and are delivered.
	 *
	 * Planted violations: in Publisher::publish(), keep outbox events in an array and insert
	 * them all in one after-commit callback registered at the outermost level: the inner
	 * level's row then appears. And, as in the previous test, fire after-commit events at once:
	 * the inner level's after-commit event then fires.
	 *
	 * @since 0.1.0
	 */
	public function test_an_inner_rollback_removes_only_its_own_events(): void {
		$b = $this->secondConnection();

		$this->listen( self::HAPPENED );
		$this->listen( self::NOTICED );

		$this->db->transaction(
			function (): void {
				$this->publisher->publish( new ThingHappened( 1 ) );
				$this->publisher->publish( new ThingNoticed( 1 ) );

				try {
					$this->db->transaction(
						function (): void {
							$this->publisher->publish( new ThingHappened( 2 ) );
							$this->publisher->publish( new ThingNoticed( 2 ) );

							throw new \RuntimeException( 'The inner level fails.' );
						}
					);
				} catch ( \RuntimeException $caught ) {
					unset( $caught );
				}
			}
		);

		$this->assertSame( '1', $b->fetchValue( sprintf( 'SELECT GROUP_CONCAT( aggregate_id ORDER BY id ) FROM `%s`', $this->outboxTable() ) ), 'Exactly the outer level\'s row is committed.' );
		$this->assertSame( 1, $this->wake->calls(), 'The inner level\'s wake went with its rollback.' );
		$this->assertSame( array( array( self::NOTICED, 1 ) ), $this->heard(), 'Only the outer level\'s after-commit event fired, after COMMIT.' );

		$this->drainer()->drain( DrainOptions::command() );

		$this->assertSame( array( array( self::NOTICED, 1 ), array( self::HAPPENED, 1 ) ), $this->heard() );
	}

	/**
	 * A row whose event is unknown, or was stored by a newer payload version, is parked; the rows around it are delivered.
	 *
	 * Planted violation: in OutboxDrainer::dispatchRow(), throw a \LogicException for an unknown
	 * event instead of parking the row. The drain then stops there.
	 *
	 * @since 0.1.0
	 */
	public function test_unknown_and_newer_rows_are_parked_and_the_drain_goes_on(): void {
		$b = $this->secondConnection();

		$this->listen( self::HAPPENED );

		$first  = $this->plantEvent( $b, new ThingHappened( 1 ) );
		$gone   = $this->plantRow( $b, 'gone', '{"v":1,"at":"2026-09-23T10:00:00.000000+00:00","p":{}}' );
		$second = $this->plantEvent( $b, new ThingHappened( 2 ) );
		$newer  = $this->plantRow( $b, ThingHappened::eventName(), '{"v":99,"at":"2026-09-23T10:00:00.000000+00:00","p":{"thing_id":3,"note":"x","line_ids":[]}}' );
		$third  = $this->plantEvent( $b, new ThingHappened( 3 ) );

		$report = $this->drainer()->drain( DrainOptions::command() );

		$this->assertSame( array( $first, $second, $third ), array_column( $this->fired, 'outbox_id' ) );
		$this->assertSame( 3, $report->dispatched );
		$this->assertSame( 2, $report->failed );

		foreach ( array( $first, $second, $third ) as $id ) {
			$this->assertSame( Outbox::DISPATCHED, $this->outboxRow( $b, $id )['state'] );
		}

		foreach ( array(
			$gone  => ReportCode::UnknownEvent,
			$newer => ReportCode::PayloadVersion,
		) as $id => $code ) {
			$row = $this->outboxRow( $b, $id );

			$this->assertSame( Outbox::FAILED, $row['state'] );
			$this->assertNull( $row['claim_token'] );
			$this->assertStringStartsWith( $code->value, (string) $row['last_error'] );
			$this->assertSame( array( $id ), array_column( $this->reportsOf( $code->value ), 'outbox_id' ) );
		}
	}

	/**
	 * Outside a transaction an outbox event is refused before any statement; an after-commit event fires at once.
	 *
	 * Planted violation: in Publisher::check(), drop the refusal of an outbox event at depth 0.
	 * The row is then stored without any change it could describe.
	 *
	 * @since 0.1.0
	 */
	public function test_outside_a_transaction_an_outbox_event_is_refused_and_an_after_commit_event_fires_at_once(): void {
		$b       = $this->secondConnection();
		$refused = null;

		$this->listen( self::NOTICED );

		$log = $this->captureQueries(
			function () use ( &$refused ): void {
				try {
					$this->publisher->publish( new ThingHappened( 1 ) );
				} catch ( \LogicException $expected ) {
					$refused = $expected;
				}
			}
		);

		$this->assertInstanceOf( \LogicException::class, $refused );
		$this->assertStringContainsString( 'inside the transaction', $refused->getMessage() );
		$this->assertQueryCount( 0, $log, 'A refused event' );
		$this->assertSame( array(), $this->outboxIds( $b ) );

		$this->publisher->publish( new ThingNoticed( 5 ) );

		$this->assertSame( array( array( self::NOTICED, 5 ) ), $this->heard() );
		$this->assertNull( $this->fired[0]['outbox_id'] );
		$this->assertSame( 1, $this->fired[0]['attempt'] );
		$this->assertSame( 0, $this->wake->calls() );
	}

	/**
	 * A row that fails outside its listeners is retried after 1, 4, 16 and 60 minutes, then parked and kept.
	 *
	 * Each retry's due time is read on the database clock, within 5 seconds. Between drains the
	 * test makes the row due again, as time passing would.
	 *
	 * Planted violations: in Backoff::seconds(), return max( … ) instead of min( … ); and in
	 * OutboxDrainer::retryOrPark(), never park. Either turns this test red.
	 *
	 * @since 0.1.0
	 */
	public function test_a_row_that_fails_outside_its_listeners_backs_off_then_is_parked_and_kept(): void {
		$b  = $this->secondConnection();
		$id = $this->plantEvent( $b, new ThrowsOnHydrate( 1 ) );

		$delays = array(
			1 => 60,
			2 => 240,
			3 => 960,
			4 => 3600,
		);

		for ( $attempt = 1; $attempt <= 5; ++$attempt ) {
			$report = $this->drainer()->drain( DrainOptions::command() );
			$row    = $this->outboxRow( $b, $id );

			$this->assertSame( (string) $attempt, $row['attempts'] );
			$this->assertNull( $row['claim_token'] );
			$this->assertStringStartsWith( ReportCode::DispatchFailed->value . ' RuntimeException: This fixture cannot be rebuilt.', (string) $row['last_error'] );

			if ( $attempt < 5 ) {
				$this->assertSame( Outbox::PENDING, $row['state'], 'Attempt ' . $attempt );
				$this->assertSame( 1, $report->retried );
				$this->assertEqualsWithDelta( $delays[ $attempt ], $this->secondsUntilDue( $b, $id ), 5, 'Attempt ' . $attempt . ' waits 1, 4, 16, then 60 minutes.' );

				$b->query( sprintf( 'UPDATE `%s` SET available_at = UTC_TIMESTAMP(6) - INTERVAL 1 SECOND WHERE id = %d', $this->outboxTable(), $id ) );

				continue;
			}

			$this->assertSame( Outbox::FAILED, $row['state'], 'The fifth failed attempt parks the row.' );
			$this->assertSame( 1, $report->failed );
		}

		$this->assertSame( 0, $this->drainer()->drain( DrainOptions::command() )->claimed, 'A failed row is never claimed again.' );
		$this->assertSame( '5', $this->outboxRow( $b, $id )['attempts'] );
		$this->assertSame( 0, $this->outbox->prune( 1000 ), 'A freshly parked row is kept for its retention.' );
		$this->assertNotNull( $this->outboxRow( $b, $id ) );
		$this->assertCount( 5, $this->reportsOf( ReportCode::DispatchFailed->value ) );
	}

	/**
	 * Retention deletes dispatched rows after 7 days and failed rows 90 days after they were parked, never a pending row; the report counts by state.
	 *
	 * Planted violation: in Outbox::prune(), drop `state = 'failed'` from the second DELETE.
	 * The pending row, due for 100 days, is then deleted.
	 *
	 * @since 0.1.0
	 */
	public function test_retention_deletes_only_rows_past_it_and_the_report_counts_by_state(): void {
		$b       = $this->secondConnection();
		$payload = Outbox::encode( new ThingHappened( 1 ), Publisher::DEFAULT_PAYLOAD_CAP_BYTES );
		$plant   = fn( array $expressions ): int => $this->plantRow( $b, ThingHappened::eventName(), $payload, $expressions );

		$dispatched8 = $plant(
			array(
				'state'         => "'dispatched'",
				'dispatched_at' => 'UTC_TIMESTAMP(6) - INTERVAL 8 DAY',
			)
		);
		$dispatched6 = $plant(
			array(
				'state'         => "'dispatched'",
				'dispatched_at' => 'UTC_TIMESTAMP(6) - INTERVAL 6 DAY',
			)
		);
		$recent      = $plant(
			array(
				'state'         => "'dispatched'",
				'dispatched_at' => 'UTC_TIMESTAMP(6) - INTERVAL 1 HOUR',
			)
		);
		$failed91    = $plant(
			array(
				'state'        => "'failed'",
				'available_at' => 'UTC_TIMESTAMP(6) - INTERVAL 91 DAY',
			)
		);
		$failed89    = $plant(
			array(
				'state'        => "'failed'",
				'available_at' => 'UTC_TIMESTAMP(6) - INTERVAL 89 DAY',
			)
		);
		$pending     = $plant(
			array(
				'created_at'   => 'UTC_TIMESTAMP(6) - INTERVAL 100 DAY',
				'available_at' => 'UTC_TIMESTAMP(6) - INTERVAL 100 DAY',
			)
		);
		$leased      = $plant(
			array(
				'claim_token'   => "'" . str_repeat( 'c', 64 ) . "'",
				'claimed_until' => 'UTC_TIMESTAMP(6) + INTERVAL 1 MINUTE',
			)
		);

		$before = $this->outbox->report();

		$this->assertSame( 2, $before->pending );
		$this->assertSame( 1, $before->inFlight );
		$this->assertSame( 2, $before->failed );
		$this->assertSame( 1, $before->dispatchedLastDay );
		$this->assertEqualsWithDelta( 100 * DAY_IN_SECONDS, $before->oldestPendingSeconds, 60 );

		$this->assertSame( 2, $this->outbox->prune( 1000 ) );
		$this->assertSame( array( $dispatched6, $recent, $failed89, $pending, $leased ), $this->outboxIds( $b ) );
		$this->assertNotContains( $dispatched8, $this->outboxIds( $b ) );
		$this->assertNotContains( $failed91, $this->outboxIds( $b ) );

		$after = $this->outbox->report();

		$this->assertSame( 2, $after->pending );
		$this->assertSame( 1, $after->failed );
		$this->assertEqualsWithDelta( 100 * DAY_IN_SECONDS, $after->oldestPendingSeconds, 60 );
		$this->assertSame( 0, $this->outbox->prune( 1000 ), 'Nothing more is past retention.' );
	}

	/**
	 * A row the lease can no longer cover is not started: it is handed back unattempted and claimed again under a fresh lease.
	 *
	 * The drainer's clock is the test's: the first listener call moves it 56 seconds on, so the
	 * 60-second lease has less than its 5-second reserve left when the second row comes up.
	 *
	 * Planted violation: in OutboxDrainer::drainHolding(), do not hand back the rows a batch did
	 * not reach. They then stay leased, and the next claim cannot take them.
	 *
	 * @since 0.1.0
	 */
	public function test_rows_the_lease_cannot_cover_are_handed_back_and_claimed_again(): void {
		$b     = $this->secondConnection();
		$ids   = array();
		$now   = 0;
		$moved = false;

		for ( $thing = 1; $thing <= 3; ++$thing ) {
			$ids[] = $this->plantEvent( $b, new ThingHappened( $thing ) );
		}

		$this->listen(
			self::HAPPENED,
			10,
			static function () use ( &$now, &$moved ): void {
				if ( ! $moved ) {
					$moved = true;
					$now  += 56 * 1000000000;
				}
			}
		);

		$clock = static function () use ( &$now ): int {
			return $now;
		};

		$report = $this->drainer( null, $clock )->drain( DrainOptions::command( 300 ) );

		$this->assertSame( $ids, array_column( $this->fired, 'outbox_id' ) );
		$this->assertSame( array( 1, 1, 1 ), array_column( $this->fired, 'attempt' ), 'A handed-back row was not counted as attempted.' );
		$this->assertSame( 3, $report->dispatched );
		$this->assertSame( 5, $report->claimed, 'Three rows, then the two handed back.' );
		$this->assertSame( $ids, $this->outboxIds( $b, "state = 'dispatched' AND attempts = 1" ) );
	}

	/**
	 * A drain stops when its time budget is spent, and hands back the rows it did not start.
	 *
	 * Planted violation: in OutboxDrainer::drainHolding(), check the budget only before each
	 * claim. The whole batch then runs past the budget.
	 *
	 * @since 0.1.0
	 */
	public function test_a_drain_stops_when_its_budget_is_spent(): void {
		$b     = $this->secondConnection();
		$ids   = array();
		$now   = 0;
		$clock = static function () use ( &$now ): int {
			return $now;
		};

		for ( $thing = 1; $thing <= 3; ++$thing ) {
			$ids[] = $this->plantEvent( $b, new ThingHappened( $thing ) );
		}

		$this->listen(
			self::HAPPENED,
			10,
			static function () use ( &$now ): void {
				$now += 11 * 1000000000;
			}
		);

		$report = $this->drainer( null, $clock )->drain( DrainOptions::command( 10 ) );

		$this->assertTrue( $report->budgetExhausted );
		$this->assertSame( 1, $report->dispatched );
		$this->assertSame( array( $ids[0] ), array_column( $this->fired, 'outbox_id' ) );
		$this->assertSame( array_slice( $ids, 1 ), $this->outboxIds( $b, "state = 'pending' AND claim_token IS NULL AND attempts = 0" ), 'The rows not started are claimable again at once, unattempted.' );
	}

	/**
	 * A row claimed more often than the attempts allow is parked without running its listeners, and the rows behind it are delivered.
	 *
	 * Its claims ended with no outcome: a listener killed the process, ran out of memory or
	 * called exit during the drain at the end of a request, where no handler records anything.
	 * The planted row carries the state that leaves: attempts at the limit and a lapsed lease.
	 *
	 * Planted violation: in OutboxDrainer::dispatchRow(), remove the check of the attempts
	 * against the limit. The row is then delivered again.
	 *
	 * @since 0.1.0
	 */
	public function test_a_row_whose_deliveries_never_end_is_parked_without_running_its_listeners(): void {
		$b         = $this->secondConnection();
		$abandoned = $this->plantEvent(
			$b,
			new ThingHappened( 1 ),
			array(
				'attempts'      => '5',
				'claim_token'   => "'" . str_repeat( 'd', 64 ) . "'",
				'claimed_until' => 'UTC_TIMESTAMP(6) - INTERVAL 1 SECOND',
			)
		);
		$behind    = $this->plantEvent( $b, new ThingHappened( 2 ) );

		$this->listen( self::HAPPENED );

		$report = $this->drainer()->drain( DrainOptions::command() );
		$row    = $this->outboxRow( $b, $abandoned );

		$this->assertSame( array( $behind ), array_column( $this->fired, 'outbox_id' ), 'The abandoned row\'s listeners never ran; the row behind it was delivered.' );
		$this->assertSame( 1, $report->failed );
		$this->assertSame( 1, $report->dispatched );
		$this->assertSame( Outbox::FAILED, $row['state'] );
		$this->assertSame( '5', $row['attempts'], 'Parking starts no delivery: the count stays at the five that were started.' );
		$this->assertNull( $row['claim_token'] );
		$this->assertStringStartsWith( ReportCode::ListenerAbandoned->value . ' ' . ThingHappened::eventName() . ': the process died or exited during delivery', (string) $row['last_error'] );
		$this->assertSame( array( $abandoned ), array_column( $this->reportsOf( ReportCode::ListenerAbandoned->value ), 'outbox_id' ) );
	}

	/**
	 * A row whose delivery kills the process is parked once its deliveries run out; the healthy row claimed with it every time is delivered, never parked.
	 *
	 * Each round plays a process that dies between row 1's listener and the record of its
	 * outcome: the listener adds a one-shot `query` filter that makes row 1's mark fail, so the
	 * drain ends there with an exception, neither marking row 1 nor handing row 2 back. Then
	 * both leases lapse, as they would. Row 2 is claimed in the same batch every round, but its
	 * delivery never starts, so it is never charged an attempt.
	 *
	 * Planted violation: count the attempt in the claim (`attempts = attempts + 1` in
	 * Outbox::CLAIM) instead of in Outbox::startDelivery(). Row 2 then runs out of attempts
	 * with row 1 and is parked undelivered.
	 *
	 * @since 0.1.0
	 */
	public function test_a_poison_row_is_parked_and_the_healthy_row_claimed_with_it_is_delivered(): void {
		$b       = $this->secondConnection();
		$poison  = $this->plantEvent( $b, new ThingHappened( 1 ) );
		$healthy = $this->plantEvent( $b, new ThingHappened( 2 ) );

		$this->listen(
			self::HAPPENED,
			10,
			static function ( DomainEvent $event ) use ( $poison ): void {
				if ( 1 !== $event->aggregateId() ) {
					return;
				}

				// The process dies before row 1's outcome is recorded: its mark never reaches the database.
				$die = static function ( $query ) use ( $poison, &$die ) {
					if ( is_string( $query ) && str_contains( $query, "SET state = 'dispatched'" ) && str_contains( $query, 'WHERE id = ' . $poison . ' ' ) ) {
						remove_filter( 'query', $die );

						return 'SELECT * FROM `seocart_no_such_table`';
					}

					return $query;
				};

				add_filter( 'query', $die );
			}
		);

		for ( $round = 1; $round <= 5; ++$round ) {
			try {
				$this->drainer()->drain( DrainOptions::command() );
				$this->fail( sprintf( 'Round %d: the process must die at row 1.', $round ) );
			} catch ( QueryFailed $died ) {
				unset( $died );
			}

			// The dead process's leases lapse.
			$b->query( sprintf( 'UPDATE `%s` SET claimed_until = UTC_TIMESTAMP(6) - INTERVAL 1 SECOND WHERE claim_token IS NOT NULL', $this->outboxTable() ) );
		}

		$this->assertSame( '0', $this->outboxRow( $b, $healthy )['attempts'], 'Row 2 was claimed in every round, but no delivery of it started.' );
		$this->assertSame( '5', $this->outboxRow( $b, $poison )['attempts'] );

		$report = $this->drainer()->drain( DrainOptions::command() );
		$failed = $this->outboxRow( $b, $poison );
		$sound  = $this->outboxRow( $b, $healthy );

		$this->assertSame( array( 1, 1, 1, 1, 1, 2 ), array_column( $this->fired, 'thing' ), 'Row 1 ran five times and never again; row 2 ran once.' );
		$this->assertSame( 1, $report->failed );
		$this->assertSame( 1, $report->dispatched );
		$this->assertSame( Outbox::FAILED, $failed['state'] );
		$this->assertStringStartsWith( ReportCode::ListenerAbandoned->value, (string) $failed['last_error'] );
		$this->assertSame( Outbox::DISPATCHED, $sound['state'], 'The healthy row was delivered, never parked.' );
		$this->assertSame( '1', $sound['attempts'] );
	}

	/**
	 * After a fatal error elsewhere in the request, the end-of-request drain delivers nothing; a later drain does.
	 *
	 * Planted violation: in OutboxDrainer::drainAtShutdown(), drop the check for a fatal error.
	 *
	 * @since 0.1.0
	 */
	public function test_after_a_fatal_error_the_end_of_request_drain_delivers_nothing(): void {
		$b         = $this->secondConnection();
		$drainer   = $this->drainer();
		$publisher = new Publisher( $this->db, $this->outbox, $this->bridge, $this->catalog, $this->correlation, array( $drainer, 'scheduleAtShutdown' ) );

		$this->listen( self::HAPPENED );
		$this->db->transaction( fn() => $publisher->publish( new ThingHappened( 3 ) ) );

		$log = $this->captureQueries(
			fn() => $drainer->drainAtShutdown(
				array(
					'type'    => E_ERROR,
					'message' => 'A fatal error elsewhere in the request.',
					'file'    => __FILE__,
					'line'    => __LINE__,
				)
			)
		);

		$this->assertQueryCount( 0, $log, 'The end-of-request drain after a fatal error' );
		$this->assertSame( array(), $this->fired );
		$this->assertSame( array(), $this->outboxIds( $b, "state <> 'pending' OR attempts <> 0" ), 'The row waits, untouched.' );
		$this->assertSame( 1, $drainer->drain( DrainOptions::command() )->dispatched, 'A later drain delivers it.' );
	}

	/**
	 * The sites that published in one request share the end-of-request budget: the second site gets what the first left.
	 *
	 * The drainer's clock is the test's; each delivery moves it 750 ms on. The main site's two
	 * events take 1.5 of the 2 seconds, so the second site's drain delivers one of its three
	 * events and stops.
	 *
	 * Planted violation: in OutboxDrainer::drainAtShutdown(), give each site a fresh budget
	 * (`$this->drain( $options )`). The second site then delivers all three.
	 *
	 * @since 0.1.0
	 */
	public function test_the_sites_of_one_request_share_the_end_of_request_budget(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Needs a multisite test run (WP_MULTISITE=1).' );
		}

		global $wpdb;

		$site = wp_insert_site(
			array(
				'domain' => 'example.org',
				'path'   => '/seocart-outbox-budget/',
			)
		);

		$this->assertIsInt( $site );

		$now       = 0;
		$drainer   = $this->drainer(
			null,
			static function () use ( &$now ): int {
				return $now;
			}
		);
		$publisher = new Publisher( $this->db, $this->outbox, $this->bridge, $this->catalog, $this->correlation, array( $drainer, 'scheduleAtShutdown' ) );

		$this->listen(
			self::HAPPENED,
			10,
			static function () use ( &$now ): void {
				$now += 750000000;
			}
		);

		try {
			$this->db->transaction( fn() => $publisher->publish( new ThingHappened( 1 ), new ThingHappened( 2 ) ) );

			switch_to_blog( $site );

			$operations = new SchemaOperations( $this->db, new DdlGenerator(), new SchemaVerifier( $this->db ) );

			( new CreateOutboxMigration() )->up( $operations );
			$operations->createTable( PlatformTables::locks() );

			$this->db->transaction( fn() => $publisher->publish( new ThingHappened( 11 ), new ThingHappened( 12 ), new ThingHappened( 13 ) ) );

			restore_current_blog();

			$drainer->drainAtShutdown();

			$this->assertSame( array( 1, 2, 11 ), array_column( $this->fired, 'thing' ), 'The second site delivered only what the shared budget left room for.' );
		} finally {
			foreach ( array( 'outbox', 'locks' ) as $table ) {
				$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->get_blog_prefix( $site ) . 'seocart_' . $table ) );
			}

			wp_delete_site( $site );
		}
	}

	/**
	 * An empty outbox reports zeros and no age.
	 *
	 * @since 0.1.0
	 */
	public function test_an_empty_outbox_reports_nothing_waiting(): void {
		$report = $this->outbox->report();

		$this->assertSame( array( 0, null, 0, 0, 0 ), array( $report->pending, $report->oldestPendingSeconds, $report->inFlight, $report->failed, $report->dispatchedLastDay ) );
	}

	/**
	 * The wake sends nothing; the drain at the end of the request delivers what the request stored, once.
	 *
	 * Planted violation: in OutboxDrainer::drainAtShutdown(), keep the remembered sites after
	 * draining. The second call then sends statements.
	 *
	 * @since 0.1.0
	 */
	public function test_the_wake_sends_nothing_and_the_drain_at_the_end_of_the_request_delivers(): void {
		$b         = $this->secondConnection();
		$drainer   = $this->drainer();
		$publisher = new Publisher( $this->db, $this->outbox, $this->bridge, $this->catalog, $this->correlation, array( $drainer, 'scheduleAtShutdown' ) );

		$this->listen( self::HAPPENED );

		$log = $this->captureQueries(
			function () use ( $publisher ): void {
				$this->db->transaction( fn() => $publisher->publish( new ThingHappened( 3 ) ) );
			}
		);

		$this->assertQueryCount( 5, $log, 'A unit of work that stores one event: four transaction statements and the INSERT' );
		$this->assertSame( array(), $this->fired, 'Nothing is delivered at COMMIT.' );

		$drainer->drainAtShutdown();

		$this->assertSame( array( array( self::HAPPENED, 3 ) ), $this->heard() );
		$this->assertSame( Outbox::DISPATCHED, $this->outboxRow( $b, (int) $this->fired[0]['outbox_id'] )['state'] );
		$this->assertQueryCount( 0, $this->captureQueries( fn() => $drainer->drainAtShutdown() ), 'A second end-of-request drain with nothing published' );
	}

	/**
	 * The drain at the end of the request switches to every site that published, and back.
	 *
	 * @since 0.1.0
	 */
	public function test_the_drain_at_the_end_of_the_request_switches_to_each_site_that_published(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Needs a multisite test run (WP_MULTISITE=1).' );
		}

		global $wpdb;

		$b    = $this->secondConnection();
		$site = wp_insert_site(
			array(
				'domain' => 'example.org',
				'path'   => '/seocart-outbox-test/',
			)
		);

		$this->assertIsInt( $site );

		$drainer   = $this->drainer();
		$publisher = new Publisher( $this->db, $this->outbox, $this->bridge, $this->catalog, $this->correlation, array( $drainer, 'scheduleAtShutdown' ) );

		$this->listen( self::HAPPENED );

		try {
			switch_to_blog( $site );

			$operations = new SchemaOperations( $this->db, new DdlGenerator(), new SchemaVerifier( $this->db ) );

			( new CreateOutboxMigration() )->up( $operations );
			$operations->createTable( PlatformTables::locks() );

			$this->db->transaction( fn() => $publisher->publish( new ThingHappened( 11 ) ) );

			restore_current_blog();

			$this->db->transaction( fn() => $publisher->publish( new ThingHappened( 12 ) ) );

			$drainer->drainAtShutdown();

			$this->assertSame( 1, get_current_blog_id(), 'The drain restored the current site.' );
			$this->assertSame( array( 11, 12 ), array_column( $this->fired, 'thing' ) );
			$this->assertSame( 'dispatched', $b->fetchValue( sprintf( 'SELECT state FROM `%sseocart_outbox`', $wpdb->get_blog_prefix( $site ) ) ) );
		} finally {
			foreach ( array( 'outbox', 'locks' ) as $table ) {
				$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->get_blog_prefix( $site ) . 'seocart_' . $table ) );
			}

			wp_delete_site( $site );
		}
	}

	/**
	 * Returns the listener calls as pairs of action and aggregate id.
	 *
	 * @since 0.1.0
	 *
	 * @return list<array{0: string, 1: int}> The calls, in order.
	 */
	private function heard(): array {
		return array_map( static fn( array $call ): array => array( $call['hook'], $call['thing'] ), $this->fired );
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
