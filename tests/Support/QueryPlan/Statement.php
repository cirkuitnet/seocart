<?php
/**
 * Statement: one SQL statement as the query-plan rule sees it, with its shape, its id and the tables it reads
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support\QueryPlan;

/**
 * A statement sent to the database, read without a database.
 *
 * Owns one fact: when two statements are the same query, and when they are the same plan to
 * judge. Its shape is the statement with its values taken out: backticks dropped, the site's
 * table prefix written `{prefix}`, every string and number written `?`, an IN list of values
 * written `( ?+ )` whatever its length, and white space collapsed. Statements of one shape are
 * one query, whose id is the first twelve hexadecimal digits of the SHA-1 of its shape; the
 * allow-list names queries by that id. But MySQL may plan a list of 24 ids and a list of 1,000
 * differently, so the key a run explains a statement under is its id with the length of each
 * IN list: one plan is judged per query and list length sent.
 *
 * It also reads the tables the statement names after FROM, JOIN and STRAIGHT_JOIN, comma joins
 * included, each under the name EXPLAIN gives it: its alias, or its own name. A partition list
 * and index hints are not aliases. One name for two tables is refused, because a plan would then
 * be judged against the wrong table. A statement is a plugin SELECT when it is a SELECT that
 * names at least one table of the plugin, `{prefix}seocart_…`.
 *
 * @since 0.1.0
 */
final class Statement {

	/**
	 * The words that can follow a table name and are not its alias.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const NOT_AN_ALIAS = 'WHERE|JOIN|LEFT|RIGHT|INNER|OUTER|CROSS|STRAIGHT_JOIN|NATURAL|ON|USING|GROUP|ORDER|LIMIT|HAVING|FOR|LOCK|UNION|WINDOW|INTO|SET|VALUES|FORCE|USE|IGNORE|PARTITION';

	/**
	 * A word of a statement: a name, possibly qualified, or a placeholder.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const WORD = '/^[A-Za-z0-9_$.{}]+$/';

	/**
	 * The statement as it was sent.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private string $sql;

	/**
	 * The site's table prefix.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private string $prefix;

	/**
	 * Reads a statement.
	 *
	 * @since 0.1.0
	 *
	 * @param string $sql    The statement as it was sent.
	 * @param string $prefix The site's table prefix, such as `wp_`; empty for a statement read from source.
	 */
	public function __construct( string $sql, string $prefix ) {
		$this->sql    = $sql;
		$this->prefix = $prefix;
	}

	/**
	 * Returns the statement as it was sent.
	 *
	 * @since 0.1.0
	 *
	 * @return string The SQL.
	 */
	public function sql(): string {
		return $this->sql;
	}

	/**
	 * Returns the statement's shape: the statement without its values.
	 *
	 * @since 0.1.0
	 *
	 * @return string The shape.
	 */
	public function shape(): string {
		$shape = (string) preg_replace( '/\bIN\s*\(\s*\?(?:\s*,\s*\?)*\s*\)/i', 'IN ( ?+ )', $this->valueless() );

		return trim( (string) preg_replace( '/\s+/', ' ', $shape ) );
	}

	/**
	 * Returns the id of the statement's query.
	 *
	 * @since 0.1.0
	 *
	 * @return string Twelve hexadecimal digits.
	 */
	public function id(): string {
		return self::idOf( $this->shape() );
	}

	/**
	 * Returns the id of a query from its shape.
	 *
	 * @since 0.1.0
	 *
	 * @param string $shape The shape.
	 * @return string Twelve hexadecimal digits.
	 */
	public static function idOf( string $shape ): string {
		return substr( sha1( $shape ), 0, 12 );
	}

	/**
	 * Returns the key a run explains the statement under: its query's id, with the length of each IN list.
	 *
	 * @since 0.1.0
	 *
	 * @return string For example `7813053b1fcc` without an IN list, or `7813053b1fcc[24]` with one of 24 values.
	 */
	public function key(): string {
		preg_match_all( '/\bIN\s*\(\s*(\?(?:\s*,\s*\?)*)\s*\)/i', $this->valueless(), $lists );

		$lengths = array_map( static fn( string $values ): int => substr_count( $values, '?' ), $lists[1] );

		return array() === $lengths ? $this->id() : $this->id() . '[' . implode( ',', $lengths ) . ']';
	}

	/**
	 * Returns the tables the statement names after FROM, JOIN and STRAIGHT_JOIN.
	 *
	 * @since 0.1.0
	 *
	 * @throws \UnexpectedValueException When one name stands for two tables.
	 *
	 * @return array<string, string> The full name of each table, keyed by the name EXPLAIN gives it: its alias, or its name.
	 */
	public function tables(): array {
		$tables = array();

		foreach ( $this->references() as list( $name, $table ) ) {
			if ( isset( $tables[ $name ] ) && $tables[ $name ] !== $table ) {
				throw new \UnexpectedValueException( sprintf( 'Query %s names both %s and %s "%s", so their plans cannot be told apart. Give each table a name of its own.', $this->id(), $tables[ $name ], $table, $name ) );
			}

			$tables[ $name ] = $table;
		}

		return $tables;
	}

	/**
	 * Tells whether the statement is a SELECT.
	 *
	 * @since 0.1.0
	 *
	 * @return bool True for a SELECT.
	 */
	public function isSelect(): bool {
		return 1 === preg_match( '/^[\s(]*SELECT\b/i', $this->sql );
	}

	/**
	 * Tells whether the statement is a SELECT that names a table of the plugin.
	 *
	 * @since 0.1.0
	 *
	 * @return bool True for a plugin SELECT.
	 */
	public function isPluginSelect(): bool {
		if ( ! $this->isSelect() ) {
			return false;
		}

		foreach ( $this->references() as list( , $table ) ) {
			if ( str_starts_with( $table, $this->prefix . 'seocart_' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Returns a table's name without the site's prefix, for a report.
	 *
	 * @since 0.1.0
	 *
	 * @param string $table The table's full name.
	 * @return string For example `seocart_products`.
	 */
	public function unprefixed( string $table ): string {
		return '' !== $this->prefix && str_starts_with( $table, $this->prefix ) ? substr( $table, strlen( $this->prefix ) ) : $table;
	}

	/**
	 * Returns the statement with its values written `?`, its prefix `{prefix}` and without backticks.
	 *
	 * @since 0.1.0
	 *
	 * @return string The statement.
	 */
	private function valueless(): string {
		$sql = str_replace( '`', '', $this->sql );
		$sql = (string) preg_replace( "/'(?:[^'\\\\]|\\\\.)*'/s", '?', $sql );

		if ( '' !== $this->prefix ) {
			$sql = (string) preg_replace( '/\b' . preg_quote( $this->prefix, '/' ) . '/', '{prefix}', $sql );
		}

		return (string) preg_replace( '/(?<![\w.{}])-?\d+(?:\.\d+)?(?!\w)/', '?', $sql );
	}

	/**
	 * Returns every table reference: the name EXPLAIN gives the table, and its full name.
	 *
	 * A derived table or a subquery after FROM is not a reference; the tables of its own FROM are.
	 *
	 * @since 0.1.0
	 *
	 * @return list<array{0: string, 1: string}> The references, in order.
	 */
	private function references(): array {
		preg_match_all( "/'(?:[^'\\\\]|\\\\.)*'|[A-Za-z0-9_$.{}]+|[(),]/", str_replace( '`', '', $this->sql ), $found );

		$tokens     = $found[0];
		$references = array();

		foreach ( $tokens as $at => $token ) {
			if ( 1 === preg_match( '/^(?:FROM|JOIN|STRAIGHT_JOIN)$/i', $token ) ) {
				$references = array_merge( $references, self::referencesAt( $tokens, $at + 1 ) );
			}
		}

		return $references;
	}

	/**
	 * Reads the table references that start at a token: one, or several joined by commas.
	 *
	 * @since 0.1.0
	 *
	 * @param string[] $tokens The statement's tokens.
	 * @param int      $at     Where the first reference starts.
	 * @return list<array{0: string, 1: string}> The references.
	 *
	 * @phpstan-param list<string> $tokens
	 */
	private static function referencesAt( array $tokens, int $at ): array {
		$references = array();

		while ( 1 === preg_match( self::WORD, $tokens[ $at ] ?? '(' ) ) {
			$table = $tokens[ $at ];
			$next  = self::skipLists( $tokens, $at + 1, 'PARTITION' );

			if ( 'AS' === strtoupper( $tokens[ $next ] ?? '' ) ) {
				++$next;
			}

			$name = $table;
			$word = $tokens[ $next ] ?? '';

			if ( 1 === preg_match( '/^[A-Za-z_][A-Za-z0-9_]*$/', $word ) && 1 !== preg_match( '/^(?:' . self::NOT_AN_ALIAS . ')$/i', $word ) ) {
				$name = $word;
				++$next;
			}

			$references[] = array( $name, $table );
			$next         = self::skipLists( $tokens, $next, 'USE|FORCE|IGNORE' );

			if ( ',' !== ( $tokens[ $next ] ?? '' ) ) {
				break;
			}

			$at = $next + 1;
		}

		return $references;
	}

	/**
	 * Skips clauses that begin with one of the given words and end with a parenthesised list, such as `FORCE INDEX ( a, b )`.
	 *
	 * @since 0.1.0
	 *
	 * @param string[] $tokens The statement's tokens.
	 * @param int      $at     Where a clause may start.
	 * @param string   $words  The words that begin one, as a regular-expression alternation.
	 * @return int Where the first token after the clauses is.
	 *
	 * @phpstan-param list<string> $tokens
	 */
	private static function skipLists( array $tokens, int $at, string $words ): int {
		while ( 1 === preg_match( '/^(?:' . $words . ')$/i', $tokens[ $at ] ?? '' ) ) {
			while ( isset( $tokens[ $at ] ) && '(' !== $tokens[ $at ] ) {
				++$at;
			}

			for ( $depth = 0; isset( $tokens[ $at ] ); ++$at ) {
				$depth += '(' === $tokens[ $at ] ? 1 : ( ')' === $tokens[ $at ] ? -1 : 0 );

				if ( 0 === $depth ) {
					++$at;
					break;
				}
			}
		}

		return $at;
	}
}
