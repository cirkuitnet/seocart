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
use SEOCart\Platform\Database\ModuleStatements;

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
 * A multi-row insert is its one-row constant with the VALUES tuple repeated, once per row, by
 * ModuleStatements::forRows(): one statement whatever the number of rows. A statement that joins
 * a table of values, such as an update of several lines each by its own amount, writes that
 * table as one derived row, `( SELECT %d AS id, … ) AS name`, which
 * ModuleStatements::forDerivedRows() repeats with UNION ALL, once per row.
 *
 * @since 0.1.0
 */
final class OrderStatements {

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
	 * The plugin's one token expansion (ModuleStatements::expand()) over the order tables: a table
	 * token becomes `%i` with the table's full name as its argument. `{list}` takes the next value,
	 * a list of ints or strings, and becomes one `%d` or `%s` per item; an empty list becomes
	 * NULL, so `x IN ({list})` matches no row rather than being malformed. `%d`, `%s` and `%i`
	 * each take the next value. The caller prepares the result with wpdb.
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
		return ModuleStatements::expand( $statement, $values, OrderTables::names(), $tableName, 'order' );
	}

	/**
	 * Sends a statement joined to a table of values, its rows first and then the statement's other values, as one statement.
	 *
	 * @since 0.1.0
	 *
	 * @param string $statement A constant with one derived row, before any other placeholder.
	 * @param array  $rows      Each row's values, in placeholder order; at least one row.
	 * @param mixed  ...$values The statement's other values.
	 * @return int The rows affected.
	 *
	 * @phpstan-param non-empty-list<list<mixed>> $rows
	 */
	public function executeForRows( string $statement, array $rows, mixed ...$values ): int {
		return $this->execute( ModuleStatements::forDerivedRows( $statement, count( $rows ) ), ...array_merge( array_merge( ...$rows ), $values ) );
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
		return $this->execute( ModuleStatements::forRows( $statement, count( $rows ) ), ...array_merge( ...$rows ) );
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
}
