<?php
/**
 * LogRetention: deletes log lines older than the retention period, one bounded batch at a time
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Logging;

use SEOCart\Platform\Database\Database;
use SEOCart\Platform\Database\Exception\DatabaseException;
use SEOCart\Platform\DataRegistry\RetentionCatalog;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The InvalidArgumentExceptions below name a period or a batch size for the developer who passed it; they are never rendered as HTML.

/**
 * The retention sweep of the `logs` table.
 *
 * Owns one fact: how old lines leave the log. One call deletes at most one batch of the oldest
 * lines past the period, in `created_at` order on its index, and says how many it deleted;
 * the caller calls again until a batch comes back short, so the sweep never holds a large
 * lock. The period is the retention catalog's default for `logs` unless one is given, and it
 * is compared on the database clock that wrote `created_at`.
 *
 * Run it outside any transaction: it is maintenance work for a scheduled job or a command.
 *
 * @since 0.1.0
 */
final class LogRetention {

	/**
	 * The connection.
	 *
	 * @since 0.1.0
	 *
	 * @var Database
	 */
	private Database $db;

	/**
	 * How old a line may get, in seconds.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private int $periodSeconds;

	/**
	 * Creates the sweep. Sends nothing.
	 *
	 * @since 0.1.0
	 *
	 * @throws \InvalidArgumentException When the period is not an ISO 8601 duration of at least one second.
	 *
	 * @param Database    $db     The connection.
	 * @param string|null $period Optional. How long lines are kept, as an ISO 8601 duration such as
	 *                            `P30D`. Default null, the retention catalog's default for `logs`.
	 */
	public function __construct( Database $db, ?string $period = null ) {
		$this->db            = $db;
		$this->periodSeconds = self::seconds( $period ?? self::cataloguedPeriod() );
	}

	/**
	 * Returns the retention catalog's period for log lines.
	 *
	 * @since 0.1.0
	 *
	 * @return string An ISO 8601 duration, such as `P30D`.
	 */
	public static function cataloguedPeriod(): string {
		return ( new RetentionCatalog() )->defaults( LogsTable::RETENTION )['all'];
	}

	/**
	 * Returns how many seconds a retention period lasts.
	 *
	 * @since 0.1.0
	 *
	 * @throws \InvalidArgumentException When the period is not an ISO 8601 duration of at least one second.
	 *
	 * @param string $period An ISO 8601 duration, such as `P30D`.
	 * @return int The seconds, counted from the Unix epoch.
	 */
	public static function seconds( string $period ): int {
		try {
			$seconds = ( new \DateTimeImmutable( '@0' ) )->add( new \DateInterval( $period ) )->getTimestamp();
		} catch ( \Exception $invalid ) {
			throw new \InvalidArgumentException( sprintf( 'The log retention period "%s" is not an ISO 8601 duration.', $period ), 0, $invalid );
		}

		if ( $seconds < 1 ) {
			throw new \InvalidArgumentException( sprintf( 'The log retention period "%s" must be at least one second.', $period ) );
		}

		return $seconds;
	}

	/**
	 * Deletes up to one batch of the oldest lines past the period.
	 *
	 * @since 0.1.0
	 *
	 * @throws DatabaseException|\InvalidArgumentException When the statement fails; or when the batch is below 1.
	 *
	 * @param int $limit The most lines to delete. At least 1.
	 * @return int How many were deleted: fewer than the limit once nothing past the period is left.
	 */
	public function sweep( int $limit ): int {
		if ( $limit < 1 ) {
			throw new \InvalidArgumentException( 'A log retention batch deletes at least one line.' );
		}

		return $this->db->execute(
			'DELETE FROM %i WHERE created_at < UTC_TIMESTAMP(6) - INTERVAL %d SECOND ORDER BY created_at LIMIT %d',
			$this->db->table( LogsTable::NAME ),
			$this->periodSeconds,
			$limit
		);
	}
}
