<?php
/**
 * LockProbe: decides whether GET_LOCK can be trusted on this host
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Database;

use SEOCart\Platform\Database\Exception\QueryFailed;

defined( 'ABSPATH' ) || exit;

/**
 * Chooses the LockMode by trying GET_LOCK once and checking every answer.
 *
 * Owns one fact: when GET_LOCK is trustworthy. It is not with a persistent connection (the
 * `p:` host prefix), which keeps a lock alive after the request that took it has died; nor
 * behind a connection pooler, where the check that the lock belongs to this connection lands
 * on another backend; nor on a host that restricts or breaks it. The probe takes a lock
 * without waiting, asks the server which connection holds it, and releases it. Anything but
 * the three expected answers, or any failed statement, chooses the `locks` table.
 *
 * decide() does the reasoning without a database, so every branch is unit-tested. The kernel
 * runs the probe at activation and caches the answer in the boot option.
 *
 * @since 0.1.0
 */
final class LockProbe {

	/**
	 * The name of the lock the probe takes and releases.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const PROBE_LOCK = 'lock_probe';

	/**
	 * Runs the probe on the live connection. Sends at most three statements.
	 *
	 * @since 0.1.0
	 *
	 * @param Database $db The connection.
	 * @return LockMode GetLock when every check passed, otherwise Table.
	 */
	public static function run( Database $db ): LockMode {
		$name = LockService::serverLockName( $db->databaseName(), $db->prefix(), self::PROBE_LOCK );

		return self::decide(
			defined( 'DB_HOST' ) ? (string) DB_HOST : '',
			$db->threadId(),
			static function ( string $sql ) use ( $db, $name ): mixed {
				return $db->fetchValue( $sql, $name );
			}
		);
	}

	/**
	 * Chooses the mode from the host setting and the server's answers.
	 *
	 * @since 0.1.0
	 *
	 * @param string                  $dbHost     The DB_HOST setting.
	 * @param int                     $threadId   The thread id of the connection, from its handshake.
	 * @param callable(string): mixed $fetchValue Sends a query whose one `%s` placeholder is the
	 *                                            probe lock's name and returns its first value; may
	 *                                            throw QueryFailed.
	 * @return LockMode GetLock when every check passed, otherwise Table.
	 */
	public static function decide( string $dbHost, int $threadId, callable $fetchValue ): LockMode {
		if ( str_starts_with( $dbHost, 'p:' ) ) {
			return LockMode::Table;
		}

		try {
			if ( '1' !== self::answer( $fetchValue( 'SELECT GET_LOCK( %s, 0 )' ) ) ) {
				return LockMode::Table;
			}

			// Always released below, even when the holder check fails, so the probe leaves nothing behind.
			$holder   = self::answer( $fetchValue( 'SELECT IS_USED_LOCK( %s )' ) );
			$released = self::answer( $fetchValue( 'SELECT RELEASE_LOCK( %s )' ) );
		} catch ( QueryFailed $failed ) {
			return LockMode::Table;
		}

		return ( (string) $threadId === $holder && '1' === $released ) ? LockMode::GetLock : LockMode::Table;
	}

	/**
	 * Reads a scalar answer the way it came from the server.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $value The value.
	 * @return string|null The value as a string, or null for SQL NULL.
	 */
	private static function answer( mixed $value ): ?string {
		return null === $value ? null : (string) $value;
	}
}
