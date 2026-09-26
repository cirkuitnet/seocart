<?php
/**
 * Tests the order number: one atomic allocation per placement, never shared, not gapless
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Order;

use SEOCart\Order\Domain\OrderNumberGenerator;
use SEOCart\Order\Infrastructure\OrderStatements;
use SEOCart\Order\Infrastructure\OrderTables;
use SEOCart\Order\Infrastructure\SequenceOrderNumberGenerator;
use SEOCart\Platform\Database\Database;
use SEOCart\Tests\Support\ChildProcessProbe;
use SEOCart\Tests\Support\Order\OrderTestCase;

/**
 * Two placements never receive the same number, whatever they do at once.
 *
 * Connection A is the generator over wpdb. Connection B is another request sending the
 * generator's own statement, built from its constant. Both paths of the statement race: the first
 * use of a scope, which creates the counter row, and every later one, which increments the row
 * that exists. The soak starts twenty processes that each run the generator in a transaction of
 * their own, holds them all on the existing counter row, and lets them go at once.
 *
 * Planted violations, shown red and removed: in SequenceOrderNumberGenerator::next(), read the
 * counter with a plain `SELECT next_value` and write `next_value = <read> + 1` back instead of the
 * one-statement allocation, first for every allocation and then for the existing row's only: the
 * soak's processes read the same committed value, so numbers repeat.
 *
 * @since 0.1.0
 *
 * @group concurrency
 */
final class OrderNumberTest extends OrderTestCase {

	/**
	 * How many processes the soak runs at once.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private const SOAK = 20;

	/**
	 * Tests that the first allocation creates the counter and every later one adds one to it.
	 *
	 * @since 0.1.0
	 */
	public function test_the_first_allocation_creates_the_counter_and_later_ones_count_on(): void {
		$numbers = $this->db->transaction(
			fn(): array => array( $this->generator( $this->db )->next( OrderNumberGenerator::DEFAULT_SCOPE ), $this->generator( $this->db )->next( OrderNumberGenerator::DEFAULT_SCOPE ) )
		);

		$this->assertSame( array( '000001', '000002' ), $numbers );
		$this->assertSame(
			array(
				'scope_key'  => 'default',
				'pattern'    => '{number}',
				'next_value' => '2',
			),
			$this->db->fetchRow( 'SELECT scope_key, pattern, next_value FROM %i', $this->table( OrderTables::NUMBER_SEQUENCE ) )
		);
	}

	/**
	 * Tests that a second allocation of a new counter waits for the first, and gets the next number once the first commits.
	 *
	 * A allocates the first number of the scope, which creates the counter row, and while A's
	 * transaction is still open B sends the same allocation: the server shows B waiting for A's
	 * row. A commits, and B's allocation gets the next number.
	 *
	 * @since 0.1.0
	 */
	public function test_a_second_allocation_of_a_new_counter_waits_for_the_first(): void {
		$b        = $this->secondConnection();
		$allocate = $this->raw( SequenceOrderNumberGenerator::ALLOCATE, OrderNumberGenerator::DEFAULT_SCOPE, SequenceOrderNumberGenerator::PATTERN );

		$b->query( 'START TRANSACTION' );

		$first = $this->db->transaction(
			function () use ( $b, $allocate ): string {
				$number = $this->generator( $this->db )->next( OrderNumberGenerator::DEFAULT_SCOPE );

				$b->queryAsync( $allocate );
				$this->awaitWaiting( $b, $allocate, 'update' );

				return $number;
			}
		);

		$this->assertTrue( $b->isReady( 5000 ), 'B\'s allocation must go through once A commits.' );

		$b->reap();

		$second = (int) $b->fetchValue( 'SELECT LAST_INSERT_ID()' );

		$b->query( 'COMMIT' );

		$this->assertSame( '000001', $first );
		$this->assertSame( 2, $second, 'B got the number after A\'s, not A\'s.' );
	}

	/**
	 * Tests that a second allocation of an existing counter waits for the first, and gets the next number once the first commits.
	 *
	 * B allocates the scope's first number and commits, so the counter row exists. A allocates the
	 * next one and, while A's transaction is still open, B sends the same allocation: the server
	 * shows B waiting for A's row. A commits, and B's allocation gets the number after A's.
	 *
	 * @since 0.1.0
	 */
	public function test_a_second_allocation_of_an_existing_counter_waits_for_the_first(): void {
		$b        = $this->secondConnection();
		$allocate = $this->raw( SequenceOrderNumberGenerator::ALLOCATE, OrderNumberGenerator::DEFAULT_SCOPE, SequenceOrderNumberGenerator::PATTERN );

		$b->query( $allocate );
		$b->query( 'START TRANSACTION' );

		$first = $this->db->transaction(
			function () use ( $b, $allocate ): string {
				$number = $this->generator( $this->db )->next( OrderNumberGenerator::DEFAULT_SCOPE );

				$b->queryAsync( $allocate );
				$this->awaitWaiting( $b, $allocate, 'update' );

				return $number;
			}
		);

		$this->assertTrue( $b->isReady( 5000 ), 'B\'s allocation must go through once A commits.' );

		$b->reap();

		$second = (int) $b->fetchValue( 'SELECT LAST_INSERT_ID()' );

		$b->query( 'COMMIT' );

		$this->assertSame( '000002', $first );
		$this->assertSame( 3, $second, 'B got the number after A\'s, not A\'s.' );
	}

	/**
	 * Tests that a rolled-back allocation returns its number, and that a committed one is never given again.
	 *
	 * @since 0.1.0
	 */
	public function test_a_rolled_back_allocation_returns_its_number_and_a_committed_one_is_kept(): void {
		$rollBack = new \RuntimeException( 'The placement failed.' );

		try {
			$this->db->transaction(
				function () use ( $rollBack ): void {
					$this->assertSame( '000001', $this->generator( $this->db )->next( OrderNumberGenerator::DEFAULT_SCOPE ) );

					throw $rollBack;
				}
			);
		} catch ( \RuntimeException $thrown ) {
			$this->assertSame( $rollBack, $thrown );
		}

		$kept = $this->db->transaction( fn(): string => $this->generator( $this->db )->next( OrderNumberGenerator::DEFAULT_SCOPE ) );
		$next = $this->db->transaction( fn(): string => $this->generator( $this->db )->next( OrderNumberGenerator::DEFAULT_SCOPE ) );

		$this->assertSame( '000001', $kept, 'The rolled-back allocation returned its number.' );
		$this->assertSame( '000002', $next, 'A committed number is kept, even by an order that fails later.' );
	}

	/**
	 * Tests that an allocation outside a transaction is refused before any statement: its number would be committed alone.
	 *
	 * @since 0.1.0
	 */
	public function test_an_allocation_outside_a_transaction_is_refused(): void {
		$queries = $this->captureQueries(
			function (): void {
				try {
					$this->generator( $this->db )->next( OrderNumberGenerator::DEFAULT_SCOPE );
					$this->fail( 'A number was allocated outside a transaction.' );
				} catch ( \LogicException $expected ) {
					$this->assertStringContainsString( 'inside a transaction', $expected->getMessage() );
				}
			}
		);

		$this->assertQueryCount( 0, $queries, 'statements before the refusal' );
	}

	/**
	 * Tests that twenty processes allocating at the same moment get twenty different numbers.
	 *
	 * B allocates the scope's first number and commits, so the counter row exists; then B allocates
	 * the second in an open transaction and holds the row, so every process that allocates waits
	 * for it. Once the server shows all twenty waiting, B commits, and they all race for the row.
	 * Numbering is not gapless, so only duplicates would be a failure; here every process commits,
	 * so the numbers are exactly the twenty after B's two.
	 *
	 * @since 0.1.0
	 */
	public function test_twenty_processes_allocating_at_once_never_share_a_number(): void {
		$b        = $this->secondConnection();
		$allocate = $this->raw( SequenceOrderNumberGenerator::ALLOCATE, OrderNumberGenerator::DEFAULT_SCOPE, SequenceOrderNumberGenerator::PATTERN );

		$b->query( $allocate );
		$b->query( 'START TRANSACTION' );
		$b->query( $allocate );

		$probes = array();

		for ( $index = 0; $index < self::SOAK; ++$index ) {
			$probes[] = ChildProcessProbe::start( dirname( __DIR__, 2 ) . '/Support/Order/order-number-probe.php' );
		}

		$this->awaitProbesWaiting( OrderTables::NUMBER_SEQUENCE, self::SOAK, $probes );

		$b->query( 'COMMIT' );

		$numbers = array();
		$peak    = 0;

		foreach ( $probes as $probe ) {
			$report = $probe->finish();

			$this->assertArrayHasKey( 'number', $report, (string) wp_json_encode( $report ) );

			$numbers[] = (int) $report['number'];
			$peak      = max( $peak, (int) $report['memory'] );
		}

		sort( $numbers );

		fwrite( STDOUT, sprintf( "\nThe soak's %d processes got %s; each peaked at %.1f MB at most.\n", self::SOAK, implode( ' ', $numbers ), $peak / 1048576 ) );

		$this->assertSame( $numbers, array_values( array_unique( $numbers ) ), 'Two processes got the same number.' );
		$this->assertSame( range( 3, self::SOAK + 2 ), $numbers );
	}

	/**
	 * Builds the generator over a connection.
	 *
	 * @since 0.1.0
	 *
	 * @param Database $db The connection.
	 * @return SequenceOrderNumberGenerator The generator.
	 */
	private function generator( Database $db ): SequenceOrderNumberGenerator {
		return new SequenceOrderNumberGenerator( new OrderStatements( $db ) );
	}
}
