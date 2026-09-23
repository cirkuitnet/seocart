<?php
/**
 * SchemaCheck: every registered table matches its declaration, and no plugin table is undeclared
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Cli\Doctor;

use SEOCart\Platform\Database\Database;
use SEOCart\Platform\Database\Schema\SchemaVerifier;
use SEOCart\Platform\DataRegistry\DataRegistry;

defined( 'ABSPATH' ) || exit;

/**
 * Compares the current site's plugin tables with the data registry, in both directions.
 *
 * Owns one fact: what "the schema is right" means for doctor. Every table the registry
 * declares must exist exactly as declared, as SchemaVerifier sees it: engine, collation,
 * columns and indexes. And every table named `{prefix}seocart_…` must be one the registry
 * declares; a table no module declares is reported, because nothing would ever migrate,
 * export, erase or remove it.
 *
 * A difference is printed as the table, column or index it concerns and the kind of mismatch,
 * in a fixed phrase: never the verifier's own words, which quote what the database holds, such
 * as a column's actual default. A difference of a kind this check does not recognize is
 * printed as a plain difference.
 *
 * @since 0.1.0
 */
final class SchemaCheck implements Check {

	/**
	 * How the verifier describes each kind of difference, and the fixed phrase printed for it.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, string>
	 */
	private const KINDS = array(
		'/^the table does not exist$/D'       => 'the table does not exist',
		'/^engine is /'                       => 'its storage engine differs from the declaration',
		'/^table collation is /'              => 'its collation differs from the declaration',
		'/^the column does not exist$/D'      => 'the column does not exist',
		'/^type is /'                         => 'its type differs from the declaration',
		'/^(?:nullable|NOT NULL), declared /' => 'whether it may be NULL differs from the declaration',
		'/^default is /'                      => 'its default differs from the declaration',
		'/^extra is /'                        => 'its extra attributes differ from the declaration',
		'/^collation is /'                    => 'its collation differs from the declaration',
		'/^the column is not declared$/D'     => 'no declaration names this column',
	);

	/**
	 * How the verifier describes each kind of index difference, and the phrase printed after the index's name.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, string>
	 */
	private const INDEX_KINDS = array(
		'does not exist'          => 'does not exist',
		'is unique, declared'     => 'differs in uniqueness from the declaration',
		'is not unique, declared' => 'differs in uniqueness from the declaration',
		'covers ('                => 'covers other columns than the declaration',
		'is not declared'         => 'is not declared',
	);

	/**
	 * The check's name.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const NAME = 'schema';

	/**
	 * The connection.
	 *
	 * @since 0.1.0
	 *
	 * @var Database
	 */
	private Database $db;

	/**
	 * What the plugin owns.
	 *
	 * @since 0.1.0
	 *
	 * @var DataRegistry
	 */
	private DataRegistry $registry;

	/**
	 * Creates the check. Sends nothing.
	 *
	 * @since 0.1.0
	 *
	 * @param Database     $db       The connection.
	 * @param DataRegistry $registry What the plugin owns.
	 */
	public function __construct( Database $db, DataRegistry $registry ) {
		$this->db       = $db;
		$this->registry = $registry;
	}

	/**
	 * Returns the check's name.
	 *
	 * @since 0.1.0
	 *
	 * @return string `schema`.
	 */
	public function name(): string {
		return self::NAME;
	}

	/**
	 * Compares the tables with their declarations, and looks for undeclared plugin tables.
	 *
	 * @since 0.1.0
	 *
	 * @return CheckResult Passed when there is no difference either way.
	 */
	public function run(): CheckResult {
		$verifier = new SchemaVerifier( $this->db );
		$findings = array();

		foreach ( $this->registry->tables() as $table ) {
			$findings = array_merge( $findings, array_map( static fn( string $difference ): string => self::kindOf( $difference ), $verifier->diff( $table ) ) );
		}

		$declared = array_map( fn( string $name ): string => $this->db->table( $name ), $this->registry->tableNames() );

		foreach ( self::pluginTables( $this->db ) as $table ) {
			if ( ! in_array( $table, $declared, true ) ) {
				$findings[] = sprintf( '%s: a plugin table that no module declares', CheckResult::identifier( $table ) );
			}
		}

		if ( array() === $findings ) {
			return CheckResult::pass( self::NAME, sprintf( 'All %d registered tables match their declarations, and no undeclared plugin table exists.', count( $declared ) ) );
		}

		return CheckResult::fail( self::NAME, sprintf( 'The database differs from the declarations in %d %s.', count( $findings ), 1 === count( $findings ) ? 'place' : 'places' ), $findings );
	}

	/**
	 * Reduces one difference the verifier found to what it concerns and the kind of mismatch.
	 *
	 * @since 0.1.0
	 *
	 * @param string $difference The verifier's line: `table: description` or `table.column: description`.
	 * @return string The table, column or index and a fixed phrase; nothing the database holds.
	 */
	private static function kindOf( string $difference ): string {
		$colon       = strpos( $difference, ': ' );
		$subject     = false === $colon ? '' : substr( $difference, 0, $colon );
		$description = false === $colon ? '' : substr( $difference, $colon + 2 );
		$shown       = '' === $subject ? 'a plugin table' : CheckResult::identifier( $subject );

		foreach ( self::KINDS as $pattern => $phrase ) {
			if ( 1 === preg_match( $pattern, $description ) ) {
				return $shown . ': ' . $phrase;
			}
		}

		if ( 1 === preg_match( '/^index (\S+) (.*)$/D', $description, $index ) ) {
			foreach ( self::INDEX_KINDS as $start => $phrase ) {
				if ( str_starts_with( $index[2], $start ) ) {
					return sprintf( '%s: index %s %s', $shown, CheckResult::identifier( $index[1] ), $phrase );
				}
			}
		}

		return $shown . ': differs from its declaration';
	}

	/**
	 * Lists the tables of the current site whose name starts with `{prefix}seocart_`.
	 *
	 * @since 0.1.0
	 *
	 * @param Database $db The connection.
	 * @return list<string> Full table names, sorted.
	 */
	public static function pluginTables( Database $db ): array {
		$rows = $db->fetchAll(
			'SELECT TABLE_NAME AS name FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE %s ORDER BY TABLE_NAME',
			addcslashes( $db->table( '' ), '\\_%' ) . '%'
		);

		return array_map( static fn( array $row ): string => (string) $row['name'], $rows );
	}
}
