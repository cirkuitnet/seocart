<?php
/**
 * SchemaVerifier: compares a table as the server reports it with its declaration
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Database\Schema;

use SEOCart\Platform\Database\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Reads a table's shape from information_schema and lists every way it differs from its TableDefinition.
 *
 * Owns one fact: what "the table matches its declaration" means. That is: the table exists;
 * its engine is InnoDB; its collation is the site's; every declared column is present with its
 * type, nullability, default and EXTRA (exactly `auto_increment` or nothing, as declared); every
 * text column has its declared collation, or the table's when none is declared; no column is
 * undeclared; and the index set is exactly the declared one, primary key included, by name,
 * uniqueness, column order and prefix length. An empty diff is the only pass. dbDelta's
 * return value is never taken as evidence.
 *
 * Both sides are normalized before they are compared. Integer display widths are dropped
 * (MySQL 8.0.19 and later omit them, MariaDB does not), type keywords are lowercased, and
 * MariaDB's quoted string defaults and its literal `NULL` default are unquoted. The storage
 * conventions use no expression default, no ENUM and no generated column, so no other server
 * difference matters.
 *
 * Temporary tables are invisible to information_schema, which is why migration tests use
 * real tables.
 *
 * @since 0.1.0
 */
final class SchemaVerifier {

	/**
	 * Integer types whose display width MariaDB still reports.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const INTEGER_WIDTH = '/\b(tinyint|smallint|mediumint|int|integer|bigint)\(\d+\)/';

	/**
	 * The connection.
	 *
	 * @since 0.1.0
	 *
	 * @var Database
	 */
	private Database $db;

	/**
	 * Creates the verifier. Sends nothing.
	 *
	 * @since 0.1.0
	 *
	 * @param Database $db The connection.
	 */
	public function __construct( Database $db ) {
		$this->db = $db;
	}

	/**
	 * Lists every difference between a table and its declaration. Sends three queries, four
	 * when wpdb has no collation configured.
	 *
	 * @since 0.1.0
	 *
	 * @param TableDefinition $definition The declaration.
	 * @return list<string> One line per difference, naming the table and the column or index; empty when they match.
	 */
	public function diff( TableDefinition $definition ): array {
		$table = $this->db->table( $definition->name() );
		$facts = $this->db->fetchRow(
			'SELECT ENGINE AS engine, TABLE_COLLATION AS collation_name FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
			$table
		);

		if ( null === $facts ) {
			return array( sprintf( '%s: the table does not exist', $table ) );
		}

		$lines = array();

		if ( 'innodb' !== strtolower( (string) $facts['engine'] ) ) {
			$lines[] = sprintf( '%s: engine is %s, declared InnoDB', $table, (string) $facts['engine'] );
		}

		$collation = $this->expectedCollation();

		if ( $collation !== (string) $facts['collation_name'] ) {
			$lines[] = sprintf( '%s: table collation is %s, expected %s', $table, (string) $facts['collation_name'], $collation );
		}

		// A text column without a declared collation inherits the table's actual one; the table's own difference is reported above, once.
		return array_merge( $lines, $this->columnDiff( $definition, $table, (string) $facts['collation_name'] ), $this->indexDiff( $definition, $table ) );
	}

	/**
	 * Summarizes a table that passed diff(), for the `migrations.postcondition_json` record.
	 *
	 * @since 0.1.0
	 *
	 * @param TableDefinition $definition The declaration that was verified.
	 * @return array{table: string, engine: string, collation: string, columns: list<string>, indexes: list<string>} What was verified.
	 */
	public function summary( TableDefinition $definition ): array {
		$indexes = array( 'PRIMARY' );

		foreach ( array_merge( $definition->uniqueKeys(), $definition->indexes() ) as $index ) {
			$indexes[] = $index->name();
		}

		return array(
			'table'     => $this->db->table( $definition->name() ),
			'engine'    => 'InnoDB',
			'collation' => $this->expectedCollation(),
			'columns'   => array_map( static fn( ColumnSpec $column ): string => $column->name(), $definition->columns() ),
			'indexes'   => $indexes,
		);
	}

	/**
	 * Tells whether a plugin table exists on the current site.
	 *
	 * @since 0.1.0
	 *
	 * @param string $table The table's unprefixed name.
	 * @return bool True when it exists.
	 */
	public function hasTable( string $table ): bool {
		return '0' !== (string) $this->db->fetchValue(
			'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
			$this->db->table( $table )
		);
	}

	/**
	 * Normalizes a column type for comparison.
	 *
	 * @since 0.1.0
	 *
	 * @param string $type A type as declared or as information_schema reports it.
	 * @return string Lowercase, with integer display widths removed: `bigint(20) unsigned` becomes `bigint unsigned`.
	 */
	public static function normalizeType( string $type ): string {
		return trim( (string) preg_replace( self::INTEGER_WIDTH, '$1', strtolower( $type ) ) );
	}

	/**
	 * Normalizes a column default for comparison.
	 *
	 * @since 0.1.0
	 *
	 * @param string|null $value A default as declared or as information_schema reports it.
	 * @return string|null The literal without quotes, or null when there is no default or it is NULL.
	 */
	public static function normalizeDefault( ?string $value ): ?string {
		if ( null === $value || 'NULL' === $value ) {
			return null;
		}

		if ( strlen( $value ) >= 2 && "'" === $value[0] && "'" === substr( $value, -1 ) ) {
			return str_replace( "''", "'", substr( $value, 1, -1 ) );
		}

		return $value;
	}

	/**
	 * Compares the columns.
	 *
	 * @since 0.1.0
	 *
	 * @param TableDefinition $definition     The declaration.
	 * @param string          $table          The full table name.
	 * @param string          $tableCollation The table's actual collation, which a text column without a declared one must have.
	 * @return list<string> One line per difference.
	 */
	private function columnDiff( TableDefinition $definition, string $table, string $tableCollation ): array {
		$actual = array();

		foreach ( $this->db->fetchAll(
			'SELECT COLUMN_NAME AS name, COLUMN_TYPE AS type, IS_NULLABLE AS nullable, COLUMN_DEFAULT AS default_value, COLLATION_NAME AS collation_name, EXTRA AS extra FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s ORDER BY ORDINAL_POSITION',
			$table
		) as $row ) {
			$actual[ strtolower( (string) $row['name'] ) ] = $row;
		}

		$lines = array();

		foreach ( $definition->columns() as $column ) {
			$name = $column->name();

			if ( ! isset( $actual[ $name ] ) ) {
				$lines[] = sprintf( '%s.%s: the column does not exist', $table, $name );

				continue;
			}

			$row = $actual[ $name ];
			unset( $actual[ $name ] );

			$type = self::normalizeType( (string) $row['type'] );

			if ( self::normalizeType( $column->type() ) !== $type ) {
				$lines[] = sprintf( '%s.%s: type is %s, declared %s', $table, $name, $type, $column->type() );
			}

			if ( ( 'YES' === $row['nullable'] ) !== $column->nullable() ) {
				$lines[] = sprintf( '%s.%s: %s, declared %s', $table, $name, 'YES' === $row['nullable'] ? 'nullable' : 'NOT NULL', $column->nullable() ? 'nullable' : 'NOT NULL' );
			}

			$default = self::normalizeDefault( null === $row['default_value'] ? null : (string) $row['default_value'] );

			if ( self::normalizeDefault( $column->defaultValue() ) !== $default ) {
				$lines[] = sprintf( '%s.%s: default is %s, declared %s', $table, $name, self::show( $default ), self::show( $column->defaultValue() ) );
			}

			$extra    = strtolower( trim( (string) $row['extra'] ) );
			$declared = $column->autoIncrement() ? 'auto_increment' : '';

			if ( $declared !== $extra ) {
				$lines[] = sprintf( '%s.%s: extra is %s, declared %s', $table, $name, self::show( '' === $extra ? null : $extra ), self::show( '' === $declared ? null : $declared ) );
			}

			$actualCollation = null === $row['collation_name'] ? null : (string) $row['collation_name'];

			if ( null !== $column->collation() && $column->collation() !== $actualCollation ) {
				$lines[] = sprintf( '%s.%s: collation is %s, declared %s', $table, $name, self::show( $actualCollation ), $column->collation() );
			} elseif ( null === $column->collation() && null !== $actualCollation && $tableCollation !== $actualCollation ) {
				// A text column without a declared collation inherits the table's; a different one is a host rewrite or a broken migration.
				$lines[] = sprintf( "%s.%s: collation is %s, declared the table's %s", $table, $name, self::show( $actualCollation ), $tableCollation );
			}
		}

		foreach ( array_keys( $actual ) as $name ) {
			$lines[] = sprintf( '%s.%s: the column is not declared', $table, $name );
		}

		return $lines;
	}

	/**
	 * Compares the index set.
	 *
	 * @since 0.1.0
	 *
	 * @param TableDefinition $definition The declaration.
	 * @param string          $table      The full table name.
	 * @return list<string> One line per difference.
	 */
	private function indexDiff( TableDefinition $definition, string $table ): array {
		$actual = array();

		foreach ( $this->db->fetchAll(
			'SELECT INDEX_NAME AS index_name, NON_UNIQUE AS non_unique, SEQ_IN_INDEX AS seq, COLUMN_NAME AS column_name, SUB_PART AS sub_part FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s ORDER BY INDEX_NAME, SEQ_IN_INDEX',
			$table
		) as $row ) {
			$name = strtolower( (string) $row['index_name'] );

			$actual[ $name ]['unique']    = '0' === (string) $row['non_unique'];
			$actual[ $name ]['columns'][] = strtolower( (string) $row['column_name'] ) . ( null === $row['sub_part'] ? '' : '(' . $row['sub_part'] . ')' );
		}

		$declared = array(
			'primary' => array(
				'unique'  => true,
				'columns' => $definition->primaryKey(),
			),
		);

		foreach ( array_merge( $definition->uniqueKeys(), $definition->indexes() ) as $index ) {
			$columns = array();

			foreach ( $index->columns() as $column ) {
				$columns[] = $column['name'] . ( null === $column['length'] ? '' : '(' . $column['length'] . ')' );
			}

			$declared[ $index->name() ] = array(
				'unique'  => $index->isUnique(),
				'columns' => $columns,
			);
		}

		$lines = array();

		foreach ( $declared as $name => $index ) {
			$label = 'primary' === $name ? 'PRIMARY' : $name;

			if ( ! isset( $actual[ $name ] ) ) {
				$lines[] = sprintf( '%s: index %s does not exist', $table, $label );

				continue;
			}

			if ( $actual[ $name ]['unique'] !== $index['unique'] ) {
				$lines[] = sprintf( '%s: index %s is %s, declared %s', $table, $label, $actual[ $name ]['unique'] ? 'unique' : 'not unique', $index['unique'] ? 'unique' : 'not unique' );
			}

			if ( $actual[ $name ]['columns'] !== $index['columns'] ) {
				$lines[] = sprintf( '%s: index %s covers (%s), declared (%s)', $table, $label, implode( ',', $actual[ $name ]['columns'] ), implode( ',', $index['columns'] ) );
			}

			unset( $actual[ $name ] );
		}

		foreach ( array_keys( $actual ) as $name ) {
			$lines[] = sprintf( '%s: index %s is not declared', $table, $name );
		}

		return $lines;
	}

	/**
	 * Returns the collation new tables get on this site.
	 *
	 * @since 0.1.0
	 *
	 * @return string wpdb's collation, or the database default when wpdb has none configured.
	 */
	private function expectedCollation(): string {
		$collation = $this->db->collation();

		return '' !== $collation ? $collation : (string) $this->db->fetchValue( 'SELECT @@collation_database' );
	}

	/**
	 * Renders a nullable value for a diff line.
	 *
	 * @since 0.1.0
	 *
	 * @param string|null $value The value.
	 * @return string The value in quotes, or `none`.
	 */
	private static function show( ?string $value ): string {
		return null === $value ? 'none' : "'" . $value . "'";
	}
}
