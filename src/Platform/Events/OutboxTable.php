<?php
/**
 * OutboxTable: the declaration of the `outbox` table
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Events;

use SEOCart\Platform\Database\Schema\Classification;
use SEOCart\Platform\Database\Schema\ColumnSpec;
use SEOCart\Platform\Database\Schema\IndexSpec;
use SEOCart\Platform\Database\Schema\MutationPattern;
use SEOCart\Platform\Database\Schema\TableDefinition;

defined( 'ABSPATH' ) || exit;

/**
 * Declares `outbox`: one row per stored event, from the transaction that recorded it until retention removes it.
 *
 * Owns one fact: the shape of the outbox table. The migration that creates it and the data
 * registry that lists it call the same factory. Every time column is `datetime(6)` and filled
 * from the database's `UTC_TIMESTAMP(6)`: the claim compares leases on the database clock, and
 * microseconds make every lease and every mark change the row. The claim token has the shape
 * of every token the platform mints, 64 hexadecimal digits.
 *
 * @since 0.1.0
 */
final class OutboxTable {

	/**
	 * The table's unprefixed name.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const NAME = 'outbox';

	/**
	 * The retention policy id: dispatched rows are kept 7 days, failed rows 90, pending rows until delivered.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const RETENTION = 'outbox';

	/**
	 * Declares the table.
	 *
	 * @since 0.1.0
	 *
	 * @return TableDefinition The declaration.
	 */
	public static function definition(): TableDefinition {
		return new TableDefinition(
			self::NAME,
			'Events',
			'Stores every event that must not be lost, inside the transaction that recorded it, until it has been delivered to every listener.',
			MutationPattern::Queue,
			array(
				new ColumnSpec( 'id', 'bigint unsigned', Classification::Public, 'Surrogate key, and the event id listeners deduplicate on.', autoIncrement: true ),
				new ColumnSpec( 'event_name', 'varchar(64)', Classification::Public, 'The event\'s name; the action it fires is seocart_ followed by it.', collation: 'ascii_bin' ),
				new ColumnSpec( 'aggregate_type', 'varchar(32)', Classification::Public, 'The kind of aggregate the event happened to, for example variant.', collation: 'ascii_bin' ),
				new ColumnSpec( 'aggregate_id', 'bigint unsigned', Classification::Public, 'The id of the aggregate the event happened to.' ),
				new ColumnSpec( 'payload_json', 'mediumtext', Classification::Public, 'The payload version, the instant the event happened and its fields: ids and summaries, never personal data.' ),
				new ColumnSpec( 'correlation_id', 'char(36)', Classification::Public, 'The correlation id of the request that recorded the event.', nullable: true, collation: 'ascii_bin' ),
				new ColumnSpec( 'state', 'varchar(12)', Classification::Public, 'pending, dispatched or failed.', defaultValue: 'pending', collation: 'ascii_bin' ),
				new ColumnSpec( 'available_at', 'datetime(6)', Classification::Public, 'When the row may next be claimed, UTC; for a failed row, when it was parked.' ),
				new ColumnSpec( 'claim_token', 'char(64)', Classification::Public, 'The token of the claim that leases the row; NULL when it is not leased.', nullable: true, collation: 'ascii_bin' ),
				new ColumnSpec( 'claimed_until', 'datetime(6)', Classification::Public, 'When the lease lapses, UTC, from the database clock.', nullable: true ),
				new ColumnSpec( 'attempts', 'smallint unsigned', Classification::Public, 'How many deliveries were started, one per delivery as it began.', defaultValue: '0' ),
				new ColumnSpec( 'dispatched_at', 'datetime(6)', Classification::Public, 'When every listener had been invoked, UTC.', nullable: true ),
				new ColumnSpec( 'last_error', 'varchar(191)', Classification::Public, 'The report code and first characters of the last failure; never a payload.', nullable: true ),
				new ColumnSpec( 'created_at', 'datetime(6)', Classification::Public, 'When the event was stored, UTC.' ),
			),
			array( 'id' ),
			array(),
			array(
				IndexSpec::key( 'state_available', array( 'state', 'available_at', 'id' ), 'The claim scan, and the sweep of failed rows past retention.' ),
				IndexSpec::key( 'claim_token', array( 'claim_token' ), 'Reads back the rows one claim leased.' ),
				IndexSpec::key( 'dispatched_at', array( 'dispatched_at' ), 'The sweep of dispatched rows past retention.' ),
				IndexSpec::key( 'aggregate', array( 'aggregate_type', 'aggregate_id' ), 'Support traces every event of one aggregate.' ),
			),
			self::RETENTION,
			array()
		);
	}
}
