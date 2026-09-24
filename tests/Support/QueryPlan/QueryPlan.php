<?php
/**
 * QueryPlan: the plan MySQL chose for one plugin SELECT, judged by the query-plan rule
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support\QueryPlan;

/**
 * One query's EXPLAIN, and whether it keeps the query-plan rule.
 *
 * Owns one fact: the query-plan rule. A plugin SELECT may not read a large table, one of at
 * least LARGE_TABLE rows, with a full table scan (EXPLAIN type `ALL`), nor with an access that
 * EXPLAIN expects to examine more than MOST_ROWS rows. Smaller tables are reported but not
 * judged, because a plan on them says little about the plan the same query gets on a real
 * store. The rule reads each table the query names, WordPress's included: it is about the
 * plugin's queries, whatever they join.
 *
 * A table is judged from LARGE_TABLE rows on, not only above it: the reference dataset holds
 * exactly that many products, variants, prices and stock items, so a stricter reading would
 * leave the plugin's main tables unjudged.
 *
 * @since 0.1.0
 */
final class QueryPlan {

	/**
	 * The rows from which a table is large, and its plans judged.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const LARGE_TABLE = 10000;

	/**
	 * The most rows an access to a large table may expect to examine.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const MOST_ROWS = 5000;

	/**
	 * The query.
	 *
	 * @since 0.1.0
	 *
	 * @var Statement
	 */
	public Statement $statement;

	/**
	 * Each access to a table the query names, as EXPLAIN reported it, with the table's size.
	 *
	 * `name` is the table's name in the plan: its alias, or its full name.
	 *
	 * @since 0.1.0
	 *
	 * @var list<array{name: string, table: string, type: string, key: string|null, rows: int, table_rows: int}>
	 */
	public array $accesses;

	/**
	 * One line per access that breaks the rule; empty when the query keeps it.
	 *
	 * @since 0.1.0
	 *
	 * @var list<string>
	 */
	public array $breaches;

	/**
	 * Records a judged plan. Use explain() or judge().
	 *
	 * @since 0.1.0
	 *
	 * @param Statement $statement The query.
	 * @param array[]   $accesses  The accesses.
	 * @param string[]  $breaches  The accesses that break the rule.
	 *
	 * @phpstan-param list<array{name: string, table: string, type: string, key: string|null, rows: int, table_rows: int}> $accesses
	 * @phpstan-param list<string> $breaches
	 */
	private function __construct( Statement $statement, array $accesses, array $breaches ) {
		$this->statement = $statement;
		$this->accesses  = $accesses;
		$this->breaches  = $breaches;
	}

	/**
	 * EXPLAINs a query once and judges its plan.
	 *
	 * @since 0.1.0
	 *
	 * @throws \RuntimeException When EXPLAIN fails.
	 *
	 * @param \wpdb     $connection A connection that records nothing, to the same database.
	 * @param Statement $statement  The query, with the values it was sent with.
	 * @param callable  $tableRows  Returns a table's row count from its full name (string): int.
	 * @return self The judged plan.
	 */
	public static function explain( \wpdb $connection, Statement $statement, callable $tableRows ): self {
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- The statement was prepared when the plugin sent it; EXPLAIN repeats it as sent.
		$rows = $connection->get_results( 'EXPLAIN ' . $statement->sql(), ARRAY_A );

		if ( '' !== $connection->last_error ) {
			throw new \RuntimeException( sprintf( 'EXPLAIN failed for query %s: %s', $statement->id(), $connection->last_error ) );
		}

		return self::judge( $statement, $rows, $tableRows );
	}

	/**
	 * Judges EXPLAIN's rows for a query.
	 *
	 * A row for a derived table, a materialised subquery or a union result (`<derived2>` and the
	 * like), or for no table at all, is left out: the tables behind it have rows of their own. A
	 * row for a real table the statement does not name is a breach, whatever its plan: its table,
	 * and so its size, is unknown, and the rule does not pass what it cannot judge.
	 *
	 * @since 0.1.0
	 *
	 * @param Statement $statement The query.
	 * @param array[]   $rows      EXPLAIN's rows, in the traditional format.
	 * @param callable  $tableRows Returns a table's row count from its full name (string): int.
	 * @return self The judged plan.
	 *
	 * @phpstan-param list<array<string, mixed>> $rows
	 */
	public static function judge( Statement $statement, array $rows, callable $tableRows ): self {
		$tables   = $statement->tables();
		$accesses = array();
		$breaches = array();

		foreach ( $rows as $row ) {
			$name  = (string) ( $row['table'] ?? '' );
			$table = $tables[ $name ] ?? null;

			if ( '' === $name || 1 === preg_match( '/^<.+>$/', $name ) ) {
				continue;
			}

			if ( null === $table ) {
				$breaches[] = sprintf( "%s: EXPLAIN reads a table of this name, which the statement's FROM, JOIN and comma joins do not name, so its plan cannot be judged", $name );

				continue;
			}

			$access = array(
				'name'       => $name,
				'table'      => $table,
				'type'       => (string) ( $row['type'] ?? '' ),
				'key'        => isset( $row['key'] ) ? (string) $row['key'] : null,
				'rows'       => (int) ( $row['rows'] ?? 0 ),
				'table_rows' => (int) $tableRows( $table ),
			);

			$accesses[] = $access;

			if ( $access['table_rows'] >= self::LARGE_TABLE && ( 'ALL' === $access['type'] || $access['rows'] > self::MOST_ROWS ) ) {
				$breaches[] = self::describe( $statement, $access );
			}
		}

		return new self( $statement, $accesses, $breaches );
	}

	/**
	 * Returns the plan as report lines: the statement's key, its verdict and its shape, then one line per access.
	 *
	 * @since 0.1.0
	 *
	 * @param string $verdict What became of the query, such as `ok`.
	 * @return list<string> The lines.
	 */
	public function lines( string $verdict ): array {
		$lines = array( sprintf( '%s %s: %s', $this->statement->key(), $verdict, $this->statement->shape() ) );

		foreach ( $this->accesses as $access ) {
			$lines[] = '    ' . self::describe( $this->statement, $access );
		}

		return $lines;
	}

	/**
	 * Describes one access for a report.
	 *
	 * @since 0.1.0
	 *
	 * @param Statement $statement The query.
	 * @param array     $access    The access.
	 * @return string For example `seocart_stock_items as i: type ALL, key none, rows 10060, of a table of 10000 rows`.
	 *
	 * @phpstan-param array{name: string, table: string, type: string, key: string|null, rows: int, table_rows: int} $access
	 */
	private static function describe( Statement $statement, array $access ): string {
		return sprintf(
			'%s%s: type %s, key %s, rows %d, of a table of %d rows',
			$statement->unprefixed( $access['table'] ),
			$access['name'] === $access['table'] ? '' : ' as ' . $access['name'],
			'' === $access['type'] ? 'none' : $access['type'],
			$access['key'] ?? 'none',
			$access['rows'],
			$access['table_rows']
		);
	}
}
