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
 * nothing when the table already matches. dbDelta cannot drop, retype or rename a column; no
 * migration needs that yet. What any of this did is never taken on trust: the migrator
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
