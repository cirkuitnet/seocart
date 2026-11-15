<?php
/**
 * Customers: which customer record a WordPress user is, and the one place a record is created
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Customer\Application;

use SEOCart\Customer\Domain\CustomerLink;
use SEOCart\Customer\Domain\CustomerRepository;
use SEOCart\Order\Application\ActorCustomers;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Support\IdGenerator;

defined( 'ABSPATH' ) || exit;

/**
 * Says which customer record an actor is, creates the record of a user's first order, and unlinks a record from its user.
 *
 * Owns one fact: who a customer record belongs to. A record is linked to a WordPress user by a
 * pair, the user's id and the time the user registered, and an actor is the record's customer only
 * when both equal the authenticated user's. A deleted user's id can come back for someone else,
 * and the hook that would follow a deletion cannot be relied on, so no hook is registered: a link
 * to a user who is gone, or whose id now names someone who registered at another time, matches
 * nobody, whatever ran.
 *
 * A visitor (user 0) is no customer, and neither is a process acting on a user's authority: a
 * command or a job is not the person. Neither is asked anything. No record has an id below 1, and
 * no record stands for a guest, so no actor can match one by having none.
 *
 * The registration time is the kernel's closure over WordPress's user read, which answers from
 * the request's cache for the user WordPress authenticated: it costs no statement.
 *
 * @since 0.2.0
 */
final class Customers implements ActorCustomers {

	/**
	 * The records.
	 *
	 * @since 0.2.0
	 *
	 * @var CustomerRepository
	 */
	private CustomerRepository $records;

	/**
	 * Mints a new record's public identifier.
	 *
	 * @since 0.2.0
	 *
	 * @var IdGenerator
	 */
	private IdGenerator $ids;

	/**
	 * Returns when a user registered, `Y-m-d H:i:s` UTC, or null when there is no such user.
	 *
	 * @since 0.2.0
	 *
	 * @var \Closure(int): ?string
	 */
	private \Closure $registeredAt;

	/**
	 * Creates the service. Sends nothing.
	 *
	 * @since 0.2.0
	 *
	 * @param CustomerRepository $records      The records.
	 * @param IdGenerator        $ids          Mints a new record's public identifier.
	 * @param \Closure           $registeredAt Returns when a user registered, or null when there is no such user.
	 *
	 * @phpstan-param \Closure(int): ?string $registeredAt
	 */
	public function __construct( CustomerRepository $records, IdGenerator $ids, \Closure $registeredAt ) {
		$this->records      = $records;
		$this->ids          = $ids;
		$this->registeredAt = $registeredAt;
	}

	/**
	 * Returns the customer record an actor is: the one linked to the actor's user at the user's registration time.
	 *
	 * A visitor and a process are no customer and cost nothing; a user costs one read.
	 *
	 * @since 0.2.0
	 *
	 * @param Actor $actor The actor.
	 * @return int|null The record's id, from 1; null when the actor is no customer.
	 */
	public function customerOf( Actor $actor ): ?int {
		if ( ! self::isPerson( $actor ) ) {
			return null;
		}

		return self::idOf( $this->records->linkOf( $actor->userId() ), ( $this->registeredAt )( $actor->userId() ) );
	}

	/**
	 * Returns the customer record an order placed by an actor belongs to, creating the record of a user's first order.
	 *
	 * The one place a record is created, inside the caller's transaction. A visitor and a process
	 * are no customer and cost nothing, and so is a user deleted since the request was
	 * authenticated: their order is a guest's. Otherwise the link is read once; a record linked to
	 * the user at their registration time is theirs. On a miss a record is inserted, and a record
	 * another first order of the same user inserted at once is taken instead, after waiting for it
	 * on the unique key. A record linked to the user's id at another registration time, which a
	 * deleted user's id that came back leaves, is unlinked first, by a conditional update that
	 * holds the link as read.
	 *
	 * @since 0.2.0
	 *
	 * @throws \LogicException Outside a transaction, when a record must be unlinked or created.
	 *
	 * @param Actor $actor The actor the order is placed by.
	 * @return int|null The record's id, from 1; null when the actor is no customer.
	 */
	public function customerFor( Actor $actor ): ?int {
		if ( ! self::isPerson( $actor ) ) {
			return null;
		}

		$registeredAt = ( $this->registeredAt )( $actor->userId() );

		if ( null === $registeredAt ) {
			return null;
		}

		$link = $this->records->linkOf( $actor->userId() );
		$own  = self::idOf( $link, $registeredAt );

		if ( null !== $own ) {
			return $own;
		}

		if ( null !== $link ) {
			$this->records->unlinkStale( $link );
		}

		return $this->records->create( $this->ids->generate(), $actor->userId(), $registeredAt );
	}

	/**
	 * Unlinks a record from a user while it still holds the link as read: one conditional update, which leaves a link changed since alone.
	 *
	 * Doctor's repair calls it for a link it found stale, and found still stale.
	 *
	 * @since 0.2.0
	 *
	 * @param CustomerLink $link The link as judged stale.
	 * @return bool True when the record still had that link and no longer has it.
	 */
	public function unlinkStale( CustomerLink $link ): bool {
		return $this->records->unlinkStale( $link );
	}

	/**
	 * Unlinks a record from the user it names, whoever that is: the record stays, and its orders keep naming it, but nobody is its customer any more.
	 *
	 * An erasure calls it for the person's own record.
	 *
	 * @since 0.2.0
	 *
	 * @param int $customerId The record.
	 * @return bool True when the record was linked and is no longer.
	 */
	public function unlink( int $customerId ): bool {
		return $this->records->unlink( $customerId );
	}

	/**
	 * Tells whether an actor is a person who may be a customer: a WordPress user acting in person.
	 *
	 * @since 0.2.0
	 *
	 * @param Actor $actor The actor.
	 * @return bool False for a visitor (user 0) and for a process acting on anyone's authority.
	 */
	private static function isPerson( Actor $actor ): bool {
		return $actor->userId() >= 1 && null === $actor->systemName();
	}

	/**
	 * Returns the record a link names when it was made for a user who registered at a time; never an id below 1.
	 *
	 * @since 0.2.0
	 *
	 * @param CustomerLink|null $link         The link read for the user's id, or null when none was.
	 * @param string|null       $registeredAt When the user registered; null when there is no such user.
	 * @return int|null The record's id; null when the link is not the user's.
	 */
	private static function idOf( ?CustomerLink $link, ?string $registeredAt ): ?int {
		return null !== $link && $link->matches( $registeredAt ) && $link->customerId >= 1 ? $link->customerId : null;
	}
}
