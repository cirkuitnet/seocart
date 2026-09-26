<?php
/**
 * OrderStatements: how the order module's statements are prepared and sent
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Order\Infrastructure;

use SEOCart\Platform\Database\Database;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- These exceptions report a caller's programming error to the developer; they are never HTML.

/**
 * Sends the order module's statements, written as constants with table and list tokens.
 *
 * Owns one fact: how an order statement is written and sent. A statement names its tables as
 * `{orders}`, `{order_lines}` and so on, and an IN list as `{list}`; expand() turns them into
 * wpdb placeholders and arguments, and refuses a token that names a table outside the order
 * module, so no order statement can reach another module's rows. Every statement of the module
 * is a public constant of the class that sends it, so a concurrency test sends exactly the
 * statement the module sends, and a test can read from the constants alone which tables they
 * change.
 *
 * A multi-row insert is its one-row constant with the VALUES tuple repeated, once per row: one
 * statement whatever the number of rows.
 *
 * @since 0.1.0
 */
final class OrderStatements {

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
	 * What precedes the VALUES tuple of an insert constant.
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
	 * Creates the sender. Sends nothing.
	 *
	 * @since 0.1.0
	 *
	 * @param Database $db The connection.
	 */
	public function __construct( Database $db ) {
		$this->db = $db;
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
	 * @throws \LogicException When a token names no order table, a list item is neither an int nor
	 *                         a string, or the values do not match the placeholders.
	 *
	 * @param string   $statement A constant of the order module.
	 * @param array    $values    The values, in placeholder order.
	 * @param callable $tableName Returns a table's full name from its unprefixed name (string).
	 * @return array{0: string, 1: list<mixed>} The statement with wpdb placeholders, and its arguments.
	 *
	 * @phpstan-param list<mixed>                $values
	 * @phpstan-param callable(string): string $tableName
	 */
	public static function expand( string $statement, array $values, callable $tableName ): array {
		$tables    = OrderTables::names();
		$arguments = array();
		$next      = 0;

		$sql = (string) preg_replace_callback(
			self::PLACEHOLDER,
			static function ( array $found ) use ( $tables, $values, $tableName, &$arguments, &$next ): string {
				$token = $found[1] ?? '';

				if ( '' !== $token && in_array( $token, $tables, true ) ) {
					$arguments[] = $tableName( $token );

					return '%i';
				}

				if ( '' !== $token && self::LIST_TOKEN !== $token ) {
					throw new \LogicException( sprintf( 'The statement names {%s}, which is not an order table.', $token ) );
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
	 * Returns an insert constant with its VALUES tuple repeated once per row.
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
		$at = strrpos( $statement, self::VALUES );

		if ( false === $at || $rows < 1 ) {
			throw new \LogicException( 'A multi-row insert repeats the VALUES tuple of a one-row insert, at least once.' );
		}

		$tuple = substr( $statement, $at + strlen( self::VALUES ) );

		return substr( $statement, 0, $at + strlen( self::VALUES ) ) . implode( ', ', array_fill( 0, $rows, $tuple ) );
	}

	/**
	 * Sends a statement that changes rows.
	 *
	 * @since 0.1.0
	 *
	 * @param string $statement A constant of the order module.
	 * @param mixed  ...$values Its values.
	 * @return int The rows affected.
	 */
	public function execute( string $statement, mixed ...$values ): int {
		list( $sql, $arguments ) = self::expand( $statement, $values, array( $this->db, 'table' ) );

		return $this->db->execute( $sql, ...$arguments );
	}

	/**
	 * Sends a one-row insert constant for several rows, as one statement.
	 *
	 * @since 0.1.0
	 *
	 * @param string $statement A one-row insert constant.
	 * @param array  $rows      Each row's values, in placeholder order; at least one row.
	 * @return int The rows inserted.
	 *
	 * @phpstan-param non-empty-list<list<mixed>> $rows
	 */
	public function insertRows( string $statement, array $rows ): int {
		return $this->execute( self::forRows( $statement, count( $rows ) ), ...array_merge( ...$rows ) );
	}

	/**
	 * Sends a query.
	 *
	 * @since 0.1.0
	 *
	 * @param string $statement A constant of the order module.
	 * @param mixed  ...$values Its values.
	 * @return list<array<string, mixed>> The rows.
	 */
	public function rows( string $statement, mixed ...$values ): array {
		list( $sql, $arguments ) = self::expand( $statement, $values, array( $this->db, 'table' ) );

		return $this->db->fetchAll( $sql, ...$arguments );
	}

	/**
	 * Returns the id the last insert generated, or the value it set with LAST_INSERT_ID( expr ).
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
}
