<?php
/**
 * Database: the one wrapper around the wpdb connection, its statements and its transactions
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Database;

use SEOCart\Platform\Database\Exception\DatabaseException;
use SEOCart\Platform\Database\Exception\QueryFailed;
use SEOCart\Platform\Database\Exception\TransactionDepthExceeded;
use SEOCart\Platform\Database\Exception\TransactionIntegrityLost;
use SEOCart\Platform\Database\Exception\TransactionRetryable;

defined( 'ABSPATH' ) || exit;

/**
 * Sends every plugin statement to wpdb, and runs units of work as depth-counted savepoint transactions.
 *
 * Owns one fact: the state of the wpdb connection as the plugin uses it. That is the open
 * depth, the connection a transaction began on, whether a deadlock has already ended the unit
 * of work, and, per level, the callbacks to run after COMMIT or ROLLBACK and the object-cache
 * keys to remove if the level rolls back.
 *
 * Two faces. Application services see the TransactionManager port. Repositories, the lock
 * service and the migrator also see the statement methods: execute(), fetchRow(), fetchAll(),
 * fetchValue(), lastInsertId() and table().
 *
 * A depth-0 transaction costs four statements: START TRANSACTION, SAVEPOINT sc_0, RELEASE
 * SAVEPOINT sc_0 and COMMIT. The release is the probe: it fails with error 1305 when anything
 * ended the transaction since it began, whoever did it and however. The connection's thread
 * id, known from the client handshake, is compared after every statement issued inside the
 * window, at no cost; a change means wpdb reconnected.
 *
 * What it cannot do: wpdb re-runs a statement on a fresh connection after error 2006, and that
 * one statement is committed on its own before this class regains control. It is detected at
 * once and reported as `connection_changed`, naming the statement; the generation marker,
 * idempotent claims, conditional updates and `doctor` are what recover from it.
 *
 * @since 0.1.0
 */
final class Database implements TransactionManager {

	/**
	 * MySQL's error for a savepoint that does not exist, which is what the probe looks for.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private const SAVEPOINT_DOES_NOT_EXIST = 1305;

	/**
	 * The error number wpdb itself assumes when it holds no usable connection handle.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private const SERVER_GONE = 2006;

	/**
	 * The name of the probe savepoint, taken at BEGIN and released just before COMMIT.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const PROBE_SAVEPOINT = 'sc_0';

	/**
	 * The connection.
	 *
	 * @since 0.1.0
	 *
	 * @var \wpdb
	 */
	private \wpdb $wpdb;

	/**
	 * Whether the transaction guards throw (development) or report (production).
	 *
	 * @since 0.1.0
	 *
	 * @var bool
	 */
	private bool $strictGuards;

	/**
	 * Receives a machine code and context for anything reported rather than thrown.
	 *
	 * @since 0.1.0
	 *
	 * @var callable(string, array<string, mixed>): void
	 */
	private $report;

	/**
	 * The deepest transaction level allowed.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private int $maxDepth;

	/**
	 * Pauses for a number of milliseconds between retry attempts.
	 *
	 * @since 0.1.0
	 *
	 * @var callable(int): void
	 */
	private $sleep;

	/**
	 * Draws a random integer in an inclusive range, for the retry jitter.
	 *
	 * @since 0.1.0
	 *
	 * @var callable(int, int): int
	 */
	private $random;

	/**
	 * Whether the thread-id comparison is meaningful. It is not under a db.php drop-in, which
	 * may legitimately switch connections between reads and writes; only the probe applies there.
	 *
	 * @since 0.1.0
	 *
	 * @var bool
	 */
	private bool $checksIdentity;

	/**
	 * How many transaction levels are open.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private int $depth = 0;

	/**
	 * The thread id of the connection the open transaction began on.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private int $openedOn = 0;

	/**
	 * Whether the open unit of work has already been ended by the server or lost with its connection.
	 *
	 * @since 0.1.0
	 *
	 * @var bool
	 */
	private bool $aborted = false;

	/**
	 * Per open level, from 1: the after-commit and after-rollback callbacks and the touched cache keys.
	 *
	 * @since 0.1.0
	 *
	 * @var array<int, array{commit: list<callable(): mixed>, rollback: list<callable(): mixed>, keys: array<string, array{0: string, 1: string}>}>
	 */
	private array $levels = array();

	/**
	 * Whether the statement being sent is one of the wrapper's own transaction-control statements.
	 *
	 * @since 0.1.0
	 *
	 * @var bool
	 */
	private bool $issuingControl = false;

	/**
	 * The transaction guards, registered at the first transaction.
	 *
	 * @since 0.1.0
	 *
	 * @var TransactionGuards|null
	 */
	private ?TransactionGuards $guards = null;

	/**
	 * Wraps a wpdb connection. Constructing it sends nothing and registers nothing.
	 *
	 * @since 0.1.0
	 *
	 * @throws \InvalidArgumentException When the depth ceiling is below 1.
	 *
	 * @param \wpdb         $wpdb         The connection, normally the global `$wpdb`.
	 * @param bool          $strictGuards True to throw when forbidden work happens inside a transaction,
	 *                                    false to report it and let it proceed.
	 * @param callable      $report       Receives a machine code (string) and its context (array).
	 * @param int           $maxDepth     Optional. The deepest level allowed. Default 5.
	 * @param callable|null $sleep        Optional. Pauses for a number of milliseconds (int). Default null,
	 *                                    which uses usleep().
	 * @param callable|null $random       Optional. Draws an integer in an inclusive range (int, int). Default
	 *                                    null, which uses random_int().
	 */
	public function __construct( \wpdb $wpdb, bool $strictGuards, callable $report, int $maxDepth = 5, ?callable $sleep = null, ?callable $random = null ) {
		if ( $maxDepth < 1 ) {
			throw new \InvalidArgumentException( 'The transaction depth ceiling must be at least 1.' );
		}

		$this->wpdb           = $wpdb;
		$this->strictGuards   = $strictGuards;
		$this->report         = $report;
		$this->maxDepth       = $maxDepth;
		$this->sleep          = $sleep ?? static function ( int $milliseconds ): void {
			usleep( $milliseconds * 1000 );
		};
		$this->random         = $random ?? static function ( int $min, int $max ): int {
			return random_int( $min, $max );
		};
		$this->checksIdentity = 'wpdb' === get_class( $wpdb );
	}

	/**
	 * Runs a unit of work inside a transaction.
	 *
	 * Whatever the work throws propagates after the level is rolled back.
	 *
	 * @since 0.1.0
	 *
	 * @throws TransactionDepthExceeded|TransactionIntegrityLost|TransactionRetryable When the level would be
	 *         deeper than the ceiling (no statement is sent); when the transaction cannot be committed as
	 *         the one that began; or when a deadlock or lock-wait timeout ended the last attempt the
	 *         policy allows.
	 *
	 * @param-immediately-invoked-callable $work
	 *
	 * @param callable(): mixed $work  The unit of work.
	 * @param RetryPolicy|null  $retry Optional. Honoured at the outermost level only. Default null, which never retries.
	 * @return mixed What the callable returned, unchanged.
	 */
	public function transaction( callable $work, ?RetryPolicy $retry = null ): mixed {
		if ( $this->depth + 1 > $this->maxDepth ) {
			TransactionDepthExceeded::raise( TransactionDepthExceeded::CODE, array( 'max_depth' => $this->maxDepth ) );
		}

		$outermost = 0 === $this->depth;
		$policy    = $retry ?? RetryPolicy::none();

		if ( $outermost ) {
			$this->registerGuards();
		} else {
			// After a deadlock an inner savepoint no longer exists; only the outermost level may run the work again.
			$policy = RetryPolicy::none();
		}

		$attempt = 1;

		while ( true ) {
			try {
				return $outermost ? $this->unitOfWork( $work ) : $this->savepoint( $work );
			} catch ( TransactionRetryable $retryable ) {
				if ( $attempt >= $policy->attempts() ) {
					throw $retryable;
				}

				( $this->sleep )( ( $this->random )( 0, $policy->ceilingMs( $attempt ) ) );

				++$attempt;
			}
		}
	}

	/**
	 * Returns how many transaction levels are open.
	 *
	 * @since 0.1.0
	 *
	 * @return int 0 outside any transaction.
	 */
	public function depth(): int {
		return $this->depth;
	}

	/**
	 * Registers work to run once the outermost level has committed, or at once outside a transaction.
	 *
	 * @since 0.1.0
	 *
	 * @param callable(): mixed $callback The work to run after COMMIT.
	 */
	public function afterCommit( callable $callback ): void {
		if ( 0 === $this->depth ) {
			$callback();

			return;
		}

		$this->levels[ $this->depth ]['commit'][] = $callback;
	}

	/**
	 * Registers work to run once the current level has been rolled back. Ignored outside a transaction.
	 *
	 * @since 0.1.0
	 *
	 * @param callable(): mixed $callback The work to run after the rollback.
	 */
	public function afterRollback( callable $callback ): void {
		if ( 0 === $this->depth ) {
			return;
		}

		$this->levels[ $this->depth ]['rollback'][] = $callback;
	}

	/**
	 * Records an object-cache key written inside the current level. Ignored outside a transaction.
	 *
	 * @since 0.1.0
	 *
	 * @param string $key   The cache key.
	 * @param string $group The cache group.
	 */
	public function touchCacheKey( string $key, string $group ): void {
		if ( 0 === $this->depth ) {
			return;
		}

		$this->levels[ $this->depth ]['keys'][ $group . "\0" . $key ] = array( $key, $group );
	}

	/**
	 * Sends a statement that returns no rows.
	 *
	 * @since 0.1.0
	 *
	 * @throws DatabaseException When the statement fails, typed by its error number.
	 *
	 * @param string $sql     The statement, with wpdb placeholders (%i, %s, %d, %f) when arguments are given.
	 * @param mixed  ...$args Values for the placeholders. When none are given the statement is sent as written.
	 * @return int The rows an INSERT, UPDATE, DELETE or REPLACE affected; 0 for DDL; the rows returned otherwise.
	 */
	public function execute( string $sql, mixed ...$args ): int {
		$result = $this->statement( $this->prepare( $sql, $args ) );

		return is_int( $result ) ? $result : 0;
	}

	/**
	 * Sends a query and returns its first row.
	 *
	 * @since 0.1.0
	 *
	 * @throws DatabaseException When the query fails, typed by its error number.
	 *
	 * @param string $sql     The query, with wpdb placeholders when arguments are given.
	 * @param mixed  ...$args Values for the placeholders.
	 * @return array<string, mixed>|null The first row keyed by column label, or null when there is none.
	 */
	public function fetchRow( string $sql, mixed ...$args ): ?array {
		return $this->fetchAll( $sql, ...$args )[0] ?? null;
	}

	/**
	 * Sends a query and returns every row.
	 *
	 * @since 0.1.0
	 *
	 * @throws DatabaseException When the query fails, typed by its error number.
	 *
	 * @param string $sql     The query, with wpdb placeholders when arguments are given.
	 * @param mixed  ...$args Values for the placeholders.
	 * @return list<array<string, mixed>> The rows, each keyed by column label. Values are strings or null.
	 */
	public function fetchAll( string $sql, mixed ...$args ): array {
		$this->statement( $this->prepare( $sql, $args ) );

		$rows = array();

		foreach ( (array) $this->wpdb->last_result as $row ) {
			$rows[] = get_object_vars( $row );
		}

		return $rows;
	}

	/**
	 * Sends a query and returns the first column of its first row.
	 *
	 * @since 0.1.0
	 *
	 * @throws DatabaseException When the query fails, typed by its error number.
	 *
	 * @param string $sql     The query, with wpdb placeholders when arguments are given.
	 * @param mixed  ...$args Values for the placeholders.
	 * @return mixed The value, a string, or null when the query returned no row or a NULL.
	 */
	public function fetchValue( string $sql, mixed ...$args ): mixed {
		$row = $this->fetchRow( $sql, ...$args );

		return null === $row ? null : ( array_values( $row )[0] ?? null );
	}

	/**
	 * Returns the AUTO_INCREMENT value of the last INSERT sent through wpdb.
	 *
	 * @since 0.1.0
	 *
	 * @return int The id, or 0 when the last statement inserted nothing.
	 */
	public function lastInsertId(): int {
		return (int) $this->wpdb->insert_id;
	}

	/**
	 * Returns the full name of a plugin table on the current site, for use with the %i placeholder.
	 *
	 * The prefix is read on every call, so the same instance serves every site of a network
	 * across switch_to_blog().
	 *
	 * @since 0.1.0
	 *
	 * @param string $name The table's unprefixed name, for example 'migrations'.
	 * @return string For example `wp_seocart_migrations`, or `wp_3_seocart_migrations` on site 3.
	 */
	public function table( string $name ): string {
		return $this->wpdb->prefix . 'seocart_' . $name;
	}

	/**
	 * Returns the table prefix of the current site.
	 *
	 * @since 0.1.0
	 *
	 * @return string For example `wp_`, or `wp_3_` on site 3 of a network.
	 */
	public function prefix(): string {
		return $this->wpdb->prefix;
	}

	/**
	 * Returns the name of the database wpdb connected to.
	 *
	 * @since 0.1.0
	 *
	 * @return string The database name.
	 */
	public function databaseName(): string {
		return (string) $this->wpdbProperty( 'dbname' );
	}

	/**
	 * Returns the character set and collation clause every CREATE TABLE ends with.
	 *
	 * @since 0.1.0
	 *
	 * @return string For example `DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci`, or empty.
	 */
	public function charsetCollate(): string {
		return $this->wpdb->get_charset_collate();
	}

	/**
	 * Returns the collation wpdb uses for the connection and for new tables.
	 *
	 * @since 0.1.0
	 *
	 * @return string For example `utf8mb4_unicode_520_ci`, or empty when none is configured.
	 */
	public function collation(): string {
		return (string) $this->wpdb->collate;
	}

	/**
	 * Returns the server thread id of the connection wpdb holds now. Sends nothing.
	 *
	 * @since 0.1.0
	 *
	 * @return int The id from the client handshake, or 0 when there is no connection.
	 */
	public function threadId(): int {
		$connection = $this->connection();

		return $connection instanceof \mysqli ? (int) mysqli_thread_id( $connection ) : 0; // phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_thread_id -- wpdb has no API for the connection identity; see the class description.
	}

	/**
	 * Describes the connection for Site Health and `doctor`. Sends nothing.
	 *
	 * @since 0.1.0
	 *
	 * @return array{wpdb_class: string, identity_check: bool, thread_id: int, server_version: string} The facts.
	 */
	public function connectionReport(): array {
		$connection = $this->connection();

		return array(
			'wpdb_class'     => get_class( $this->wpdb ),
			'identity_check' => $this->checksIdentity,
			'thread_id'      => $this->threadId(),
			'server_version' => $connection instanceof \mysqli ? (string) mysqli_get_server_info( $connection ) : '', // phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_get_server_info -- read from the handshake, as wpdb::db_server_info() does, without assuming a handle.
		);
	}

	/**
	 * Runs the outermost level: BEGIN, the work, the checks, COMMIT, then the after-commit callbacks.
	 *
	 * @since 0.1.0
	 *
	 * @throws \Throwable Whatever the work or the checks threw, after the rollback.
	 *
	 * @param callable(): mixed $work The unit of work.
	 * @return mixed What the callable returned.
	 */
	private function unitOfWork( callable $work ): mixed {
		$this->control( 'START TRANSACTION' );

		$this->openedOn = $this->threadId();
		$this->depth    = 1;
		$this->aborted  = false;
		$this->levels   = array( 1 => self::newLevel() );

		try {
			$this->control( 'SAVEPOINT ' . self::PROBE_SAVEPOINT );

			$result = $work();

			$this->assertCommittable();
			$this->control( 'COMMIT' );
		} catch ( \Throwable $failure ) {
			$this->abandon();

			throw $failure;
		}

		$committed = $this->levels[1]['commit'];

		$this->reset();
		$this->runAfterCommit( $committed );

		return $result;
	}

	/**
	 * Runs an inner level inside its own savepoint. Whatever the work throws propagates after the
	 * level is rolled back.
	 *
	 * @since 0.1.0
	 *
	 * @throws TransactionIntegrityLost When the unit of work was already ended.
	 *
	 * @param callable(): mixed $work The unit of work.
	 * @return mixed What the callable returned.
	 */
	private function savepoint( callable $work ): mixed {
		if ( $this->aborted ) {
			TransactionIntegrityLost::raiseLost( TransactionIntegrityLost::ABORTED, 'SAVEPOINT' );
		}

		$index = $this->depth;
		$name  = 'sc_' . $index;

		$this->control( 'SAVEPOINT ' . $name );

		$this->depth                  = $index + 1;
		$this->levels[ $this->depth ] = self::newLevel();

		try {
			$result = $work();

			if ( $this->aborted ) {
				TransactionIntegrityLost::raiseLost( TransactionIntegrityLost::ABORTED, 'RELEASE SAVEPOINT ' . $name );
			}

			$this->control( 'RELEASE SAVEPOINT ' . $name );
		} catch ( \Throwable $failure ) {
			$level = $this->closeLevel( $index );

			if ( ! $this->aborted ) {
				try {
					$this->control( 'ROLLBACK TO SAVEPOINT ' . $name, false );
				} catch ( DatabaseException $rollbackFailed ) {
					// The savepoint is gone, so the transaction around it is not the one that began: nothing may commit it.
					$this->aborted = true;
				}
			}

			$this->flush( $level['keys'] );
			$this->runAfterRollback( $level['rollback'] );

			throw $failure;
		}

		$level = $this->closeLevel( $index );

		$this->levels[ $index ]['commit']   = array_merge( $this->levels[ $index ]['commit'], $level['commit'] );
		$this->levels[ $index ]['rollback'] = array_merge( $this->levels[ $index ]['rollback'], $level['rollback'] );
		$this->levels[ $index ]['keys']     = $this->levels[ $index ]['keys'] + $level['keys'];

		return $result;
	}

	/**
	 * Proves, just before COMMIT, that the transaction BEGIN opened is still the open one on the same connection.
	 *
	 * @since 0.1.0
	 *
	 * @throws TransactionIntegrityLost|QueryFailed When it is not; or when the probe fails for another reason.
	 */
	private function assertCommittable(): void {
		if ( $this->aborted ) {
			TransactionIntegrityLost::raiseLost( TransactionIntegrityLost::ABORTED, 'COMMIT' );
		}

		$this->assertSameConnection( 'COMMIT' );

		try {
			$this->control( 'RELEASE SAVEPOINT ' . self::PROBE_SAVEPOINT );
		} catch ( QueryFailed $probe ) {
			if ( self::SAVEPOINT_DOES_NOT_EXIST === $probe->errno() ) {
				$this->aborted = true;

				$lost = TransactionIntegrityLost::lost( TransactionIntegrityLost::ENDED_EXTERNALLY, 'RELEASE SAVEPOINT ' . self::PROBE_SAVEPOINT, $probe );

				throw $lost;
			}

			throw $probe;
		}
	}

	/**
	 * Rolls the outermost level back, removes every cache key it touched and runs its after-rollback callbacks.
	 *
	 * ROLLBACK is sent even when the unit of work was already ended; it is harmless then. A
	 * failure of the ROLLBACK itself is reported, never thrown, so the original failure wins.
	 *
	 * @since 0.1.0
	 */
	private function abandon(): void {
		try {
			$this->control( 'ROLLBACK', false );
		} catch ( DatabaseException $failure ) {
			( $this->report )( (string) $failure->errorCode()->value, array( 'statement' => 'ROLLBACK' ) + $failure->context() );
		}

		$keys      = array();
		$callbacks = array();

		foreach ( $this->levels as $level ) {
			$keys      = $keys + $level['keys'];
			$callbacks = array_merge( $callbacks, $level['rollback'] );
		}

		$this->reset();
		$this->flush( $keys );
		$this->runAfterRollback( $callbacks );
	}

	/**
	 * Throws when wpdb no longer holds the connection the transaction began on.
	 *
	 * @since 0.1.0
	 *
	 * @throws TransactionIntegrityLost With the reason `connection_changed`.
	 *
	 * @param string $sql The statement after which the check runs.
	 */
	private function assertSameConnection( string $sql ): void {
		if ( ! $this->checksIdentity ) {
			return;
		}

		$current = $this->threadId();

		if ( $current !== $this->openedOn ) {
			$this->aborted = true;

			TransactionIntegrityLost::raiseLost( TransactionIntegrityLost::CONNECTION_CHANGED, $sql );
		}
	}

	/**
	 * Sends a statement from a caller, unless the unit of work it would belong to has already ended.
	 *
	 * @since 0.1.0
	 *
	 * @throws TransactionIntegrityLost When a deadlock or lock-wait timeout already ended the unit of work.
	 *
	 * @param string $sql The statement, ready to send.
	 * @return int|bool What wpdb::query() returned.
	 */
	private function statement( string $sql ): int|bool {
		if ( $this->aborted ) {
			TransactionIntegrityLost::raiseLost( TransactionIntegrityLost::ABORTED, $sql );
		}

		return $this->send( $sql );
	}

	/**
	 * Sends one of the wrapper's own transaction-control statements, which the DDL guard lets through.
	 *
	 * @since 0.1.0
	 *
	 * @param string $sql           The statement.
	 * @param bool   $checkIdentity Optional. Whether to compare the connection afterwards. Default true.
	 */
	private function control( string $sql, bool $checkIdentity = true ): void {
		$this->issuingControl = true;

		try {
			$this->send( $sql, $checkIdentity );
		} finally {
			$this->issuingControl = false;
		}
	}

	/**
	 * Sends a statement to wpdb with its error output suppressed, and types a failure by its error number.
	 *
	 * @since 0.1.0
	 *
	 * @throws DatabaseException When the statement fails, or when the connection changed inside a window.
	 *
	 * @param string $sql           The statement, ready to send.
	 * @param bool   $checkIdentity Optional. Whether to compare the connection afterwards inside a window. Default true.
	 * @return int|bool What wpdb::query() returned.
	 */
	private function send( string $sql, bool $checkIdentity = true ): int|bool {
		$wpdb       = $this->wpdb;
		$suppressed = $wpdb->suppress_errors( true );

		try {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- The one place plugin statements reach wpdb: prepare() bound every value, and callers own their caching.
			$result = $wpdb->query( $sql );

			// Read at once: the error number is only kept until the next statement on this handle.
			$connection = $this->connection();
			$errno      = $connection instanceof \mysqli ? mysqli_errno( $connection ) : self::SERVER_GONE; // phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_errno -- wpdb keeps only the error text, which is not a discriminator.
			$sqlstate   = $connection instanceof \mysqli ? mysqli_sqlstate( $connection ) : ''; // phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_sqlstate -- as above.
		} finally {
			$wpdb->suppress_errors( $suppressed );
		}

		$inside = $this->depth > 0;

		if ( $inside && $checkIdentity ) {
			$this->assertSameConnection( $sql );
		}

		if ( false !== $result ) {
			return $result;
		}

		$failure = QueryFailed::fromErrno( $errno, $sqlstate, $sql, $wpdb->last_error, $inside );

		if ( $inside && ( $failure instanceof TransactionRetryable || $failure instanceof TransactionIntegrityLost ) ) {
			// InnoDB rolled back the transaction (1213) or the statement (1205), or the connection is gone.
			$this->aborted = true;
		}

		throw $failure;
	}

	/**
	 * Binds placeholder values with wpdb::prepare().
	 *
	 * @since 0.1.0
	 *
	 * @throws QueryFailed When wpdb::prepare() rejects the statement or its arguments.
	 *
	 * @param string       $sql  The statement with placeholders.
	 * @param array<mixed> $args The values. An empty list sends the statement as written.
	 * @return string The statement, ready to send.
	 */
	private function prepare( string $sql, array $args ): string {
		if ( array() === $args ) {
			return $sql;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- This is the prepare step; the statement text is the caller's literal.
		$prepared = $this->wpdb->prepare( $sql, ...$args );

		if ( ! is_string( $prepared ) || '' === $prepared ) {
			QueryFailed::raiseRefused( 0, '', $sql, 'wpdb::prepare() rejected the statement or its arguments.' );
		}

		return $prepared;
	}

	/**
	 * Returns the connection handle wpdb holds now.
	 *
	 * @since 0.1.0
	 *
	 * @return mixed A mysqli object, or null or false when there is none.
	 */
	private function connection(): mixed {
		return $this->wpdbProperty( 'dbh' );
	}

	/**
	 * Reads one of wpdb's protected properties.
	 *
	 * The wpdb class keeps its connection handle and database name protected and documents its
	 * magic getter as the way to read them. PHPStan sees the declared visibility, so the getter is
	 * called explicitly, here and nowhere else.
	 *
	 * @since 0.1.0
	 *
	 * @param string $name The property name.
	 * @return mixed Its value.
	 */
	private function wpdbProperty( string $name ): mixed {
		return $this->wpdb->__get( $name );
	}

	/**
	 * Removes the given keys from the object cache.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, array{0: string, 1: string}> $keys Key and group pairs.
	 */
	private function flush( array $keys ): void {
		foreach ( $keys as $pair ) {
			wp_cache_delete( $pair[0], $pair[1] );
		}
	}

	/**
	 * Runs after-commit callbacks. Every callback runs; the first failure is rethrown afterwards.
	 *
	 * @since 0.1.0
	 *
	 * @throws \Throwable The first failure of a callback, once all of them have run.
	 *
	 * @param callable[] $callbacks The callbacks, in registration order.
	 */
	private function runAfterCommit( array $callbacks ): void {
		$first = null;

		foreach ( $callbacks as $callback ) {
			try {
				$callback();
			} catch ( \Throwable $failure ) {
				$first = $first ?? $failure;
			}
		}

		if ( null !== $first ) {
			throw $first;
		}
	}

	/**
	 * Runs after-rollback callbacks. A failing callback is reported, so the rollback's cause still propagates.
	 *
	 * @since 0.1.0
	 *
	 * @param callable[] $callbacks The callbacks, in registration order.
	 */
	private function runAfterRollback( array $callbacks ): void {
		foreach ( $callbacks as $callback ) {
			try {
				$callback();
			} catch ( \Throwable $failure ) {
				( $this->report )(
					ReportCode::AfterRollbackFailed->value,
					array(
						'exception' => get_class( $failure ),
						'message'   => $failure->getMessage(),
					)
				);
			}
		}
	}

	/**
	 * Closes the level above the given depth and returns what it collected.
	 *
	 * @since 0.1.0
	 *
	 * @param int $index The depth to return to.
	 * @return array{commit: list<callable(): mixed>, rollback: list<callable(): mixed>, keys: array<string, array{0: string, 1: string}>} The closed level.
	 */
	private function closeLevel( int $index ): array {
		$level = $this->levels[ $index + 1 ];

		unset( $this->levels[ $index + 1 ] );

		$this->depth = $index;

		return $level;
	}

	/**
	 * Returns the state of the connection outside any transaction.
	 *
	 * @since 0.1.0
	 */
	private function reset(): void {
		$this->depth    = 0;
		$this->openedOn = 0;
		$this->aborted  = false;
		$this->levels   = array();
	}

	/**
	 * Registers the transaction guards the first time a transaction opens.
	 *
	 * @since 0.1.0
	 */
	private function registerGuards(): void {
		if ( null !== $this->guards ) {
			return;
		}

		$this->guards = new TransactionGuards( $this->strictGuards, $this->report, fn(): bool => $this->issuingControl );
		$this->guards->register( $this );
	}

	/**
	 * Returns an empty level.
	 *
	 * @since 0.1.0
	 *
	 * @return array{commit: list<callable(): mixed>, rollback: list<callable(): mixed>, keys: array<string, array{0: string, 1: string}>} The level.
	 */
	private static function newLevel(): array {
		return array(
			'commit'   => array(),
			'rollback' => array(),
			'keys'     => array(),
		);
	}
}
