<?php
/**
 * Tests Statement::shape(): a list of values has one shape whatever its length
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Support\QueryPlan;

use PHPUnit\Framework\TestCase;
use SEOCart\Tests\Support\QueryPlan\Statement;

/**
 * A statement sent with one value in a list and the same statement sent with nine are one query:
 * one id in the allow-list, and one read of the inventory's. An IN list is one such list, and so is
 * a FIELD list after its first argument, which pairs each row a read finds with the place of its
 * value in the IN list beside it.
 *
 * Runs with no WordPress and no database.
 *
 * @since 0.2.0
 */
final class StatementShapeTest extends TestCase {

	/**
	 * Tests that a FIELD list and an IN list of any length have the shape of one.
	 *
	 * Planted violation: in Statement::shape(), leave FIELD lists as they are; the two statements
	 * then differ, and so does the read of `MysqlProductRepository::SKU_HOLDERS` from the
	 * statement QueryPlanTest's run sends with nine SKUs.
	 *
	 * @since 0.2.0
	 */
	public function test_lists_of_any_length_have_one_shape(): void {
		$one  = new Statement( "SELECT id, FIELD( sku, 'A' ) AS wanted FROM wp_seocart_variants WHERE sku IN ( 'A' )", 'wp_' );
		$nine = new Statement( "SELECT id, FIELD( sku, 'A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I' ) AS wanted FROM wp_seocart_variants WHERE sku IN ( 'A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I' )", 'wp_' );

		$this->assertSame( $one->shape(), $nine->shape() );
		$this->assertSame( $one->id(), $nine->id() );
		$this->assertStringContainsString( 'FIELD( sku, ?+ )', $nine->shape() );
	}

	/**
	 * Tests that a FIELD whose arguments are not all values keeps its shape.
	 *
	 * @since 0.2.0
	 */
	public function test_a_field_of_columns_keeps_its_shape(): void {
		$statement = new Statement( 'SELECT id, FIELD( status, position ) AS place FROM wp_seocart_variants WHERE id = 5', 'wp_' );

		$this->assertStringContainsString( 'FIELD( status, position )', $statement->shape() );
	}
}
