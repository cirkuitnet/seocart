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
use SEOCart\Platform\Database\MysqlErrno;
use SEOCart\Platform\Database\StatementDiagnostic;

defined( 'ABSPATH' ) || exit;

/**
 * A statement failed, and the server's error number says how.
 *
 * Owns one fact: which exception class a MySQL error number becomes. fromErrno() is the only
 * place that decision is made; everything else catches the class it produces. The decision
 * reads the error number and never the error text, which differs between MySQL and MariaDB
 * and between server languages. The context holds the error number and the SQLSTATE only; the
 * statement and the server's text are in the StatementDiagnostic this exception carries as its
 * previous exception.
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
	 * A duplicate entry is a unique key doing its job. A deadlock rolls back the whole
	 * transaction; a lock-wait timeout rolls back the statement, but the unit of work cannot
	 * safely continue. Both are worth running again from the beginning.
	 *
	 * @since 0.1.0
	 *
	 * @var array<int, class-string<QueryFailed>>
	 */
	private const CLASSES = array(
		MysqlErrno::DUPLICATE_ENTRY   => DuplicateKey::class,
		MysqlErrno::LOCK_WAIT_TIMEOUT => TransactionRetryable::class,
		MysqlErrno::DEADLOCK          => TransactionRetryable::class,
	);

	/**
	 * Error numbers that mean the connection went away. Inside a transaction, the transaction is gone as well.
	 *
	 * @since 0.1.0
	 *
	 * @var list<int>
	 */
	private const CONNECTION_GONE = array( MysqlErrno::SERVER_GONE, MysqlErrno::CONNECTION_LOST );

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
		$diagnostic = StatementDiagnostic::of( $statement, $serverMessage );

		if ( $insideTransaction && in_array( $errno, self::CONNECTION_GONE, true ) ) {
			return TransactionIntegrityLost::lost( TransactionIntegrityLost::CONNECTION_LOST, $statement, $diagnostic );
		}

		$class = self::CLASSES[ $errno ] ?? self::class;

		return $class::because( $class::CODE, self::facts( $errno, $sqlstate ), $diagnostic );
	}

	/**
	 * Raises a QueryFailed for a statement refused outside the error-number table: by WordPress, or while dbDelta ran.
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
		$refused = self::because( self::CODE, self::facts( $errno, $sqlstate ), StatementDiagnostic::of( $statement, $serverMessage ) );

		throw $refused;
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
	 * Returns the beginning of the statement that failed, from the diagnostic. Never render it.
	 *
	 * @since 0.1.0
	 *
	 * @return string At most STATEMENT_LENGTH characters, or an empty string.
	 */
	public function statement(): string {
		return (string) $this->diagnostic()?->statement();
	}

	/**
	 * Returns the error text the server gave, from the diagnostic. For people only: never decide on it, never render it.
	 *
	 * @since 0.1.0
	 *
	 * @return string The text, or an empty string.
	 */
	public function serverMessage(): string {
		return (string) $this->diagnostic()?->serverMessage();
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
	 * @param int    $errno    The MySQL error number.
	 * @param string $sqlstate The SQLSTATE.
	 * @return array{errno: int, sqlstate: string} The context.
	 */
	private static function facts( int $errno, string $sqlstate ): array {
		return array(
			'errno'    => $errno,
			'sqlstate' => $sqlstate,
		);
	}
}
