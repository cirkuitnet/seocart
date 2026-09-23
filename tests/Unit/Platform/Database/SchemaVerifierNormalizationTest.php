<?php
/**
 * Tests how the post-condition verifier normalizes what MySQL and MariaDB report
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Platform\Database;

use PHPUnit\Framework\TestCase;
use SEOCart\Platform\Database\Schema\SchemaVerifier;

/**
 * The server differences the verifier absorbs, shown with the values each server reports.
 *
 * The development server runs MySQL 8.4, so the MariaDB forms are exercised here, where they
 * can be written down.
 *
 * @since 0.1.0
 */
final class SchemaVerifierNormalizationTest extends TestCase {

	/**
	 * Lists reported types with their normalized form.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{string, string}> Reported, normalized.
	 */
	public static function types(): array {
		return array(
			'MariaDB bigint width'     => array( 'bigint(20) unsigned', 'bigint unsigned' ),
			'MySQL 8 bigint'           => array( 'bigint unsigned', 'bigint unsigned' ),
			'uppercase int with width' => array( 'INT(11)', 'int' ),
			'boolean'                  => array( 'tinyint(1)', 'tinyint' ),
			'varchar keeps its length' => array( 'varchar(191)', 'varchar(191)' ),
			'decimal keeps precision'  => array( 'decimal(24,12)', 'decimal(24,12)' ),
			'datetime keeps fraction'  => array( 'datetime(6)', 'datetime(6)' ),
		);
	}

	/**
	 * Tests that display widths and letter case do not count as differences.
	 *
	 * @since 0.1.0
	 *
	 * @dataProvider types
	 *
	 * @param string $reported   The type as a server reports it.
	 * @param string $normalized The expected normal form.
	 */
	public function test_types_are_normalized( string $reported, string $normalized ): void {
		$this->assertSame( $normalized, SchemaVerifier::normalizeType( $reported ) );
	}

	/**
	 * Lists reported defaults with their normalized form.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{string|null, string|null}> Reported, normalized.
	 */
	public static function defaults(): array {
		return array(
			'no default'            => array( null, null ),
			'MariaDB NULL default'  => array( 'NULL', null ),
			'MariaDB quoted string' => array( "'abc'", 'abc' ),
			'MariaDB quoted empty'  => array( "''", '' ),
			'MariaDB escaped quote' => array( "'it''s'", "it's" ),
			'MySQL plain string'    => array( 'abc', 'abc' ),
			'MySQL empty string'    => array( '', '' ),
			'number, either server' => array( '0', '0' ),
		);
	}

	/**
	 * Tests that MariaDB's quoting of defaults does not count as a difference.
	 *
	 * @since 0.1.0
	 *
	 * @dataProvider defaults
	 *
	 * @param string|null $reported   The default as a server reports it.
	 * @param string|null $normalized The expected normal form.
	 */
	public function test_defaults_are_normalized( ?string $reported, ?string $normalized ): void {
		$this->assertSame( $normalized, SchemaVerifier::normalizeDefault( $reported ) );
	}
}
