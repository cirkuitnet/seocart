<?php
/**
 * Tests that concurrent hits on the counter table never lose a count, on two real connections
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\RateLimiter;

use SEOCart\Platform\Database\Schema\DdlGenerator;
use SEOCart\Platform\Database\Schema\SchemaVerifier;
use SEOCart\Platform\Database\SchemaOperations;
use SEOCart\Platform\RateLimiter\ClientIdentity;
use SEOCart\Platform\RateLimiter\Migrations\CreateRateCountersMigration;
use SEOCart\Platform\RateLimiter\RateCountersTable;
use SEOCart\Platform\RateLimiter\TableRateLimiter;
use SEOCart\Tests\Support\DatabaseTestCase;
use SEOCart\Tests\Support\SecondDatabase;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Connection A holds a transaction open with raw statements while B waits.

/**
 * Connection A is the table adapter over wpdb. Connection B is another request: the table adapter
 * over a second connection, or the adapter's own statement sent from its constant. Interleavings
 * are set by barriers and by the server's process list, never by pauses.
 *
 * @since 0.1.0
 *
 * @group concurrency
 */
final class RateLimiterConcurrencyTest extends DatabaseTestCase {

	/**
	 * The window of the tests: a day, so no window ends while a test runs but at midnight UTC.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private const WINDOW = 86400;

	/**
	 * The second databases the test opened.
	 *
	 * @since 0.1.0
	 *
	 * @var list<SecondDatabase>
	 */
	private array $seconds = array();

	/**
	 * Creates the counter table with its migration.
	 *
	 * @since 0.1.0
	 */
	public function set_up(): void {
		parent::set_up();

		( new CreateRateCountersMigration() )->up( new SchemaOperations( $this->db, new DdlGenerator(), new SchemaVerifier( $this->db ) ) );
	}

	/**
	 * Closes the second databases.
	 *
	 * @since 0.1.0
	 */
	public function tear_down(): void {
		foreach ( $this->seconds as $second ) {
			$second->close();
		}

		$this->seconds = array();

		parent::tear_down();
	}

	/**
	 * Tests that a hit B makes between A's decision to count and A's write is not lost.
	 *
	 * B runs its whole hit at the moment A is about to send its INSERT. A's count must then be B's
	 * plus one, and the row must hold both.
	 *
	 * Planted violation: in TableRateLimiter::hit(), read the count first and write it back:
	 * `$read = $this->peek( $bucket, $identity, $window );` then send the INSERT with
	 * `ON DUPLICATE KEY UPDATE count = LAST_INSERT_ID(` . ( $read + 1 ) . `)`. A then writes the
	 * count it read before B's hit, and the row loses B's.
	 *
	 * @since 0.1.0
	 */
	public function test_a_hit_between_another_hits_read_and_write_is_not_lost(): void {
		$second  = new SecondDatabase( $this->reporter() );
		$a       = new TableRateLimiter( $this->db );
		$b       = new TableRateLimiter( $second->db() );
		$client  = ClientIdentity::ofClient( '192.0.2.1', 0, 'test secret' );
		$b_count = null;

		$this->seconds[] = $second;

		$barrier = $this->beforeStatement(
			'/^INSERT INTO `?' . preg_quote( $this->db->table( RateCountersTable::NAME ), '/' ) . '/',
			static function () use ( $b, $client, &$b_count ): void {
				$b_count = $b->hit( 'cart.write', $client, self::WINDOW );
			}
		);

		$a_count = $a->hit( 'cart.write', $client, self::WINDOW );

		$this->assertTrue( $barrier->fired, 'B never ran, so the test proves nothing.' );
		$this->assertSame( 1, $b_count );
		$this->assertSame( 2, $a_count, 'A counted over B\'s hit.' );
		$this->assertSame( 2, $b->peek( 'cart.write', $client, self::WINDOW ), 'The row holds both hits.' );
	}

	/**
	 * Tests that a hit waiting on the row another connection is counting in is counted after it, not instead of it.
	 *
	 * A counts inside an open transaction, so the row stays locked. B sends the adapter's statement
	 * and the server shows it waiting. When A commits, B's statement returns the count after A's.
	 *
	 * @since 0.1.0
	 */
	public function test_a_hit_waiting_on_a_locked_row_is_counted_after_it(): void {
		global $wpdb;

		$client = ClientIdentity::ofClient( '192.0.2.1', 0, 'test secret' );
		$b      = $this->secondConnection();
		$hit    = (string) $wpdb->prepare( TableRateLimiter::HIT, $this->db->table( RateCountersTable::NAME ), 'cart.write', $client->key(), self::WINDOW, self::WINDOW, self::WINDOW ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The statement is the adapter's constant; this is its prepare step.

		$wpdb->query( 'START TRANSACTION' );

		try {
			$this->assertSame( 1, ( new TableRateLimiter( $this->db ) )->hit( 'cart.write', $client, self::WINDOW ) );

			$b->queryAsync( $hit );
			$this->awaitWaiting( $b, $hit, 'update' );
		} finally {
			$wpdb->query( 'COMMIT' );
		}

		$this->assertTrue( $b->isReady( 5000 ), 'B did not finish after A committed.' );
		$b->reap();

		$this->assertSame( '2', $b->fetchValue( 'SELECT LAST_INSERT_ID()' ), 'B\'s statement returned the count after A\'s.' );
		$this->assertSame( '2', $b->fetchValue( (string) $wpdb->prepare( 'SELECT count FROM %i WHERE key_hash = %s', $this->db->table( RateCountersTable::NAME ), $client->key() ) ) );
	}
}
