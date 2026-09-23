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
	 * The machine code.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const CODE = 'database.query_failed';

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
	 * How much of a statement is kept for the log. Enough to recognize it, short of most values.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const STATEMENT_LENGTH = 120;

	/**
	 * The MySQL error number, or 0 when WordPress refused the statement before the server saw it.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private int $errno;

	/**
	 * The SQLSTATE the server reported, or an empty string.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private string $sqlstate;

	/**
	 * The beginning of the statement that failed.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private string $statement;

	/**
	 * Describes a refused statement.
	 *
	 * @since 0.1.0
	 *
	 * @param int    $errno         The MySQL error number, or 0.
	 * @param string $sqlstate      The SQLSTATE, or an empty string.
	 * @param string $statement     The statement. Only its first STATEMENT_LENGTH characters are kept.
	 * @param string $serverMessage The error text wpdb recorded. Context only, never a discriminator.
	 */
	final public function __construct( int $errno, string $sqlstate, string $statement, string $serverMessage ) {
		$this->errno     = $errno;
		$this->sqlstate  = $sqlstate;
		$this->statement = self::shorten( $statement );

		parent::__construct(
			sprintf( 'The database refused a statement (error %d, SQLSTATE %s): %s', $errno, '' === $sqlstate ? 'none' : $sqlstate, $this->statement ),
			array(
				'errno'          => $errno,
				'sqlstate'       => $sqlstate,
				'statement'      => $this->statement,
				'server_message' => $serverMessage,
			)
		);
	}

	/**
	 * Types a failed statement by its error number.
	 *
	 * @since 0.1.0
	 *
	 * @param int    $errno             The MySQL error number, or 0.
	 * @param string $sqlstate          The SQLSTATE, or an empty string.
	 * @param string $statement         The statement that failed.
	 * @param string $serverMessage     The error text wpdb recorded.
	 * @param bool   $insideTransaction Whether the statement ran inside a transaction window.
	 * @return DatabaseException A TransactionIntegrityLost when the connection went away inside a
	 *                           window; otherwise a QueryFailed or the subclass the table names.
	 */
	public static function fromErrno( int $errno, string $sqlstate, string $statement, string $serverMessage, bool $insideTransaction ): DatabaseException {
		if ( $insideTransaction && in_array( $errno, self::CONNECTION_LOST, true ) ) {
			return TransactionIntegrityLost::connectionLost( $errno, $statement );
		}

		$class = self::CLASSES[ $errno ] ?? self::class;

		return new $class( $errno, $sqlstate, $statement, $serverMessage );
	}

	/**
	 * Returns the MySQL error number.
	 *
	 * @since 0.1.0
	 *
	 * @return int The error number, or 0 when WordPress refused the statement before the server saw it.
	 */
	public function errno(): int {
		return $this->errno;
	}

	/**
	 * Returns the SQLSTATE.
	 *
	 * @since 0.1.0
	 *
	 * @return string The SQLSTATE, or an empty string.
	 */
	public function sqlstate(): string {
		return $this->sqlstate;
	}

	/**
	 * Returns the beginning of the statement that failed.
	 *
	 * @since 0.1.0
	 *
	 * @return string At most STATEMENT_LENGTH characters.
	 */
	public function statement(): string {
		return $this->statement;
	}

	/**
	 * Cuts a statement to the length kept for the log.
	 *
	 * @since 0.1.0
	 *
	 * @param string $statement A statement.
	 * @return string Its first STATEMENT_LENGTH characters, with whitespace runs collapsed.
	 */
	public static function shorten( string $statement ): string {
		return substr( trim( (string) preg_replace( '/\s+/', ' ', $statement ) ), 0, self::STATEMENT_LENGTH );
	}
}
