<?php
/**
 * OrderTestCase: the base of the tests that place and change orders against real tables
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support\Order;

use SEOCart\Order\Application\Orders;
use SEOCart\Order\Domain\InsertedOrder;
use SEOCart\Order\Domain\NewOrder;
use SEOCart\Order\Domain\OrderStatus;
use SEOCart\Order\Domain\OrderStatusRegistry;
use SEOCart\Order\Domain\PaymentStatus;
use SEOCart\Order\Infrastructure\Migrations\CreateOrderTables;
use SEOCart\Order\Infrastructure\MysqlConversionContexts;
use SEOCart\Order\Infrastructure\MysqlOrderRepository;
use SEOCart\Order\Infrastructure\OrderStatements;
use SEOCart\Order\Infrastructure\OrderTables;
use SEOCart\Order\Infrastructure\SequenceOrderNumberGenerator;
use SEOCart\Order\Infrastructure\WordPressAccessKeys;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Platform\Database\Database;
use SEOCart\Platform\Database\Schema\DdlGenerator;
use SEOCart\Platform\Database\Schema\SchemaVerifier;
use SEOCart\Platform\Database\SchemaOperations;
use SEOCart\Platform\Events\EventCatalog;
use SEOCart\Platform\Events\HookBridge;
use SEOCart\Platform\Events\Migrations\CreateOutboxMigration;
use SEOCart\Platform\Events\Outbox;
use SEOCart\Platform\Events\OutboxTable;
use SEOCart\Platform\Events\Publisher;
use SEOCart\Platform\Kernel\Modules;
use SEOCart\Platform\Logging\CorrelationId;
use SEOCart\Support\IdGenerator;
use SEOCart\Tests\Support\DatabaseTestCase;
use SEOCart\Tests\Support\Doubles\FrozenClock;
use SEOCart\Tests\Support\Doubles\RecordingWake;
use SEOCart\Tests\Support\Doubles\SequentialIdGenerator;
use SEOCart\Tests\Support\RunningProbe;
use SEOCart\Tests\Support\SecondConnection;
use SEOCart\Tests\Support\SecondDatabase;

/**
 * A DatabaseTestCase with the order tables and the outbox, and the order service wired as the kernel wires it.
 *
 * Owns one fact: how an order test gets real tables, a service over them, and a second runner.
 * The tables are created by their own migrations in set_up() and dropped by the base
 * tear_down(), so a test passes alone, in any order and any number of times. The service
 * publishes through the real publisher, so an outbox event is a row; the wake never drains.
 *
 * The second runner is either connection B sending the module's own statements, prepared from
 * their public constants (raw()), so a change to a statement changes B's copy; or a second
 * service over a SecondDatabase, when B must run the plugin's code. Interleavings are set by
 * barriers, never pauses: beforeStatement() runs B's side at the moment connection A is about to
 * send a statement of a given shape (shapeOf()), and awaitWaiting() asks the server whether B is
 * waiting.
 *
 * @since 0.1.0
 */
abstract class OrderTestCase extends DatabaseTestCase {

	/**
	 * The instant the service's clock shows.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	protected const NOW = '2026-09-26 12:00:00';

	/**
	 * The service over `$this->db`.
	 *
	 * @since 0.1.0
	 *
	 * @var Orders
	 */
	protected Orders $orders;

	/**
	 * The ids the service over `$this->db` mints.
	 *
	 * @since 0.1.0
	 *
	 * @var SequentialIdGenerator
	 */
	protected SequentialIdGenerator $ids;

	/**
	 * The correlation id every service of the test shares.
	 *
	 * @since 0.1.0
	 *
	 * @var CorrelationId
	 */
	protected CorrelationId $correlation;

	/**
	 * The bridge every publisher of the test shares.
	 *
	 * @since 0.1.0
	 *
	 * @var HookBridge
	 */
	private HookBridge $bridge;

	/**
	 * The second databases this test opened.
	 *
	 * @since 0.1.0
	 *
	 * @var list<SecondDatabase>
	 */
	private array $secondDatabases = array();

	/**
	 * Creates the tables and the service.
	 *
	 * @since 0.1.0
	 */
	public function set_up(): void {
		parent::set_up();

		$operations = new SchemaOperations( $this->db, new DdlGenerator(), new SchemaVerifier( $this->db ) );

		( new CreateOutboxMigration() )->up( $operations );
		( new CreateOrderTables() )->up( $operations );

		$this->correlation = new CorrelationId( new SequentialIdGenerator( 900000 ) );
		$this->bridge      = new HookBridge( $this->reporter() );
		$this->ids         = new SequentialIdGenerator( 1 );
		$this->orders      = $this->ordersOver( $this->db, $this->ids );
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
	 * Builds the order service over a connection, as the kernel builds it.
	 *
	 * @since 0.1.0
	 *
	 * @param Database    $db  The connection.
	 * @param IdGenerator $ids The ids it mints: a second runner needs its own range.
	 * @return Orders The service.
	 */
	protected function ordersOver( Database $db, IdGenerator $ids ): Orders {
		$statements = new OrderStatements( $db );
		$publisher  = new Publisher( $db, new Outbox( $db ), $this->bridge, new EventCatalog( Modules::EVENT_CLASSES ), $this->correlation, new RecordingWake() );

		return new Orders(
			new MysqlOrderRepository( $statements, $ids ),
			new SequenceOrderNumberGenerator( $statements ),
			new WordPressAccessKeys(),
			new MysqlConversionContexts( $statements, $ids ),
			new OrderStatusRegistry(),
			$db,
			$publisher,
			$ids,
			FrozenClock::at( self::NOW ),
			$this->correlation
		);
	}

	/**
	 * Opens a second runner of the plugin's code: the service over its own connection. It is closed in tear_down().
	 *
	 * @since 0.1.0
	 *
	 * @return array{0: Orders, 1: Database} The service, and the connection it runs on.
	 */
	protected function secondOrders(): array {
		$second                  = new SecondDatabase( $this->reporter() );
		$this->secondDatabases[] = $second;

		return array( $this->ordersOver( $second->db(), new SequentialIdGenerator( 500000 ) ), $second->db() );
	}

	/**
	 * Places an order through the service, in a transaction of its own.
	 *
	 * @since 0.1.0
	 *
	 * @param NewOrder|null $order Optional. The document. Default the two-line fixture.
	 * @param Actor|null    $actor Optional. Who places it. Default a visitor.
	 * @return InsertedOrder The order.
	 */
	protected function place( ?NewOrder $order = null, ?Actor $actor = null ): InsertedOrder {
		return $this->db->transaction( fn(): InsertedOrder => $this->orders->insert( $order ?? NewOrders::forTwoLines(), $actor ?? Actor::user( 0 ) ) );
	}

	/**
	 * Puts an order in a status and a payment status directly, as another path would have left it.
	 *
	 * @since 0.1.0
	 *
	 * @param int           $orderId       The order.
	 * @param OrderStatus   $status        The status.
	 * @param PaymentStatus $paymentStatus The payment status.
	 */
	protected function plantStatus( int $orderId, OrderStatus $status, PaymentStatus $paymentStatus ): void {
		$this->db->execute( 'UPDATE %i SET status = %s, payment_status = %s WHERE id = %d', $this->table( OrderTables::ORDERS ), $status->value, $paymentStatus->value, $orderId );
	}

	/**
	 * Reads an order's status and payment status as connection B sees them, which is what is committed.
	 *
	 * @since 0.1.0
	 *
	 * @param SecondConnection $b       Connection B.
	 * @param int              $orderId The order.
	 * @return array{status: string, payment_status: string}|null The statuses, or null when there is no order.
	 *
	 * @phpstan-impure
	 */
	protected function committedStatus( SecondConnection $b, int $orderId ): ?array {
		$row = $b->fetchRow( sprintf( 'SELECT status, payment_status FROM `%s` WHERE id = %d', $this->table( OrderTables::ORDERS ), $orderId ) );

		return null === $row ? null : array(
			'status'         => (string) $row['status'],
			'payment_status' => (string) $row['payment_status'],
		);
	}

	/**
	 * Reads an order's events, oldest first.
	 *
	 * @since 0.1.0
	 *
	 * @param int $orderId The order.
	 * @return list<string> Each event as `machine:from>to:reason`.
	 */
	protected function eventsOf( int $orderId ): array {
		return array_map(
			static fn( array $row ): string => $row['machine'] . ':' . $row['from_status'] . '>' . $row['to_status'] . ':' . $row['reason'],
			$this->db->fetchAll( 'SELECT machine, from_status, to_status, reason FROM %i WHERE order_id = %d ORDER BY id', $this->table( OrderTables::EVENTS ), $orderId )
		);
	}

	/**
	 * Counts the outbox rows of one event.
	 *
	 * @since 0.1.0
	 *
	 * @param string $eventName The event's name.
	 * @return int The count.
	 */
	protected function outboxRows( string $eventName ): int {
		return (int) $this->db->fetchValue( 'SELECT COUNT(*) FROM %i WHERE event_name = %s', $this->table( OutboxTable::NAME ), $eventName );
	}

	/**
	 * Returns a statement of the order module prepared for connection B, from its own constant.
	 *
	 * @since 0.1.0
	 *
	 * @param string $statement A constant of the order module.
	 * @param mixed  ...$values Its values.
	 * @return string The statement, ready to send.
	 */
	protected function raw( string $statement, mixed ...$values ): string {
		global $wpdb;

		list( $sql, $arguments ) = OrderStatements::expand( $statement, $values, fn( string $name ): string => $this->table( $name ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The statement is the module's constant, expanded; this is its prepare step.
		return (string) $wpdb->prepare( $sql, ...$arguments );
	}

	/**
	 * Returns a pattern matching exactly the statements a constant produces.
	 *
	 * @since 0.1.0
	 *
	 * @param string $statement A constant of the order module.
	 * @return string A regular expression over the whole statement.
	 */
	protected static function shapeOf( string $statement ): string {
		$pattern = (string) preg_replace_callback(
			'/\{[a-z_]+\}|%[dsi]|[^{%]+|[{%]/',
			static function ( array $part ): string {
				return match ( true ) {
					'{list}' === $part[0]                                      => "(?:NULL|(?:-?\d+|'[^']*')(?:, (?:-?\d+|'[^']*'))*)",
					str_starts_with( $part[0], '{' ) && strlen( $part[0] ) > 1 => '`[^`]+`',
					'%d' === $part[0]                                          => '-?\d+',
					'%s' === $part[0]                                          => "'[^']*'",
					'%i' === $part[0]                                          => '`[^`]+`',
					default                                                    => preg_quote( $part[0], '/' ),
				};
			},
			$statement
		);

		return '/^' . $pattern . '$/';
	}

	/**
	 * Counts committed outbox rows of one event, as connection B sees them.
	 *
	 * @since 0.1.0
	 *
	 * @param SecondConnection $b         Connection B.
	 * @param string           $eventName The event's name.
	 * @return int The count.
	 *
	 * @phpstan-impure
	 */
	protected function committedEvents( SecondConnection $b, string $eventName ): int {
		return (int) $b->fetchValue( sprintf( "SELECT COUNT(*) FROM `%s` WHERE event_name = '%s'", $this->table( OutboxTable::NAME ), $eventName ) );
	}

	/**
	 * Returns once the server shows a number of connections waiting on a row lock in statements that name a table, and fails the test otherwise.
	 *
	 * Asks the process list, as awaitWaiting() does for one connection; between two looks it
	 * watches the probes, never a pause: a probe that ends first was never blocked, and fails the
	 * test with what it printed. The deadline fails the test too. An INSERT waiting for a row lock
	 * shows the state `update`, and an UPDATE the state `updating`.
	 *
	 * @since 0.1.0
	 *
	 * @param string         $table  The unprefixed name of the table the statements name.
	 * @param int            $count  How many connections must be waiting.
	 * @param RunningProbe[] $probes The probes that should be waiting.
	 */
	protected function awaitProbesWaiting( string $table, int $count, array $probes ): void {
		$observer = $this->secondConnection();
		$query    = sprintf( "SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE COMMAND = 'Query' AND STATE IN ( 'update', 'updating' ) AND INFO LIKE '%%%s%%'", $this->table( $table ) );
		$deadline = hrtime( true ) + 60000 * 1000000;

		while ( true ) {
			$waiting = (int) $observer->fetchValue( $query );

			foreach ( $probes as $probe ) {
				if ( $probe->watch( 1 ) ) {
					$this->fail( sprintf( "A probe ended before %d allocations were waiting (%d were).\nIts report: %s\nIts output:\n%s", $count, $waiting, $probe->reportSoFar(), $probe->output() ) );
				}
			}

			if ( $waiting >= $count ) {
				return;
			}

			if ( hrtime( true ) >= $deadline ) {
				$this->fail( sprintf( 'The server showed %d of %d allocations waiting within 60 seconds.', $waiting, $count ) );
			}
		}
	}

	/**
	 * Returns a plugin table's full name.
	 *
	 * @since 0.1.0
	 *
	 * @param string $name The unprefixed name.
	 * @return string The full name.
	 */
	protected function table( string $name ): string {
		return $this->db->table( $name );
	}
}
