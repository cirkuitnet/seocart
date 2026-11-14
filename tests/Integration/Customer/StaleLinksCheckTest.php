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

use SEOCart\Customer\Infrastructure\CustomerTables;
use SEOCart\Customer\Infrastructure\Doctor\StaleLinksCheck;
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
 * records past the first page are not counted.
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

		require_once ABSPATH . 'wp-admin/includes/user.php';

		wp_delete_user( $gone );

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
}
