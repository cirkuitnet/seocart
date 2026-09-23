<?php
/**
 * PlatformTables: the declarations of the two tables the Database module itself needs
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Database\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Declares `migrations` and `locks`.
 *
 * Owns one fact: the shape of the migrator's own tables. The bootstrap migration creates them
 * from these factories and the data registry registers the same factories, so each table is
 * declared once and read twice.
 *
 * @since 0.1.0
 */
final class PlatformTables {

	/**
	 * Declares `migrations`: one row per migration this site has run or is running.
	 *
	 * @since 0.1.0
	 *
	 * @return TableDefinition The declaration.
	 */
	public static function migrations(): TableDefinition {
		return new TableDefinition(
			'migrations',
			'Platform',
			'Records every migration this site has run, with its state, checksum, timing and the post-conditions it verified.',
			MutationPattern::MutableTransactional,
			array(
				new ColumnSpec( 'id', 'bigint unsigned', Classification::Public, 'Surrogate key.', autoIncrement: true ),
				new ColumnSpec( 'migration_id', 'varchar(191)', Classification::Public, 'The migration class id, sortable: YYYYMMDD_NNNN_name.', collation: 'ascii_bin' ),
				new ColumnSpec( 'kind', 'varchar(10)', Classification::Public, 'schema or data.', collation: 'ascii_bin' ),
				new ColumnSpec( 'can_operate_half_applied', 'tinyint(1)', Classification::Public, '1 when the store keeps trading while this migration is incomplete.', defaultValue: '0' ),
				new ColumnSpec( 'state', 'varchar(10)', Classification::Public, 'running, applied or failed.', collation: 'ascii_bin' ),
				new ColumnSpec( 'plugin_version', 'varchar(32)', Classification::Public, 'The plugin version that last ran the migration.', collation: 'ascii_bin' ),
				new ColumnSpec( 'checksum', 'char(64)', Classification::Public, 'SHA-256 of the migration class file when it last ran.', collation: 'ascii_bin' ),
				new ColumnSpec( 'batch_cursor', 'varchar(191)', Classification::Public, 'Where a data migration resumes; NULL before the first batch and once it is done.', nullable: true ),
				new ColumnSpec( 'applied_at', 'datetime', Classification::Public, 'When the migration was recorded as applied, UTC.', nullable: true ),
				new ColumnSpec( 'duration_ms', 'int unsigned', Classification::Public, 'How long the run that applied it took.', nullable: true ),
				new ColumnSpec( 'error_code', 'varchar(100)', Classification::Public, 'The machine code of the last failure.', nullable: true, collation: 'ascii_bin' ),
				new ColumnSpec( 'error_message', 'varchar(500)', Classification::Public, 'The first 500 characters of the last failure message.', nullable: true ),
				new ColumnSpec( 'postcondition_json', 'longtext', Classification::Public, 'The verified table summary, or the post-condition diff of a failure.', nullable: true ),
				new ColumnSpec( 'created_at', 'datetime', Classification::Public, 'When the row was first written, UTC.' ),
				new ColumnSpec( 'updated_at', 'datetime', Classification::Public, 'When the row last changed, UTC.' ),
			),
			array( 'id' ),
			array(
				IndexSpec::unique( 'migration_id', array( 'migration_id' ), 'Each migration is recorded at most once.' ),
			),
			array(
				IndexSpec::key( 'state', array( 'state' ), 'The schema gate and doctor find running and failed migrations.' ),
				IndexSpec::key( 'applied_at', array( 'applied_at' ), 'doctor lists migrations in the order they were applied.' ),
			),
			'permanent',
			array()
		);
	}

	/**
	 * Declares `locks`: one permanent row per lock name, holding the current lease when there is one.
	 *
	 * @since 0.1.0
	 *
	 * @return TableDefinition The declaration.
	 */
	public static function locks(): TableDefinition {
		return new TableDefinition(
			'locks',
			'Platform',
			'Lease-based advisory locks for hosts where GET_LOCK cannot be trusted.',
			MutationPattern::MutableTransactional,
			array(
				new ColumnSpec( 'name', 'varchar(191)', Classification::Public, 'The lock name, for example schema.', collation: 'ascii_bin' ),
				new ColumnSpec( 'owner_token', 'char(64)', Classification::Public, 'The current holder\'s token; NULL when the lock is free.', nullable: true, collation: 'ascii_bin' ),
				new ColumnSpec( 'acquired_at', 'datetime(6)', Classification::Public, 'When the current or last lease was taken, UTC, from the database clock.', nullable: true ),
				new ColumnSpec( 'expires_at', 'datetime(6)', Classification::Public, 'When the lease lapses unless renewed, UTC, from the database clock. Microseconds, so that every renewal changes the row.', nullable: true ),
				new ColumnSpec( 'holder', 'varchar(64)', Classification::Public, 'Which runner holds it: PHP SAPI and process id, never a host name.', nullable: true, collation: 'ascii_bin' ),
				new ColumnSpec( 'created_at', 'datetime', Classification::Public, 'When the lock name was first used, UTC.' ),
			),
			array( 'name' ),
			array(),
			array(),
			'permanent',
			array()
		);
	}
}
