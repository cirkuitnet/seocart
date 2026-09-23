<?php
/**
 * TableDefinition: the one declaration of a plugin table
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
 * Everything that is true of one plugin table, declared once, in the module that owns it.
 *
 * Owns one fact: the table's declaration, complete from the first migration on. Name, owning
 * module, purpose, mutation pattern,
 * columns with their classification, primary key, unique keys with the invariant each
 * enforces, indexes with the query each serves, retention policy and orphan policy.
 *
 * The migration that creates a table and the data registry that lists it read the same
 * object, from the same static factory in the owning module. The DDL generator and the
 * post-condition verifier read the storage shape; the registry reads the rest. Every field is
 * required, so a table cannot exist without a classification for each column or a retention
 * policy. Pure data: constructing one does no I/O and calls no WordPress function.
 *
 * @since 0.1.0
 */
final class TableDefinition {

	/**
	 * A table name without prefix: lowercase snake_case.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const NAME_PATTERN = '/^[a-z][a-z0-9_]{0,63}$/';

	/**
	 * The unprefixed name, for example `order_lines`.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private string $name;

	/**
	 * The owning module, for example `Order`.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private string $module;

	/**
	 * One sentence saying what the table is for.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private string $purpose;

	/**
	 * How the rows may change.
	 *
	 * @since 0.1.0
	 *
	 * @var MutationPattern
	 */
	private MutationPattern $mutationPattern;

	/**
	 * The columns, in table order.
	 *
	 * @since 0.1.0
	 *
	 * @var list<ColumnSpec>
	 */
	private array $columns;

	/**
	 * The primary key's columns, in order.
	 *
	 * @since 0.1.0
	 *
	 * @var list<string>
	 */
	private array $primaryKey;

	/**
	 * The unique keys, each with the invariant it enforces.
	 *
	 * @since 0.1.0
	 *
	 * @var list<IndexSpec>
	 */
	private array $uniqueKeys;

	/**
	 * The plain indexes, each with the query it serves.
	 *
	 * @since 0.1.0
	 *
	 * @var list<IndexSpec>
	 */
	private array $indexes;

	/**
	 * The retention policy id, or `permanent`.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private string $retention;

	/**
	 * The orphan policy per relationship, in both directions.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, string>
	 */
	private array $orphanPolicy;

	/**
	 * Declares a table.
	 *
	 * @since 0.1.0
	 *
	 * @throws \InvalidArgumentException When the declaration is incomplete or inconsistent.
	 *
	 * @param string                $name            The unprefixed name, lowercase snake_case.
	 * @param string                $module          The owning module.
	 * @param string                $purpose         One sentence saying what the table is for.
	 * @param MutationPattern       $mutationPattern How the rows may change.
	 * @param ColumnSpec[]          $columns         The columns, in table order.
	 * @param string[]              $primaryKey      The primary key's columns, in order.
	 * @param IndexSpec[]           $uniqueKeys      The unique keys. Every one must be declared with IndexSpec::unique().
	 * @param IndexSpec[]           $indexes         The plain indexes. Every one must be declared with IndexSpec::key().
	 * @param string                $retention       The retention policy id, or `permanent`.
	 * @param array<string, string> $orphanPolicy    Relationship => what happens to orphans in each direction.
	 *                                               Empty when the table has no relationship worth a policy.
	 */
	public function __construct(
		string $name,
		string $module,
		string $purpose,
		MutationPattern $mutationPattern,
		array $columns,
		array $primaryKey,
		array $uniqueKeys,
		array $indexes,
		string $retention,
		array $orphanPolicy
	) {
		if ( 1 !== preg_match( self::NAME_PATTERN, $name ) ) {
			throw new \InvalidArgumentException( sprintf( 'Table name "%s" must be lowercase snake_case, without the prefix.', $name ) );
		}

		foreach ( array(
			'module'    => $module,
			'purpose'   => $purpose,
			'retention' => $retention,
		) as $field => $value ) {
			if ( '' === trim( $value ) ) {
				throw new \InvalidArgumentException( sprintf( 'Table %s needs a %s.', $name, $field ) );
			}
		}

		$names = array();

		foreach ( $columns as $column ) {
			if ( isset( $names[ $column->name() ] ) ) {
				throw new \InvalidArgumentException( sprintf( 'Table %s declares column %s twice.', $name, $column->name() ) );
			}

			$names[ $column->name() ] = true;
		}

		if ( array() === $names ) {
			throw new \InvalidArgumentException( sprintf( 'Table %s needs at least one column.', $name ) );
		}

		if ( array() === $primaryKey ) {
			throw new \InvalidArgumentException( sprintf( 'Table %s needs a primary key.', $name ) );
		}

		foreach ( $primaryKey as $column ) {
			if ( ! isset( $names[ $column ] ) ) {
				throw new \InvalidArgumentException( sprintf( 'Table %s: primary key column %s is not declared.', $name, $column ) );
			}
		}

		$indexNames = array();

		foreach ( array(
			'unique key' => array( $uniqueKeys, true ),
			'index'      => array( $indexes, false ),
		) as $kind => $group ) {
			foreach ( $group[0] as $index ) {
				if ( $index->isUnique() !== $group[1] ) {
					throw new \InvalidArgumentException( sprintf( 'Table %s: every %s must be declared with IndexSpec::%s().', $name, $kind, $group[1] ? 'unique' : 'key' ) );
				}

				if ( isset( $indexNames[ $index->name() ] ) ) {
					throw new \InvalidArgumentException( sprintf( 'Table %s declares index %s twice.', $name, $index->name() ) );
				}

				$indexNames[ $index->name() ] = true;

				foreach ( $index->columns() as $column ) {
					if ( ! isset( $names[ $column['name'] ] ) ) {
						throw new \InvalidArgumentException( sprintf( 'Table %s: index %s names undeclared column %s.', $name, $index->name(), $column['name'] ) );
					}
				}
			}
		}

		$this->name            = $name;
		$this->module          = $module;
		$this->purpose         = $purpose;
		$this->mutationPattern = $mutationPattern;
		$this->columns         = array_values( $columns );
		$this->primaryKey      = array_values( $primaryKey );
		$this->uniqueKeys      = array_values( $uniqueKeys );
		$this->indexes         = array_values( $indexes );
		$this->retention       = $retention;
		$this->orphanPolicy    = $orphanPolicy;
	}

	/**
	 * Returns the unprefixed table name.
	 *
	 * @since 0.1.0
	 *
	 * @return string For example `migrations`.
	 */
	public function name(): string {
		return $this->name;
	}

	/**
	 * Returns the owning module.
	 *
	 * @since 0.1.0
	 *
	 * @return string For example `Platform`.
	 */
	public function module(): string {
		return $this->module;
	}

	/**
	 * Returns what the table is for.
	 *
	 * @since 0.1.0
	 *
	 * @return string One sentence.
	 */
	public function purpose(): string {
		return $this->purpose;
	}

	/**
	 * Returns how the rows may change.
	 *
	 * @since 0.1.0
	 *
	 * @return MutationPattern The pattern.
	 */
	public function mutationPattern(): MutationPattern {
		return $this->mutationPattern;
	}

	/**
	 * Returns the columns.
	 *
	 * @since 0.1.0
	 *
	 * @return list<ColumnSpec> In table order.
	 */
	public function columns(): array {
		return $this->columns;
	}

	/**
	 * Returns the primary key's columns.
	 *
	 * @since 0.1.0
	 *
	 * @return list<string> In key order.
	 */
	public function primaryKey(): array {
		return $this->primaryKey;
	}

	/**
	 * Returns the unique keys.
	 *
	 * @since 0.1.0
	 *
	 * @return list<IndexSpec> Each with the invariant it enforces.
	 */
	public function uniqueKeys(): array {
		return $this->uniqueKeys;
	}

	/**
	 * Returns the plain indexes.
	 *
	 * @since 0.1.0
	 *
	 * @return list<IndexSpec> Each with the query it serves.
	 */
	public function indexes(): array {
		return $this->indexes;
	}

	/**
	 * Returns the retention policy.
	 *
	 * @since 0.1.0
	 *
	 * @return string A policy id, or `permanent`.
	 */
	public function retention(): string {
		return $this->retention;
	}

	/**
	 * Returns the orphan policy.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, string> Relationship => what happens to orphans in each direction.
	 */
	public function orphanPolicy(): array {
		return $this->orphanPolicy;
	}
}
