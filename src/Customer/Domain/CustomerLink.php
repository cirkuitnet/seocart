<?php
/**
 * CustomerLink: a customer record and the WordPress user it is linked to
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Customer\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * A customer record's link, as stored: the record, the user's id and the time the user registered.
 *
 * Owns one fact: what a link says, and whom it matches. A user is the record's customer only while
 * both halves equal the user's own: an id alone could have come back for someone else after the
 * user it named was deleted.
 *
 * @since 0.2.0
 */
final readonly class CustomerLink {

	/**
	 * Holds the link.
	 *
	 * @since 0.2.0
	 *
	 * @param int         $customerId   The customer record.
	 * @param int         $userId       The WordPress user it is linked to.
	 * @param string|null $registeredAt When that user registered, as the link copied it: `Y-m-d H:i:s`, UTC; null
	 *                                  when the row holds none, which no link the store makes does.
	 */
	public function __construct(
		public int $customerId,
		public int $userId,
		public ?string $registeredAt
	) {
	}

	/**
	 * Tells whether the link names a user who registered at a time.
	 *
	 * @since 0.2.0
	 *
	 * @param string|null $registeredAt When the user registered, `Y-m-d H:i:s`, UTC; null when there is no such user.
	 * @return bool True when the link holds that time.
	 */
	public function matches( ?string $registeredAt ): bool {
		return $this->registeredAt === $registeredAt;
	}
}
