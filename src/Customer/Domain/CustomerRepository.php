<?php
/**
 * CustomerRepository: the port to the customer records
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Customer\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes the customer records and their links.
 *
 * Owns one fact for the customer module: how a record and its link are stored. Each method is one
 * statement. The link is read by the user's id alone and judged by the caller with the user's
 * registration time; no method joins the WordPress users, which a network or a split database may
 * keep elsewhere.
 *
 * @since 0.2.0
 */
interface CustomerRepository {

	/**
	 * Returns the record linked to a user, with the registration time it was linked at. One read.
	 *
	 * @since 0.2.0
	 *
	 * @param int $userId The WordPress user, from 1.
	 * @return CustomerLink|null The link; null when no record is linked to the user's id.
	 */
	public function linkOf( int $userId ): ?CustomerLink;

	/**
	 * Creates a record linked to a user, or returns the one another request linked to the user first. One statement, inside the caller's transaction.
	 *
	 * Two first orders of one user at once insert into the same unique key: the second waits for
	 * the first to commit, then takes its record.
	 *
	 * @since 0.2.0
	 *
	 * @throws \LogicException Outside a transaction, or when the server answers no record.
	 *
	 * @param string $uuid         The new record's public identifier.
	 * @param int    $userId       The WordPress user, from 1.
	 * @param string $registeredAt When the user registered, `Y-m-d H:i:s`, UTC.
	 * @return int The record linked to the user.
	 */
	public function create( string $uuid, int $userId, string $registeredAt ): int;

	/**
	 * Empties a record's link, whoever it names. One conditional update.
	 *
	 * @since 0.2.0
	 *
	 * @param int $customerId The record.
	 * @return bool True when the record was linked and is no longer.
	 */
	public function unlink( int $customerId ): bool;

	/**
	 * Empties a record's link while it still names a user at a registration time. One conditional update, whose `WHERE` holds the link as read.
	 *
	 * @since 0.2.0
	 *
	 * @param CustomerLink $link The link as read.
	 * @return bool True when the record still had that link and no longer has it.
	 */
	public function unlinkStale( CustomerLink $link ): bool;

	/**
	 * Lists the linked records after one, in id order. One read.
	 *
	 * @since 0.2.0
	 *
	 * @param int $afterId The last record of the page before, or 0 for the first page.
	 * @param int $limit   The most records to list.
	 * @return list<CustomerLink> The links.
	 */
	public function linked( int $afterId, int $limit ): array;
}
