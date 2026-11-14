<?php
/**
 * MysqlCustomerRepository: the customer records, in MySQL
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Customer\Infrastructure;

use SEOCart\Customer\Domain\CustomerLink;
use SEOCart\Customer\Domain\CustomerRepository;
use SEOCart\Platform\Database\Database;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- These exceptions report a caller's programming error to the developer; they are never HTML.

/**
 * Reads and writes `customers` with one statement per method.
 *
 * Owns one fact: how the customer records are read and written. Each statement is a public
 * constant, so a concurrency test sends exactly the statement the module sends. A record's link
 * changes only by a conditional update whose `WHERE` holds what the caller read.
 *
 * @since 0.2.0
 */
final class MysqlCustomerRepository implements CustomerRepository {

	/**
	 * The record linked to a user's id, with the registration time it was linked at, by the `user` key.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const LINK_OF = 'SELECT id, user_registered_at FROM %i WHERE user_id = %d';

	/**
	 * A new record linked to a user; when another holds the user's id, that record is taken, and its id becomes the insert id.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const CREATE = 'INSERT INTO %i ( uuid, user_id, user_registered_at, created_at, updated_at ) VALUES ( %s, %d, %s, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6) ) ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID( id )';

	/**
	 * A record's link emptied, whoever it names.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const UNLINK = 'UPDATE %i SET user_id = NULL, user_registered_at = NULL, updated_at = UTC_TIMESTAMP(6) WHERE id = %d AND user_id IS NOT NULL';

	/**
	 * A record's link emptied while it still names the user and the registration time read.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const UNLINK_STALE = 'UPDATE %i SET user_id = NULL, user_registered_at = NULL, updated_at = UTC_TIMESTAMP(6) WHERE id = %d AND user_id = %d AND user_registered_at = %s';

	/**
	 * A page of the linked records, in id order, by the primary key.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const LINKED = 'SELECT id, user_id, user_registered_at FROM %i WHERE id > %d AND user_id IS NOT NULL ORDER BY id LIMIT %d';

	/**
	 * The connection.
	 *
	 * @since 0.2.0
	 *
	 * @var Database
	 */
	private Database $db;

	/**
	 * Creates the repository. Sends nothing.
	 *
	 * @since 0.2.0
	 *
	 * @param Database $db The connection.
	 */
	public function __construct( Database $db ) {
		$this->db = $db;
	}

	/**
	 * Returns the record linked to a user, with the registration time it was linked at.
	 *
	 * @since 0.2.0
	 *
	 * @param int $userId The WordPress user.
	 * @return CustomerLink|null The link; null when no record is linked to the user's id.
	 */
	public function linkOf( int $userId ): ?CustomerLink {
		$row = $this->db->fetchRow( self::LINK_OF, $this->table(), $userId );

		return null === $row ? null : new CustomerLink( (int) $row['id'], $userId, self::timeOf( $row['user_registered_at'] ) );
	}

	/**
	 * Creates a record linked to a user, or returns the one another request linked to the user first.
	 *
	 * @since 0.2.0
	 *
	 * @throws \LogicException Outside a transaction, or when the server answers no record.
	 *
	 * @param string $uuid         The new record's public identifier.
	 * @param int    $userId       The WordPress user.
	 * @param string $registeredAt When the user registered.
	 * @return int The record linked to the user.
	 */
	public function create( string $uuid, int $userId, string $registeredAt ): int {
		$this->requireTransaction( __FUNCTION__ );

		$this->db->execute( self::CREATE, $this->table(), $uuid, $userId, $registeredAt );

		$id = $this->db->lastInsertId();

		if ( $id < 1 ) {
			throw new \LogicException( 'The server answered no customer record for the insert: a record\'s id is never below 1.' );
		}

		return $id;
	}

	/**
	 * Empties a record's link, whoever it names.
	 *
	 * @since 0.2.0
	 *
	 * @param int $customerId The record.
	 * @return bool True when the record was linked and is no longer.
	 */
	public function unlink( int $customerId ): bool {
		return 1 === $this->db->execute( self::UNLINK, $this->table(), $customerId );
	}

	/**
	 * Empties a record's link while it still names a user at a registration time.
	 *
	 * @since 0.2.0
	 *
	 * @throws \LogicException Outside a transaction.
	 *
	 * @param CustomerLink $link The link as read.
	 * @return bool True when the record still had that link and no longer has it.
	 */
	public function unlinkStale( CustomerLink $link ): bool {
		$this->requireTransaction( __FUNCTION__ );

		return 1 === $this->db->execute( self::UNLINK_STALE, $this->table(), $link->customerId, $link->userId, $link->registeredAt );
	}

	/**
	 * Lists the linked records after one, in id order.
	 *
	 * @since 0.2.0
	 *
	 * @param int $afterId The last record of the page before, or 0 for the first page.
	 * @param int $limit   The most records to list.
	 * @return list<CustomerLink> The links.
	 */
	public function linked( int $afterId, int $limit ): array {
		return array_map(
			static fn( array $row ): CustomerLink => new CustomerLink( (int) $row['id'], (int) $row['user_id'], self::timeOf( $row['user_registered_at'] ) ),
			$this->db->fetchAll( self::LINKED, $this->table(), $afterId, $limit )
		);
	}

	/**
	 * Returns a stored registration time as a link holds it.
	 *
	 * @since 0.2.0
	 *
	 * @param mixed $stored The column's value.
	 * @return string|null The time, `Y-m-d H:i:s`; null when the row holds none.
	 */
	private static function timeOf( mixed $stored ): ?string {
		return null === $stored ? null : (string) $stored;
	}

	/**
	 * Refuses a write that must commit with the order it is made for.
	 *
	 * @since 0.2.0
	 *
	 * @throws \LogicException Outside a transaction.
	 *
	 * @param string $method The method called.
	 */
	private function requireTransaction( string $method ): void {
		if ( 0 === $this->db->depth() ) {
			throw new \LogicException( sprintf( 'MysqlCustomerRepository::%s() runs only inside the transaction that writes on the customer\'s behalf: a record is created with the order it is created for, or not at all.', $method ) );
		}
	}

	/**
	 * Returns the table's full name.
	 *
	 * @since 0.2.0
	 *
	 * @return string The name.
	 */
	private function table(): string {
		return $this->db->table( CustomerTables::CUSTOMERS );
	}
}
