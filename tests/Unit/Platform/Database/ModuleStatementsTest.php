<?php
/**
 * Tests the plugin's one expansion of table and list tokens in a module's statements
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Platform\Database;

use PHPUnit\Framework\TestCase;
use SEOCart\Inventory\Infrastructure\MysqlStockRepository;
use SEOCart\Order\Infrastructure\OrderStatements;
use SEOCart\Payment\Infrastructure\MysqlPaymentRepository;
use SEOCart\Platform\Database\ModuleStatements;

/**
 * A table token becomes `%i` with the table's name, an IN list one placeholder per item, and anything a statement may not say is refused.
 *
 * The three modules that write their statements with tokens expand them here, each over its own
 * tables, so the expansion is one rule: the last test holds each module's expand() to it.
 *
 * Planted violation, shown red and removed: in ModuleStatements::expandList(), leave an empty
 * list empty instead of NULL: `IN ()` is malformed.
 *
 * @since 0.1.0
 */
final class ModuleStatementsTest extends TestCase {

	/**
	 * The tables of the module under test.
	 *
	 * @since 0.1.0
	 *
	 * @var list<string>
	 */
	private const TABLES = array( 'widgets', 'widget_parts' );

	/**
	 * Tests that table tokens and values become placeholders and arguments, in order.
	 *
	 * @since 0.1.0
	 */
	public function test_tokens_and_values_become_placeholders_and_arguments(): void {
		list( $sql, $arguments ) = self::expand( 'SELECT p.id FROM {widgets} w JOIN {widget_parts} p ON p.widget_id = w.id WHERE w.id = %d AND p.name = %s', array( 7, 'bolt' ) );

		$this->assertSame( 'SELECT p.id FROM %i w JOIN %i p ON p.widget_id = w.id WHERE w.id = %d AND p.name = %s', $sql );
		$this->assertSame( array( 'wp_seocart_widgets', 'wp_seocart_widget_parts', 7, 'bolt' ), $arguments );
	}

	/**
	 * Tests that an IN list becomes one placeholder per item, of its type, and an empty one NULL, which matches no row.
	 *
	 * @since 0.1.0
	 */
	public function test_a_list_becomes_one_placeholder_per_item(): void {
		$this->assertSame(
			array( 'SELECT id FROM %i WHERE id IN (%d, %d) AND status IN (%s)', array( 'wp_seocart_widgets', 3, 4, 'open' ) ),
			self::expand( 'SELECT id FROM {widgets} WHERE id IN ({list}) AND status IN ({list})', array( array( 3, 4 ), array( 'open' ) ) )
		);
		$this->assertSame(
			array( 'SELECT id FROM %i WHERE id IN (NULL)', array( 'wp_seocart_widgets' ) ),
			self::expand( 'SELECT id FROM {widgets} WHERE id IN ({list})', array( array() ) )
		);
	}

	/**
	 * Tests what is refused: another module's table, a list item that is neither an int nor a string, and values that do not match the placeholders.
	 *
	 * @since 0.1.0
	 */
	public function test_what_a_statement_may_not_say_is_refused(): void {
		$refusals = array(
			'another module\'s table' => array( 'SELECT id FROM {orders} WHERE id = %d', array( 1 ), 'not a table of the widget module' ),
			'a float in a list'       => array( 'SELECT id FROM {widgets} WHERE id IN ({list})', array( array( 1.5 ) ), 'ints or strings' ),
			'a value too many'        => array( 'SELECT id FROM {widgets} WHERE id = %d', array( 1, 2 ), 'fewer placeholders than values' ),
			'a value too few'         => array( 'SELECT id FROM {widgets} WHERE id = %d AND name = %s', array( 1 ), 'more placeholders than values' ),
		);

		foreach ( $refusals as $case => list( $statement, $values, $message ) ) {
			try {
				self::expand( $statement, $values );
				$this->fail( "Accepted {$case}." );
			} catch ( \LogicException $refused ) {
				$this->assertStringContainsString( $message, $refused->getMessage(), $case );
			}
		}
	}

	/**
	 * Tests that a derived table of values repeats its one row with UNION ALL, once per row, and keeps the rest of the statement whole.
	 *
	 * @since 0.1.0
	 */
	public function test_a_derived_row_repeats_once_per_row(): void {
		$statement = 'UPDATE {widgets} widget JOIN ( SELECT %d AS id, %d AS size ) AS asked ON asked.id = widget.id SET widget.size = asked.size WHERE widget.kind = %s';

		$this->assertSame( $statement, ModuleStatements::forDerivedRows( $statement, 1 ), 'One row is the constant as written.' );
		$this->assertSame(
			'UPDATE {widgets} widget JOIN ( SELECT %d AS id, %d AS size UNION ALL SELECT %d AS id, %d AS size UNION ALL SELECT %d AS id, %d AS size ) AS asked '
				. 'ON asked.id = widget.id SET widget.size = asked.size WHERE widget.kind = %s',
			ModuleStatements::forDerivedRows( $statement, 3 )
		);
		$this->assertSame(
			'INSERT INTO {widget_parts} ( widget_id, name ) SELECT row.widget_id, row.name FROM ( SELECT %d AS widget_id, %s AS name UNION ALL SELECT %d AS widget_id, %s AS name ) AS row '
				. 'WHERE row.name IN ( SELECT name FROM {widget_parts} )',
			ModuleStatements::forDerivedRows( 'INSERT INTO {widget_parts} ( widget_id, name ) SELECT row.widget_id, row.name FROM ( SELECT %d AS widget_id, %s AS name ) AS row WHERE row.name IN ( SELECT name FROM {widget_parts} )', 2 ),
			'Only the first derived table is the table of values; a later subquery is left alone.'
		);

		foreach ( array(
			'no derived table' => array( 'SELECT id FROM {widgets} WHERE id = %d', 2 ),
			'no row'           => array( $statement, 0 ),
		) as $case => list( $refused, $rows ) ) {
			try {
				ModuleStatements::forDerivedRows( $refused, $rows );
				$this->fail( "Repeated a statement with {$case}." );
			} catch ( \LogicException $refusal ) {
				$this->assertStringContainsString( 'at least once', $refusal->getMessage(), $case );
			}
		}
	}

	/**
	 * Tests that each module's expand() is this one, over its own tables.
	 *
	 * @since 0.1.0
	 */
	public function test_each_module_expands_through_it(): void {
		$name = static fn( string $table ): string => 'wp_seocart_' . $table;

		$this->assertSame( array( 'SELECT 1 FROM %i WHERE id IN (%d)', array( 'wp_seocart_orders', 1 ) ), OrderStatements::expand( 'SELECT 1 FROM {orders} WHERE id IN ({list})', array( array( 1 ) ), $name ) );
		$this->assertSame( array( 'SELECT 1 FROM %i WHERE variant_id IN (%d)', array( 'wp_seocart_stock_items', 1 ) ), MysqlStockRepository::expand( 'SELECT 1 FROM {stock_items} WHERE variant_id IN ({list})', array( array( 1 ) ), $name ) );
		$this->assertSame( array( 'SELECT 1 FROM %i WHERE id IN (%d)', array( 'wp_seocart_payment_intents', 1 ) ), MysqlPaymentRepository::expand( 'SELECT 1 FROM {payment_intents} WHERE id IN ({list})', array( array( 1 ) ), $name ) );

		foreach ( array(
			'order'     => array( OrderStatements::class, 'expand' ),
			'inventory' => array( MysqlStockRepository::class, 'expand' ),
			'payment'   => array( MysqlPaymentRepository::class, 'expand' ),
		) as $module => $expand ) {
			try {
				$expand( 'SELECT 1 FROM {widgets}', array(), $name );
				$this->fail( "The {$module} module expanded another module's table." );
			} catch ( \LogicException $refused ) {
				$this->assertStringContainsString( "not a table of the {$module} module", $refused->getMessage() );
			}
		}
	}

	/**
	 * Expands a statement over the widget module's tables.
	 *
	 * @since 0.1.0
	 *
	 * @param string $statement The statement.
	 * @param array  $values    Its values.
	 * @return array{0: string, 1: list<mixed>} The statement and its arguments.
	 *
	 * @phpstan-param list<mixed> $values
	 */
	private static function expand( string $statement, array $values ): array {
		return ModuleStatements::expand( $statement, $values, self::TABLES, static fn( string $table ): string => 'wp_seocart_' . $table, 'widget' );
	}
}
