<?php
/**
 * Tests the declaration of one index: its kind, its uniqueness and its columns
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Platform\Database;

use PHPUnit\Framework\TestCase;
use SEOCart\Platform\Database\Schema\Classification;
use SEOCart\Platform\Database\Schema\ColumnSpec;
use SEOCart\Platform\Database\Schema\IndexKind;
use SEOCart\Platform\Database\Schema\IndexSpec;
use SEOCart\Platform\Database\Schema\MutationPattern;
use SEOCart\Platform\Database\Schema\TableDefinition;

/**
 * Each factory declares its own kind; a fulltext key is not unique, indexes whole columns and is declared among a table's plain indexes.
 *
 * Planted violation: in IndexSpec::isUnique(), answer true for a fulltext key too. A table that
 * declares one among its indexes is then refused, and the kind case turns red.
 *
 * @since 0.2.0
 */
final class IndexSpecTest extends TestCase {

	/**
	 * Tests that each factory declares its kind, and that only a unique key is unique.
	 *
	 * @since 0.2.0
	 */
	public function test_each_factory_declares_its_kind(): void {
		$unique   = IndexSpec::unique( 'uuid', array( 'uuid' ), 'One row per public id.' );
		$key      = IndexSpec::key( 'name_created', array( 'name(20)', 'created_at' ), 'Lists by name.' );
		$fulltext = IndexSpec::fulltext( 'search_document', array( 'search_document' ), 'Finds the documents holding words.' );

		$this->assertSame( array( IndexKind::Unique, IndexKind::Key, IndexKind::Fulltext ), array( $unique->kind(), $key->kind(), $fulltext->kind() ) );
		$this->assertSame( array( true, false, false ), array( $unique->isUnique(), $key->isUnique(), $fulltext->isUnique() ) );
		$this->assertSame( array( 'BTREE', 'BTREE', 'FULLTEXT' ), array( IndexKind::Unique->indexType(), IndexKind::Key->indexType(), IndexKind::Fulltext->indexType() ) );
		$this->assertSame(
			array(
				array(
					'name'   => 'search_document',
					'length' => null,
				),
			),
			$fulltext->columns()
		);
		$this->assertSame( 'Finds the documents holding words.', $fulltext->purpose() );
	}

	/**
	 * Tests that a fulltext key's column takes no prefix length: the key indexes the whole column.
	 *
	 * @since 0.2.0
	 */
	public function test_a_fulltext_column_with_a_prefix_length_is_refused(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'a fulltext key indexes the whole column' );

		IndexSpec::fulltext( 'search_document', array( 'search_document(20)' ), 'Finds the documents holding words.' );
	}

	/**
	 * Tests that a table declares a fulltext key among its plain indexes, and refuses it among its unique keys.
	 *
	 * @since 0.2.0
	 */
	public function test_a_table_declares_a_fulltext_key_among_its_indexes(): void {
		$fulltext = IndexSpec::fulltext( 'body', array( 'body' ), 'Finds the rows holding words.' );
		$table    = self::table( array(), array( $fulltext ) );

		$this->assertSame( array( $fulltext ), $table->indexes() );
		$this->assertSame( $fulltext, $table->index( 'body' ) );

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'every unique key must be declared with IndexSpec::unique()' );

		self::table( array( $fulltext ), array() );
	}

	/**
	 * Declares a table with a text column and the given keys.
	 *
	 * @since 0.2.0
	 *
	 * @param IndexSpec[] $uniqueKeys The unique keys.
	 * @param IndexSpec[] $indexes    The plain indexes.
	 * @return TableDefinition The declaration.
	 */
	private static function table( array $uniqueKeys, array $indexes ): TableDefinition {
		return new TableDefinition(
			'documents',
			'Tests',
			'A fixture.',
			MutationPattern::Config,
			array(
				new ColumnSpec( 'id', 'bigint unsigned', Classification::Public, 'Key.', autoIncrement: true ),
				new ColumnSpec( 'body', 'text', Classification::Public, 'Words.' ),
			),
			array( 'id' ),
			$uniqueKeys,
			$indexes,
			'permanent',
			array()
		);
	}
}
