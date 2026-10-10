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
use SEOCart\Platform\Database\Schema\DdlGenerator;
use SEOCart\Platform\Database\Schema\SchemaVerifier;
use SEOCart\Platform\Database\Schema\TableDefinition;

defined( 'ABSPATH' ) || exit;

/**
 * Creates tables from their declarations.
 *
 * Owns one fact: how a declaration becomes DDL on this server. A table that does not exist is
 * created with the generated CREATE TABLE, sent through Database, so a failure keeps its error
 * number (the bootstrap relies on seeing 1050 when two runners race). A table that exists is
 * handed to dbDelta with the same statement, which adds any missing column or index and does
 * nothing when the table already matches. dbDelta cannot drop, retype or rename a column, and
 * never changes an index it finds; a unique key whose declared columns changed is replaced by
 * replaceUniqueKey() instead. What any of this did is never taken on trust: the migrator
 * verifies the result.
 *
 * @since 0.1.0
 */
final class SchemaOperations {

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
		$statement = $this->generator->createTable( $definition, $this->db->table( $definition->name() ), $this->db->charsetCollate() );

		if ( ! $this->verifier->hasTable( $definition->name() ) ) {
			$this->db->execute( $statement );

			return;
		}

		$this->reconcile( $statement );
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
