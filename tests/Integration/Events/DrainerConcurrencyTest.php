<?php
/**
 * Tests drainers racing each other: the claim on the server, the drain lock, and a lease that lapses mid-dispatch
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Events;

use SEOCart\Platform\Events\DrainOptions;
use SEOCart\Platform\Events\DrainReport;
use SEOCart\Platform\Events\EventEnvelope;
use SEOCart\Platform\Events\Outbox;
use SEOCart\Platform\Events\OutboxDrainer;
use SEOCart\Platform\Events\ReportCode;
use SEOCart\Support\Events\DomainEvent;
use SEOCart\Tests\Support\Events\OutboxTestCase;
use SEOCart\Tests\Support\Events\ThingHappened;

// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- The first test binds the claim statement itself with prepare(), to race it from raw connections.

/**
 * Within a live lease a row is dispatched by exactly one drainer; after it lapses, the late drainer changes nothing.
 *
 * Three kinds of second runner: raw connections racing the very claim statement on the
 * server, held apart by a row lock the test watches in the process list; a second drainer
 * over its own wpdb connection (SecondDatabase), run from inside the first drainer's listener;
 * and planted state, a crashed drainer's lock and leases. No test sleeps.
 *
 * Each test names its planted violation, in src/Platform/Events/.
 *
 * @since 0.1.0
 *
 * @group concurrency
 */
final class DrainerConcurrencyTest extends OutboxTestCase {

	/**
	 * The action ThingHappened fires.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const HAPPENED = 'seocart_test_thing_happened';

	/**
	 * Two claims racing on the server partition the rows: the loser waits for the winner's row locks, then skips its rows.
	 *
	 * B1 claims five rows inside an open transaction and holds their locks; B2 sends the same
	 * claim and is seen waiting in the server; B1 commits; B2 then takes the other five. MySQL
	 * 8.4 shows an `UPDATE … ORDER BY … LIMIT` that waits for a row lock in the process-list
	 * state `System lock`, not `updating` as a plain `UPDATE … WHERE id =` does.
	 *
	 * Planted violation: in Outbox::CLAIM, drop `AND ( claimed_until IS NULL OR claimed_until < UTC_TIMESTAMP(6) )`.
	 * B2 then takes B1's rows as soon as it may.
	 *
	 * @since 0.1.0
	 */
	public function test_two_claims_racing_on_the_server_partition_the_rows(): void {
		global $wpdb;

		$b   = $this->secondConnection();
		$ids = array();

		for ( $thing = 1; $thing <= 10; ++$thing ) {
			$ids[] = $this->plantEvent( $b, new ThingHappened( $thing ) );
		}

		$tokenA = str_repeat( 'a', 64 );
		$tokenB = str_repeat( 'b', 64 );
		$claimA = (string) $wpdb->prepare( Outbox::CLAIM, $this->outboxTable(), $tokenA, 60, 5 );
		$claimB = (string) $wpdb->prepare( Outbox::CLAIM, $this->outboxTable(), $tokenB, 60, 5 );
		$b1     = $this->secondConnection();
		$b2     = $this->secondConnection();

		$b1->query( 'START TRANSACTION' );
		$b1->query( $claimA );

		$this->assertSame( 5, $b1->affectedRows() );

		$b2->queryAsync( $claimB );

		$this->awaitWaiting( $b2, $claimB, 'System lock' );

		$b1->query( 'COMMIT' );

		$this->assertTrue( $b2->isReady( 2000 ), 'B2 must finish once B1 has committed.' );
		$this->assertSame( 5, $b2->reap() );
		$this->assertSame( array_slice( $ids, 0, 5 ), $this->outboxIds( $b, "claim_token = '" . $tokenA . "'" ) );
		$this->assertSame( array_slice( $ids, 5 ), $this->outboxIds( $b, "claim_token = '" . $tokenB . "'" ) );
		$this->assertSame( array(), $this->outboxIds( $b, 'attempts <> 0' ), 'A claim counts no attempt; only a delivery that starts does.' );
	}

	/**
	 * A second drainer skips while the first holds the lock; once the lock is free it never takes a row another drainer leased.
	 *
	 * D1 drains over wpdb; its listener, the first time it runs, starts D2 over a second
	 * connection, which must find the lock held. Then a crashed drainer is planted: its lock row
	 * expired, and a live lease on five new rows. D2 reclaims the lock and delivers only the
	 * other five. Every row appears exactly once in the listener log.
	 *
	 * Planted violation: in OutboxDrainer::drain(), take the lock under a name of the drainer's
	 * own (`self::LOCK_NAME . spl_object_id( $this )`), so drainers no longer exclude each other.
	 *
	 * @since 0.1.0
	 */
	public function test_a_second_drainer_skips_the_held_lock_and_never_takes_a_leased_row(): void {
		$b     = $this->secondConnection();
		$d2    = $this->drainer( $this->secondDatabase()->db() );
		$first = array();
		$inner = array();

		for ( $thing = 1; $thing <= 10; ++$thing ) {
			$first[] = $this->plantEvent( $b, new ThingHappened( $thing ) );
		}

		$this->listen(
			self::HAPPENED,
			10,
			static function () use ( $d2, &$inner ): void {
				if ( array() === $inner ) {
					$inner[] = $d2->drain( DrainOptions::command() );
				}
			}
		);

		$outer = $this->drainer()->drain( DrainOptions::command() );

		$this->assertSame( DrainReport::SKIPPED_LOCKED, $inner[0]->skipped, 'D2 found the lock held by D1.' );
		$this->assertSame( 10, $outer->dispatched );

		$second = array();

		for ( $thing = 11; $thing <= 20; ++$thing ) {
			$second[] = $this->plantEvent( $b, new ThingHappened( $thing ) );
		}

		$leased = array_slice( $second, 0, 5 );

		$b->query( sprintf( "UPDATE `%s` SET claim_token = '%s', claimed_until = UTC_TIMESTAMP(6) + INTERVAL 1 HOUR, attempts = 1 WHERE id IN ( %s )", $this->outboxTable(), str_repeat( 'f', 64 ), implode( ', ', $leased ) ) );
		$b->query( sprintf( "UPDATE `%s` SET owner_token = '%s', expires_at = UTC_TIMESTAMP(6) - INTERVAL 1 SECOND WHERE name = '%s'", $this->db->table( 'locks' ), str_repeat( 'e', 64 ), OutboxDrainer::LOCK_NAME ) );

		$report = $d2->drain( DrainOptions::command() );

		$this->assertNull( $report->skipped, 'D2 reclaimed the expired lock.' );
		$this->assertSame( 5, $report->dispatched );
		$this->assertSame( array_merge( $first, array_slice( $second, 5 ) ), array_column( $this->fired, 'outbox_id' ), 'Every delivered row appears once, and no leased row appears.' );
		$this->assertSame( $leased, $this->outboxIds( $b, "claim_token = '" . str_repeat( 'f', 64 ) . "' AND state = 'pending'" ), 'The leased rows are untouched.' );
	}

	/**
	 * A drainer whose lease lapsed while its listener ran marks nothing and stops; the drainer that took over marks the row once.
	 *
	 * D1 claims row R. Its listener, on attempt 1, makes R's lease and D1's lock lapse and runs
	 * D2, which reclaims both, delivers R again (attempt 2) and marks it. Back in D1, the mark
	 * finds R carrying another token: D1 reports the lost lease, stops the batch, and its next
	 * renewal finds the lock lost. A consumer keyed on the outbox id applies one side effect.
	 * Neither putting R back nor parking it with D1's token changes it either.
	 *
	 * Planted violations: in Outbox::markDispatched(), drop `AND claim_token = %s`: D1 then marks
	 * R a second time, rewriting dispatched_at, and reports nothing. In Outbox::release(), write
	 * `( claim_token = %s OR 1 = 1 )`: D1's token then puts R back.
	 *
	 * @since 0.1.0
	 */
	public function test_a_drainer_whose_lease_lapsed_mid_dispatch_marks_nothing_and_stops(): void {
		$b         = $this->secondConnection();
		$d2        = $this->drainer( $this->secondDatabase()->db() );
		$row       = $this->plantEvent( $b, new ThingHappened( 1 ) );
		$applied   = array();
		$markedBy2 = null;
		$inner     = null;
		$d1Token   = null;

		$this->listen(
			self::HAPPENED,
			10,
			function ( DomainEvent $event, EventEnvelope $envelope ) use ( $b, $d2, $row, &$applied, &$markedBy2, &$inner, &$d1Token ): void {
				// The consumer: its side effect is keyed on the outbox id, so a redelivery changes nothing.
				$applied[ (int) $envelope->outboxId ] = true;

				if ( 1 !== $envelope->attempt ) {
					return;
				}

				$d1Token = $this->outboxRow( $b, $row )['claim_token'];

				$b->query( sprintf( 'UPDATE `%s` SET claimed_until = UTC_TIMESTAMP(6) - INTERVAL 1 SECOND WHERE id = %d', $this->outboxTable(), $row ) );
				$b->query( sprintf( "UPDATE `%s` SET expires_at = UTC_TIMESTAMP(6) - INTERVAL 1 SECOND WHERE name = '%s'", $this->db->table( 'locks' ), OutboxDrainer::LOCK_NAME ) );

				$inner     = $d2->drain( DrainOptions::command() );
				$markedBy2 = $this->outboxRow( $b, $row )['dispatched_at'];
			}
		);

		$outer = $this->drainer()->drain( DrainOptions::command() );
		$final = $this->outboxRow( $b, $row );

		$this->assertNotNull( $inner );
		$this->assertSame( 1, $inner->dispatched, 'D2 took the row over and marked it.' );
		$this->assertSame( 0, $outer->dispatched, 'D1 marked nothing.' );
		$this->assertSame( array( 1, 2 ), array_column( $this->fired, 'attempt' ) );
		$this->assertSame( Outbox::DISPATCHED, $final['state'] );
		$this->assertSame( '2', $final['attempts'] );
		$this->assertNotNull( $markedBy2 );
		$this->assertSame( $markedBy2, $final['dispatched_at'], 'The row was marked once, by D2.' );
		$this->assertSame( array( $row => true ), $applied, 'One side effect for one outbox id.' );
		$this->assertSame(
			array(
				array(
					'lease'     => 'row',
					'outbox_id' => $row,
					'event'     => ThingHappened::eventName(),
				),
			),
			array_values( array_filter( $this->reportsOf( ReportCode::LeaseLost->value ), static fn( array $context ): bool => 'row' === $context['lease'] ) ),
			'D1 reported the lost lease on the row.'
		);
		$this->assertSame( array( 'lock' ), array_column( array_filter( $this->reportsOf( ReportCode::LeaseLost->value ), static fn( array $context ): bool => 'lock' === $context['lease'] ), 'lease' ), 'D1 then found its lock lost, and stopped.' );

		$this->assertIsString( $d1Token );
		$this->assertSame( 64, strlen( $d1Token ) );
		$this->assertFalse( $this->outbox->retry( $row, $d1Token, 60, 'late' ), 'D1\'s token no longer puts R back.' );
		$this->assertFalse( $this->outbox->park( $row, $d1Token, 'late' ), 'D1\'s token no longer parks R.' );
		$this->assertSame( $final, $this->outboxRow( $b, $row ), 'R is unchanged: dispatched, once.' );
	}

	/**
	 * A drainer whose lease lapsed after its own check, but before the delivery started, starts nothing and charges no attempt.
	 *
	 * The drainer's clock says the lease still covers the row. Then, just before the start is
	 * sent, B moves the row's lease into the past, as a drainer that stalled there would find it
	 * on the database's clock. Nobody has reclaimed the row, so it still carries the drainer's
	 * token; the start must refuse it all the same, and the lost lease is reported. The drain
	 * then claims the row afresh, as any drainer may, and delivers it under a live lease: the
	 * listener runs once, as delivery 1, because the refused start charged nothing.
	 *
	 * Planted violation: in Outbox::startDelivery(), drop `AND state = 'pending' AND claimed_until > UTC_TIMESTAMP(6)`.
	 * The stalled drainer then counts the attempt and runs the listener on the lapsed lease.
	 *
	 * @since 0.1.0
	 */
	public function test_a_delivery_whose_lease_lapsed_before_it_started_does_not_start(): void {
		$b     = $this->secondConnection();
		$row   = $this->plantEvent( $b, new ThingHappened( 1 ) );
		$lease = array();

		$this->listen(
			self::HAPPENED,
			10,
			function () use ( $b, $row, &$lease ): void {
				$lease[] = $b->fetchValue( sprintf( 'SELECT claimed_until > UTC_TIMESTAMP(6) FROM `%s` WHERE id = %d', $this->outboxTable(), $row ) );
			}
		);

		// One shot: the lease lapses between the drainer's check and the start of the delivery.
		$stall = function ( $query ) use ( $b, $row, &$stall ) {
			if ( is_string( $query ) && str_contains( $query, 'SET attempts = attempts + 1' ) && str_contains( $query, 'WHERE id = ' . $row . ' ' ) ) {
				remove_filter( 'query', $stall );

				$b->query( sprintf( 'UPDATE `%s` SET claimed_until = UTC_TIMESTAMP(6) - INTERVAL 1 SECOND WHERE id = %d', $this->outboxTable(), $row ) );
			}

			return $query;
		};

		add_filter( 'query', $stall );

		$report = $this->drainer()->drain( DrainOptions::command() );
		$final  = $this->outboxRow( $b, $row );

		$this->assertSame( array( '1' ), $lease, 'The listener ran once, and under a live lease.' );
		$this->assertSame( array( 1 ), array_column( $this->fired, 'attempt' ), 'It ran as delivery 1: the refused start charged nothing.' );
		$this->assertSame( '1', $final['attempts'] );
		$this->assertSame( Outbox::DISPATCHED, $final['state'] );
		$this->assertSame( 1, $report->dispatched );
		$this->assertSame(
			array(
				array(
					'lease'     => 'row',
					'outbox_id' => $row,
					'event'     => ThingHappened::eventName(),
				),
			),
			$this->reportsOf( ReportCode::LeaseLost->value ),
			'The refused start was reported as a lost lease, and nothing else was.'
		);
	}
}
