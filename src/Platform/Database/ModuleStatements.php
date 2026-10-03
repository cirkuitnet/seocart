<?php
/**
 * ModuleStatements: how a module's statements, written with table and list tokens, are prepared and sent
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Database;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- These exceptions report a caller's programming error to the developer; they are never HTML.

/**
 * Sends one module's statements, each written as a constant that names its tables as tokens.
 *
 * Owns one fact: how a statement written with tokens becomes wpdb placeholders and arguments.
 * A statement names each table as its unprefixed name in braces, `{name}`, and an IN list
 * as `{list}`; expand() turns each table token into `%i` with the table's full name, and each
 * list into one placeholder per item. A token that names no table of the module is refused, so
 * a module's statement cannot reach another module's rows. Writing every statement as a public
 * constant of the class that sends it lets a concurrency test send exactly the statement the
 * module sends, and lets a test read from the constants alone which tables they change.
 *
 * A statement of a variable number of rows is still one constant: an insert of several rows
 * repeats the VALUES tuple of its one-row constant (forRows()), and a statement that joins a
 * table of values repeats its one derived row (forDerivedRows()).
 *
 * Every module that writes its statements this way expands them here; a module that also
 * sends them through an instance of this class needs no sender of its own.
 *
 * @since 0.1.0
 */
final class ModuleStatements {

	/**
	 * A table token, the list token, or a value placeholder.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const PLACEHOLDER = '/\{([a-z_]+)\}|%[dsi]/';

	/**
	 * The token of an IN list.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const LIST_TOKEN = 'list';

	/**
	 * What opens a derived table of values: its row is the SELECT that follows.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const DERIVED_OPEN = '( SELECT ';

	/**
	 * What closes a derived table of values, before its name.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const DERIVED_CLOSE = ' ) AS ';

	/**
	 * What opens the VALUES tuple of a one-row insert: the tuple is the parenthesised group after it.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const VALUES = ' VALUES ';

	/**
	 * The connection.
	 *
	 * @since 0.1.0
	 *
	 * @var Database
	 */
	private Database $db;

	/**
	 * The module's name, for the messages.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private string $module;

	/**
	 * The unprefixed names of the module's tables: the only tables its statements may name.
	 *
	 * @since 0.1.0
	 *
	 * @var list<string>
	 */
	private array $tables;

	/**
	 * Creates the sender. Sends nothing.
	 *
	 * @since 0.1.0
	 *
	 * @param Database $db     The connection.
	 * @param string   $module The module's name, for the messages, for example `payment`.
	 * @param string[] $tables The unprefixed names of the module's tables.
	 *
	 * @phpstan-param list<string> $tables
	 */
	public function __construct( Database $db, string $module, array $tables ) {
		$this->db     = $db;
		$this->module = $module;
		$this->tables = $tables;
	}

	/**
	 * Turns a statement's tokens into wpdb placeholders and its values into arguments, in order.
	 *
	 * A table token becomes `%i` with the table's full name as its argument. `{list}` takes the
	 * next value, a list of ints or strings, and becomes one `%d` or `%s` per item; an empty list
	 * becomes NULL, so `x IN ({list})` matches no row rather than being malformed. `%d`, `%s` and
	 * `%i` each take the next value. The caller prepares the result with wpdb.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException When a token names no table of the module, a list item is neither an
	 *                         int nor a string, or the values do not match the placeholders.
	 *
	 * @param string   $statement A constant of the module.
	 * @param array    $values    The values, in placeholder order.
	 * @param string[] $tables    The unprefixed names of the module's tables.
	 * @param callable $tableName Returns a table's full name from its unprefixed name (string).
	 * @param string   $module    The module's name, for the message.
	 * @return array{0: string, 1: list<mixed>} The statement with wpdb placeholders, and its arguments.
	 *
	 * @phpstan-param list<mixed>              $values
	 * @phpstan-param list<string>             $tables
	 * @phpstan-param callable(string): string $tableName
	 */
	public static function expand( string $statement, array $values, array $tables, callable $tableName, string $module ): array {
		$arguments = array();
		$next      = 0;

		$sql = (string) preg_replace_callback(
			self::PLACEHOLDER,
			static function ( array $found ) use ( $tables, $values, $tableName, $module, &$arguments, &$next ): string {
				$token = $found[1] ?? '';

				if ( '' !== $token && in_array( $token, $tables, true ) ) {
					$arguments[] = $tableName( $token );

					return '%i';
				}

				if ( '' !== $token && self::LIST_TOKEN !== $token ) {
					throw new \LogicException( sprintf( 'The statement names {%1$s}, which is not a table of the %2$s module.', $token, $module ) );
				}

				if ( ! array_key_exists( $next, $values ) ) {
					throw new \LogicException( 'The statement has more placeholders than values.' );
				}

				$value = $values[ $next++ ];

				if ( '' === $token ) {
					$arguments[] = $value;

					return $found[0];
				}

				return self::expandList( is_array( $value ) ? $value : array( $value ), $arguments );
			},
			$statement
		);

		if ( count( $values ) !== $next ) {
			throw new \LogicException( 'The statement has fewer placeholders than values.' );
		}

		return array( $sql, $arguments );
	}

	/**
	 * Returns a statement with the row of its derived table of values repeated once per row, joined by UNION ALL.
	 *
	 * A statement that joins a table of values, such as an update of several rows each by its own
	 * amount, or an insert that checks each row it inserts, writes that table as one derived row:
	 * the first `( SELECT %d AS id, … ) AS name` of the statement. Its column names come from its
	 * first row, as SQL takes them, so the statement stays one constant whatever the number of rows.
	 * The caller sends every row's values first, in order, then the statement's other values.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException When the statement has no derived table of values, or no row is asked for.
	 *
	 * @param string $statement A constant with one derived row.
	 * @param int    $rows      How many rows, 1 or more.
	 * @return string The statement with every row.
	 */
	public static function forDerivedRows( string $statement, int $rows ): string {
		$open  = strpos( $statement, self::DERIVED_OPEN );
		$close = false === $open ? false : strpos( $statement, self::DERIVED_CLOSE, $open );

		if ( false === $open || false === $close || $rows < 1 ) {
			throw new \LogicException( 'A table of values repeats the one derived row of its statement, at least once.' );
		}

		$from = $open + strlen( '( ' );
		$row  = substr( $statement, $from, $close - $from );

		return substr( $statement, 0, $from ) . implode( ' UNION ALL ', array_fill( 0, $rows, $row ) ) . substr( $statement, $close );
	}

	/**
	 * Returns a one-row insert with its VALUES tuple repeated once per row: one statement whatever the number of rows.
	 *
	 * A multi-row insert is written as its one-row constant, `INSERT … VALUES ( %d, … )`, which
	 * stays the one declaration of its shape. The tuple is the parenthesised group right after the
	 * first ` VALUES `, its own parentheses included; whatever follows it, such as an ON DUPLICATE
	 * KEY UPDATE clause, is kept once. The rows are inserted in the order their tuples are written.
	 * The caller sends the values in placeholder order: those before the tuple, such as the
	 * table's, then every row's, then those after it. A tuple's string literals hold no
	 * parenthesis.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException When the statement has no VALUES tuple, or no row is asked for.
	 *
	 * @param string $statement A one-row insert constant.
	 * @param int    $rows      How many rows, 1 or more.
	 * @return string The multi-row statement.
	 */
	public static function forRows( string $statement, int $rows ): string {
		$values = strpos( $statement, self::VALUES );
		$from   = false === $values ? false : $values + strlen( self::VALUES );
		$to     = false === $from || '(' !== ( $statement[ $from ] ?? '' ) ? false : self::closingParenthesis( $statement, $from );

		if ( false === $from || false === $to || $rows < 1 ) {
			throw new \LogicException( 'A multi-row insert repeats the VALUES tuple of a one-row insert, at least once.' );
		}

		$tuple = substr( $statement, $from, $to - $from + 1 );

		return substr( $statement, 0, $from ) . implode( ', ', array_fill( 0, $rows, $tuple ) ) . substr( $statement, $to + 1 );
	}

	/**
	 * Sends a statement that changes rows.
	 *
	 * @since 0.1.0
	 *
	 * @param string $statement A constant of the module.
	 * @param mixed  ...$values Its values.
	 * @return int The rows affected.
	 */
	public function execute( string $statement, mixed ...$values ): int {
		list( $sql, $arguments ) = self::expand( $statement, $values, $this->tables, array( $this->db, 'table' ), $this->module );

		return $this->db->execute( $sql, ...$arguments );
	}

	/**
	 * Sends a query.
	 *
	 * @since 0.1.0
	 *
	 * @param string $statement A constant of the module.
	 * @param mixed  ...$values Its values.
	 * @return list<array<string, mixed>> The rows.
	 */
	public function rows( string $statement, mixed ...$values ): array {
		list( $sql, $arguments ) = self::expand( $statement, $values, $this->tables, array( $this->db, 'table' ), $this->module );

		return $this->db->fetchAll( $sql, ...$arguments );
	}

	/**
	 * Returns the id the last insert generated.
	 *
	 * @since 0.1.0
	 *
	 * @return int The id.
	 */
	public function lastInsertId(): int {
		return $this->db->lastInsertId();
	}

	/**
	 * Refuses a statement that belongs to a group but is sent outside a transaction.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException Outside a transaction.
	 *
	 * @param string $caller The method called, for the message.
	 */
	public function requireTransaction( string $caller ): void {
		if ( 0 === $this->db->depth() ) {
			throw new \LogicException( sprintf( '%s runs only inside a transaction: its statement is one of a group that must commit together.', $caller ) );
		}
	}

	/**
	 * Adds a list's items to the arguments and returns their placeholders.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException When an item is neither an int nor a string.
	 *
	 * @param array $items     The items.
	 * @param array $arguments The arguments so far; the items are added.
	 * @return string The placeholders, comma-separated, or NULL for an empty list.
	 *
	 * @phpstan-param array<mixed> $items
	 * @phpstan-param list<mixed>  $arguments
	 */
	private static function expandList( array $items, array &$arguments ): string {
		if ( array() === $items ) {
			return 'NULL';
		}

		$placeholders = array();

		foreach ( $items as $item ) {
			if ( ! is_int( $item ) && ! is_string( $item ) ) {
				throw new \LogicException( 'An IN list holds ints or strings.' );
			}

			$arguments[]    = $item;
			$placeholders[] = is_int( $item ) ? '%d' : '%s';
		}

		return implode( ', ', $placeholders );
	}

	/**
	 * Finds the parenthesis that closes the one at a position, counting the pairs nested inside.
	 *
	 * @since 0.1.0
	 *
	 * @param string $statement The statement.
	 * @param int    $open      The position of an opening parenthesis.
	 * @return int|false The position of its closing parenthesis, or false when it is never closed.
	 */
	private static function closingParenthesis( string $statement, int $open ): int|false {
		$depth = 0;

		for ( $at = $open, $length = strlen( $statement ); $at < $length; $at++ ) {
			if ( '(' === $statement[ $at ] ) {
				++$depth;
			} elseif ( ')' === $statement[ $at ] && 0 === --$depth ) {
				return $at;
			}
		}

		return false;
	}
}
