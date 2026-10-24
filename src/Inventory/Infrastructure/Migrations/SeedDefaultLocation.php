<?php
/**
 * SeedDefaultLocation: the store's default location, holding every stock row the store had
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Inventory\Infrastructure\Migrations;

use SEOCart\Inventory\Infrastructure\InventoryTables;
use SEOCart\Inventory\Infrastructure\MysqlLocationRepository;
use SEOCart\Platform\Database\Database;
use SEOCart\Platform\Database\DataMigration;
use SEOCart\Platform\Database\ModuleStatements;
use SEOCart\Platform\Database\Schema\SchemaVerifier;
use SEOCart\Support\SystemClock;
use SEOCart\Support\SystemIdGenerator;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- These exceptions report a state a person must look at, or a cursor the migrator stored, to the developer; they are never HTML.

/**
 * Gives the store its default location, copies each variant's stock policy to its own row, and puts every stock row the store had at the default location.
 *
 * Owns one fact: where the stock a store had before it kept stock per location is now. The first
 * batch creates the default location when the store has none: named after the site, with no
 * address, active, fulfilling online orders and offering no pickup. The following batches page
 * through `stock_items`, then the hold, allocation and ledger rows, a thousand rows a batch in
 * the order of their keys, the cursor naming the table and the last key read, and set the
 * location of every row that has none to the default location. Each batch reads its page first
 * and changes only rows of that page, and only rows with no location, so a batch run again
 * changes nothing; the store's writes are held until the chain is applied, so nothing else
 * changes the rows meanwhile.
 *
 * While `stock_items` still has the policy columns, as on a store that kept stock per variant,
 * the pages of `stock_items` also write each variant's `track`, `backorder_policy` and
 * `low_stock_threshold` to its row of `stock_policies`, leaving a row already there as it is. On
 * a site installed since, `stock_items` never had those columns, the stock tables are empty and
 * nothing is copied or filled.
 *
 * The ledger is append-only, and this migration is the one place that changes ledger rows, as
 * declared on MutationPattern::AppendOnly: it sets the location column the same chain added, on
 * rows it reads only to page through them, and leaves every column recorded before unchanged.
 *
 * @since 0.2.0
 */
final class SeedDefaultLocation implements DataMigration {

	/**
	 * The id.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const ID = '20261024_0002_inventory_default_location';

	/**
	 * How many rows one batch reads.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	public const BATCH = 1000;

	/**
	 * The name the default location takes when the site has no title.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const FALLBACK_NAME = 'Default location';

	/**
	 * A page of stock items, whole: the policy columns are read from it while they exist.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const ITEMS_PAGE = 'SELECT * FROM {stock_items} WHERE variant_id > %d ORDER BY variant_id LIMIT %d';

	/**
	 * A page of hold rows' keys.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const HOLDS_PAGE = 'SELECT id FROM {stock_holds} WHERE id > %d ORDER BY id LIMIT %d';

	/**
	 * A page of allocation rows' keys.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const ALLOCATIONS_PAGE = 'SELECT id FROM {stock_allocations} WHERE id > %d ORDER BY id LIMIT %d';

	/**
	 * A page of ledger rows' keys.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const LEDGER_PAGE = 'SELECT id FROM {stock_ledger} WHERE id > %d ORDER BY id LIMIT %d';

	/**
	 * A variant's stock policy, from its item, unless it has one: one row of a multi-row insert, the threshold '' for none.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const COPY_POLICY = "INSERT INTO {stock_policies} ( variant_id, track, backorder_policy, low_stock_threshold, updated_at ) VALUES ( %d, %d, %s, NULLIF( %s, '' ), UTC_TIMESTAMP(6) ) ON DUPLICATE KEY UPDATE variant_id = variant_id";

	/**
	 * Puts a page of stock items at the default location.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const FILL_ITEMS = 'UPDATE {stock_items} SET location_id = %d WHERE location_id IS NULL AND variant_id > %d AND variant_id <= %d';

	/**
	 * Puts a page of hold rows at the default location.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const FILL_HOLDS = 'UPDATE {stock_holds} SET location_id = %d WHERE location_id IS NULL AND id > %d AND id <= %d';

	/**
	 * Puts a page of allocation rows at the default location.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const FILL_ALLOCATIONS = 'UPDATE {stock_allocations} SET location_id = %d WHERE location_id IS NULL AND id > %d AND id <= %d';

	/**
	 * Puts a page of ledger rows at the default location: the one change to the ledger, which sets the column this chain added and no other.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const FILL_LEDGER = 'UPDATE {stock_ledger} SET location_id = %d WHERE location_id IS NULL AND id > %d AND id <= %d';

	/**
	 * The phases after the first batch, in order: each its page, its fill and its key column.
	 *
	 * `stock_policies` pages through `stock_items` and copies the policies as it fills the items,
	 * so it takes the place of `stock_items` on a store that still has the policy columns.
	 *
	 * @since 0.2.0
	 *
	 * @var array<string, array{0: string, 1: string, 2: string}>
	 */
	private const PHASES = array(
		InventoryTables::POLICIES    => array( self::ITEMS_PAGE, self::FILL_ITEMS, 'variant_id' ),
		InventoryTables::ITEMS       => array( self::ITEMS_PAGE, self::FILL_ITEMS, 'variant_id' ),
		InventoryTables::HOLDS       => array( self::HOLDS_PAGE, self::FILL_HOLDS, 'id' ),
		InventoryTables::ALLOCATIONS => array( self::ALLOCATIONS_PAGE, self::FILL_ALLOCATIONS, 'id' ),
		InventoryTables::LEDGER      => array( self::LEDGER_PAGE, self::FILL_LEDGER, 'id' ),
	);

	/**
	 * The module whose tables the statements name.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const MODULE = 'inventory';

	/**
	 * Returns the id.
	 *
	 * @since 0.2.0
	 *
	 * @return string The id.
	 */
	public function id(): string {
		return self::ID;
	}

	/**
	 * Tells whether the store may trade while this runs: no, a stock row without its location is not yet at any location.
	 *
	 * @since 0.2.0
	 *
	 * @return bool False.
	 */
	public function canOperateHalfApplied(): bool {
		return false;
	}

	/**
	 * Runs one batch: the default location first, then one page of one table, and names where the next batch goes on.
	 *
	 * @since 0.2.0
	 *
	 * @throws \RuntimeException         When the store has locations and none of them is the default: a person must choose one.
	 * @throws \UnexpectedValueException When the cursor names no phase of this migration.
	 *
	 * @param string|null $cursor Where to continue: null to begin, or `{table}:{last key read}`.
	 * @param Database    $db     The connection, in the batch's transaction.
	 * @return string|null The next batch's cursor, or null when every table was read.
	 */
	public function runBatch( ?string $cursor, Database $db ): ?string {
		if ( null === $cursor ) {
			self::defaultLocation( $db );

			// A store that kept stock per variant still has the policy columns on its items: copy them as the items are filled.
			return ( ( new SchemaVerifier( $db ) )->hasColumn( InventoryTables::ITEMS, 'track' ) ? InventoryTables::POLICIES : InventoryTables::ITEMS ) . ':0';
		}

		list( $phase, $after )        = self::position( $cursor );
		list( $page, $fill, $column ) = self::PHASES[ $phase ];

		$statements = new ModuleStatements( $db, self::MODULE, InventoryTables::names() );
		$rows       = $statements->rows( $page, $after, self::BATCH );

		if ( array() !== $rows ) {
			$last = (int) $rows[ count( $rows ) - 1 ][ $column ];

			if ( InventoryTables::POLICIES === $phase ) {
				self::copyPolicies( $statements, $rows );
			}

			$statements->execute( $fill, self::defaultLocation( $db ), $after, $last );

			if ( count( $rows ) === self::BATCH ) {
				return $phase . ':' . $last;
			}
		}

		return self::next( $phase );
	}

	/**
	 * Returns the default location's id, creating the location when the store has none.
	 *
	 * @since 0.2.0
	 *
	 * @throws \RuntimeException When the store has locations and none of them is the default.
	 *
	 * @param Database $db The connection, in the batch's transaction.
	 * @return int The id.
	 */
	private static function defaultLocation( Database $db ): int {
		$locations = new MysqlLocationRepository( $db );
		$default   = $locations->defaultId();

		if ( null !== $default ) {
			return $default;
		}

		if ( $locations->any() ) {
			throw new \RuntimeException( 'The store has locations, but none of them is its default location, so its stock rows cannot be put at one. Mark one location as the default, then run `wp seocart migrate` again.' );
		}

		$title = wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES );
		$name  = '' === trim( $title ) ? self::FALLBACK_NAME : mb_substr( trim( $title ), 0, 100 );

		return $locations->createDefault( ( new SystemIdGenerator( new SystemClock() ) )->generate(), $name );
	}

	/**
	 * Writes the stock policy of every variant of a page of old items, leaving a policy already there as it is.
	 *
	 * @since 0.2.0
	 *
	 * @param ModuleStatements           $statements The module's statements.
	 * @param list<array<string, mixed>> $rows       The page, read whole.
	 */
	private static function copyPolicies( ModuleStatements $statements, array $rows ): void {
		$values = array();

		foreach ( $rows as $row ) {
			array_push( $values, (int) $row['variant_id'], (int) $row['track'], (string) $row['backorder_policy'], null === $row['low_stock_threshold'] ? '' : (string) $row['low_stock_threshold'] );
		}

		$statements->execute( ModuleStatements::forRows( self::COPY_POLICY, count( $rows ) ), ...$values );
	}

	/**
	 * Returns the cursor of the phase after one, or null after the last.
	 *
	 * @since 0.2.0
	 *
	 * @param string $phase The phase just finished.
	 * @return string|null The next phase's cursor, or null.
	 */
	private static function next( string $phase ): ?string {
		// Copying the policies fills the items as well, so the items' own phase comes after it with nothing left to do.
		$phases = array_keys( self::PHASES );
		$next   = $phases[ (int) array_search( InventoryTables::POLICIES === $phase ? InventoryTables::ITEMS : $phase, $phases, true ) + 1 ] ?? null;

		return null === $next ? null : $next . ':0';
	}

	/**
	 * Reads a cursor.
	 *
	 * @since 0.2.0
	 *
	 * @throws \UnexpectedValueException When the cursor names no phase of this migration.
	 *
	 * @param string $cursor `{table}:{last key read}`.
	 * @return array{0: string, 1: int} The phase and the last key read.
	 */
	private static function position( string $cursor ): array {
		list( $phase, $after ) = array_pad( explode( ':', $cursor, 2 ), 2, '0' );

		if ( ! isset( self::PHASES[ $phase ] ) ) {
			throw new \UnexpectedValueException( sprintf( 'The cursor %s names no table this migration reads.', $cursor ) );
		}

		return array( $phase, (int) $after );
	}
}
