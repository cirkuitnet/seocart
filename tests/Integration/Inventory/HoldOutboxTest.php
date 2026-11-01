<?php
/**
 * Tests that every transaction that holds or gives back stock stores its events in the outbox, one insert per transaction
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Inventory;

use SEOCart\Cart\Domain\Cart;
use SEOCart\Inventory\Domain\Event\StockAdjusted;
use SEOCart\Inventory\Domain\Event\StockHoldExpired;
use SEOCart\Inventory\Domain\Event\StockReservationReleased;
use SEOCart\Inventory\Domain\Event\StockReserved;
use SEOCart\Inventory\Domain\Hold;
use SEOCart\Inventory\Domain\HoldLine;
use SEOCart\Inventory\Domain\LedgerReason;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Platform\Events\Outbox;
use SEOCart\Platform\Events\OutboxTable;
use SEOCart\Platform\Events\Publisher;
use SEOCart\Tests\Support\Inventory\StockTestCase;
use SEOCart\Tests\Support\QueryLog;
use SEOCart\Tests\Support\SecondConnection;

/**
 * A hold and every release store their events in the outbox inside their own transaction, so a listener hears them after the commit, from any process, at least once.
 *
 * A hold stores one reservation, with the expiries of the holds it reclaimed, in one insert; a
 * hold of untracked variants only stores nothing. Each release stores one release per hold it
 * gave back, in one insert per call; deleting variants stores the releases and the write-offs of
 * the stock together, the releases first. The rows are read through connection B, so only what
 * was committed is seen; one test also counts them on the unit's own connection before the commit.
 *
 * Planted violations, each named on its test.
 *
 * @since 0.2.0
 */
final class HoldOutboxTest extends StockTestCase {

	/**
	 * Tests that a hold stores one reservation, which connection B sees only once the caller's transaction commits, with the payload of the hold taken; each hold stores its own.
	 *
	 * @since 0.2.0
	 */
	public function test_a_hold_stores_one_reservation_seen_after_its_commit(): void {
		$variant = self::variant();
		$other   = self::variant();
		$b       = $this->secondConnection();

		$this->stockItem( $variant, 3 );
		$this->stockItem( $other, 2 );

		$inside = null;
		$hold   = $this->db->transaction(
			function () use ( $b, $variant, $other, &$inside ): Hold {
				$hold   = $this->service->hold( array( new HoldLine( $other, 1 ), new HoldLine( $variant, 2 ) ), 600, 41 );
				$inside = $this->committedEvents( $b, StockReserved::eventName() );

				return $hold;
			}
		);

		$this->assertSame( 0, $inside, 'Connection B saw the reservation before the commit.' );
		$this->assertSame( 1, $this->committedEvents( $b, StockReserved::eventName() ) );

		$expected = new StockReserved( $hold->holdGroup, 41, null, array( $variant, $other ), array( 2, 1 ), array( $variant, $other ), array( $this->defaultLocation, $this->defaultLocation ), array( 2, 1 ), $hold->expiresAt->format( 'Y-m-d H:i:s' ), new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) ) );

		$this->assertSame( $expected->toPayload(), $this->committedPayload( $b, StockReserved::eventName() ), 'The stored payload is the hold\'s.' );
		$this->assertSame( array( StockReserved::AGGREGATE, (string) $variant ), array_values( (array) $b->fetchRow( sprintf( "SELECT aggregate_type, aggregate_id FROM `%s` WHERE event_name = '%s'", $this->table( OutboxTable::NAME ), StockReserved::eventName() ) ) ) );

		$this->service->hold( array( new HoldLine( $variant, 1 ) ), 600 );

		$this->assertSame( 2, $this->committedEvents( $b, StockReserved::eventName() ), 'One reservation per hold.' );
	}

	/**
	 * Tests that a hold of untracked variants only stores no reservation and sends no outbox insert.
	 *
	 * @since 0.2.0
	 */
	public function test_a_hold_of_untracked_variants_only_stores_nothing(): void {
		$untracked = self::variant();
		$b         = $this->secondConnection();

		$this->plantItem( $untracked, 0, 0, 0, false );

		$hold = null;
		$log  = $this->captureQueries(
			function () use ( $untracked, &$hold ): void {
				$hold = $this->service->hold( array( new HoldLine( $untracked, 2 ) ), 600 );
			}
		);

		$this->assertNotNull( $hold );
		$this->assertSame( array( $untracked ), $hold->untracked );
		$this->assertQueryCount( 0, $this->outboxInserts( $log ), 'Outbox inserts' );
		$this->assertSame( 0, $this->outboxMark( $b ), 'The outbox holds nothing.' );
	}

	/**
	 * Tests that a hold that reclaims expired holds stores their expiries and its reservation with one insert, the expiries first.
	 *
	 * Planted violation, shown red and removed: in StockService::holdInside(), publish the
	 * reservation in a call of its own after the expiries': the hold sends two inserts.
	 *
	 * @since 0.2.0
	 */
	public function test_a_hold_that_reclaims_stores_the_expiries_and_its_reservation_with_one_insert(): void {
		$variant = self::variant();
		$b       = $this->secondConnection();

		$this->stockItem( $variant, 2 );
		$this->plantHold( $variant, 1, -60 );
		$this->plantHold( $variant, 1, -60 );

		$mark = $this->outboxMark( $b );
		$log  = $this->captureQueries(
			function () use ( $variant ): void {
				$this->service->hold( array( new HoldLine( $variant, 2 ) ), 600 );
			}
		);

		$this->assertQueryCount( 1, $this->outboxInserts( $log ), 'Outbox inserts of the hold' );
		$this->assertSame( array( StockHoldExpired::eventName(), StockHoldExpired::eventName(), StockReserved::eventName() ), $this->committedNames( $b, $mark ) );
	}

	/**
	 * Tests that each release stores one release per hold it gives back, with one insert per call: a release of one hold, a release of variants two holds hold, and the repair of holds whose item is gone.
	 *
	 * @since 0.2.0
	 */
	public function test_each_release_stores_one_release_per_hold_with_one_insert(): void {
		$first  = self::variant();
		$second = self::variant();
		$b      = $this->secondConnection();

		$this->stockItem( $first, 5 );
		$this->stockItem( $second, 5 );

		$one  = $this->service->hold( array( new HoldLine( $first, 1 ) ), 600 );
		$mark = $this->outboxMark( $b );
		$log  = $this->captureQueries( fn() => $this->service->release( $one->holdGroup, 'payment_declined' ) );

		$this->assertQueryCount( 1, $this->outboxInserts( $log ), 'Outbox inserts of release()' );
		$this->assertSame( array( array( $one->holdGroup, 'payment_declined', array( $first ), array( 1 ) ) ), self::releases( $this->committedHoldEvents( $b, $mark ) ) );

		$both  = $this->service->hold( array( new HoldLine( $first, 1 ), new HoldLine( $second, 2 ) ), 600 );
		$other = $this->service->hold( array( new HoldLine( $second, 1 ) ), 600 );
		$mark  = $this->outboxMark( $b );
		$log   = $this->captureQueries(
			function () use ( $first, $second ): void {
				$this->db->transaction(
					function () use ( $first, $second ): void {
						$this->service->releaseVariants( array( $first, $second ), 'product_trashed' );
					}
				);
			}
		);

		$this->assertQueryCount( 1, $this->outboxInserts( $log ), 'Outbox inserts of releaseVariants() over two holds' );
		$this->assertEqualsCanonicalizing(
			array(
				array( $both->holdGroup, 'product_trashed', array( $first, $second ), array( 1, 2 ) ),
				array( $other->holdGroup, 'product_trashed', array( $second ), array( 1 ) ),
			),
			self::releases( $this->committedHoldEvents( $b, $mark ) )
		);

		$gone    = $this->plantLocation();
		$expired = $this->plantHold( $first, 1, -60, null, $gone );
		$live    = $this->plantHold( $first, 2, 900, null, $gone );
		$mark    = $this->outboxMark( $b );
		$log     = $this->captureQueries( fn() => $this->service->releaseHoldsOfMissingItems( array( array( $first, $gone ) ) ) );

		$this->assertQueryCount( 1, $this->outboxInserts( $log ), 'Outbox inserts of releaseHoldsOfMissingItems() over two holds' );
		$this->assertEqualsCanonicalizing(
			array(
				array( $expired, 'item_missing', array( $first ), array( 1 ) ),
				array( $live, 'item_missing', array( $first ), array( 2 ) ),
			),
			self::releases( $this->committedHoldEvents( $b, $mark ) )
		);
	}

	/**
	 * Tests that deleting a variant held at two locations stores the release of its hold and the write-off at each location with one insert, the release first.
	 *
	 * Planted violation, shown red and removed: in StockService::deleteVariants(), publish the
	 * write-offs in a call of their own after the releases: the delete sends two inserts.
	 *
	 * @since 0.2.0
	 */
	public function test_deleting_variants_stores_the_releases_and_the_write_offs_with_one_insert(): void {
		$variant = self::variant();
		$second  = $this->plantLocation();
		$b       = $this->secondConnection();

		$this->stockItem( $variant, 3 );
		$this->service->adjust( $variant, $second, 2, LedgerReason::Received, Actor::user( 1 ) );

		$hold = $this->service->hold( array( new HoldLine( $variant, 1 ), new HoldLine( $variant, 1, $second ) ), 600 );
		$mark = $this->outboxMark( $b );
		$log  = $this->captureQueries(
			function () use ( $variant ): void {
				$this->db->transaction(
					function () use ( $variant ): void {
						$this->service->deleteVariants( array( $variant ), Actor::user( 4 ) );
					}
				);
			}
		);

		$this->assertQueryCount( 1, $this->outboxInserts( $log ), 'Outbox inserts of deleteVariants()' );
		$this->assertSame( array( StockReservationReleased::eventName(), StockAdjusted::eventName(), StockAdjusted::eventName() ), $this->committedNames( $b, $mark ), 'The release, then the write-off at each location.' );
		$this->assertSame( array( array( $hold->holdGroup, 'variant_deleted', array( $variant ), array( 2 ) ) ), self::releases( $this->committedHoldEvents( $b, $mark ) ) );
	}

	/**
	 * Tests that every path that holds or gives back stock stores its rows inside its unit of work: the unit's own connection counts them, connection B sees none, and B sees them all once the unit commits.
	 *
	 * Planted violations, each shown red and removed: store the events through afterCommit(), in a
	 * transaction of their own, in StockService::holdInside(): the hold counts no row inside its
	 * unit; in publishReleases(): neither the release, the release of variants, the delete nor the
	 * repair does; at deleteVariants()'s call of it: the delete does not; in reclaimExpired(): the
	 * reclaim does not.
	 *
	 * @since 0.2.0
	 */
	public function test_every_path_stores_its_rows_inside_its_unit_of_work(): void {
		$b        = $this->secondConnection();
		$reserved = self::variant();
		$released = self::variant();
		$trashed  = self::variant();
		$expired  = self::variant();
		$deleted  = self::variant();
		$missing  = self::variant();
		$gone     = $this->plantLocation();

		foreach ( array( $reserved, $released, $trashed, $expired, $deleted, $missing ) as $variant ) {
			$this->stockItem( $variant, 2 );
		}

		$hold = $this->service->hold( array( new HoldLine( $released, 1 ) ), 600 );

		$this->service->hold( array( new HoldLine( $trashed, 1 ) ), 600 );
		$this->service->hold( array( new HoldLine( $deleted, 1 ) ), 600 );
		$this->plantHold( $expired, 1, -60 );
		$this->plantHold( $missing, 1, 600, null, $gone );

		$this->assertSame(
			array(
				'hold'                       => array( 1, 0, 1 ),
				'release'                    => array( 1, 0, 1 ),
				'releaseVariants'            => array( 1, 0, 1 ),
				'reclaimExpired'             => array( 1, 0, 1 ),
				'deleteVariants'             => array( 2, 0, 2 ),
				'releaseHoldsOfMissingItems' => array( 1, 0, 1 ),
			),
			array(
				'hold'                       => $this->rowsAroundTheCommit( $b, fn() => $this->service->hold( array( new HoldLine( $reserved, 1 ) ), 600 ) ),
				'release'                    => $this->rowsAroundTheCommit( $b, fn() => $this->service->release( $hold->holdGroup, 'payment_declined' ) ),
				'releaseVariants'            => $this->rowsAroundTheCommit( $b, fn() => $this->service->releaseVariants( array( $trashed ), 'product_trashed' ) ),
				'reclaimExpired'             => $this->rowsAroundTheCommit( $b, fn() => $this->service->reclaimExpired( $expired, $this->defaultLocation ) ),
				'deleteVariants'             => $this->rowsAroundTheCommit( $b, fn() => $this->service->deleteVariants( array( $deleted ), Actor::user( 4 ) ) ),
				'releaseHoldsOfMissingItems' => $this->rowsAroundTheCommit( $b, fn() => $this->service->releaseHoldsOfMissingItems( array( array( $missing, $gone ) ) ) ),
			),
			'Each path\'s rows: on the unit\'s own connection before the commit, on connection B before it, on connection B after it.'
		);
	}

	/**
	 * Tests that the largest hold a cart can ask for, every line at three locations, is stored under the outbox's cap, and prints its size: the cap was already checked for this event before it was stored.
	 *
	 * @since 0.2.0
	 */
	public function test_the_largest_hold_of_a_cart_is_stored_under_the_cap(): void {
		$locations = array( $this->defaultLocation, $this->plantLocation(), $this->plantLocation() );
		$lines     = array();
		$b         = $this->secondConnection();

		for ( $line = 0; $line < Cart::MAX_LINES; ++$line ) {
			$variant = self::variant();

			foreach ( $locations as $location ) {
				$this->plantItem( $variant, 9, 0, 0, true, $location );

				$lines[] = new HoldLine( $variant, 3, $location );
			}
		}

		$this->service->hold( $lines, 600 );

		$size = strlen( (string) $b->fetchValue( sprintf( "SELECT payload_json FROM `%s` WHERE event_name = '%s'", $this->table( OutboxTable::NAME ), StockReserved::eventName() ) ) );

		fwrite( STDOUT, sprintf( "\nThe reservation of %d lines at %d locations: %d bytes stored, of %d allowed.\n", Cart::MAX_LINES, count( $locations ), $size, Publisher::DEFAULT_PAYLOAD_CAP_BYTES ) );

		$this->assertGreaterThan( 0, $size, 'The reservation is stored.' );
		$this->assertLessThan( Publisher::DEFAULT_PAYLOAD_CAP_BYTES, $size );
		$this->assertCount( Cart::MAX_LINES * count( $locations ), Outbox::decode( (string) $b->fetchValue( sprintf( "SELECT payload_json FROM `%s` WHERE event_name = '%s'", $this->table( OutboxTable::NAME ), StockReserved::eventName() ) ) )['p']['location_ids'] ?? array() );
	}

	/**
	 * Returns the outbox inserts of a query log.
	 *
	 * @since 0.2.0
	 *
	 * @param QueryLog $log The log.
	 * @return QueryLog The inserts into the outbox table.
	 */
	private function outboxInserts( QueryLog $log ): QueryLog {
		return $log->matching( '/^INSERT INTO `' . preg_quote( $this->table( OutboxTable::NAME ), '/' ) . '`/' );
	}

	/**
	 * Runs one path in a unit of work and counts the outbox rows it stored, with one statement on both connections.
	 *
	 * @since 0.2.0
	 *
	 * @param SecondConnection  $b    Connection B.
	 * @param callable(): mixed $path The path, which joins the unit of work.
	 * @return list<int> The rows on the unit's own connection before the commit, on connection B before it, and on connection B after it.
	 *
	 * @phpstan-impure
	 */
	private function rowsAroundTheCommit( SecondConnection $b, callable $path ): array {
		$count = sprintf( 'SELECT COUNT(*) FROM `%s` WHERE id > %d', $this->table( OutboxTable::NAME ), $this->outboxMark( $b ) );
		$rows  = array();

		$this->db->transaction(
			function () use ( $b, $path, $count, &$rows ): void {
				$path();

				$rows[] = (int) $this->db->fetchValue( $count );
				$rows[] = (int) $b->fetchValue( $count );
			}
		);

		$rows[] = (int) $b->fetchValue( $count );

		return $rows;
	}

	/**
	 * Reads the names of the committed outbox rows after a mark, in id order, as connection B sees them.
	 *
	 * @since 0.2.0
	 *
	 * @param SecondConnection $b    Connection B.
	 * @param int              $mark Only rows after this id.
	 * @return list<string> The names.
	 *
	 * @phpstan-impure
	 */
	private function committedNames( SecondConnection $b, int $mark ): array {
		$names = (string) $b->fetchValue( sprintf( "SELECT GROUP_CONCAT( event_name ORDER BY id SEPARATOR ',' ) FROM `%s` WHERE id > %d", $this->table( OutboxTable::NAME ), $mark ) );

		return '' === $names ? array() : explode( ',', $names );
	}

	/**
	 * Returns each release's hold, reason, variants and units.
	 *
	 * @since 0.2.0
	 *
	 * @param list<StockReserved|StockReservationReleased> $events The events rebuilt from the outbox.
	 * @return list<array{0: string, 1: string, 2: list<int>, 3: list<int>}> One entry per release.
	 */
	private static function releases( array $events ): array {
		$releases = array();

		foreach ( $events as $event ) {
			self::assertInstanceOf( StockReservationReleased::class, $event );

			$releases[] = array( $event->holdId, $event->reason, $event->variantIds, $event->quantities );
		}

		return $releases;
	}
}
