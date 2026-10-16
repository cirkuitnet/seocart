<?php
/**
 * DatabaseServer: which server the connection reached, and its version, from the version string of the client handshake
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Database;

defined( 'ABSPATH' ) || exit;

/**
 * The connected server: MySQL or MariaDB, and its version.
 *
 * Owns one fact: what a server's version string, as the client handshake gives it, says about
 * the server. MariaDB names itself in the string, and before version 11 it puts `5.5.5-` in
 * front of its own version for the sake of old clients. Database::server() reads the string
 * once per connection; reading it here is pure, so every version string can be tested without
 * a server.
 *
 * @since 0.2.0
 */
final class DatabaseServer {

	/**
	 * The first MySQL version that reports an integer column without its display width: `bigint unsigned`, not `bigint(20) unsigned`.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const MYSQL_OMITS_INTEGER_WIDTHS = '8.0.19';

	/**
	 * Which server it is.
	 *
	 * @since 0.2.0
	 *
	 * @var ServerKind
	 */
	private ServerKind $kind;

	/**
	 * The version, digits and dots only.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private string $version;

	/**
	 * Creates the value. Use fromHandshake().
	 *
	 * @since 0.2.0
	 *
	 * @param ServerKind $kind    Which server it is.
	 * @param string     $version The version, digits and dots only.
	 */
	private function __construct( ServerKind $kind, string $version ) {
		$this->kind    = $kind;
		$this->version = $version;
	}

	/**
	 * Reads a server's version string.
	 *
	 * @since 0.2.0
	 *
	 * @param string $serverInfo The string the client handshake gave, as wpdb::db_server_info() returns it:
	 *                           `8.4.5`, `11.8.9-MariaDB`, `5.5.5-10.6.18-MariaDB-1:10.6.18+maria~ubu2004`.
	 * @return self The server.
	 */
	public static function fromHandshake( string $serverInfo ): self {
		$kind = str_contains( $serverInfo, 'MariaDB' ) ? ServerKind::MariaDB : ServerKind::MySQL;

		if ( ServerKind::MariaDB === $kind && str_starts_with( $serverInfo, '5.5.5-' ) ) {
			$serverInfo = substr( $serverInfo, strlen( '5.5.5-' ) );
		}

		return new self( $kind, (string) preg_replace( '/[^0-9.].*$/s', '', $serverInfo ) );
	}

	/**
	 * Returns which server it is.
	 *
	 * @since 0.2.0
	 *
	 * @return ServerKind MySQL or MariaDB.
	 */
	public function kind(): ServerKind {
		return $this->kind;
	}

	/**
	 * Returns the server's version.
	 *
	 * @since 0.2.0
	 *
	 * @return string Digits and dots only, such as `8.4.5` or `11.8.9`; empty when the string named none.
	 */
	public function version(): string {
		return $this->version;
	}

	/**
	 * Names the server and its version, for a report line.
	 *
	 * @since 0.2.0
	 *
	 * @return string For example `MySQL 8.4.5` or `MariaDB 11.8.9`.
	 */
	public function label(): string {
		return ( ServerKind::MariaDB === $this->kind ? 'MariaDB' : 'MySQL' ) . ' ' . $this->version;
	}

	/**
	 * Tells whether the server reports an integer column with its display width, as `bigint(20) unsigned`.
	 *
	 * MariaDB always does. MySQL did before 8.0.19; since then it reports `bigint unsigned`, and
	 * only `tinyint(1)` keeps its width. dbDelta() compares a column's reported type with the one
	 * a statement names, as text, so the statement for an existing table must name each integer
	 * type the way the server reports it, or dbDelta() changes every integer column on every run.
	 *
	 * @since 0.2.0
	 *
	 * @return bool True when it reports display widths.
	 */
	public function reportsIntegerDisplayWidths(): bool {
		return ServerKind::MariaDB === $this->kind || version_compare( $this->version, self::MYSQL_OMITS_INTEGER_WIDTHS, '<' );
	}
}
