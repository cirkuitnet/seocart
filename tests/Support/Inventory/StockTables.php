<?php
/**
 * StockTables: the inventory tables a test needs, with the default location a store has
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support\Inventory;

use SEOCart\Inventory\Infrastructure\Migrations\CreateStockTablesMigration;
use SEOCart\Inventory\Infrastructure\Migrations\SeedDefaultLocation;
use SEOCart\Inventory\Infrastructure\MysqlLocationRepository;
use SEOCart\Platform\Database\Database;
use SEOCart\Platform\Database\Schema\DdlGenerator;
use SEOCart\Platform\Database\Schema\SchemaVerifier;
use SEOCart\Platform\Database\SchemaOperations;

/**
 * Creates the inventory tables and the store's default location, as a fresh install does.
 *
 * Owns one fact: what a test that holds or sells stock must have besides its rows. Every stock
 * row is at a location, and a line or a new item that names none is at the default one, so the
 * tables are not enough without it. The default location is made by the upgrade's own first
 * batch, the one a fresh install runs.
 *
 * @since 0.2.0
 */
final class StockTables {

	/**
	 * Creates the inventory tables and the default location, and returns the location's id.
	 *
	 * @since 0.2.0
	 *
	 * @param Database $db The connection, outside any transaction.
	 * @return int The default location's id.
	 */
	public static function create( Database $db ): int {
		( new CreateStockTablesMigration() )->up( new SchemaOperations( $db, new DdlGenerator(), new SchemaVerifier( $db ) ) );

		return self::defaultLocation( $db );
	}

	/**
	 * Creates the default location, unless the store has one, and returns its id.
	 *
	 * @since 0.2.0
	 *
	 * @param Database $db The connection, with the inventory tables.
	 * @return int The default location's id.
	 */
	public static function defaultLocation( Database $db ): int {
		$db->transaction( static fn(): ?string => ( new SeedDefaultLocation() )->runBatch( null, $db ) );

		return (int) ( new MysqlLocationRepository( $db ) )->defaultId();
	}
}
