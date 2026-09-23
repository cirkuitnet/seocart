<?php
/**
 * MysqlErrno: the MySQL error numbers the Database module acts on
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Names for the MySQL and client error numbers the module compares against.
 *
 * Owns one fact: which server error number means what. QueryFailed::fromErrno() is the only
 * place that turns a number into an exception class; other code only asks whether a failure
 * it expects, such as a missing table, is the one it caught. A test keeps these numbers from
 * being written as literals anywhere else in the module.
 *
 * @since 0.1.0
 */
final class MysqlErrno {

	/**
	 * CREATE TABLE of a table that exists.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const TABLE_EXISTS = 1050;

	/**
	 * A duplicate value for a unique key.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const DUPLICATE_ENTRY = 1062;

	/**
	 * A table that does not exist.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const NO_SUCH_TABLE = 1146;

	/**
	 * A lock-wait timeout: InnoDB rolled back the statement.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const LOCK_WAIT_TIMEOUT = 1205;

	/**
	 * A deadlock: InnoDB rolled back the whole transaction.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const DEADLOCK = 1213;

	/**
	 * A savepoint that does not exist, which is what the transaction probe looks for.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const SAVEPOINT_DOES_NOT_EXIST = 1305;

	/**
	 * The server has gone away; also what wpdb assumes when it holds no usable connection.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const SERVER_GONE = 2006;

	/**
	 * The connection was lost during a query.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const CONNECTION_LOST = 2013;
}
