<?php
/**
 * LogsTable: the declaration of the `logs` table
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Logging;

use SEOCart\Platform\Database\Schema\Classification;
use SEOCart\Platform\Database\Schema\ColumnSpec;
use SEOCart\Platform\Database\Schema\IndexSpec;
use SEOCart\Platform\Database\Schema\MutationPattern;
use SEOCart\Platform\Database\Schema\TableDefinition;

defined( 'ABSPATH' ) || exit;

/**
 * Declares `logs`: the plugin's operational log, one row per line.
 *
 * Owns one fact: the shape of the log table. The migration that creates it and the data
 * registry that lists it call the same factory.
 *
 * Lines are only ever inserted, and deleted by age once the retention period has passed. The
 * message is a fixed sentence the plugin's code wrote; the values of a line are in its
 * context, redacted before they were written, and the context and the user id are the columns
 * that can still say something about a person. Every time is the database's
 * `UTC_TIMESTAMP(6)`, the clock the retention sweep compares with.
 *
 * @since 0.1.0
 */
final class LogsTable {

	/**
	 * The table's unprefixed name.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const NAME = 'logs';

	/**
	 * The retention policy id: lines are deleted once they are older than its period.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const RETENTION = 'logs';

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
			'Logging',
			'Records the plugin\'s operational log: one line per thing worth keeping, redacted before it is written, with the correlation id of the work that wrote it.',
			MutationPattern::AppendOnly,
			array(
				new ColumnSpec( 'id', 'bigint unsigned', Classification::Public, 'Surrogate key.', autoIncrement: true ),
				new ColumnSpec( 'level', 'varchar(10)', Classification::Public, 'debug, info, warning or error.', collation: 'ascii_bin' ),
				new ColumnSpec( 'channel', 'varchar(32)', Classification::Public, 'The part of the plugin that wrote the line: the machine code up to its first dot.', collation: 'ascii_bin' ),
				new ColumnSpec( 'machine_code', 'varchar(100)', Classification::Public, 'What happened, as a stable code such as events.listener_failed.', collation: 'ascii_bin' ),
				new ColumnSpec( 'message', 'varchar(500)', Classification::Public, 'A fixed sentence the plugin\'s code wrote for the line. Values never go into it; they are in context_json.' ),
				new ColumnSpec(
					'context_json',
					'mediumtext',
					Classification::Pii,
					'The line\'s values as JSON, after redaction: personal-data values replaced, secrets dropped, card numbers removed. What remains can still describe the person the work was for.',
					nullable: true,
					erasure: ColumnSpec::ERASE_DESTROY
				),
				new ColumnSpec( 'correlation_id', 'char(36)', Classification::Public, 'The correlation id of the request, job or event delivery that wrote the line.', collation: 'ascii_bin' ),
				new ColumnSpec( 'user_id', 'bigint unsigned', Classification::Pii, 'The WordPress user the work ran for; NULL when there was none.', nullable: true, erasure: ColumnSpec::ERASE_DESTROY ),
				new ColumnSpec( 'created_at', 'datetime(6)', Classification::Public, 'When the line was written, UTC, from the database clock.' ),
			),
			array( 'id' ),
			array(),
			array(
				IndexSpec::key( 'created_at', array( 'created_at' ), 'The retention sweep deletes the oldest lines first.' ),
				IndexSpec::key( 'level_created', array( 'level', 'created_at' ), 'An administrator lists the lines of one level, newest first.' ),
				IndexSpec::key( 'correlation_id', array( 'correlation_id' ), 'Support finds every line one request, job or event delivery wrote.' ),
				IndexSpec::key( 'channel_created', array( 'channel', 'created_at' ), 'An administrator lists the lines of one part of the plugin, newest first.' ),
			),
			self::RETENTION,
			array()
		);
	}
}
