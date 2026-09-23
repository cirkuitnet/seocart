<?php
/**
 * SecondConnection: a second, independent MySQL connection for concurrency tests
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support;

// phpcs:disable WordPress.DB.RestrictedFunctions, WordPress.DB.RestrictedClasses -- This class exists to be a raw mysqli connection that wpdb does not know about.

/**
 * Connection B: another client of the test database, opened directly with mysqli.
 *
 * Owns one fact: how a test plays the other runner. It reads the same DB_HOST, DB_USER,
 * DB_PASSWORD and DB_NAME wpdb uses, and parses the host with wpdb::parse_db_host(), so
 * `host:port` and `localhost:/path/to/mysql.sock` both work and no socket path is written
 * into a test.
 *
 * Interleaving is controlled by the test, never by the clock. queryAsync() sends a statement
 * that may block inside the server (on a row lock or a GET_LOCK) and returns at once;
 * isReady( 0 ) asserts "still blocked" without waiting; isReady( $deadline ) waits for an
 * answer that must come, and the deadline is a certainty, not a pause.
 *
 * It never calls mysqli_report(): that setting is global to the process and wpdb relies on
 * error reporting being off. Every error number is checked by hand and thrown as a
 * \RuntimeException whose code is the MySQL error number.
 *
 * @since 0.1.0
 */
final class SecondConnection {

	/**
	 * The connection.
	 *
	 * @since 0.1.0
	 *
	 * @var \mysqli
	 */
	private \mysqli $link;

	/**
	 * Whether an asynchronous statement is waiting to be reaped.
	 *
	 * @since 0.1.0
	 *
	 * @var bool
	 */
	private bool $pending = false;

	/**
	 * Whether close() has run.
	 *
	 * @since 0.1.0
	 *
	 * @var bool
	 */
	private bool $closed = false;

	/**
	 * Opens the connection to the test database.
	 *
	 * @since 0.1.0
	 *
	 * @throws \RuntimeException When the connection cannot be opened.
	 */
	public function __construct() {
		global $wpdb;

		$parts = $wpdb->parse_db_host( DB_HOST );

		if ( false === $parts ) {
			throw new \RuntimeException( 'DB_HOST could not be parsed.' );
		}

		list( $host, $port, $socket ) = $parts;

		$link = mysqli_init();

		if ( false === $link || ! mysqli_real_connect( $link, $host, DB_USER, DB_PASSWORD, DB_NAME, $port, $socket ) ) {
			throw new \RuntimeException( 'The second test connection could not be opened: ' . mysqli_connect_error() );
		}

		mysqli_set_charset( $link, 'utf8mb4' );

		$this->link = $link;
	}

	/**
	 * Sends a statement and waits for it.
	 *
	 * @since 0.1.0
	 *
	 * @throws \RuntimeException When the server refuses it; the code is the MySQL error number.
	 *
	 * @param string $sql The statement.
	 */
	public function query( string $sql ): void {
		$result = mysqli_query( $this->link, $sql );

		if ( false === $result ) {
			$this->fail( $sql );
		}

		if ( $result instanceof \mysqli_result ) {
			mysqli_free_result( $result );
		}
	}

	/**
	 * Sends a query and returns the first column of its first row.
	 *
	 * @since 0.1.0
	 *
	 * @param string $sql The query.
	 * @return string|null The value, or null for no row or a NULL.
	 */
	public function fetchValue( string $sql ): ?string {
		$row = $this->fetchRow( $sql );

		return null === $row ? null : ( array_values( $row )[0] ?? null );
	}

	/**
	 * Sends a query and returns its first row.
	 *
	 * @since 0.1.0
	 *
	 * @throws \RuntimeException When the server refuses it; the code is the MySQL error number.
	 *
	 * @param string $sql The query.
	 * @return array<string, string|null>|null The row, or null when there is none.
	 */
	public function fetchRow( string $sql ): ?array {
		$result = mysqli_query( $this->link, $sql );

		if ( ! $result instanceof \mysqli_result ) {
			$this->fail( $sql );
		}

		$row = mysqli_fetch_assoc( $result );

		mysqli_free_result( $result );

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Returns the rows the last statement changed.
	 *
	 * @since 0.1.0
	 *
	 * @return int The count.
	 */
	public function affectedRows(): int {
		return (int) mysqli_affected_rows( $this->link );
	}

	/**
	 * Returns this connection's server thread id.
	 *
	 * @since 0.1.0
	 *
	 * @return int The id.
	 */
	public function threadId(): int {
		return (int) mysqli_thread_id( $this->link );
	}

	/**
	 * Ends another connection of the same MySQL user, as a server restart or a timeout would.
	 *
	 * @since 0.1.0
	 *
	 * @param int $threadId The victim's thread id.
	 */
	public function kill( int $threadId ): void {
		$this->query( 'KILL ' . $threadId );
	}

	/**
	 * Sends a statement without waiting for its answer. It may block inside the server.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException When the previous asynchronous statement was not reaped.
	 *
	 * @param string $sql The statement.
	 */
	public function queryAsync( string $sql ): void {
		if ( $this->pending ) {
			throw new \LogicException( 'Reap the previous asynchronous statement first.' );
		}

		mysqli_query( $this->link, $sql, MYSQLI_ASYNC );

		$this->pending = true;
	}

	/**
	 * Tells whether the asynchronous statement has an answer, waiting at most the given time.
	 *
	 * @since 0.1.0
	 *
	 * @param int $timeoutMs How long to wait. 0 answers at once.
	 * @return bool True when the answer is ready to reap.
	 */
	public function isReady( int $timeoutMs ): bool {
		$read   = array( $this->link );
		$error  = array( $this->link );
		$reject = array();

		return mysqli_poll( $read, $error, $reject, intdiv( $timeoutMs, 1000 ), ( $timeoutMs % 1000 ) * 1000 ) > 0;
	}

	/**
	 * Collects the answer of the asynchronous statement.
	 *
	 * @since 0.1.0
	 *
	 * @throws \RuntimeException When the statement failed; the code is the MySQL error number.
	 *
	 * @return string|int|null The first column of the first row for a query; the affected rows otherwise.
	 */
	public function reap(): string|int|null {
		$this->pending = false;

		$result = mysqli_reap_async_query( $this->link );

		if ( $result instanceof \mysqli_result ) {
			$row = mysqli_fetch_row( $result );

			mysqli_free_result( $result );

			return is_array( $row ) && null !== $row[0] ? (string) $row[0] : null;
		}

		// A statement without a result set: the error number, not the return value, tells success.
		if ( 0 !== mysqli_errno( $this->link ) ) {
			$this->fail( '(asynchronous statement)' );
		}

		return $this->affectedRows();
	}

	/**
	 * Closes the connection. The server rolls back its open transaction and frees its locks.
	 *
	 * @since 0.1.0
	 */
	public function close(): void {
		if ( $this->closed ) {
			return;
		}

		$this->closed = true;

		mysqli_close( $this->link );
	}

	/**
	 * Throws the error of the last statement.
	 *
	 * @since 0.1.0
	 *
	 * @throws \RuntimeException Always.
	 *
	 * @param string $sql The statement that failed.
	 * @return never
	 */
	private function fail( string $sql ): never {
		throw new \RuntimeException(
			sprintf( 'Connection B: error %d (%s) for: %s', mysqli_errno( $this->link ), mysqli_error( $this->link ), $sql ),
			mysqli_errno( $this->link )
		);
	}
}
