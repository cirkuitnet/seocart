<?php
/**
 * EarlierStockTables: the four stock tables as a store kept them before it kept stock per location
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support\Inventory;

use SEOCart\Inventory\Infrastructure\InventoryTables;
use SEOCart\Platform\Database\Database;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL -- The fixture builds the earlier shape by hand, as the earlier release's own migration created it.

/**
 * Replaces the inventory tables with the four stock tables of the earlier shape: one item per variant, its policy on the item, and no location anywhere.
 *
 * Owns one fact: what an upgraded store's stock tables looked like. The statements are what the
 * earlier release's DdlGenerator wrote for its declarations, so the chain that keys stock by
 * location starts from exactly the tables a store has.
 *
 * @since 0.2.0
 */
final class EarlierStockTables {

	/**
	 * The four tables, each with `%s` for its full name and `%c` for the site's charset clause.
	 *
	 * @since 0.2.0
	 *
	 * @var array<string, string>
	 */
	private const TABLES = array(
		InventoryTables::ITEMS       => "CREATE TABLE `%s` (
			`variant_id` bigint unsigned NOT NULL,
			`on_hand` int NOT NULL DEFAULT '0',
			`allocated` int NOT NULL DEFAULT '0',
			`held` int NOT NULL DEFAULT '0',
			`track` tinyint(1) NOT NULL DEFAULT '1',
			`backorder_policy` varchar(8) COLLATE ascii_bin NOT NULL DEFAULT 'no',
			`low_stock_threshold` int NULL,
			`updated_at` datetime(6) NOT NULL,
			PRIMARY KEY  (`variant_id`),
			KEY `track_threshold` (`track`,`low_stock_threshold`)
		) ENGINE=InnoDB %c",
		InventoryTables::LEDGER      => 'CREATE TABLE `%s` (
			`id` bigint unsigned NOT NULL AUTO_INCREMENT,
			`variant_id` bigint unsigned NOT NULL,
			`delta` int NOT NULL,
			`on_hand_after` int NOT NULL,
			`reason` varchar(32) COLLATE ascii_bin NOT NULL,
			`order_id` bigint unsigned NULL,
			`allocation_id` bigint unsigned NULL,
			`actor_type` varchar(16) COLLATE ascii_bin NOT NULL,
			`actor_id` bigint unsigned NULL,
			`correlation_id` char(36) COLLATE ascii_bin NULL,
			`created_at` datetime(6) NOT NULL,
			PRIMARY KEY  (`id`),
			KEY `variant_created` (`variant_id`,`created_at`),
			KEY `order_id` (`order_id`),
			KEY `created_at` (`created_at`)
		) ENGINE=InnoDB %c',
		InventoryTables::HOLDS       => 'CREATE TABLE `%s` (
			`id` bigint unsigned NOT NULL AUTO_INCREMENT,
			`variant_id` bigint unsigned NOT NULL,
			`cart_id` bigint unsigned NULL,
			`order_id` bigint unsigned NULL,
			`hold_group` char(36) COLLATE ascii_bin NOT NULL,
			`quantity` int NOT NULL,
			`expires_at` datetime NOT NULL,
			`reclaim_token` char(36) COLLATE ascii_bin NULL,
			`created_at` datetime(6) NOT NULL,
			PRIMARY KEY  (`id`),
			KEY `variant_expires` (`variant_id`,`expires_at`),
			KEY `reclaim_token` (`reclaim_token`),
			KEY `hold_group` (`hold_group`),
			KEY `order_id` (`order_id`),
			KEY `expires_at` (`expires_at`)
		) ENGINE=InnoDB %c',
		InventoryTables::ALLOCATIONS => "CREATE TABLE `%s` (
			`id` bigint unsigned NOT NULL AUTO_INCREMENT,
			`variant_id` bigint unsigned NOT NULL,
			`order_id` bigint unsigned NOT NULL,
			`order_line_id` bigint unsigned NOT NULL,
			`quantity` int NOT NULL,
			`posted_quantity` int NOT NULL DEFAULT '0',
			`state` varchar(12) COLLATE ascii_bin NOT NULL DEFAULT 'open',
			`created_at` datetime(6) NOT NULL,
			`posted_at` datetime NULL,
			PRIMARY KEY  (`id`),
			UNIQUE KEY `order_line_allocation` (`order_line_id`,`variant_id`),
			KEY `variant_state` (`variant_id`,`state`),
			KEY `order_id` (`order_id`),
			KEY `state_created` (`state`,`created_at`)
		) ENGINE=InnoDB %c",
	);

	/**
	 * Drops every inventory table there is, and creates the four of the earlier shape, empty.
	 *
	 * @since 0.2.0
	 *
	 * @param Database $db The connection, outside any transaction.
	 */
	public static function create( Database $db ): void {
		global $wpdb;

		foreach ( InventoryTables::names() as $name ) {
			$wpdb->query( sprintf( 'DROP TABLE IF EXISTS `%s`', $db->table( $name ) ) );
		}

		foreach ( self::TABLES as $name => $statement ) {
			$wpdb->query( str_replace( array( '%s', '%c' ), array( $db->table( $name ), $db->charsetCollate() ), $statement ) );
		}
	}
}
