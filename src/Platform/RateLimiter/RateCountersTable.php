<?php
/**
 * RateCountersTable: declares `rate_counters`, the rate limiter's table
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\RateLimiter;

use SEOCart\Platform\Database\Schema\Classification;
use SEOCart\Platform\Database\Schema\ColumnSpec;
use SEOCart\Platform\Database\Schema\IndexSpec;
use SEOCart\Platform\Database\Schema\MutationPattern;
use SEOCart\Platform\Database\Schema\TableDefinition;

defined( 'ABSPATH' ) || exit;

/**
 * Declares `rate_counters`: one row per bucket, client identity and window.
 *
 * Owns one fact: the shape of the rate limiter's table, which TableRateLimiter writes when the
 * site has no persistent object cache. The migration that creates it and the data registry that
 * lists it call the same factory. A row is inserted by a client's first request of a window and
 * increased by the next ones; it expires when its window ends, at most a day after it began, and
 * the sweep deletes it then. Every time is the database's `UTC_TIMESTAMP()`.
 *
 * The client identity is an HMAC keyed with a secret the table does not hold, so the key cannot be
 * tied to a person; it is still classified as personal data, because it is derived from one.
 *
 * @since 0.1.0
 */
final class RateCountersTable {

	/**
	 * The table's unprefixed name.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const NAME = 'rate_counters';

	/**
	 * The retention policy id: a row is deleted once its window has ended.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const RETENTION = 'rate_counters';

	/**
	 * Declares the table.
	 *
	 * @since 0.1.0
	 *
	 * @return TableDefinition The declaration.
	 */
	public static function definition(): TableDefinition {
		$unlinkable = 'An HMAC keyed with a secret the table does not hold: it cannot be matched to a person, and every row is deleted within a day.';

		return new TableDefinition(
			self::NAME,
			'RateLimiter',
			'Counts each client\'s requests to each rate-limited bucket in fixed windows, on a site without a persistent object cache.',
			MutationPattern::MutableTransactional,
			array(
				new ColumnSpec( 'scope', 'varchar(64)', Classification::Public, 'The bucket counted, such as cart.write.', collation: 'ascii_bin' ),
				new ColumnSpec(
					'key_hash',
					'char(64)',
					Classification::Pii,
					'The client identity: an HMAC over the client\'s address, cart token and customer id, in hexadecimal.',
					collation: 'ascii_bin',
					erasure: ColumnSpec::ERASE_RETAIN,
					retainedBecause: $unlinkable,
					notExportedBecause: $unlinkable
				),
				new ColumnSpec( 'window_start', 'datetime', Classification::Public, 'When the window began, UTC, from the database clock; windows are aligned to the Unix epoch.' ),
				new ColumnSpec( 'count', 'int unsigned', Classification::Public, 'The requests counted in the window.' ),
				new ColumnSpec( 'expires_at', 'datetime', Classification::Public, 'When the window ends and the row may be swept, UTC, from the database clock.' ),
			),
			array( 'scope', 'key_hash', 'window_start' ),
			array(),
			array(
				IndexSpec::key( 'expires_at', array( 'expires_at' ), 'The sweep deletes the rows whose window has ended, oldest first.' ),
			),
			self::RETENTION,
			array()
		);
	}
}
