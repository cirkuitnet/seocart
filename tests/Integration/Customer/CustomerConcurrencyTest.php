<?php
/**
 * Tests two first orders of one user at once, on two connections
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Customer;

use SEOCart\Customer\Infrastructure\CustomerTables;
use SEOCart\Inventory\Infrastructure\InventoryTables;
use SEOCart\Order\Infrastructure\OrderTables;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Tests\Support\Checkout\PlacementTestCase;
use SEOCart\Tests\Support\CreatesUsers;

/**
 * Two first placements of one user, on two carts and two real connections, end with one customer record that both orders belong to.
 *
 * The test's own placement creates the user's record in its first unit of work and, before it
 * commits, a second placement of the same user starts in a process of its own, on the user's
 * other cart: it reads no link, the record being uncommitted, and its insert waits on the record's
 * unique key. The barrier is the server's process list showing that insert waiting, never a pause.
 * Once the first commits, the second takes the first's record.
 *
 * Planted violation: in CustomerTables::customers(), drop the `user` unique key. The second
 * insert then waits for nothing, and the user has two records.
 *
 * @group concurrency
 *
 * @since 0.2.0
 */
final class CustomerConcurrencyTest extends PlacementTestCase {

	use CreatesUsers;

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
	 * Tests that two first placements of one user at once end with one record, the second waiting on the first's.
	 *
	 * @since 0.2.0
	 */
	public function test_two_first_orders_of_one_user_at_once_end_with_one_record(): void {
		$user = $this->createUser( 'subscriber' );
		$mug  = $this->sellable( 5 );

		$this->readyCart( array( $mug => 1 ) );

		$theirs = $this->placeInput( 'the other cart' );
		$token  = $this->tokens->presented;

		$this->readyCart( array( $mug => 1 ) );

		$input    = $this->placeInput( 'this cart' );
		$second   = null;
		$insert   = sprintf( 'INSERT INTO `%s`', $this->table( CustomerTables::CUSTOMERS ) );
		$barriers = $this->beforeStatement(
			'/^' . preg_quote( sprintf( 'UPDATE `%s` SET held = held + ', $this->table( InventoryTables::ITEMS ) ), '/' ) . '/',
			function () use ( $token, $theirs, $user, $insert, &$second ): void {
				$this->assertNotNull( $token );

				$second = $this->startPlacement( $token, $theirs, 0, $user );

				$this->awaitProbeSending( $second, $insert, 'update' );
			}
		);

		$answer = $this->placement->place( $input, Actor::user( $user ) );
		$report = $second?->finish() ?? array();
		$b      = $this->secondConnection();
		$theirs = (array) json_decode( (string) ( $report['answer_json'] ?? '' ), true );
		$owners = array();

		foreach ( array( (string) $answer['order_uuid'], (string) ( $theirs['order_uuid'] ?? '' ) ) as $uuid ) {
			$owners[] = $b->fetchValue( sprintf( "SELECT customer_id FROM `%s` WHERE uuid = '%s'", $this->table( OrderTables::ORDERS ), $uuid ) );
		}

		$this->assertTrue( $barriers->fired );
		$this->assertSame( 'approved', $answer['outcome'] );
		$this->assertSame( 'approved', $theirs['outcome'] ?? $report, 'The second placement went through once the first committed.' );
		$this->assertSame( 1, $this->committedCount( $b, CustomerTables::CUSTOMERS ), 'One record for the user.' );
		$this->assertSame( 1, $this->committedCount( $b, CustomerTables::CUSTOMERS, sprintf( 'user_id = %d', $user ) ) );
		$this->assertNotNull( $owners[0] );
		$this->assertSame( $owners[0], $owners[1], 'Both orders belong to the one record.' );
		$this->assertSame( array(), $this->sleeps, 'No deadlock.' );
	}
}
