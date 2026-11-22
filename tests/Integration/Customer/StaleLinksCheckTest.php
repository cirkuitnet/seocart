<?php
/**
 * Tests doctor's check of the customer records whose link is no longer true
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Customer;

use SEOCart\Customer\Application\Customers;
use SEOCart\Customer\Infrastructure\CustomerTables;
use SEOCart\Customer\Infrastructure\Doctor\StaleLinksCheck;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Platform\Cli\Doctor\Doctor;
use SEOCart\Platform\Kernel\Container;
use SEOCart\Tests\Support\CreatesUsers;
use SEOCart\Tests\Support\Customer\CustomerRecords;
use SEOCart\Tests\Support\DatabaseTestCase;
use SEOCart\Tests\Support\KernelContainer;

/**
 * The check counts the linked records whose user is gone or registered at another time, compared with WordPress's users a page at a time and never joined to them, and its repair unlinks each, keeping the record.
 *
 * Planted violations, each shown red and removed: in StaleLinksCheck::staleOf(), compare the
 * user's id alone (drop `|| ! $link->matches( … )`): the record of a user registered at another
 * time is not counted; in StaleLinksCheck::run(), read one page only (drop the loop): the
 * records past the first page are not counted; in StaleLinksCheck::repair(), unlink each link
 * found still stale by its record's id alone (`$this->customers->unlink( $link->customerId )` in
 * place of `unlinkStale( $link )`): the links changed between the check and its repair to a live
 * pair are unlinked; in MysqlCustomerRepository::UNLINK_STALE, drop
 * `AND user_registered_at <=> NULLIF( %s, '' )`: the link set to its own user's registration time
 * between the check and its repair is unlinked.
 *
 * @since 0.2.0
 */
final class StaleLinksCheckTest extends DatabaseTestCase {

	use CreatesUsers;
	use CustomerRecords;

	/**
	 * The kernel's container over the test's connection.
	 *
	 * @since 0.2.0
	 *
	 * @var Container
	 */
	private Container $kernel;

	/**
	 * Creates the customer records' table and the container.
	 *
	 * @since 0.2.0
	 */
	public function set_up(): void {
		parent::set_up();

		$this->createCustomerTable();

		$this->kernel = KernelContainer::build( $this->db, $this->reporter() );
	}

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
	 * Tests that the check passes when every link names its user, and is one of doctor's checks.
	 *
	 * @since 0.2.0
	 */
	public function test_every_true_link_passes_and_the_check_is_doctors(): void {
		$user = $this->createUser( 'subscriber' );

		$this->plantCustomer( $user, self::registeredAt( $user ) );
		$this->plantCustomer( null, null );

		$result = $this->check()->run();

		$this->assertTrue( $result->passed, $result->summary );
		$this->assertContains( StaleLinksCheck::NAME, array_map( static fn( $check ): string => $check->name(), $this->kernel->get( Doctor::class )->checks() ) );
	}

	/**
	 * Tests that a deleted user's link and a link at another registration time are counted, a note that names no person, and that the repair unlinks both and keeps the records, after which the check passes.
	 *
	 * @since 0.2.0
	 */
	public function test_stale_links_are_counted_and_the_repair_unlinks_them(): void {
		$kept    = $this->createUser( 'subscriber' );
		$gone    = $this->createUser( 'subscriber' );
		$renewed = $this->createUser( 'subscriber' );
		$live    = $this->plantCustomer( $kept, self::registeredAt( $kept ) );
		$deleted = $this->plantCustomer( $gone, self::registeredAt( $gone ) );
		$other   = $this->plantCustomer( $renewed, '2001-02-03 04:05:06' );

		$this->assertTrue( $this->deleteUser( $gone ) );

		$check  = $this->check();
		$log    = $this->captureQueries( fn() => $check->run() );
		$result = $check->run();

		$this->assertFalse( $result->passed );
		$this->assertSame( '2 customer records are linked to a user who is gone or registered at another time.', $result->summary );
		$this->assertStringNotContainsString( (string) $gone, implode( "\n", $result->findings ), 'The note names no user.' );
		$this->assertSame( array(), array_values( array_filter( $log->sqls(), fn( string $sql ): bool => str_contains( $sql, $this->db->table( CustomerTables::CUSTOMERS ) ) && str_contains( $sql, $GLOBALS['wpdb']->users ) ) ), 'No statement joins the records with the users.' );

		$repair = $check->repair();

		$this->assertSame( array( "unlinked customer record {$deleted} from its user.", "unlinked customer record {$other} from its user." ), $repair->changes );
		$this->assertSame(
			array(
				$live    => array(
					'user_id'            => $kept,
					'user_registered_at' => self::registeredAt( $kept ),
				),
				$deleted => array(
					'user_id'            => null,
					'user_registered_at' => null,
				),
				$other   => array(
					'user_id'            => null,
					'user_registered_at' => null,
				),
			),
			$this->customerRows(),
			'The repair keeps every record, and unlinks the stale ones alone.'
		);
		$this->assertTrue( $this->check()->run()->passed, 'Nothing is stale after the repair.' );
	}

	/**
	 * Tests that a link whose user is registered again at the time it holds, between the check and its repair, is left linked: the repair judges again, and the customer still reads their order.
	 *
	 * Planted violation: in StaleLinksCheck::repair(), unlink each record run() found by its id
	 * alone, through Customers::unlink(): the live link is unlinked, and the customer's order is
	 * refused to them.
	 *
	 * @since 0.2.0
	 */
	public function test_a_link_that_is_true_again_before_the_repair_stays(): void {
		$user   = $this->createUser( 'subscriber' );
		$record = $this->plantCustomer( $user, '2001-02-03 04:05:06' );
		$check  = $this->check();

		$this->assertFalse( $check->run()->passed, 'The link is stale when the check runs.' );

		// The user's registration time comes back to the one the link holds, as a restored user's does.
		$this->db->execute( 'UPDATE %i SET user_registered = %s WHERE ID = %d', $GLOBALS['wpdb']->users, '2001-02-03 04:05:06', $user );
		clean_user_cache( $user );

		$repair = $check->repair();

		$this->assertSame( array(), $repair->changes, 'The repair unlinked a link that is true again.' );
		$this->assertSame(
			array(
				'user_id'            => $user,
				'user_registered_at' => '2001-02-03 04:05:06',
			),
			$this->customerRows()[ $record ] ?? null
		);
		$this->assertSame( $record, $this->kernel->get( Customers::class )->customerOf( Actor::user( $user ) ), 'The user is the record\'s customer.' );
		$this->assertTrue( $this->check()->run()->passed );
	}

	/**
	 * Tests that a link rewritten between the check and its repair is left for the next run: one moved to another user at that user's time, one moved to another stale pair, and one set to its own user's registration time.
	 *
	 * The repair reads the users again but not the links, so it still holds each link as run() read
	 * it: only its conditional unlink, which holds the record's id, user id and registration time,
	 * leaves a rewritten link alone. The next run finds the one still stale, and its repair unlinks it.
	 *
	 * @since 0.2.0
	 */
	public function test_a_link_that_changes_between_the_check_and_its_repair_is_left_for_the_next_run(): void {
		$first   = $this->createUser( 'subscriber' );
		$second  = $this->createUser( 'subscriber' );
		$third   = $this->createUser( 'subscriber' );
		$fourth  = $this->createUser( 'subscriber' );
		$fifth   = $this->createUser( 'subscriber' );
		$moved   = $this->plantCustomer( $first, '2001-02-03 04:05:06' );
		$stale   = $this->plantCustomer( $second, '2001-02-03 04:05:06' );
		$renewed = $this->plantCustomer( $fifth, '2001-02-03 04:05:06' );
		$check   = $this->check();

		$this->assertSame( '3 customer records are linked to a user who is gone or registered at another time.', $check->run()->summary );

		// Between the check and its repair, an import or a restore rewrites the three links.
		$this->relink( $moved, $third, self::registeredAt( $third ) );
		$this->relink( $stale, $fourth, '2002-03-04 05:06:07' );
		$this->relink( $renewed, $fifth, self::registeredAt( $fifth ) );

		$this->assertSame( array(), $check->repair()->changes, 'The repair unlinked a link rewritten since the check.' );
		$this->assertSame(
			array(
				$moved   => array(
					'user_id'            => $third,
					'user_registered_at' => self::registeredAt( $third ),
				),
				$stale   => array(
					'user_id'            => $fourth,
					'user_registered_at' => '2002-03-04 05:06:07',
				),
				$renewed => array(
					'user_id'            => $fifth,
					'user_registered_at' => self::registeredAt( $fifth ),
				),
			),
			$this->customerRows(),
			'Every rewritten link is as it was rewritten.'
		);
		$this->assertSame( array( $moved, $renewed ), array( $this->customers()->customerOf( Actor::user( $third ) ), $this->customers()->customerOf( Actor::user( $fifth ) ) ), 'The two live links name their users\' records.' );

		$this->assertSame( '1 customer record is linked to a user who is gone or registered at another time.', $check->run()->summary, 'The next run finds the link still stale.' );
		$this->assertSame( array( "unlinked customer record {$stale} from its user." ), $check->repair()->changes, 'The next repair unlinks it alone.' );
		$this->assertTrue( $this->check()->run()->passed, 'Nothing is stale after the next repair.' );
		$this->assertSame( array( $third, $fifth ), array( $this->customerRows()[ $moved ]['user_id'] ?? null, $this->customerRows()[ $renewed ]['user_id'] ?? null ), 'The two live links stay.' );
	}

	/**
	 * Tests that the check reads every page: records past the first page are counted too.
	 *
	 * @since 0.2.0
	 */
	public function test_every_page_is_read(): void {
		$count = StaleLinksCheck::PAGE + 3;

		// Records of users who never existed, linked as a deleted user's are: one row each, from a sequence.
		$this->db->execute(
			"INSERT INTO %i ( uuid, user_id, user_registered_at, created_at, updated_at ) WITH RECURSIVE n ( i ) AS ( SELECT 1 UNION ALL SELECT i + 1 FROM n WHERE i < %d ) SELECT CONCAT( '0192f000-0000-7000-8000-', LPAD( n.i, 12, '0' ) ), 900000000 + n.i, '2001-02-03 04:05:06', UTC_TIMESTAMP(6), UTC_TIMESTAMP(6) FROM n",
			$this->db->table( CustomerTables::CUSTOMERS ),
			$count
		);

		$result = $this->check()->run();

		$this->assertFalse( $result->passed );
		$this->assertSame( "{$count} customer records are linked to a user who is gone or registered at another time.", $result->summary );
	}

	/**
	 * Returns the production check.
	 *
	 * @since 0.2.0
	 *
	 * @return StaleLinksCheck The check.
	 */
	private function check(): StaleLinksCheck {
		return $this->kernel->get( StaleLinksCheck::class );
	}

	/**
	 * Returns the production customers service.
	 *
	 * @since 0.2.0
	 *
	 * @return Customers The service.
	 */
	private function customers(): Customers {
		return $this->kernel->get( Customers::class );
	}

	/**
	 * Rewrites a record's link by SQL, as an import or a restore writes the table, with no code of the store's.
	 *
	 * @since 0.2.0
	 *
	 * @param int    $record       The record.
	 * @param int    $userId       The user the link names now.
	 * @param string $registeredAt The registration time the link holds now.
	 */
	private function relink( int $record, int $userId, string $registeredAt ): void {
		$this->db->execute( 'UPDATE %i SET user_id = %d, user_registered_at = %s WHERE id = %d', $this->db->table( CustomerTables::CUSTOMERS ), $userId, $registeredAt, $record );
	}
}
