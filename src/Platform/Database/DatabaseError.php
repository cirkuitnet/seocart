<?php
/**
 * DatabaseError: the error catalog of the Database module
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Database;

use SEOCart\Support\Error\ErrorCode;
use SEOCart\Support\Error\ErrorDefinition;

defined( 'ABSPATH' ) || exit;

/**
 * The errors the Database module raises, each with its HTTP status and message.
 *
 * This enum owns one fact: how each database failure is reported through the one error
 * table. Every case is raised by exactly one exception class under Exception/, which names
 * its case in its CODE constant. The context keys of a failure are the placeholders of its
 * row. Codes that are only logged or recorded, never thrown, are in ReportCode instead.
 *
 * A transaction that could not be completed and a lock another process holds answer 503: the
 * request may succeed if it is sent again. A duplicate unique key answers 409. Everything else
 * is a server fault, 500.
 *
 * @since 0.1.0
 */
enum DatabaseError: string implements ErrorCode {

	/**
	 * The database refused a statement for a reason no other case covers.
	 *
	 * @since 0.1.0
	 */
	case QueryFailed = 'database.query_failed';

	/**
	 * A statement would have broken a unique key.
	 *
	 * @since 0.1.0
	 */
	case DuplicateKey = 'database.duplicate_key';

	/**
	 * A deadlock or a lock-wait timeout ended the unit of work; running it again may succeed.
	 *
	 * @since 0.1.0
	 */
	case TransactionRetryable = 'database.transaction_retryable';

	/**
	 * The transaction could not be committed as the one that began.
	 *
	 * @since 0.1.0
	 */
	case TransactionLost = 'database.transaction_lost';

	/**
	 * Transactions were nested deeper than the ceiling.
	 *
	 * @since 0.1.0
	 */
	case TransactionDepth = 'database.transaction_depth';

	/**
	 * Work that must never run inside a transaction was attempted inside one.
	 *
	 * @since 0.1.0
	 */
	case ForbiddenInTransaction = 'database.forbidden_in_transaction';

	/**
	 * Another process held a lock for the whole bounded wait.
	 *
	 * @since 0.1.0
	 */
	case LockNotAcquired = 'database.lock_not_acquired';

	/**
	 * A lease the process believed it held is no longer its own.
	 *
	 * @since 0.1.0
	 */
	case LockLost = 'database.lock_lost';

	/**
	 * A migration threw, or its tables do not match their declarations afterwards.
	 *
	 * @since 0.1.0
	 */
	case MigrationFailed = 'database.migration_failed';

	/**
	 * Returns the catalog's rows.
	 *
	 * @since 0.1.0
	 *
	 * @return list<ErrorDefinition> One row per case.
	 */
	public static function definitions(): array {
		$statement = array( 'errno', 'sqlstate', 'statement', 'server_message' );

		return array(
			new ErrorDefinition(
				self::QueryFailed,
				500,
				static fn(): string =>
					/* translators: %1$s: MySQL error number. %2$s: SQLSTATE code. %3$s: The beginning of the SQL statement. %4$s: The error text the database server returned. */
					__( 'The database refused a statement with error %1$s (SQLSTATE %2$s): %3$s. The server said: %4$s', 'seocart' ),
				$statement
			),
			new ErrorDefinition(
				self::DuplicateKey,
				409,
				static fn(): string =>
					/* translators: %1$s: MySQL error number. %2$s: SQLSTATE code. %3$s: The beginning of the SQL statement. %4$s: The error text the database server returned. */
					__( 'The record already exists: the database refused a duplicate value for a unique key with error %1$s (SQLSTATE %2$s) in: %3$s. The server said: %4$s', 'seocart' ),
				$statement
			),
			new ErrorDefinition(
				self::TransactionRetryable,
				500,
				static fn(): string =>
					/* translators: %1$s: MySQL error number. %2$s: SQLSTATE code. %3$s: The beginning of the SQL statement. %4$s: The error text the database server returned. */
					__( 'The database ended the operation to resolve a conflict with another request, with error %1$s (SQLSTATE %2$s) at: %3$s. The server said: %4$s', 'seocart' ),
				$statement
			),
			new ErrorDefinition(
				self::TransactionLost,
				503,
				static fn(): string =>
					/* translators: %1$s: Why the transaction was lost, a code such as connection_changed. %2$s: The beginning of the SQL statement at which it was noticed. */
					__( 'The database transaction could not be completed safely (%1$s) at: %2$s. Try again.', 'seocart' ),
				array( 'reason', 'statement' )
			),
			new ErrorDefinition(
				self::TransactionDepth,
				500,
				static fn(): string =>
					/* translators: %1$s: The deepest nesting of database transactions allowed. */
					__( 'Database transactions were nested more than %1$s levels deep.', 'seocart' ),
				array( 'max_depth' )
			),
			new ErrorDefinition(
				self::ForbiddenInTransaction,
				500,
				static fn(): string =>
					/* translators: %1$s: The kind of work, such as http, mail, ddl or lock. %2$s: Where it was attempted: a host name, a statement or a lock name. */
					__( 'Work of the kind %1$s is not allowed while a database transaction is open (%2$s).', 'seocart' ),
				array( 'kind', 'detail' )
			),
			new ErrorDefinition(
				self::LockNotAcquired,
				503,
				static fn(): string =>
					/* translators: %1$s: The lock name. %2$s: How long the process waited, in milliseconds. */
					__( 'Another process holds the lock %1$s; gave up after waiting %2$s milliseconds. Try again later.', 'seocart' ),
				array( 'name', 'waited' )
			),
			new ErrorDefinition(
				self::LockLost,
				500,
				static fn(): string =>
					/* translators: %1$s: The lock name. %2$s: How the lock was held, get_lock or table. %3$s: Why it is lost, a code such as reclaimed. */
					__( 'The lock %1$s (%2$s) is no longer held by this process (%3$s).', 'seocart' ),
				array( 'name', 'mode', 'reason' )
			),
			new ErrorDefinition(
				self::MigrationFailed,
				500,
				static fn(): string =>
					/* translators: %1$s: The migration id. %2$s: The error code recorded for it. %3$s: What went wrong, or the differences between the tables and their declarations, one per line. */
					__( 'Database migration %1$s failed with %2$s: %3$s', 'seocart' ),
				array( 'migration_id', 'error_code', 'detail' )
			),
		);
	}
}
