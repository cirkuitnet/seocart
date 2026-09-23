<?php
/**
 * Tests `wp seocart outbox`: drain, status and prune, through run() and without WP-CLI
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Events;

use SEOCart\Platform\Events\Cli\OutboxCommand;
use SEOCart\Platform\Events\Outbox;
use SEOCart\Platform\Events\OutboxDrainer;
use SEOCart\Tests\Support\Events\OutboxTestCase;
use SEOCart\Tests\Support\Events\ThingHappened;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- One test drops the outbox table to stage a database failure.

/**
 * The command's exit codes and what it prints.
 *
 * Planted violation: in OutboxCommand::drain(), return EXIT_OK when another drainer holds the lock.
 *
 * @since 0.1.0
 */
final class OutboxCommandTest extends OutboxTestCase {

	/**
	 * `drain` delivers what is due, prints what it did and exits 0; with nothing due it still exits 0.
	 *
	 * @since 0.1.0
	 */
	public function test_drain_delivers_and_prints_what_it_did(): void {
		$b     = $this->secondConnection();
		$lines = array();
		$first = $this->plantEvent( $b, new ThingHappened( 1 ) );

		$this->plantEvent( $b, new ThingHappened( 2 ) );

		$this->assertSame( OutboxCommand::EXIT_OK, $this->command( $lines )->run( array( 'drain' ), array( 'budget' => '30' ) ) );
		$this->assertSame( array( 'Claimed 2, dispatched 2, retried 0, parked as failed 0, pruned 0.' ), $lines );
		$this->assertSame( Outbox::DISPATCHED, $this->outboxRow( $b, $first )['state'] );

		$lines = array();

		$this->assertSame( OutboxCommand::EXIT_OK, $this->command( $lines )->run( array( 'drain' ), array() ) );
		$this->assertSame( array( 'Claimed 0, dispatched 0, retried 0, parked as failed 0, pruned 0.' ), $lines );
	}

	/**
	 * `drain` exits 2 and changes nothing while another drainer holds the lock.
	 *
	 * @since 0.1.0
	 */
	public function test_drain_exits_2_while_another_drainer_holds_the_lock(): void {
		$b     = $this->secondConnection();
		$lines = array();
		$id    = $this->plantEvent( $b, new ThingHappened( 1 ) );

		$b->query( sprintf( "INSERT INTO `%s` ( name, owner_token, acquired_at, expires_at, holder, created_at ) VALUES ( '%s', '%s', UTC_TIMESTAMP(6), UTC_TIMESTAMP(6) + INTERVAL 1 HOUR, 'other:1', UTC_TIMESTAMP() )", $this->db->table( 'locks' ), OutboxDrainer::LOCK_NAME, str_repeat( 'b', 64 ) ) );

		$this->assertSame( OutboxCommand::EXIT_BLOCKED, $this->command( $lines )->run( array( 'drain' ), array() ) );
		$this->assertSame( array( 'Another drainer holds the outbox lock, so nothing was done. Run the command again later.' ), $lines );
		$this->assertSame( Outbox::PENDING, $this->outboxRow( $b, $id )['state'] );
		$this->assertSame( '0', $this->outboxRow( $b, $id )['attempts'] );
	}

	/**
	 * `status` prints the counts and exits 0.
	 *
	 * @since 0.1.0
	 */
	public function test_status_prints_the_counts(): void {
		$b     = $this->secondConnection();
		$lines = array();

		$this->plantEvent( $b, new ThingHappened( 1 ), array( 'created_at' => 'UTC_TIMESTAMP(6) - INTERVAL 90 SECOND' ) );
		$this->plantEvent( $b, new ThingHappened( 2 ), array( 'state' => "'failed'" ) );

		$this->assertSame( OutboxCommand::EXIT_OK, $this->command( $lines )->run( array( 'status' ), array() ) );
		$this->assertCount( 4, $lines );
		$this->assertMatchesRegularExpression( '/^Pending: 1 \(oldest stored (9\d) seconds ago\)$/', $lines[0] );
		$this->assertSame( array( 'In flight: 0', 'Failed: 1', 'Dispatched in the last 24 hours: 0' ), array_slice( $lines, 1 ) );
	}

	/**
	 * `prune` deletes every row past retention, prints the count and exits 0.
	 *
	 * @since 0.1.0
	 */
	public function test_prune_deletes_what_is_past_retention_and_prints_the_count(): void {
		$b     = $this->secondConnection();
		$lines = array();

		$this->plantEvent(
			$b,
			new ThingHappened( 1 ),
			array(
				'state'         => "'dispatched'",
				'dispatched_at' => 'UTC_TIMESTAMP(6) - INTERVAL 8 DAY',
			)
		);
		$kept = $this->plantEvent( $b, new ThingHappened( 2 ) );

		$this->assertSame( OutboxCommand::EXIT_OK, $this->command( $lines )->run( array( 'prune' ), array() ) );
		$this->assertSame( array( 'Pruned 1 row past retention.' ), $lines );
		$this->assertSame( array( $kept ), $this->outboxIds( $b ) );
	}

	/**
	 * An unknown action prints the usage and exits 1; a database failure prints its code and exits 1.
	 *
	 * @since 0.1.0
	 */
	public function test_misuse_and_database_failures_exit_1(): void {
		global $wpdb;

		$lines = array();

		$this->assertSame( OutboxCommand::EXIT_FAILED, $this->command( $lines )->run( array( 'flush' ), array() ) );
		$this->assertSame( array( 'Usage: wp seocart outbox <drain|status|prune> [--budget=<seconds>]' ), $lines );

		$wpdb->query( $wpdb->prepare( 'DROP TABLE %i', $this->outboxTable() ) );

		$lines = array();

		$this->assertSame( OutboxCommand::EXIT_FAILED, $this->command( $lines )->run( array( 'status' ), array() ) );
		$this->assertCount( 1, $lines );
		$this->assertStringStartsWith( 'database.query_failed: ', $lines[0] );
	}

	/**
	 * Builds the command over a table-mode drainer, printing into a list.
	 *
	 * @since 0.1.0
	 *
	 * @param string[] $lines Receives each printed line.
	 * @return OutboxCommand The command.
	 */
	private function command( array &$lines ): OutboxCommand {
		return new OutboxCommand(
			$this->drainer(),
			$this->outbox,
			static function ( string $line ) use ( &$lines ): void {
				$lines[] = $line;
			}
		);
	}
}
