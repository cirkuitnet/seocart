<?php
/**
 * StaleLinksCheck: customer records linked to a WordPress user who is gone, or whose id now names someone else
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Customer\Infrastructure\Doctor;

use SEOCart\Customer\Application\Customers;
use SEOCart\Customer\Domain\CustomerLink;
use SEOCart\Customer\Domain\CustomerRepository;
use SEOCart\Platform\Cli\Doctor\CheckResult;
use SEOCart\Platform\Cli\Doctor\Repairable;
use SEOCart\Platform\Cli\Doctor\RepairResult;

defined( 'ABSPATH' ) || exit;

/**
 * Reports, and unlinks, the customer records whose link is no longer true: the user it names was deleted, or registered at another time than the link holds.
 *
 * Owns one fact: which links are stale. Such a link already matches nobody, so the check is a
 * note of housekeeping rather than a danger: no hook follows a deleted user, and a record keeps
 * its link until something unlinks it. The linked records are read a page at a time, and each
 * page's users are asked of WordPress by their ids, never joined to the users table, which a
 * network or a split database may keep elsewhere. It fails while any is found, so that `--repair`
 * reaches it; the repair unlinks each record through Customers::unlink(), which keeps the record
 * and its orders and only empties the link.
 *
 * @since 0.2.0
 */
final class StaleLinksCheck implements Repairable {

	/**
	 * The check's name.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const NAME = 'customer_links';

	/**
	 * How many linked records a page holds, and so how many users WordPress is asked for at once.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	public const PAGE = 500;

	/**
	 * The records.
	 *
	 * @since 0.2.0
	 *
	 * @var CustomerRepository
	 */
	private CustomerRepository $records;

	/**
	 * Unlinks a record.
	 *
	 * @since 0.2.0
	 *
	 * @var Customers
	 */
	private Customers $customers;

	/**
	 * Returns when each of some users registered, by id, for the users that exist.
	 *
	 * @since 0.2.0
	 *
	 * @var \Closure(list<int>): array<int, string>
	 */
	private \Closure $registrations;

	/**
	 * The records the last run() found stale, for repair() to unlink.
	 *
	 * @since 0.2.0
	 *
	 * @var list<int>
	 */
	private array $found = array();

	/**
	 * Creates the check. Sends nothing.
	 *
	 * @since 0.2.0
	 *
	 * @param CustomerRepository $records       The records.
	 * @param Customers          $customers     Unlinks a record.
	 * @param \Closure           $registrations Returns when each of some users registered, by id, for the users that exist.
	 *
	 * @phpstan-param \Closure(list<int>): array<int, string> $registrations
	 */
	public function __construct( CustomerRepository $records, Customers $customers, \Closure $registrations ) {
		$this->records       = $records;
		$this->customers     = $customers;
		$this->registrations = $registrations;
	}

	/**
	 * Returns the check's name.
	 *
	 * @since 0.2.0
	 *
	 * @return string `customer_links`.
	 */
	public function name(): string {
		return self::NAME;
	}

	/**
	 * Counts the linked records whose user is gone or registered at another time.
	 *
	 * @since 0.2.0
	 *
	 * @return CheckResult Passed when every link names its user; failed with the count otherwise.
	 */
	public function run(): CheckResult {
		$this->found = array();
		$after       = 0;

		do {
			$page = $this->records->linked( $after, self::PAGE );

			if ( array() === $page ) {
				break;
			}

			$this->found = array_merge( $this->found, $this->staleOf( $page ) );
			$after       = end( $page )->customerId;
			$more        = self::PAGE === count( $page );
		} while ( $more );

		if ( array() === $this->found ) {
			return CheckResult::pass( self::NAME, 'Every customer record linked to a user names that user, at the time they registered.' );
		}

		$count = count( $this->found );

		return CheckResult::fail(
			self::NAME,
			sprintf( '%d customer %s linked to a user who is gone or registered at another time.', $count, 1 === $count ? 'record is' : 'records are' ),
			array( 'Note: such a link matches nobody, so no order is shown to anyone by it. --repair unlinks each record, keeping the record and its orders.' )
		);
	}

	/**
	 * Unlinks each record run() found stale.
	 *
	 * @since 0.2.0
	 *
	 * @return RepairResult What was unlinked.
	 */
	public function repair(): RepairResult {
		$changes = array();

		foreach ( $this->found as $customerId ) {
			if ( $this->customers->unlink( $customerId ) ) {
				$changes[] = sprintf( 'unlinked customer record %d from its user.', $customerId );
			}
		}

		return new RepairResult( self::NAME, $changes );
	}

	/**
	 * Returns the records of a page whose user is gone or registered at another time.
	 *
	 * @since 0.2.0
	 *
	 * @param CustomerLink[] $page The linked records.
	 * @return list<int> The stale records' ids.
	 *
	 * @phpstan-param list<CustomerLink> $page
	 */
	private function staleOf( array $page ): array {
		$registered = ( $this->registrations )( array_values( array_unique( array_map( static fn( CustomerLink $link ): int => $link->userId, $page ) ) ) );
		$stale      = array();

		foreach ( $page as $link ) {
			if ( ! isset( $registered[ $link->userId ] ) || ! $link->matches( $registered[ $link->userId ] ) ) {
				$stale[] = $link->customerId;
			}
		}

		return $stale;
	}
}
