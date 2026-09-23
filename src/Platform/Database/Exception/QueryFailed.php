<?php
/**
 * QueryFailed: a statement the database refused, and the table that types it by errno
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Database\Exception;

use SEOCart\Platform\Database\DatabaseError;

defined( 'ABSPATH' ) || exit;

/**
 * A statement failed, and the server's error number says how.
 *
 * Owns one fact: which exception class a MySQL error number becomes. fromErrno() is the only
 * place that decision is made; everything else catches the class it produces. The decision
 * reads the error number and never the error text, which differs between MySQL and MariaDB
 * and between server languages; the text travels in the context for people only.
 *
 * The table is hand-maintained, so QueryFailedMappingTest compares it with the documented
 * mapping as a set (DRY rule 11).
 *
 * @since 0.1.0
 */
class QueryFailed extends DatabaseException {

	/**
	 * The catalog case this class raises.
	 *
	 * @since 0.1.0
	 *
	 * @var DatabaseError
	 */
	public const CODE = DatabaseError::QueryFailed;

	/**
	 * Error numbers that become a more specific class. Any other number stays a QueryFailed.
	 *
	 * 1062 is a duplicate entry for a unique key. 1213 is a deadlock, after which InnoDB has
	 * rolled back the whole transaction; 1205 is a lock-wait timeout, after which only the
	 * statement was rolled back but the unit of work cannot safely continue. Both are worth
	 * running again from the beginning.
	 *
	 * @since 0.1.0
	 *
	 * @var array<int, class-string<QueryFailed>>
	 */
	private const CLASSES = array(
		1062 => DuplicateKey::class,
		1205 => TransactionRetryable::class,
		1213 => TransactionRetryable::class,
	);

	/**
	 * Error numbers that mean the connection went away: server gone (2006) and connection lost (2013).
	 *
	 * Inside a transaction they mean the transaction is gone as well.
	 *
	 * @since 0.1.0
	 *
	 * @var list<int>
	 */
	private const CONNECTION_LOST = array( 2006, 2013 );

	/**
	 * How much of a statement is kept. Enough to recognize it, short of most values.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const STATEMENT_LENGTH = 120;

	/**
	 * Types a failed statement by its error number, without throwing it.
	 *
	 * @since 0.1.0
	 *
	 * @param int    $errno             The MySQL error number, or 0 when WordPress refused the statement first.
	 * @param string $sqlstate          The SQLSTATE, or an empty string.
	 * @param string $statement         The statement that failed.
	 * @param string $serverMessage     The error text wpdb recorded.
	 * @param bool   $insideTransaction Whether the statement ran inside a transaction window.
	 * @return DatabaseException A TransactionIntegrityLost when the connection went away inside a
	 *                           window; otherwise a QueryFailed or the subclass the table names.
	 */
	public static function fromErrno( int $errno, string $sqlstate, string $statement, string $serverMessage, bool $insideTransaction ): DatabaseException {
		if ( $insideTransaction && in_array( $errno, self::CONNECTION_LOST, true ) ) {
			return TransactionIntegrityLost::lost( TransactionIntegrityLost::CONNECTION_LOST, $statement );
		}

		$class = self::CLASSES[ $errno ] ?? self::class;

		return $class::because( $class::CODE, self::facts( $errno, $sqlstate, $statement, $serverMessage ) );
	}

	/**
	 * Raises a QueryFailed for a statement refused before or outside the error-number table.
	 *
	 * @since 0.1.0
	 *
	 * @throws QueryFailed Always.
	 *
	 * @param int    $errno         The MySQL error number, or 0.
	 * @param string $sqlstate      The SQLSTATE, or an empty string.
	 * @param string $statement     The statement.
	 * @param string $serverMessage What refused it.
	 * @return never
	 */
	public static function raiseRefused( int $errno, string $sqlstate, string $statement, string $serverMessage ): never {
		self::raise( self::CODE, self::facts( $errno, $sqlstate, $statement, $serverMessage ) );
	}

	/**
	 * Returns the MySQL error number.
	 *
	 * @since 0.1.0
	 *
	 * @return int The error number, or 0 when WordPress refused the statement before the server saw it.
	 */
	public function errno(): int {
		return (int) $this->context()['errno'];
	}

	/**
	 * Returns the SQLSTATE.
	 *
	 * @since 0.1.0
	 *
	 * @return string The SQLSTATE, or an empty string.
	 */
	public function sqlstate(): string {
		return (string) $this->context()['sqlstate'];
	}

	/**
	 * Returns the beginning of the statement that failed.
	 *
	 * @since 0.1.0
	 *
	 * @return string At most STATEMENT_LENGTH characters.
	 */
	public function statement(): string {
		return (string) $this->context()['statement'];
	}

	/**
	 * Returns the error text the server gave. For people only: never decide on it.
	 *
	 * @since 0.1.0
	 *
	 * @return string The text.
	 */
	public function serverMessage(): string {
		return (string) $this->context()['server_message'];
	}

	/**
	 * Cuts a statement to the length kept.
	 *
	 * @since 0.1.0
	 *
	 * @param string $statement A statement.
	 * @return string Its first STATEMENT_LENGTH characters, with whitespace runs collapsed.
	 */
	public static function shorten( string $statement ): string {
		return substr( trim( (string) preg_replace( '/\s+/', ' ', $statement ) ), 0, self::STATEMENT_LENGTH );
	}

	/**
	 * Builds the context the three statement codes share.
	 *
	 * @since 0.1.0
	 *
	 * @param int    $errno         The MySQL error number.
	 * @param string $sqlstate      The SQLSTATE.
	 * @param string $statement     The statement, cut to STATEMENT_LENGTH here.
	 * @param string $serverMessage The error text.
	 * @return array{errno: int, sqlstate: string, statement: string, server_message: string} The context.
	 */
	private static function facts( int $errno, string $sqlstate, string $statement, string $serverMessage ): array {
		return array(
			'errno'          => $errno,
			'sqlstate'       => $sqlstate,
			'statement'      => self::shorten( $statement ),
			'server_message' => $serverMessage,
		);
	}
}
