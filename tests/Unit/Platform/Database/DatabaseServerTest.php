<?php
/**
 * Tests what a server's version string says about the server
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Platform\Database;

use PHPUnit\Framework\TestCase;
use SEOCart\Platform\Database\DatabaseServer;
use SEOCart\Platform\Database\ServerKind;

/**
 * The version strings of the servers the plugin meets, written down as each server's client handshake gives them.
 *
 * The development server and CI run MySQL 8.4 and MariaDB 11.8 only, so the older forms are
 * exercised here.
 *
 * Planted violation: in DatabaseServer::reportsIntegerDisplayWidths(), answer true for MariaDB
 * only. MySQL 8.0.16, which reports `bigint(20) unsigned`, would then get the statement without
 * widths, and dbDelta() would change every integer column of an existing table on every run.
 *
 * @since 0.2.0
 */
final class DatabaseServerTest extends TestCase {

	/**
	 * Lists version strings with the server, the version and whether it reports integer display widths.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, array{string, ServerKind, string, bool}> Version string, server, version, widths.
	 */
	public static function versionStrings(): array {
		return array(
			'MariaDB 11.8'                 => array( '11.8.9-MariaDB', ServerKind::MariaDB, '11.8.9', true ),
			'MariaDB 11.8, a distribution' => array( '11.8.3-MariaDB-ubu2404', ServerKind::MariaDB, '11.8.3', true ),
			'MariaDB 10.6, behind 5.5.5-'  => array( '5.5.5-10.6.18-MariaDB-1:10.6.18+maria~ubu2004', ServerKind::MariaDB, '10.6.18', true ),
			'MySQL 8.0.16'                 => array( '8.0.16', ServerKind::MySQL, '8.0.16', true ),
			'MySQL 8.0.19'                 => array( '8.0.19', ServerKind::MySQL, '8.0.19', false ),
			'MySQL 8.0, a distribution'    => array( '8.0.36-0ubuntu0.22.04.1', ServerKind::MySQL, '8.0.36', false ),
			'MySQL 8.4'                    => array( '8.4.5', ServerKind::MySQL, '8.4.5', false ),
		);
	}

	/**
	 * Tests that a version string names the server, its version, and whether it reports integer display widths.
	 *
	 * @since 0.2.0
	 *
	 * @dataProvider versionStrings
	 *
	 * @param string     $serverInfo The version string.
	 * @param ServerKind $kind       The server it names.
	 * @param string     $version    Its version.
	 * @param bool       $widths     Whether that server reports `bigint(20) unsigned` rather than `bigint unsigned`.
	 */
	public function test_a_version_string_names_the_server_and_whether_it_reports_integer_widths( string $serverInfo, ServerKind $kind, string $version, bool $widths ): void {
		$server = DatabaseServer::fromHandshake( $serverInfo );

		$this->assertSame( $kind, $server->kind() );
		$this->assertSame( $version, $server->version() );
		$this->assertSame( $widths, $server->reportsIntegerDisplayWidths() );
	}

	/**
	 * Tests the label a report line shows.
	 *
	 * @since 0.2.0
	 */
	public function test_the_label_names_the_server_and_its_version(): void {
		$this->assertSame( 'MariaDB 10.6.18', DatabaseServer::fromHandshake( '5.5.5-10.6.18-MariaDB-1:10.6.18+maria~ubu2004' )->label() );
		$this->assertSame( 'MySQL 8.4.5', DatabaseServer::fromHandshake( '8.4.5' )->label() );
	}
}
