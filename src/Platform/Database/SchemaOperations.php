<?php
/**
 * SchemaOperations: the DDL a schema migration may perform
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Database;

use SEOCart\Platform\Database\Exception\QueryFailed;
use SEOCart\Platform\Database\Schema\ColumnSpec;
use SEOCart\Platform\Database\Schema\DdlGenerator;
use SEOCart\Platform\Database\Schema\SchemaVerifier;
use SEOCart\Platform\Database\Schema\TableDefinition;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- These exceptions report a migration's programming error to the developer who wrote it; they are never HTML.

/**
 * Creates tables from their declarations, and changes existing ones until they match them.
 *
 * Owns one fact: how a declaration becomes DDL on this server. A table that does not exist is
 * created with the generated CREATE TABLE, sent through Database, so a failure keeps its error
 * number (the bootstrap relies on seeing 1050 when two runners race). A table that exists is
 * handed to dbDelta with the same statement, which adds any missing column or index and does
 * nothing when the table already matches. dbDelta compares each column's type as the server
 * reports it with the statement's, as text, so on a server that reports integer display widths
 * (MariaDB, and MySQL before 8.0.19) that statement writes them, or dbDelta would change every
 * integer column on every run. dbDelta cannot drop, retype or rename a column, and
 * never changes an index it finds; a unique key whose declared columns changed is replaced by
 * replaceUniqueKey() instead, a column a migration must add before the table reaches its
 * declared shape by addColumn(), and every other change of shape by alterTable(), with the
 * clauses the migration writes. What any of this did is never taken on trust: the migrator
 * verifies the result.
 *
 * addColumn() and alterTable() send their ALTER in strict mode. WordPress opens its connection
 * without it, and in that mode an ALTER that cannot keep a row's value as it is, such as a
 * column made NOT NULL while a row still holds NULL, stores the column's implicit default in its
 * place and succeeds with a warning. A change of shape must never change data silently, so for
 * the one statement the session's SQL mode gains STRICT_ALL_TABLES, and the server refuses such
 * an ALTER whole and leaves the table as it was; the mode the session had is restored after it,
 * whatever happened.
 *
 * @since 0.1.0
 */
final class SchemaOperations {

	/**
	 * The clauses alterTable() sends: each changes a column or a key in a form MySQL and MariaDB both accept, and nothing else.
	 *
	 * No RENAME, which the two servers write differently, and no ALGORITHM or LOCK hint, which
	 * each may refuse for a statement the other runs.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const ALTER_CLAUSE = '/^(?:(?:MODIFY|DROP COLUMN|DROP INDEX|ADD KEY|ADD UNIQUE KEY|ADD PRIMARY KEY) [^;]+|DROP PRIMARY KEY)\z/';

	/**
	 * Saves the session's SQL mode and adds strict mode to it, for the one ALTER that follows.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const STRICT_MODE = "SET @seocart_sql_mode = @@SESSION.sql_mode, SESSION sql_mode = CONCAT_WS( ',', NULLIF( @@SESSION.sql_mode, '' ), 'STRICT_ALL_TABLES' )";

	/**
	 * Gives the session back the SQL mode STRICT_MODE saved.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const SAVED_MODE = 'SET SESSION sql_mode = @seocart_sql_mode';

	/**
	 * The connection.
	 *
	 * @since 0.1.0
	 *
	 * @var Database
	 */
	private Database $db;

	/**
	 * Writes CREATE TABLE statements.
	 *
	 * @since 0.1.0
	 *
	 * @var DdlGenerator
	 */
	private DdlGenerator $generator;

	/**
	 * Reads table shapes from information_schema.
	 *
	 * @since 0.1.0
	 *
	 * @var SchemaVerifier
	 */
	private SchemaVerifier $verifier;

	/**
	 * Creates the operations. Sends nothing.
	 *
	 * @since 0.1.0
	 *
	 * @param Database       $db        The connection.
	 * @param DdlGenerator   $generator Writes CREATE TABLE statements.
	 * @param SchemaVerifier $verifier  Reads table shapes.
	 */
	public function __construct( Database $db, DdlGenerator $generator, SchemaVerifier $verifier ) {
		$this->db        = $db;
		$this->generator = $generator;
		$this->verifier  = $verifier;
	}

	/**
	 * Creates a table from its declaration, or adds what an existing one lacks.
	 *
	 * @since 0.1.0
	 *
	 * @throws QueryFailed When the server refuses the DDL.
	 *
	 * @param TableDefinition $definition The declaration.
	 */
	public function createTable( TableDefinition $definition ): void {
		$table = $this->db->table( $definition->name() );

		if ( ! $this->verifier->hasTable( $definition->name() ) ) {
			$this->db->execute( $this->generator->createTable( $definition, $table, $this->db->charsetCollate() ) );

			return;
		}

		$this->reconcile( $this->generator->createTable( $definition, $table, $this->db->charsetCollate(), $this->db->server()->reportsIntegerDisplayWidths() ) );
	}

	/**
	 * Gives an existing table's unique key the columns its declaration names now, in one statement, so the table is never without the key.
	 *
	 * One ALTER TABLE drops the key and adds it again under the same name, which the server applies
	 * whole. A key already as declared is left as it is, and nothing is sent, as on a site that
	 * created the table from the declaration since; a table, or a key, that does not exist yet is
	 * created from the declaration as createTable() creates it.
	 *
	 * @since 0.2.0
	 *
	 * @throws \InvalidArgumentException When the declaration declares no unique key by that name, before any statement.
	 * @throws QueryFailed               When the server refuses the DDL, as when rows already break the key as declared.
	 *
	 * @param TableDefinition $definition The table's declaration, with the key as it must end.
	 * @param string          $name       The unique key's name.
	 */
	public function replaceUniqueKey( TableDefinition $definition, string $name ): void {
		$statement = $this->generator->replaceUniqueKey( $definition, $this->db->table( $definition->name() ), $name );

		$matches = $this->verifier->hasTable( $definition->name() ) ? $this->verifier->indexMatches( $definition, $name ) : null;

		if ( true === $matches ) {
			return;
		}

		if ( null === $matches ) {
			$this->createTable( $definition );

			return;
		}

		$this->db->execute( $statement );
	}

	/**
	 * Adds a column to an existing table, as the column is written here, unless the table has a column of that name already.
	 *
	 * For a column a migration needs before its table reaches the shape it declares, such as one
	 * added nullable, filled in batches and made NOT NULL afterwards. The column is looked for by
	 * name alone: one of that name, whatever its type or nullability, means nothing is sent, as on
	 * a site whose table was created since in its final shape. Sent in strict mode, as the class
	 * says.
	 *
	 * @since 0.2.0
	 *
	 * @throws QueryFailed When the server refuses the ALTER, as when the table does not exist.
	 *
	 * @param string     $table  The table's unprefixed name.
	 * @param ColumnSpec $column The column, as it is to be added.
	 */
	public function addColumn( string $table, ColumnSpec $column ): void {
		if ( $this->verifier->hasColumn( $table, $column->name() ) ) {
			return;
		}

		$this->alterStrictly( 'ALTER TABLE `' . $this->db->table( $table ) . '` ADD COLUMN ' . $this->generator->columnLine( $column ) );
	}

	/**
	 * Brings an existing table to its declaration with one ALTER TABLE of the given clauses, unless it matches already.
	 *
	 * For the changes dbDelta cannot make: a column made NOT NULL, a primary key replaced, a
	 * column or a key dropped, a key added in the same statement. When the table already matches
	 * its declaration nothing is sent, so a migration run again sends no DDL. Afterwards the table
	 * must match it exactly, or the clauses were wrong, and the exception names the statement and
	 * every difference left, rather than leaving that to the migrator's check of the whole
	 * migration. Sent in strict mode, as the class says: a row the change cannot keep as it is
	 * makes the server refuse the whole statement.
	 *
	 * @since 0.2.0
	 *
	 * @throws \InvalidArgumentException             When there is no clause, or a clause is not one of ALTER_CLAUSE's, before any statement.
	 * @throws QueryFailed|\UnexpectedValueException QueryFailed when the server refuses the ALTER, which leaves the table as it
	 *                                               was; \UnexpectedValueException when the table still differs from its
	 *                                               declaration after the ALTER.
	 *
	 * @param TableDefinition $target     The table's declaration: the shape it must end in.
	 * @param string          ...$clauses The clauses, in order, such as `MODIFY location_id bigint unsigned NOT NULL`.
	 */
	public function alterTable( TableDefinition $target, string ...$clauses ): void {
		if ( array() === $clauses ) {
			throw new \InvalidArgumentException( sprintf( 'An ALTER of %s needs at least one clause.', $target->name() ) );
		}

		foreach ( $clauses as $clause ) {
			if ( 1 !== preg_match( self::ALTER_CLAUSE, $clause ) ) {
				throw new \InvalidArgumentException( sprintf( 'The clause "%s" is not one alterTable() sends: MODIFY, DROP PRIMARY KEY, ADD PRIMARY KEY, DROP COLUMN, DROP INDEX, ADD KEY or ADD UNIQUE KEY, in capitals.', $clause ) );
			}
		}

		if ( array() === $this->verifier->diff( $target ) ) {
			return;
		}

		$statement = 'ALTER TABLE `' . $this->db->table( $target->name() ) . '` ' . implode( ', ', $clauses );

		$this->alterStrictly( $statement );

		$left = $this->verifier->diff( $target );

		if ( array() !== $left ) {
			throw new \UnexpectedValueException( sprintf( '%1$s left the table short of its declaration: %2$s', $statement, implode( '; ', $left ) ) );
		}
	}

	/**
	 * Returns a plugin table's full name on this site, for a message that names a statement a person runs.
	 *
	 * @since 0.2.0
	 *
	 * @param string $name The table's unprefixed name.
	 * @return string The full name, the site's prefix included.
	 */
	public function tableName( string $name ): string {
		return $this->db->table( $name );
	}

	/**
	 * Creates several tables, in order.
	 *
	 * @since 0.1.0
	 *
	 * @param TableDefinition[] $definitions The declarations.
	 */
	public function createTables( array $definitions ): void {
		foreach ( $definitions as $definition ) {
			$this->createTable( $definition );
		}
	}

	/**
	 * Sends an ALTER TABLE with strict mode added to the session's SQL mode, and gives the session its own mode back afterwards.
	 *
	 * Two statements around the ALTER: one saves the mode and adds STRICT_ALL_TABLES to it, the
	 * other restores what it saved, whether the ALTER was applied or refused.
	 *
	 * @since 0.2.0
	 *
	 * @throws QueryFailed When the server refuses the ALTER.
	 *
	 * @param string $statement The ALTER TABLE statement, sent as written.
	 */
	private function alterStrictly( string $statement ): void {
		$this->db->execute( self::STRICT_MODE );

		try {
			$this->db->execute( $statement );
		} finally {
			$this->db->execute( self::SAVED_MODE );
		}
	}

	/**
	 * Hands a CREATE TABLE for an existing table to dbDelta, which adds what is missing.
	 *
	 * The dbDelta() function sends its statements through the global wpdb and keeps no error
	 * numbers, so its error output is suppressed while it runs and the errors wpdb recorded for
	 * its ALTERs are read back from the global error list afterwards.
	 *
	 * @since 0.1.0
	 *
	 * @throws QueryFailed When an ALTER that dbDelta sent failed.
	 *
	 * @param string $statement The CREATE TABLE statement.
	 */
	private function reconcile( string $statement ): void {
		global $wpdb, $EZSQL_ERROR;

		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		$recorded   = is_array( $EZSQL_ERROR ) ? count( $EZSQL_ERROR ) : 0;
		$suppressed = $wpdb->suppress_errors( true );

		try {
			dbDelta( $statement );
		} finally {
			$wpdb->suppress_errors( $suppressed );
		}

		foreach ( array_slice( is_array( $EZSQL_ERROR ) ? $EZSQL_ERROR : array(), $recorded ) as $error ) {
			$query = (string) ( $error['query'] ?? '' );

			// dbDelta asks DESCRIBE and SHOW INDEX first; only a change it made can fail the step.
			if ( 1 === preg_match( '/^\s*(?:ALTER|CREATE)\b/i', $query ) ) {
				QueryFailed::raiseRefused( 0, '', $query, (string) ( $error['error_str'] ?? '' ) );
			}
		}
	}
}
