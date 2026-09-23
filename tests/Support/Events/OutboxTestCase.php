<?php
/**
 * OutboxTestCase: the base of the tests that publish, store and deliver events against real tables
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support\Events;

use SEOCart\Platform\Database\Database;
use SEOCart\Platform\Database\LockMode;
use SEOCart\Platform\Database\LockService;
use SEOCart\Platform\Database\Schema\DdlGenerator;
use SEOCart\Platform\Database\Schema\PlatformTables;
use SEOCart\Platform\Database\Schema\SchemaVerifier;
use SEOCart\Platform\Database\SchemaOperations;
use SEOCart\Platform\Events\EventCatalog;
use SEOCart\Platform\Events\EventEnvelope;
use SEOCart\Platform\Events\HookBridge;
use SEOCart\Platform\Events\Migrations\CreateOutboxMigration;
use SEOCart\Platform\Events\Outbox;
use SEOCart\Platform\Events\OutboxDrainer;
use SEOCart\Platform\Events\OutboxTable;
use SEOCart\Platform\Events\Publisher;
use SEOCart\Platform\Logging\CorrelationId;
use SEOCart\Support\Events\DomainEvent;
use SEOCart\Tests\Support\DatabaseTestCase;
use SEOCart\Tests\Support\Doubles\RecordingWake;
use SEOCart\Tests\Support\Doubles\SequentialIdGenerator;
use SEOCart\Tests\Support\SecondConnection;
use SEOCart\Tests\Support\SecondDatabase;

/**
 * A DatabaseTestCase with the `outbox` and `locks` tables, and the event classes wired as the kernel wires them.
 *
 * Owns one fact: how an events test gets a publisher, a bridge and drainers over real tables.
 * The outbox table is created by its own migration, through SchemaOperations, in set_up();
 * the base tear_down() drops it. The wake is a RecordingWake, so nothing drains unless a test
 * calls drain(). Every report of the bridge and the drainers lands in `$this->reports`, next
 * to the database's. Drainers take the drain lock in table mode, so a second drainer over a
 * SecondDatabase contends for the same row.
 *
 * listen() adds a listener that appends one entry to `$this->fired` per call: the action, the
 * outbox id, the attempt, the correlation id on the envelope, the correlation id in force
 * inside the listener, and the aggregate id.
 *
 * @since 0.1.0
 */
abstract class OutboxTestCase extends DatabaseTestCase {

	/**
	 * The fixture event catalog.
	 *
	 * @since 0.1.0
	 *
	 * @var EventCatalog
	 */
	protected EventCatalog $catalog;

	/**
	 * The process's correlation id, minting sequential ids.
	 *
	 * @since 0.1.0
	 *
	 * @var CorrelationId
	 */
	protected CorrelationId $correlation;

	/**
	 * The bridge every publisher and drainer of the test shares.
	 *
	 * @since 0.1.0
	 *
	 * @var HookBridge
	 */
	protected HookBridge $bridge;

	/**
	 * The outbox rows, over `$this->db`.
	 *
	 * @since 0.1.0
	 *
	 * @var Outbox
	 */
	protected Outbox $outbox;

	/**
	 * The publisher's wake, which never drains.
	 *
	 * @since 0.1.0
	 *
	 * @var RecordingWake
	 */
	protected RecordingWake $wake;

	/**
	 * The publisher, over `$this->db`.
	 *
	 * @since 0.1.0
	 *
	 * @var Publisher
	 */
	protected Publisher $publisher;

	/**
	 * One entry per listener call, in order.
	 *
	 * @since 0.1.0
	 *
	 * @var list<array{hook: string, outbox_id: int|null, attempt: int, correlation_id: string|null, current: string, thing: int}>
	 */
	protected array $fired = array();

	/**
	 * The second databases this test opened.
	 *
	 * @since 0.1.0
	 *
	 * @var list<SecondDatabase>
	 */
	private array $secondDatabases = array();

	/**
	 * Creates the tables and the event classes.
	 *
	 * @since 0.1.0
	 */
	public function set_up(): void {
		parent::set_up();

		$operations = new SchemaOperations( $this->db, new DdlGenerator(), new SchemaVerifier( $this->db ) );

		( new CreateOutboxMigration() )->up( $operations );
		$operations->createTable( PlatformTables::locks() );

		$this->fired       = array();
		$this->catalog     = self::fixtureCatalog();
		$this->correlation = new CorrelationId( new SequentialIdGenerator() );
		$this->bridge      = new HookBridge( $this->reporter() );
		$this->outbox      = new Outbox( $this->db );
		$this->wake        = new RecordingWake();
		$this->publisher   = new Publisher( $this->db, $this->outbox, $this->bridge, $this->catalog, $this->correlation, $this->wake );
	}

	/**
	 * Closes the second databases.
	 *
	 * @since 0.1.0
	 */
	public function tear_down(): void {
		foreach ( $this->secondDatabases as $second ) {
			$second->close();
		}

		$this->secondDatabases = array();

		parent::tear_down();
	}

	/**
	 * Returns the catalog of the fixture events.
	 *
	 * @since 0.1.0
	 *
	 * @return EventCatalog The catalog.
	 */
	protected static function fixtureCatalog(): EventCatalog {
		return new EventCatalog( array( ThingHappened::class, ThingNoticed::class, MutableThing::class, ThrowsOnHydrate::class, AnyPayload::class ) );
	}

	/**
	 * Builds a drainer over a connection, sharing the test's bridge, catalog and correlation id.
	 *
	 * @since 0.1.0
	 *
	 * @param Database|null $db    Optional. The connection. Default `$this->db`.
	 * @param callable|null $clock Optional. The drainer's monotonic clock in nanoseconds. Default hrtime().
	 * @return OutboxDrainer The drainer, taking the drain lock in table mode.
	 */
	protected function drainer( ?Database $db = null, ?callable $clock = null ): OutboxDrainer {
		$db ??= $this->db;

		return new OutboxDrainer( $db, new Outbox( $db ), $this->bridge, $this->catalog, new LockService( $db, LockMode::Table, $this->sleeper() ), $this->correlation, $this->reporter(), null, $clock );
	}

	/**
	 * Opens another runner's connection. It is closed in tear_down().
	 *
	 * @since 0.1.0
	 *
	 * @return SecondDatabase The connection.
	 */
	protected function secondDatabase(): SecondDatabase {
		$second                  = new SecondDatabase( $this->reporter() );
		$this->secondDatabases[] = $second;

		return $second;
	}

	/**
	 * Adds a listener that records each call in `$this->fired`, then runs an optional callback.
	 *
	 * @since 0.1.0
	 *
	 * @param string        $hook     The action.
	 * @param int           $priority Optional. The priority. Default 10.
	 * @param callable|null $then     Optional. Runs after recording, with the event and the envelope.
	 * @return \Closure The listener, as registered.
	 */
	protected function listen( string $hook, int $priority = 10, ?callable $then = null ): \Closure {
		$listener = function ( DomainEvent $event, EventEnvelope $envelope ) use ( $hook, $then ): void {
			$this->fired[] = array(
				'hook'           => $hook,
				'outbox_id'      => $envelope->outboxId,
				'attempt'        => $envelope->attempt,
				'correlation_id' => $envelope->correlationId,
				'current'        => $this->correlation->current(),
				'thing'          => $event->aggregateId(),
			);

			if ( null !== $then ) {
				$then( $event, $envelope );
			}
		};

		add_action( $hook, $listener, $priority, 2 );

		return $listener;
	}

	/**
	 * Returns the full name of the outbox table.
	 *
	 * @since 0.1.0
	 *
	 * @return string The name.
	 */
	protected function outboxTable(): string {
		return $this->db->table( OutboxTable::NAME );
	}

	/**
	 * Reads one outbox row as connection B sees it, which is what is committed.
	 *
	 * @since 0.1.0
	 *
	 * @param SecondConnection $b  Connection B.
	 * @param int              $id The row's id.
	 * @return array<string, string|null>|null The row, or null.
	 */
	protected function outboxRow( SecondConnection $b, int $id ): ?array {
		return $b->fetchRow( sprintf( 'SELECT * FROM `%s` WHERE id = %d', $this->outboxTable(), $id ) );
	}

	/**
	 * Lists the ids of the committed outbox rows that match a condition.
	 *
	 * @since 0.1.0
	 *
	 * @param SecondConnection $b     Connection B.
	 * @param string           $where Optional. The condition. Default every row.
	 * @return list<int> The ids, ascending.
	 */
	protected function outboxIds( SecondConnection $b, string $where = '1 = 1' ): array {
		$ids = (string) $b->fetchValue( sprintf( 'SELECT GROUP_CONCAT( id ORDER BY id ) FROM `%s` WHERE %s', $this->outboxTable(), $where ) );

		return '' === $ids ? array() : array_map( 'intval', explode( ',', $ids ) );
	}

	/**
	 * Inserts an outbox row directly through B, as an earlier request, a crash or another release would have left it.
	 *
	 * @since 0.1.0
	 *
	 * @param SecondConnection     $b           Connection B.
	 * @param string               $eventName   The stored event name.
	 * @param string               $payloadJson The stored payload.
	 * @param array<string, mixed> $expressions Optional. Column => SQL expression, overriding the defaults:
	 *                                          pending, due one second ago, attempts 0, created now.
	 * @return int The row's id.
	 */
	protected function plantRow( SecondConnection $b, string $eventName, string $payloadJson, array $expressions = array() ): int {
		global $wpdb;

		$columns = array_merge(
			array(
				'event_name'     => $wpdb->prepare( '%s', $eventName ),
				'aggregate_type' => "'thing'",
				'aggregate_id'   => '1',
				'payload_json'   => $wpdb->prepare( '%s', $payloadJson ),
				'correlation_id' => "'00000000-0000-7000-8000-00000000abcd'",
				'state'          => "'pending'",
				'available_at'   => 'UTC_TIMESTAMP(6) - INTERVAL 1 SECOND',
				'attempts'       => '0',
				'created_at'     => 'UTC_TIMESTAMP(6)',
			),
			$expressions
		);

		$b->query( sprintf( 'INSERT INTO `%s` ( %s ) VALUES ( %s )', $this->outboxTable(), implode( ', ', array_keys( $columns ) ), implode( ', ', $columns ) ) );

		return (int) $b->fetchValue( 'SELECT LAST_INSERT_ID()' );
	}

	/**
	 * Inserts an event as the publisher would have stored it, directly through B.
	 *
	 * @since 0.1.0
	 *
	 * @param SecondConnection     $b           Connection B.
	 * @param DomainEvent          $event       The event.
	 * @param array<string, mixed> $expressions Optional. Column => SQL expression, as for plantRow().
	 * @return int The row's id.
	 */
	protected function plantEvent( SecondConnection $b, DomainEvent $event, array $expressions = array() ): int {
		return $this->plantRow( $b, $event::eventName(), Outbox::encode( $event, Publisher::DEFAULT_PAYLOAD_CAP_BYTES ), $expressions + array( 'aggregate_id' => (string) $event->aggregateId() ) );
	}

	/**
	 * Returns the contexts of every report with a code.
	 *
	 * @since 0.1.0
	 *
	 * @param string $code The report code.
	 * @return list<array<string, mixed>> The contexts, in order.
	 */
	protected function reportsOf( string $code ): array {
		return array_values(
			array_map(
				static fn( array $report ): array => $report['context'],
				array_filter( $this->reports, static fn( array $report ): bool => $code === $report['code'] )
			)
		);
	}
}
