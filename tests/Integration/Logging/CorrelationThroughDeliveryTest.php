<?php
/**
 * Tests that a line written while an event is delivered carries the id of the request that stored the event
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Logging;

use SEOCart\Platform\Database\LockMode;
use SEOCart\Platform\Database\LockService;
use SEOCart\Platform\Database\Schema\DdlGenerator;
use SEOCart\Platform\Database\Schema\SchemaVerifier;
use SEOCart\Platform\Database\SchemaOperations;
use SEOCart\Platform\DataRegistry\OwnedData;
use SEOCart\Platform\Events\DrainOptions;
use SEOCart\Platform\Events\EventEnvelope;
use SEOCart\Platform\Events\Outbox;
use SEOCart\Platform\Events\OutboxDrainer;
use SEOCart\Platform\Events\ReportCode;
use SEOCart\Platform\Logging\FallbackLog;
use SEOCart\Platform\Logging\Level;
use SEOCart\Platform\Logging\Logger;
use SEOCart\Platform\Logging\LogsTable;
use SEOCart\Platform\Logging\Migrations\CreateLogsMigration;
use SEOCart\Platform\Logging\Redactor;
use SEOCart\Platform\Logging\Reporter;
use SEOCart\Support\Events\DomainEvent;
use SEOCart\Tests\Support\Events\OutboxTestCase;
use SEOCart\Tests\Support\Events\ThingHappened;
use SEOCart\Tests\Support\Events\ThrowsOnHydrate;
use SEOCart\Tests\Support\Logging\DeclaredFields;

/**
 * The correlation id travels request → outbox → listener → log line, and a job's handler runs under the id its payload carries.
 *
 * The request that stores an event has one id; a later request delivers it with an id of its
 * own. A listener that logs during the delivery writes the storing request's id, and the
 * delivering request's own lines keep theirs. The same holds for a job: its handler runs
 * inside CorrelationId::scoped() with the id the job's payload carries, and scoped() is what
 * this test drives for it.
 *
 * Planted violation: in Logger::line(), stamp the id the logger saw when it was built instead
 * of current() (the delivery's line carries the delivering request's id).
 *
 * @since 0.1.0
 */
final class CorrelationThroughDeliveryTest extends OutboxTestCase {

	/**
	 * The id of the request that stores the event.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const STORING_REQUEST = '0192a3b4-0000-7000-8000-00000000aaaa';

	/**
	 * The id of the request that delivers it.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const DELIVERING_REQUEST = '0192a3b4-0000-7000-8000-00000000bbbb';

	/**
	 * The fallback lines of the logger.
	 *
	 * @since 0.1.0
	 *
	 * @var list<string>
	 */
	private array $fallback = array();

	/**
	 * Creates the log table as well.
	 *
	 * @since 0.1.0
	 */
	public function set_up(): void {
		parent::set_up();

		( new CreateLogsMigration() )->up( new SchemaOperations( $this->db, new DdlGenerator(), new SchemaVerifier( $this->db ) ) );

		$this->fallback = array();
	}

	/**
	 * Builds a FallbackLog that records its lines, and runs nothing at the end of the process.
	 *
	 * @since 0.1.0
	 *
	 * @return FallbackLog The fallback.
	 */
	private function fallbackLog(): FallbackLog {
		return new FallbackLog(
			function ( string $line ): void {
				$this->fallback[] = $line;
			},
			static function (): void {}
		);
	}

	/**
	 * Tests that the listener's line carries the storing request's id, and the delivering request's lines keep their own.
	 *
	 * @since 0.1.0
	 */
	public function test_a_line_logged_during_delivery_carries_the_storing_requests_id(): void {
		$logger = new Logger(
			$this->db,
			$this->correlation,
			Redactor::fromDeclarations( OwnedData::registry(), ...DeclaredFields::production() ),
			Level::Debug,
			null,
			$this->fallbackLog()
		);

		$this->correlation->accept( self::STORING_REQUEST );
		$this->db->transaction( fn() => $this->publisher->publish( new ThingHappened( 7 ) ) );

		$this->correlation->accept( self::DELIVERING_REQUEST );
		$logger->info( 'test.before_delivery', 'The delivering request logs first.' );

		$this->listen(
			'seocart_' . ThingHappened::eventName(),
			10,
			static function () use ( $logger ): void {
				$logger->info( 'test.listener', 'A listener logs while the event is delivered.' );
			}
		);

		$this->drainer()->drain( DrainOptions::command() );

		$logger->info( 'test.after_delivery', 'The delivering request logs again.' );

		$this->correlation->scoped( self::STORING_REQUEST, static fn() => $logger->info( 'test.job', 'A job handler runs under the id its payload carries.' ) );

		$this->assertCount( 1, $this->fired, 'The listener ran.' );
		$this->assertSame(
			array(
				'test.before_delivery' => self::DELIVERING_REQUEST,
				'test.listener'        => self::STORING_REQUEST,
				'test.after_delivery'  => self::DELIVERING_REQUEST,
				'test.job'             => self::STORING_REQUEST,
			),
			array_column( $this->db->fetchAll( 'SELECT machine_code, correlation_id FROM %i ORDER BY id', $this->db->table( LogsTable::NAME ) ), 'correlation_id', 'machine_code' )
		);
		$this->assertSame( array(), $this->fallback );
	}

	/**
	 * Tests that the drainer's own reports about a row carry the id stored with the row, not the delivering request's.
	 *
	 * Four planted rows, stored under one request's id: an event no class is registered for, a
	 * payload newer than its class, an event whose class cannot be rebuilt, and a row whose
	 * deliveries were all started and none finished. The drainer reports through the logger,
	 * as the kernel binds it, while another request drains.
	 *
	 * Planted violation: in OutboxDrainer::parkUndeliverable() and retryOrPark(), report without
	 * scoped() (every line carries the delivering request's id).
	 *
	 * @since 0.1.0
	 */
	public function test_the_drainers_reports_about_a_row_carry_the_rows_correlation_id(): void {
		$b      = $this->secondConnection();
		$stored = '00000000-0000-7000-8000-00000000abcd';
		$logger = new Logger(
			$this->db,
			$this->correlation,
			Redactor::fromDeclarations( OwnedData::registry(), ...DeclaredFields::production() ),
			Level::Debug,
			null,
			$this->fallbackLog()
		);

		$this->plantRow( $b, 'gone', '{"v":1,"at":"2026-09-23T10:00:00.000000+00:00","p":{}}' );
		$this->plantRow( $b, ThingHappened::eventName(), '{"v":99,"at":"2026-09-23T10:00:00.000000+00:00","p":{"thing_id":3,"note":"x","line_ids":[]}}' );
		$this->plantEvent( $b, new ThrowsOnHydrate( 1 ) );
		$this->plantEvent( $b, new ThingHappened( 4 ), array( 'attempts' => '5' ) );

		$this->correlation->accept( self::DELIVERING_REQUEST );

		$drainer = new OutboxDrainer( $this->db, new Outbox( $this->db ), $this->bridge, $this->catalog, new LockService( $this->db, LockMode::Table, $this->sleeper() ), $this->correlation, new Reporter( static fn(): Logger => $logger, $this->correlation ) );

		$drainer->drain( DrainOptions::command() );

		$lines = array_column( $this->db->fetchAll( 'SELECT machine_code, correlation_id FROM %i ORDER BY id', $this->db->table( LogsTable::NAME ) ), 'correlation_id', 'machine_code' );

		ksort( $lines );

		$this->assertSame(
			array(
				ReportCode::DispatchFailed->value    => $stored,
				ReportCode::ListenerAbandoned->value => $stored,
				ReportCode::PayloadVersion->value    => $stored,
				ReportCode::UnknownEvent->value      => $stored,
			),
			$lines
		);
		$this->assertSame( self::DELIVERING_REQUEST, $this->correlation->current(), 'The drainer\'s own id is back.' );
		$this->assertSame( array(), $this->fallback );
	}

	/**
	 * Tests that a lost lease on a row is reported under the id stored with the row, on every path that can lose one.
	 *
	 * Four rows, each stored under an id of its own and each drained on its own, lose their
	 * lease: one before its delivery starts; one while its listener runs and then fails, so the
	 * retry finds another token; one on its last attempt, so the park does; and one whose
	 * listener succeeds, so the mark does. Each `events.lease_lost` line carries its row's id.
	 *
	 * Planted violation: in OutboxDrainer::reportLeaseLost(), report without scoped() (every line
	 * carries the delivering request's id).
	 *
	 * @since 0.1.0
	 */
	public function test_a_lost_lease_is_reported_under_the_rows_correlation_id(): void {
		$b      = $this->secondConnection();
		$logger = new Logger(
			$this->db,
			$this->correlation,
			Redactor::fromDeclarations( OwnedData::registry(), ...DeclaredFields::production() ),
			Level::Debug,
			null,
			$this->fallbackLog()
		);
		$steal  = function ( int $row ) use ( $b ): void {
			$b->query( sprintf( "UPDATE `%s` SET claim_token = REPEAT( 'f', 64 ), claimed_until = UTC_TIMESTAMP(6) + INTERVAL 1 HOUR WHERE id = %d", $this->outboxTable(), $row ) );
		};

		/**
		 * What each row's listener does, by outbox id: `fail` or `steal`; any other row is delivered.
		 *
		 * @var array<int, string> $plan
		 */
		$plan = array();

		$this->listen(
			'seocart_' . ThingHappened::eventName(),
			10,
			static function ( DomainEvent $event, EventEnvelope $envelope ) use ( &$plan, $steal ): void {
				$then = $plan[ (int) $envelope->outboxId ] ?? 'deliver';

				if ( 'deliver' === $then ) {
					return;
				}

				$steal( (int) $envelope->outboxId );

				if ( 'fail' === $then ) {
					throw new \RuntimeException( 'The listener failed after the lease was taken over.' );
				}
			}
		);

		$this->correlation->accept( self::DELIVERING_REQUEST );

		$drainer = new OutboxDrainer( $this->db, new Outbox( $this->db ), $this->bridge, $this->catalog, new LockService( $this->db, LockMode::Table, $this->sleeper() ), $this->correlation, new Reporter( static fn(): Logger => $logger, $this->correlation ) );
		$stored  = array();

		// Before the delivery starts: the lease lapses between the drainer's check and the start.
		$start            = $this->plantEvent( $b, new ThingHappened( 1 ), array( 'correlation_id' => "'00000000-0000-7000-8000-0000000000a1'" ) );
		$stored[ $start ] = '00000000-0000-7000-8000-0000000000a1';
		$stall            = function ( $query ) use ( $b, $start, &$stall ) {
			if ( is_string( $query ) && str_contains( $query, 'SET attempts = attempts + 1' ) && str_contains( $query, 'WHERE id = ' . $start . ' ' ) ) {
				remove_filter( 'query', $stall );

				$b->query( sprintf( 'UPDATE `%s` SET claimed_until = UTC_TIMESTAMP(6) - INTERVAL 1 SECOND WHERE id = %d', $this->outboxTable(), $start ) );
			}

			return $query;
		};

		add_filter( 'query', $stall );
		$drainer->drain( DrainOptions::command() );
		remove_filter( 'query', $stall );

		foreach ( array(
			'fail'    => array( '00000000-0000-7000-8000-0000000000a2', '0' ),
			'park'    => array( '00000000-0000-7000-8000-0000000000a3', '4' ),
			'deliver' => array( '00000000-0000-7000-8000-0000000000a4', '0' ),
		) as $then => list( $id, $attempts ) ) {
			$row            = $this->plantEvent(
				$b,
				new ThingHappened( 2 ),
				array(
					'correlation_id' => "'{$id}'",
					'attempts'       => $attempts,
				)
			);
			$stored[ $row ] = $id;
			$plan[ $row ]   = 'deliver' === $then ? 'steal' : 'fail';

			$drainer->drain( DrainOptions::command() );
		}

		$lost = array();

		foreach ( $this->db->fetchAll( 'SELECT correlation_id, context_json FROM %i WHERE machine_code = %s ORDER BY id', $this->db->table( LogsTable::NAME ), ReportCode::LeaseLost->value ) as $line ) {
			$lost[ (int) json_decode( (string) $line['context_json'], true )['outbox_id'] ] = $line['correlation_id'];
		}

		ksort( $lost );

		$this->assertSame( $stored, $lost, 'Each lost lease is reported once, under its row\'s id.' );
		$this->assertSame( self::DELIVERING_REQUEST, $this->correlation->current(), 'The drainer\'s own id is back.' );
		$this->assertSame( array(), $this->fallback );
	}

	/**
	 * Tests that a fatal error in a listener is reported under the id stored with its row, whatever id the listener left in force.
	 *
	 * The listener takes on an id of its own, as one that handles another request's work would,
	 * and then dies. PHP cannot be killed inside PHPUnit, so it calls the shutdown handler with
	 * the description error_get_last() would give, while its row is in flight.
	 *
	 * Planted violation: in OutboxDrainer::releaseAfterFatal(), report without scoped() (the line
	 * carries the id the listener left).
	 *
	 * @since 0.1.0
	 */
	public function test_a_fatal_error_in_a_listener_is_reported_under_the_rows_correlation_id(): void {
		$b      = $this->secondConnection();
		$stored = '00000000-0000-7000-8000-0000000000f1';
		$logger = new Logger(
			$this->db,
			$this->correlation,
			Redactor::fromDeclarations( OwnedData::registry(), ...DeclaredFields::production() ),
			Level::Debug,
			null,
			$this->fallbackLog()
		);

		$this->plantEvent( $b, new ThingHappened( 1 ), array( 'correlation_id' => "'{$stored}'" ) );

		$this->listen(
			'seocart_' . ThingHappened::eventName(),
			10,
			function (): void {
				$this->correlation->accept( '0192a3b4-0000-7000-8000-00000000cccc' );

				OutboxDrainer::handleShutdown(
					array(
						'type'    => E_ERROR,
						'message' => 'Allowed memory size exhausted',
						'file'    => __FILE__,
						'line'    => __LINE__,
					)
				);
			}
		);

		$this->correlation->accept( self::DELIVERING_REQUEST );

		( new OutboxDrainer( $this->db, new Outbox( $this->db ), $this->bridge, $this->catalog, new LockService( $this->db, LockMode::Table, $this->sleeper() ), $this->correlation, new Reporter( static fn(): Logger => $logger, $this->correlation ) ) )->drain( DrainOptions::command() );

		$fatal = $this->db->fetchAll( 'SELECT correlation_id FROM %i WHERE machine_code = %s', $this->db->table( LogsTable::NAME ), ReportCode::ListenerFatal->value );

		$this->assertSame( array( array( 'correlation_id' => $stored ) ), $fatal );
		$this->assertSame( array(), $this->fallback );
	}
}
