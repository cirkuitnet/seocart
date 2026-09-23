<?php
/**
 * LocksCheck: no lock holds a lease that lapsed without being released
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Cli\Doctor;

use SEOCart\Platform\Database\Database;
use SEOCart\Platform\Database\Exception\QueryFailed;
use SEOCart\Platform\Database\MysqlErrno;

defined( 'ABSPATH' ) || exit;

/**
 * Finds stale leases in the `locks` table.
 *
 * Owns one fact: what doctor calls a stale lock. A table-mode lease that lapsed while its
 * holder's token is still set means the process holding it ended, or hung, without releasing
 * it. The next runner that asks for the lock takes it over, so nothing is stuck for good, but
 * whatever that process was doing stopped halfway. A lock held through GET_LOCK dies with its
 * connection and cannot go stale, so only the table is read, on the database clock.
 *
 * @since 0.1.0
 */
final class LocksCheck implements Check {

	/**
	 * The check's name.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const NAME = 'locks';

	/**
	 * A holder as the lock service writes it: the PHP SAPI, a colon and the process id.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const HOLDER_PATTERN = '/^[a-z0-9_-]{1,32}:\d{1,10}$/iD';

	/**
	 * The connection.
	 *
	 * @since 0.1.0
	 *
	 * @var Database
	 */
	private Database $db;

	/**
	 * Creates the check. Sends nothing.
	 *
	 * @since 0.1.0
	 *
	 * @param Database $db The connection.
	 */
	public function __construct( Database $db ) {
		$this->db = $db;
	}

	/**
	 * Returns the check's name.
	 *
	 * @since 0.1.0
	 *
	 * @return string `locks`.
	 */
	public function name(): string {
		return self::NAME;
	}

	/**
	 * Lists every lock whose lease lapsed while it is still held.
	 *
	 * @since 0.1.0
	 *
	 * @throws QueryFailed When the table cannot be read for a reason other than its absence.
	 *
	 * @return CheckResult Passed when no lease is stale.
	 */
	public function run(): CheckResult {
		try {
			$stale = $this->db->fetchAll(
				'SELECT name, holder, TIMESTAMPDIFF( SECOND, expires_at, UTC_TIMESTAMP(6) ) AS lapsed FROM %i WHERE owner_token IS NOT NULL AND expires_at < UTC_TIMESTAMP(6) ORDER BY name',
				$this->db->table( 'locks' )
			);
		} catch ( QueryFailed $failed ) {
			if ( MysqlErrno::NO_SUCH_TABLE === $failed->errno() ) {
				return CheckResult::fail( self::NAME, 'The locks table does not exist; the schema check says more.' );
			}

			throw $failed;
		}

		if ( array() === $stale ) {
			return CheckResult::pass( self::NAME, 'No lock holds a lease that lapsed.' );
		}

		$findings = array();

		// Names and holders are printed only in the shape the plugin writes them, so a planted value cannot reach the output.
		foreach ( $stale as $lock ) {
			$holder     = (string) $lock['holder'];
			$findings[] = sprintf(
				'Lock %1$s: its lease lapsed %2$d seconds ago and was never released (holder %3$s). The next runner to ask for it takes it over; check what that holder was doing.',
				CheckResult::identifier( (string) $lock['name'] ),
				(int) $lock['lapsed'],
				( 1 === preg_match( self::HOLDER_PATTERN, $holder ) ) ? $holder : 'not recorded'
			);
		}

		return CheckResult::fail( self::NAME, sprintf( '%d %s a stale lease.', count( $stale ), 1 === count( $stale ) ? 'lock holds' : 'locks hold' ), $findings );
	}
}
