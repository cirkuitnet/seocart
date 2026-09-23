<?php
/**
 * TestTables: table declarations for migration fixtures
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support\Migrations;

use SEOCart\Platform\Database\Schema\Classification;
use SEOCart\Platform\Database\Schema\ColumnSpec;
use SEOCart\Platform\Database\Schema\IndexSpec;
use SEOCart\Platform\Database\Schema\MutationPattern;
use SEOCart\Platform\Database\Schema\TableDefinition;

/**
 * Declares the small tables the fixture migrations create.
 *
 * Owns one fact: the shape of a fixture table. Every name starts with `test_`, so
 * DatabaseTestCase drops every one of them after each test.
 *
 * @since 0.1.0
 */
final class TestTables {

	/**
	 * Declares `test_{suffix}`: an id, a value with a default, and an index on the value.
	 *
	 * @since 0.1.0
	 *
	 * @param string $suffix    The table's name after `test_`.
	 * @param bool   $withIndex Optional. Whether to declare the `value` index. Default true.
	 * @return TableDefinition The declaration.
	 */
	public static function simple( string $suffix, bool $withIndex = true ): TableDefinition {
		return new TableDefinition(
			'test_' . $suffix,
			'Tests',
			'A fixture table created by a fixture migration.',
			MutationPattern::Config,
			array(
				new ColumnSpec( 'id', 'bigint unsigned', Classification::Public, 'Surrogate key.', autoIncrement: true ),
				new ColumnSpec( 'value', 'varchar(100)', Classification::Public, 'A value.', defaultValue: '' ),
				new ColumnSpec( 'code', 'char(3)', Classification::Public, 'An identifier-shaped value.', collation: 'ascii_bin', defaultValue: 'abc' ),
			),
			array( 'id' ),
			array(),
			$withIndex ? array( IndexSpec::key( 'value', array( 'value' ), 'Fixture lookups by value.' ) ) : array(),
			'permanent',
			array()
		);
	}
}
