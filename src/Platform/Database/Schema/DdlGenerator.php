<?php
/**
 * DdlGenerator: writes the CREATE TABLE statement for a table declaration, in the form dbDelta parses, and the ALTER that gives an existing unique key its declared columns
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Database\Schema;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- A declaration error is a message for the developer who wrote the migration; it is never HTML.

/**
 * Turns a TableDefinition into a CREATE TABLE statement, and into the ALTER TABLE that replaces one of its unique keys, which dbDelta never changes.
 *
 * Owns one fact: the formatting rules dbDelta's regular-expression parser needs, applied by
 * code instead of by hand. Lowercase types, backticked identifiers, one column or key per line
 * and no blank line, exactly two spaces between `PRIMARY KEY` and its column list, exactly
 * one space after `KEY name` and `UNIQUE KEY name`, no COMMENT, no IF NOT EXISTS and no
 * FOREIGN KEY, and `ENGINE=InnoDB` stated with the site's character set and collation after it.
 * And, for the statement dbDelta compares with an existing table on a server that reports
 * integer display widths, each integer type written as that server reports it.
 *
 * Pure: it builds a string and touches nothing.
 *
 * @since 0.1.0
 */
final class DdlGenerator {

	/**
	 * The display width a server that reports widths shows for each integer type a column may declare without one.
	 *
	 * MariaDB, and MySQL before 8.0.19, report `bigint unsigned` as `bigint(20) unsigned`: the
	 * width of the type's widest value, its sign included when it has one.
	 *
	 * @since 0.2.0
	 *
	 * @var array<string, int>
	 */
	private const DISPLAY_WIDTHS = array(
		'tinyint'            => 4,
		'tinyint unsigned'   => 3,
		'smallint'           => 6,
		'smallint unsigned'  => 5,
		'mediumint'          => 9,
		'mediumint unsigned' => 8,
		'int'                => 11,
		'int unsigned'       => 10,
		'bigint'             => 20,
		'bigint unsigned'    => 20,
	);

	/**
	 * Writes the CREATE TABLE statement for a table.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 The integer display widths, for the statement dbDelta compares with an existing table.
	 *
	 * @param TableDefinition $definition     The table's declaration.
	 * @param string          $tableName      The full table name, prefix included, as Database::table() returns it.
	 * @param string          $charsetCollate The clause wpdb::get_charset_collate() returns. May be empty.
	 * @param bool            $integerWidths  Optional. Whether to write each integer type as a server that reports
	 *                                        display widths reports it (withDisplayWidth()). Default false: as declared.
	 * @return string The statement, without a trailing semicolon.
	 */
	public function createTable( TableDefinition $definition, string $tableName, string $charsetCollate, bool $integerWidths = false ): string {
		$lines = array();

		foreach ( $definition->columns() as $column ) {
			$lines[] = self::column( $column, $integerWidths );
		}

		$lines[] = 'PRIMARY KEY  (' . self::columnList(
			array_map(
				static fn( string $name ): array => array(
					'name'   => $name,
					'length' => null,
				),
				$definition->primaryKey()
			)
		) . ')';

		foreach ( $definition->uniqueKeys() as $index ) {
			$lines[] = 'UNIQUE KEY `' . $index->name() . '` (' . self::columnList( $index->columns() ) . ')';
		}

		foreach ( $definition->indexes() as $index ) {
			$lines[] = 'KEY `' . $index->name() . '` (' . self::columnList( $index->columns() ) . ')';
		}

		return 'CREATE TABLE `' . $tableName . "` (\n\t" . implode( ",\n\t", $lines ) . "\n) ENGINE=InnoDB" . ( '' === $charsetCollate ? '' : ' ' . $charsetCollate );
	}

	/**
	 * Writes the statement that gives an existing table's unique key the columns its declaration names now: one ALTER TABLE that drops the key and adds it again under the same name.
	 *
	 * One statement, which the server applies whole, so the table is never without the key.
	 *
	 * @since 0.2.0
	 *
	 * @throws \InvalidArgumentException When the declaration declares no unique key by that name.
	 *
	 * @param TableDefinition $definition The table's declaration.
	 * @param string          $tableName  The full table name, prefix included, as Database::table() returns it.
	 * @param string          $name       The unique key's name.
	 * @return string The statement, without a trailing semicolon.
	 */
	public function replaceUniqueKey( TableDefinition $definition, string $tableName, string $name ): string {
		$key = $definition->index( $name );

		if ( null === $key || ! $key->isUnique() ) {
			throw new \InvalidArgumentException( sprintf( 'Table %1$s declares no unique key named %2$s.', $definition->name(), $name ) );
		}

		return 'ALTER TABLE `' . $tableName . '` DROP INDEX `' . $key->name() . '`, ADD UNIQUE KEY `' . $key->name() . '` (' . self::columnList( $key->columns() ) . ')';
	}

	/**
	 * Writes an integer type the way a server that reports display widths reports it.
	 *
	 * @since 0.2.0
	 *
	 * @param string $type A declared type, lowercase, as ColumnSpec holds it.
	 * @return string `bigint unsigned` becomes `bigint(20) unsigned`; a type with a width of its own, such as
	 *                `tinyint(1)`, and every type that is not an integer, come back unchanged.
	 */
	public static function withDisplayWidth( string $type ): string {
		if ( ! isset( self::DISPLAY_WIDTHS[ $type ] ) ) {
			return $type;
		}

		$parts = explode( ' ', $type, 2 );

		return $parts[0] . '(' . self::DISPLAY_WIDTHS[ $type ] . ')' . ( isset( $parts[1] ) ? ' ' . $parts[1] : '' );
	}

	/**
	 * Writes one column line.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 The integer display widths.
	 *
	 * @param ColumnSpec $column        The column.
	 * @param bool       $integerWidths Whether to write an integer type with its display width.
	 * @return string For example "`uuid` char(36) COLLATE ascii_bin NOT NULL".
	 */
	private static function column( ColumnSpec $column, bool $integerWidths ): string {
		$type = strtolower( $column->type() );
		$line = '`' . $column->name() . '` ' . ( $integerWidths ? self::withDisplayWidth( $type ) : $type );

		if ( null !== $column->collation() ) {
			$line .= ' COLLATE ' . $column->collation();
		}

		$line .= $column->nullable() ? ' NULL' : ' NOT NULL';

		if ( null !== $column->defaultValue() ) {
			$line .= " DEFAULT '" . $column->defaultValue() . "'";
		}

		if ( $column->autoIncrement() ) {
			$line .= ' AUTO_INCREMENT';
		}

		return $line;
	}

	/**
	 * Writes a key's column list.
	 *
	 * @since 0.1.0
	 *
	 * @param list<array{name: string, length: int|null}> $columns The columns in key order.
	 * @return string For example "`a`,`b`(20)".
	 */
	private static function columnList( array $columns ): string {
		$parts = array();

		foreach ( $columns as $column ) {
			$parts[] = '`' . $column['name'] . '`' . ( null === $column['length'] ? '' : '(' . $column['length'] . ')' );
		}

		return implode( ',', $parts );
	}
}
