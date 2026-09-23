<?php
/**
 * LogConnection: the logger's own database connection, for lines written while a transaction is open
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Logging;

use SEOCart\Platform\Database\Database;

defined( 'ABSPATH' ) || exit;

/**
 * A second wpdb connection, opened with the site's own credentials, that can fail without harm.
 *
 * Owns one fact: how the logger reaches the database outside the caller's transaction. A line
 * written on the connection that holds an open transaction becomes part of it, and a rollback
 * would take the line with it; exactly the lines that explain a failure would be lost. So a
 * line logged inside a transaction is written here, on a connection of its own, where it
 * commits at once and survives the rollback, and even the death of the process.
 *
 * It is a wpdb in every respect but one: it never stops the request. wpdb gives up on a failed
 * connection by printing an error page and dying, which is right for WordPress's own
 * connection and wrong for a log line, so this class connects and reconnects without that, and
 * its errors are never printed. The logger opens it only when a line is written inside a
 * transaction, at most once per process.
 *
 * Its limits:
 *
 * - It is one more database connection, held for the rest of the process, by every process
 *   that logs inside a transaction. On a host near its connection limit it may be refused;
 *   those lines then go to PHP's error log, and the logger does not try again in that process.
 * - It connects with the `DB_USER`, `DB_PASSWORD`, `DB_NAME` and `DB_HOST` constants, as
 *   WordPress's own connection does by default.
 * - A database drop-in that routes connections itself, such as HyperDB or LudicrousDB, is not
 *   consulted: this connection goes wherever the constants point, which on such a site may not
 *   be the server that takes writes. Lines it cannot write there are lost the same way.
 *
 * @since 0.1.0
 */
final class LogConnection extends \wpdb {

	/**
	 * Opens the connection and wraps it, or returns null when it cannot be opened.
	 *
	 * The connection's `prefix` stays empty, so the Database over it cannot name a table and its
	 * character-set filter recognizes no plugin table. Neither is needed: the logger names its
	 * table in full from the site's connection, and wpdb learns a plugin table's character set
	 * from the site connection's filter, which is registered once that connection has run a
	 * statement, as it has whenever a transaction is open, the only time this one is used.
	 *
	 * @since 0.1.0
	 *
	 * @param string $prefix The table prefix of the current site. It is recorded as the
	 *                       connection's base prefix only; nothing reads it.
	 * @return Database|null A Database over the connection, which reports nothing; null when the
	 *                       connection could not be made.
	 */
	public static function database( string $prefix ): ?Database {
		if ( ! defined( 'DB_USER' ) || ! defined( 'DB_PASSWORD' ) || ! defined( 'DB_NAME' ) || ! defined( 'DB_HOST' ) ) {
			return null;
		}

		$connection = new self( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );

		if ( ! $connection->ready ) {
			return null;
		}

		$connection->hide_errors();
		$connection->suppress_errors( true );
		// Sets base_prefix only; with false, wpdb leaves prefix and the table names empty.
		$connection->set_prefix( $prefix, false );

		// It never opens a transaction, and only transactions report.
		return new Database( $connection, false, static function (): void {} );
	}

	/**
	 * Connects without giving up the request when the connection fails.
	 *
	 * @since 0.1.0
	 *
	 * @param bool $allow_bail Ignored: this connection never bails.
	 * @return bool True when connected.
	 */
	public function db_connect( $allow_bail = true ) {
		unset( $allow_bail );

		return parent::db_connect( false );
	}

	/**
	 * Reconnects without giving up the request when the connection cannot be restored.
	 *
	 * @since 0.1.0
	 *
	 * @param bool $allow_bail Ignored: this connection never bails.
	 * @return bool True when connected; false otherwise.
	 */
	public function check_connection( $allow_bail = true ) {
		unset( $allow_bail );

		return parent::check_connection( false );
	}

	/**
	 * Never ends the request: the failure is left to the logger, which falls back to the error log.
	 *
	 * @since 0.1.0
	 *
	 * @param string $message    The error message.
	 * @param string $error_code Optional. A code. Default '500'.
	 * @return false Always.
	 */
	public function bail( $message, $error_code = '500' ) {
		unset( $message, $error_code );

		return false;
	}
}
