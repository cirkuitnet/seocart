<?php
/**
 * TableRateLimiter: counts requests in the `rate_counters` table, one statement per hit
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\RateLimiter;

use SEOCart\Platform\Database\Database;
use SEOCart\Platform\Database\Exception\DatabaseException;

defined( 'ABSPATH' ) || exit;

/**
 * The rate limiter of a site without a persistent object cache.
 *
 * Owns one fact: how a hit is counted in the database. One statement counts it and returns the
 * count: an `INSERT … ON DUPLICATE KEY UPDATE` whose `LAST_INSERT_ID( expr )` hands the new count
 * back to the client, which wpdb reads as the insert id, so no second statement reads it. A new
 * row starts at 1, an existing one gets one more; MySQL holds the row's lock for the increment,
 * so two concurrent hits never count once.
 *
 * The window is computed by the database clock alone, in the statement: its start is the current
 * UTC time less the seconds since the last multiple of the window, and a row expires when its
 * window ends. SweepRateCounters deletes expired rows.
 *
 * @since 0.1.0
 */
final class TableRateLimiter implements RateLimiter {

	/**
	 * The statement that counts one hit and sets the insert id to the new count.
	 *
	 * Placeholders: the table, the bucket, the identity's key, then the window three times.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const HIT = 'INSERT INTO %i (scope, key_hash, window_start, count, expires_at) VALUES (%s, %s, UTC_TIMESTAMP() - INTERVAL (UNIX_TIMESTAMP() MOD %d) SECOND, LAST_INSERT_ID(1), UTC_TIMESTAMP() - INTERVAL (UNIX_TIMESTAMP() MOD %d) SECOND + INTERVAL %d SECOND) ON DUPLICATE KEY UPDATE count = LAST_INSERT_ID(count + 1)';

	/**
	 * The query that reads the count of the current window.
	 *
	 * Placeholders: the table, the bucket, the identity's key, the window.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const PEEK = 'SELECT count FROM %i WHERE scope = %s AND key_hash = %s AND window_start = UTC_TIMESTAMP() - INTERVAL (UNIX_TIMESTAMP() MOD %d) SECOND';

	/**
	 * Counts one request in the window's row while it holds fewer than a limit: inserted at 1, or counted in only below the limit, and left as it is at the limit.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const COUNT_BELOW = 'INSERT INTO %i (scope, key_hash, window_start, count, expires_at) VALUES (%s, %s, UTC_TIMESTAMP() - INTERVAL (UNIX_TIMESTAMP() MOD %d) SECOND, 1, UTC_TIMESTAMP() - INTERVAL (UNIX_TIMESTAMP() MOD %d) SECOND + INTERVAL %d SECOND) ON DUPLICATE KEY UPDATE count = IF( count < %d, count + 1, count )';

	/**
	 * The connection.
	 *
	 * @since 0.1.0
	 *
	 * @var Database
	 */
	private Database $db;

	/**
	 * Creates the adapter. Sends nothing.
	 *
	 * @since 0.1.0
	 *
	 * @param Database $db The connection.
	 */
	public function __construct( Database $db ) {
		$this->db = $db;
	}

	/**
	 * Counts one request, in one statement.
	 *
	 * @since 0.1.0
	 *
	 * @throws DatabaseException|\InvalidArgumentException When the statement fails, or the bucket or
	 *                                                     the window is refused.
	 *
	 * @param string         $bucket   What is counted.
	 * @param ClientIdentity $identity Who sent the request.
	 * @param int            $window   The length of a window, in seconds.
	 * @return int The requests counted in the current window, this one included.
	 */
	public function hit( string $bucket, ClientIdentity $identity, int $window ): int {
		RateLimit::checkCounter( $bucket, $window );

		$this->db->execute( self::HIT, $this->db->table( RateCountersTable::NAME ), $bucket, $identity->key(), $window, $window, $window );

		return $this->db->lastInsertId();
	}

	/**
	 * Returns the count of the current window without counting.
	 *
	 * @since 0.1.0
	 *
	 * @throws DatabaseException|\InvalidArgumentException When the query fails, or the bucket or the
	 *                                                     window is refused.
	 *
	 * @param string         $bucket   What is counted.
	 * @param ClientIdentity $identity Whose requests.
	 * @param int            $window   The length of a window, in seconds.
	 * @return int The requests counted in the current window, 0 when there is no row.
	 */
	public function peek( string $bucket, ClientIdentity $identity, int $window ): int {
		RateLimit::checkCounter( $bucket, $window );

		return (int) $this->db->fetchValue( self::PEEK, $this->db->table( RateCountersTable::NAME ), $bucket, $identity->key(), $window );
	}

	/**
	 * Counts one request only while its window has counted fewer than a limit, in one statement that changes no row at the limit.
	 *
	 * The insert's row lock serialises requests racing at the limit, and the update counts only
	 * below it. The server reports the row it inserted as one row affected, one it counted in as two,
	 * and one left unchanged at the limit as none.
	 *
	 * @since 0.2.0
	 *
	 * @throws DatabaseException|\InvalidArgumentException When the statement fails, or the bucket, the window or the limit
	 *                                                     is refused.
	 *
	 * @param string         $bucket   What is counted.
	 * @param ClientIdentity $identity Who sent the request.
	 * @param int            $window   The length of a window, in seconds.
	 * @param int            $limit    The most requests a window counts, at least 1.
	 * @return bool True when the request was counted; false when the window was at the limit.
	 */
	public function countBelow( string $bucket, ClientIdentity $identity, int $window, int $limit ): bool {
		RateLimit::checkCounter( $bucket, $window );

		if ( $limit < 1 ) {
			throw new \InvalidArgumentException( 'A limit counts at least one request.' );
		}

		return 0 < $this->db->execute( self::COUNT_BELOW, $this->db->table( RateCountersTable::NAME ), $bucket, $identity->key(), $window, $window, $window, $limit );
	}
}
