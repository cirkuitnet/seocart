<?php
/**
 * QueryLog: the database queries one piece of work issued, ready to be filtered and printed
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support;

/**
 * An immutable list of queries taken from `$wpdb->queries`.
 *
 * Every filter returns a new log, so a budget reads as a sentence:
 *
 *     $this->assertQueryCountAtMost( 12, $log->forTable( $wpdb->posts )->ofType( 'SELECT' ), 'G5' );
 *
 * Nothing here calls WordPress: the log is built from data, which is what lets the filters
 * and the failure report be unit-tested without a database.
 *
 * @since 0.1.0
 */
final class QueryLog implements \Countable {

	/**
	 * The queries, in the order they ran.
	 *
	 * @since 0.1.0
	 *
	 * @var list<array{sql: string, caller: string}>
	 */
	private array $queries;

	/**
	 * Wraps a list of queries.
	 *
	 * @since 0.1.0
	 *
	 * @param list<array{sql: string, caller: string}> $queries The queries, in the order they ran.
	 */
	private function __construct( array $queries ) {
		$this->queries = $queries;
	}

	/**
	 * Builds a log from entries of `$wpdb->queries`.
	 *
	 * @since 0.1.0
	 *
	 * @param array<int, mixed> $entries Entries as wpdb records them when SAVEQUERIES is on: the SQL at
	 *                                   index 0 and the call-stack summary at index 2.
	 * @return self The log.
	 */
	public static function fromWpdb( array $entries ): self {
		$queries = array();

		foreach ( $entries as $entry ) {
			$queries[] = array(
				'sql'    => self::collapseWhitespace( (string) ( $entry[0] ?? '' ) ),
				'caller' => (string) ( $entry[2] ?? '' ),
			);
		}

		return new self( $queries );
	}

	/**
	 * Keeps the queries that name a table.
	 *
	 * @since 0.1.0
	 *
	 * @param string $table The full table name, prefix included, for example `$wpdb->posts`.
	 * @return self A log of the matching queries.
	 */
	public function forTable( string $table ): self {
		// A table name is a whole identifier: `wp_seocart_orders` must not match `wp_seocart_orders_lines`.
		return $this->matching( '/(?<![A-Za-z0-9_$])' . preg_quote( $table, '/' ) . '(?![A-Za-z0-9_$])/i' );
	}

	/**
	 * Keeps the queries of the given statement types.
	 *
	 * @since 0.1.0
	 *
	 * @param string ...$types Statement keywords such as 'SELECT', 'INSERT', 'UPDATE' or 'DELETE'. Case-insensitive.
	 * @return self A log of the matching queries.
	 */
	public function ofType( string ...$types ): self {
		$types = array_map( 'strtoupper', $types );

		return $this->filter(
			static function ( array $query ) use ( $types ): bool {
				return 1 === preg_match( '/^[\s(]*([A-Za-z]+)/', $query['sql'], $matches )
					&& in_array( strtoupper( $matches[1] ), $types, true );
			}
		);
	}

	/**
	 * Keeps the queries whose SQL has a given shape.
	 *
	 * @since 0.1.0
	 *
	 * @param string $pattern A regular expression, delimiters included. It is matched against the
	 *                        SQL with every run of whitespace collapsed to one space.
	 * @return self A log of the matching queries.
	 */
	public function matching( string $pattern ): self {
		return $this->filter(
			static function ( array $query ) use ( $pattern ): bool {
				return 1 === preg_match( $pattern, $query['sql'] );
			}
		);
	}

	/**
	 * Keeps the queries that shipped plugin code caused.
	 *
	 * @since 0.1.0
	 *
	 * @param PluginOwnership $owner The rules that decide what is plugin code.
	 * @return self A log of the queries whose call stack contains plugin code.
	 */
	public function issuedBy( PluginOwnership $owner ): self {
		return $this->filter(
			static function ( array $query ) use ( $owner ): bool {
				return $owner->ownsCaller( $query['caller'] );
			}
		);
	}

	/**
	 * Counts the queries.
	 *
	 * @since 0.1.0
	 *
	 * @return int The number of queries in the log.
	 */
	public function count(): int {
		return count( $this->queries );
	}

	/**
	 * Prints the queries with their callers, for a failure message.
	 *
	 * @since 0.1.0
	 *
	 * @return string One numbered block per query, or a note that there are none.
	 */
	public function describe(): string {
		if ( array() === $this->queries ) {
			return '  (no queries)';
		}

		$blocks = array();

		foreach ( $this->queries as $index => $query ) {
			$blocks[] = sprintf(
				"  %d. %s\n     caller: %s",
				$index + 1,
				$query['sql'],
				'' === $query['caller'] ? '(not recorded)' : $query['caller']
			);
		}

		return implode( "\n", $blocks );
	}

	/**
	 * Returns a log of the queries a test accepts.
	 *
	 * @since 0.1.0
	 *
	 * @param callable $accepts Receives one query, an array with the keys 'sql' and 'caller', and returns whether it is kept.
	 * @return self A log of the kept queries.
	 */
	private function filter( callable $accepts ): self {
		return new self( array_values( array_filter( $this->queries, $accepts ) ) );
	}

	/**
	 * Collapses every run of whitespace, so that a multi-line query prints on one line.
	 *
	 * @since 0.1.0
	 *
	 * @param string $sql The SQL as it was sent.
	 * @return string The SQL on one line.
	 */
	private static function collapseWhitespace( string $sql ): string {
		return trim( (string) preg_replace( '/\s+/', ' ', $sql ) );
	}
}
