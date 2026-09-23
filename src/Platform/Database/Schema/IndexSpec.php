<?php
/**
 * IndexSpec: the declaration of one secondary index of a plugin table
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Database\Schema;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- A declaration error is a message for the developer's test run, never HTML; this class may not call WordPress.

/**
 * One unique key or plain index, with the reason it exists.
 *
 * Owns one fact: an index and its justification. A unique key names the invariant it
 * enforces; a plain index names the query it serves. An index nothing names is deleted in
 * review, so the reason is required.
 *
 * Columns are written in index order; a prefix length is written after the name, as in
 * `value(20)`. Pure data.
 *
 * @since 0.1.0
 */
final class IndexSpec {

	/**
	 * An index name: lowercase snake_case, never `primary`.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const NAME_PATTERN = '/^(?!primary$)[a-z][a-z0-9_]{0,63}$/';

	/**
	 * One indexed column: a name and an optional prefix length.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const COLUMN_PATTERN = '/^([a-z][a-z0-9_]{0,63})(?:\((\d+)\))?$/';

	/**
	 * The index name.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private string $name;

	/**
	 * The indexed columns in order, each with its prefix length or null.
	 *
	 * @since 0.1.0
	 *
	 * @var list<array{name: string, length: int|null}>
	 */
	private array $columns;

	/**
	 * Whether the index is unique.
	 *
	 * @since 0.1.0
	 *
	 * @var bool
	 */
	private bool $unique;

	/**
	 * The invariant a unique key enforces, or the query a plain index serves.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private string $purpose;

	/**
	 * Declares an index. Use unique() or key().
	 *
	 * @since 0.1.0
	 *
	 * @throws \InvalidArgumentException When the name, a column or the reason is invalid.
	 *
	 * @param string   $name    Lowercase snake_case.
	 * @param string[] $columns The columns in index order, each optionally with a prefix length.
	 * @param bool     $unique  Whether the index is unique.
	 * @param string   $purpose Why the index exists. Not empty.
	 */
	private function __construct( string $name, array $columns, bool $unique, string $purpose ) {
		if ( 1 !== preg_match( self::NAME_PATTERN, $name ) ) {
			throw new \InvalidArgumentException( sprintf( 'Index name "%s" must be lowercase snake_case and not "primary".', $name ) );
		}

		if ( array() === $columns ) {
			throw new \InvalidArgumentException( sprintf( 'Index %s needs at least one column.', $name ) );
		}

		if ( '' === trim( $purpose ) ) {
			throw new \InvalidArgumentException( sprintf( 'Index %s needs the invariant it enforces or the query it serves.', $name ) );
		}

		$this->columns = array();

		foreach ( $columns as $column ) {
			if ( 1 !== preg_match( self::COLUMN_PATTERN, $column, $parts ) ) {
				throw new \InvalidArgumentException( sprintf( 'Index %s: "%s" is not a column, or a column with a prefix length.', $name, $column ) );
			}

			$this->columns[] = array(
				'name'   => $parts[1],
				'length' => isset( $parts[2] ) ? (int) $parts[2] : null,
			);
		}

		$this->name    = $name;
		$this->unique  = $unique;
		$this->purpose = $purpose;
	}

	/**
	 * Declares a unique key.
	 *
	 * @since 0.1.0
	 *
	 * @param string   $name      Lowercase snake_case.
	 * @param string[] $columns   The columns in index order.
	 * @param string   $invariant The invariant the key enforces.
	 * @return self The declaration.
	 */
	public static function unique( string $name, array $columns, string $invariant ): self {
		return new self( $name, $columns, true, $invariant );
	}

	/**
	 * Declares a plain index.
	 *
	 * @since 0.1.0
	 *
	 * @param string   $name    Lowercase snake_case.
	 * @param string[] $columns The columns in index order.
	 * @param string   $query   The query the index serves.
	 * @return self The declaration.
	 */
	public static function key( string $name, array $columns, string $query ): self {
		return new self( $name, $columns, false, $query );
	}

	/**
	 * Returns the index name.
	 *
	 * @since 0.1.0
	 *
	 * @return string Lowercase snake_case.
	 */
	public function name(): string {
		return $this->name;
	}

	/**
	 * Returns the indexed columns.
	 *
	 * @since 0.1.0
	 *
	 * @return list<array{name: string, length: int|null}> In index order, each with its prefix length or null.
	 */
	public function columns(): array {
		return $this->columns;
	}

	/**
	 * Tells whether the index is unique.
	 *
	 * @since 0.1.0
	 *
	 * @return bool True for a unique key.
	 */
	public function isUnique(): bool {
		return $this->unique;
	}

	/**
	 * Returns why the index exists.
	 *
	 * @since 0.1.0
	 *
	 * @return string The invariant of a unique key, or the query of a plain index.
	 */
	public function purpose(): string {
		return $this->purpose;
	}
}
