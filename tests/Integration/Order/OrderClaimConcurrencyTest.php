<?php
/**
 * Tests two claims of one guest's order at once, on two real connections, and a claim killed before its audit row
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Order;

use SEOCart\Customer\Infrastructure\CustomerTables;
use SEOCart\Order\Application\OrderAccessPolicy;
use SEOCart\Order\Infrastructure\OrderTables;
use SEOCart\Order\Interfaces\StoreApi\AccountOrderClaim;
use SEOCart\Order\Interfaces\StoreApi\OrderStoreOperations;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Tests\Support\Checkout\PlacementTestCase;
use SEOCart\Tests\Support\CreatesUsers;
use SEOCart\Tests\Support\RunningProbe;
use SEOCart\Tests\Support\SecondConnection;

/**
 * Two claims of one guest's order, the test's own and a second in a process of its own, with a barrier after both have read; and a claim killed between its update and its audit row.
 *
 * The test's claim runs until its order is updated, its unit of work still open, and then, before
 * its audit row is sent, starts the second claim and waits until the server shows it waiting: the
 * second has read the order as the first left it committed, a guest's, and judged it claimable,
 * and its own unit now waits on the first's locks. Then the first commits. The barrier is the
 * server's process list, never a pause.
 *
 * - One user twice, at a first record: the second's record waits on the first's unique key and
 *   takes it, its update changes no row, and it reads the order again and finds it its own: both
 *   are answered with the order, one row changed, one audit row, one record.
 * - Two users whose accounts share the order's address: the second's update waits on the order's
 *   row, changes nothing, reads the order again and finds another customer's: it is refused, its
 *   unit rolls back, and its record with it.
 *
 * A claim killed right before its audit row is sent leaves nothing: the server rolls its unit
 * back, so the order has no customer, the user no record and the order no row; and the claim sent
 * again is taken.
 *
 * Planted violations: in MysqlOrderRepository::CLAIM, leave out `AND customer_id IS NULL`: the
 * second user's claim is taken too, moves the order and writes a second audit row; in
 * OrderAccessPolicy::claimInside(), refuse whenever the update changed no row, whoever the order
 * now belongs to: the same user's second claim is refused.
 *
 * @group concurrency
 *
 * @since 0.2.0
 */
final class OrderClaimConcurrencyTest extends PlacementTestCase {

	use CreatesUsers;

	/**
	 * The email address the order is placed with, and the claimants' accounts have.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const EMAIL = 'ada@example.com';

	/**
	 * Deletes the users the test created.
	 *
	 * @since 0.2.0
	 */
	public function tear_down(): void {
		$this->deleteCreatedUsers();

		parent::tear_down();
	}

	/**
	 * Tests that one user's two claims at once, at their first record, change one row and both answer the order, with one audit row and one record.
	 *
	 * @since 0.2.0
	 */
	public function test_one_user_claiming_twice_at_once_changes_one_row(): void {
		$order  = $this->placeWithEmail( self::guest(), self::EMAIL, 'race-one-user' );
		$user   = $this->createUserWithEmail( 'subscriber', self::EMAIL );
		$second = null;
		$waited = false;
		$insert = sprintf( 'INSERT INTO `%s`', $this->table( CustomerTables::CUSTOMERS ) );

		$barrier = $this->beforeStatement(
			$this->auditRow(),
			function () use ( $order, $user, $insert, &$second, &$waited ): void {
				$second = $this->startClaim( $order['uuid'], $order['key'], $user );
				$waited = $this->awaitProbeSendingOrEnd( $second, $insert, 'update' );
			}
		);

		$answer = $this->claimInProcess( $order, $user );
		$report = $second?->finish() ?? array();
		$b      = $this->secondConnection();

		$this->printSequence( 'One user, twice', $waited, $report );

		$this->assertTrue( $barrier->fired );
		$this->assertTrue( $waited, 'The second claim\'s record waited on the first\'s.' );
		$this->assertSame( $order['uuid'], $answer['uuid'] );
		$this->assertSame( array( 'uuid' => $order['uuid'] ), (array) json_decode( (string) ( $report['answer_json'] ?? '' ), true ), 'The second claim is answered with the order too: ' . (string) wp_json_encode( $report ) );
		$this->assertSame( 1, $this->auditRows( $b, $order['uuid'] ), 'One row changed, and one audit row.' );
		$this->assertSame( 1, $this->committedCount( $b, CustomerTables::CUSTOMERS, sprintf( 'user_id = %d', $user ) ), 'One record.' );
		$this->assertSame( $this->recordOf( $b, $user ), $this->customerOf( $b, $order['uuid'] ), 'The order is the user\'s.' );
		$this->assertSame( array(), $this->sleeps, 'No deadlock.' );
	}

	/**
	 * Tests that of two users with the order's address claiming at once, one wins: the other is refused, and keeps no record.
	 *
	 * @since 0.2.0
	 */
	public function test_two_users_with_one_address_claiming_at_once_one_wins(): void {
		$order  = $this->placeWithEmail( self::guest(), self::EMAIL, 'race-two-users' );
		$winner = $this->createUserWithEmail( 'subscriber', self::EMAIL );
		$loser  = $this->createUserWithEmail( 'subscriber', self::EMAIL );
		$second = null;
		$waited = false;
		$update = sprintf( 'UPDATE `%s` SET customer_id', $this->table( OrderTables::ORDERS ) );

		$barrier = $this->beforeStatement(
			$this->auditRow(),
			function () use ( $order, $loser, $update, &$second, &$waited ): void {
				$second = $this->startClaim( $order['uuid'], $order['key'], $loser );
				$waited = $this->awaitProbeSendingOrEnd( $second, $update, 'updating' );
			}
		);

		$answer = $this->claimInProcess( $order, $winner );
		$report = $second?->finish() ?? array();
		$b      = $this->secondConnection();

		$this->printSequence( 'Two users, one address', $waited, $report );

		$this->assertTrue( $barrier->fired );
		$this->assertTrue( $waited, 'The second claim\'s update waited on the order\'s row.' );
		$this->assertSame( $order['uuid'], $answer['uuid'] );
		$this->assertSame( 'order.not_found', $report['refused'] ?? null, 'The second claim is refused: ' . (string) wp_json_encode( $report ) );
		$this->assertSame( 1, $this->auditRows( $b, $order['uuid'] ), 'One audit row.' );
		$this->assertSame( $this->recordOf( $b, $winner ), $this->customerOf( $b, $order['uuid'] ), 'The order is the winner\'s.' );
		$this->assertSame( 0, $this->committedCount( $b, CustomerTables::CUSTOMERS, sprintf( 'user_id = %d', $loser ) ), 'No record is left for the loser.' );
		$this->assertSame( array(), $this->sleeps, 'No deadlock.' );
	}

	/**
	 * Tests that a claim killed after its update and before its audit row keeps nothing, and that the claim sent again is taken.
	 *
	 * @since 0.2.0
	 */
	public function test_a_claim_killed_before_its_audit_row_keeps_nothing(): void {
		$order = $this->placeWithEmail( self::guest(), self::EMAIL, 'claim-killed' );
		$user  = $this->createUserWithEmail( 'subscriber', self::EMAIL );
		$probe = $this->startClaim( $order['uuid'], $order['key'], $user, sprintf( 'INSERT INTO `%s` ( order_id, machine', $this->table( OrderTables::EVENTS ) ) );

		$this->awaitKilled( $probe );
		$this->assertSame( '', $probe->reportSoFar(), "The claim answered: it was not killed.\n" . $probe->output() );

		$b = $this->secondConnection();

		$this->assertNull( $this->customerOf( $b, $order['uuid'] ), 'The order has no customer.' );
		$this->assertSame( 0, $this->committedCount( $b, CustomerTables::CUSTOMERS, sprintf( 'user_id = %d', $user ) ), 'The user has no record.' );
		$this->assertSame( 0, $this->auditRows( $b, $order['uuid'] ), 'The order has no claim row.' );

		$this->assertSame( $order['uuid'], $this->claimInProcess( $order, $user )['uuid'], 'The claim sent again is taken.' );
		$this->assertSame( $this->recordOf( $b, $user ), $this->customerOf( $b, $order['uuid'] ) );
		$this->assertSame( 1, $this->auditRows( $b, $order['uuid'] ) );

		fwrite( STDOUT, "\nA claim killed before its audit row: the order had no customer, the user no record, the order no row; the claim sent again was taken, with one row.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Printed for the report.
	}

	/**
	 * Claims an order in the test's own process, through the claim's service, for a user.
	 *
	 * @since 0.2.0
	 *
	 * @param array{uuid: string, key: string} $order The order and its key.
	 * @param int                              $user  The user.
	 * @return array{uuid: string} The answer.
	 */
	private function claimInProcess( array $order, int $user ): array {
		return $this->kernel->get( AccountOrderClaim::class )->claim(
			array(
				'uuid'                    => $order['uuid'],
				OrderStoreOperations::KEY => $order['key'],
			),
			Actor::user( $user )
		);
	}

	/**
	 * Returns the pattern of a claim's audit row, as the test's connection sends it.
	 *
	 * @since 0.2.0
	 *
	 * @return string The regular expression.
	 */
	private function auditRow(): string {
		return '/^' . preg_quote( sprintf( 'INSERT INTO `%s` ( order_id, machine', $this->table( OrderTables::EVENTS ) ), '/' ) . '/';
	}

	/**
	 * Waits for a probe to end, killed, watching its output and never pausing; fails the test at the deadline.
	 *
	 * @since 0.2.0
	 *
	 * @param RunningProbe $probe The probe.
	 */
	private function awaitKilled( RunningProbe $probe ): void {
		$deadline = hrtime( true ) + 60 * 1000000000;

		while ( ! $probe->watch( 50 ) ) {
			if ( hrtime( true ) >= $deadline ) {
				$this->fail( "The probe did not end.\n" . $probe->output() );
			}
		}
	}

	/**
	 * Counts an order's claim rows, as connection B sees them.
	 *
	 * @since 0.2.0
	 *
	 * @param SecondConnection $b    Connection B.
	 * @param string           $uuid The order.
	 * @return int The rows.
	 */
	private function auditRows( SecondConnection $b, string $uuid ): int {
		return (int) $b->fetchValue( sprintf( "SELECT COUNT(*) FROM `%s` e JOIN `%s` o ON o.id = e.order_id WHERE o.uuid = '%s' AND e.reason = '%s'", $this->table( OrderTables::EVENTS ), $this->table( OrderTables::ORDERS ), $uuid, OrderAccessPolicy::CLAIMED ) );
	}

	/**
	 * Returns an order's committed customer, as connection B sees it.
	 *
	 * @since 0.2.0
	 *
	 * @param SecondConnection $b    Connection B.
	 * @param string           $uuid The order.
	 * @return int|null The record's id, or null.
	 */
	private function customerOf( SecondConnection $b, string $uuid ): ?int {
		$customer = $b->fetchValue( sprintf( "SELECT customer_id FROM `%s` WHERE uuid = '%s'", $this->table( OrderTables::ORDERS ), $uuid ) );

		return null === $customer ? null : (int) $customer;
	}

	/**
	 * Returns a user's committed record, as connection B sees it.
	 *
	 * @since 0.2.0
	 *
	 * @param SecondConnection $b    Connection B.
	 * @param int              $user The user.
	 * @return int|null The record's id, or null.
	 */
	private function recordOf( SecondConnection $b, int $user ): ?int {
		$record = $b->fetchValue( sprintf( 'SELECT id FROM `%s` WHERE user_id = %d', $this->table( CustomerTables::CUSTOMERS ), $user ) );

		return null === $record ? null : (int) $record;
	}

	/**
	 * Prints a race's sequence for the report.
	 *
	 * @since 0.2.0
	 *
	 * @param string               $race   The race.
	 * @param bool                 $waited Whether the server showed the second claim waiting.
	 * @param array<string, mixed> $report The second claim's report.
	 */
	private function printSequence( string $race, bool $waited, array $report ): void {
		fwrite( STDOUT, sprintf( "\n%s: the first claim read, updated the order and, before its audit row, started the second; the second read the order as a guest's and %s; the first committed; the second answered %s.\n", $race, $waited ? 'the server showed it waiting' : 'never waited', (string) wp_json_encode( $report ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Printed for the report.
	}
}
