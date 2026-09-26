<?php
/**
 * Tests the order module's statements, read from their constants: what they name and what they change
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Order;

use PHPUnit\Framework\TestCase;
use SEOCart\Order\Domain\OrderRepository;
use SEOCart\Order\Infrastructure\MysqlOrderRepository;
use SEOCart\Order\Infrastructure\OrderStatements;
use SEOCart\Order\Infrastructure\OrderTables;
use SEOCart\Platform\Database\Schema\MutationPattern;
use SEOCart\Platform\DataRegistry\OwnedData;
use SEOCart\Tests\Unit\Support\PhpSource;

/**
 * Every order statement is a constant, so these facts are read from the constants alone.
 *
 * - An order's snapshots reference nothing live: no statement names a table outside the order
 *   module, as a token or as a bare name.
 * - The append-only tables are appended to only: no statement updates or deletes a row of
 *   `order_events`, `order_totals`, `order_tax_components` or `conversion_contexts`.
 * - A storefront names an order by its uuid: the repository has no lookup by an integer or by
 *   the order number.
 * - An order's status changes only through TRANSITION, whose WHERE clause the registry compiles,
 *   and its payment status only through RECORD_PAYMENT: no other statement assigns either, in an
 *   UPDATE or in an ON DUPLICATE KEY UPDATE clause. An INSERT writes a new order's first values,
 *   which is not a change of its status.
 *
 * The statements are found, not listed: every SQL constant of every class under src/Order, so a
 * class added there is scanned without being named here.
 *
 * Planted violations, each shown red and removed:
 * - in MysqlOrderRepository::FIND_LINES, join `{variants}` for the SKU: the table scan fails;
 * - add `public const PURGE_EVENTS = 'DELETE FROM {order_events} WHERE order_id = %d';` to
 *   MysqlOrderRepository: the append-only scan fails;
 * - add `public function findById( int $orderId ): ?OrderView;` to OrderRepository: the lookup
 *   test fails;
 * - add `public const FORCE_STATUS = 'UPDATE {orders} SET status = %s WHERE id = %d';` to
 *   MysqlOrderRepository: the writer scan fails;
 * - add a class of its own under src/Order/Infrastructure whose constant is
 *   `INSERT INTO {orders} ( id ) VALUES ( %d ) ON DUPLICATE KEY UPDATE payment_status = %s`: the
 *   writer scan finds it, although nothing names the class.
 *
 * @since 0.1.0
 */
final class StatementsTest extends TestCase {

	/**
	 * The only clause by which a statement may touch an existing append-only row: finding its id, which changes nothing.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const FIND_EXISTING = 'ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID( id )';

	/**
	 * Tests that every statement names only order tables, and names no other module's table bare.
	 *
	 * @since 0.1.0
	 */
	public function test_no_statement_names_a_table_outside_the_order_module(): void {
		$ours   = OrderTables::names();
		$others = array();

		foreach ( OwnedData::registry()->tables() as $table ) {
			if ( 'Order' !== $table->module() ) {
				$others[] = $table->name();
			}
		}

		$this->assertContains( 'variants', $others, 'The scan must know the catalog\'s tables, or it proves nothing.' );

		$named   = array();
		$foreign = array();

		foreach ( self::statements() as $name => $statement ) {
			preg_match_all( '/\{([a-z_]+)\}/', $statement, $tokens );

			foreach ( $tokens[1] as $token ) {
				if ( 'list' !== $token ) {
					$named[ $token ] = true;
				}

				if ( 'list' !== $token && ! in_array( $token, $ours, true ) ) {
					$foreign[] = "{$name} names {{$token}}";
				}
			}

			foreach ( array_merge( $others, array( 'customers', 'customer_addresses', 'promotions', 'carts' ) ) as $table ) {
				if ( 1 === preg_match( '/(?<![\w{])' . preg_quote( $table, '/' ) . '(?![\w}])/', $statement ) ) {
					$foreign[] = "{$name} names {$table}";
				}
			}
		}

		$this->assertSame( array(), $foreign, 'An order\'s rows are its snapshots: no order statement may read or write another module\'s table.' );
		$this->assertSame( array(), array_values( array_diff( $ours, array_keys( $named ) ) ), 'Every order table is named by some statement, so the scan saw them all.' );
	}

	/**
	 * Tests that no statement updates or deletes a row of an append-only table, and that the declarations agree on which tables those are.
	 *
	 * @since 0.1.0
	 */
	public function test_no_statement_changes_an_append_only_row(): void {
		$appendOnly = array();

		foreach ( OrderTables::all() as $table ) {
			if ( MutationPattern::AppendOnly === $table->mutationPattern() ) {
				$appendOnly[] = $table->name();
			}
		}

		$this->assertSame( array( 'order_tax_components', 'order_totals', 'order_events', 'conversion_contexts' ), $appendOnly );

		$changes = array();

		foreach ( self::statements() as $name => $statement ) {
			foreach ( $appendOnly as $table ) {
				$token = '{' . $table . '}';

				if ( ! str_contains( $statement, $token ) ) {
					continue;
				}

				$updates = 1 === preg_match( '/^\s*(UPDATE|DELETE)\b/i', $statement );
				$upserts = str_contains( $statement, 'ON DUPLICATE KEY UPDATE' ) && ! str_ends_with( $statement, self::FIND_EXISTING );

				if ( $updates || $upserts ) {
					$changes[] = "{$name} changes {$table}";
				}
			}
		}

		$this->assertSame( array(), $changes, 'An append-only table is only appended to.' );
	}

	/**
	 * Tests that the repository finds an order by no integer and by no order number.
	 *
	 * @since 0.1.0
	 */
	public function test_the_repository_has_no_lookup_by_an_integer_or_a_number(): void {
		$lookups = array();

		foreach ( ( new \ReflectionClass( OrderRepository::class ) )->getMethods() as $method ) {
			$name = $method->getName();

			if ( 1 === preg_match( '/By(?:Id|Number|OrderNumber)$/i', $name ) ) {
				$lookups[] = $name;
			}

			if ( ! str_starts_with( $name, 'find' ) ) {
				continue;
			}

			foreach ( $method->getParameters() as $parameter ) {
				if ( 'int' === (string) $parameter->getType() ) {
					$lookups[] = "{$name}( int \${$parameter->getName()} )";
				}
			}
		}

		$this->assertSame( array(), $lookups, 'A storefront names an order by its uuid only.' );
	}

	/**
	 * Tests that only TRANSITION changes an order's status and only RECORD_PAYMENT its payment status.
	 *
	 * @since 0.1.0
	 */
	public function test_only_the_transition_and_the_projection_change_the_statuses(): void {
		$writers = array(
			'status'         => array(),
			'payment_status' => array(),
		);

		foreach ( self::statements() as $name => $statement ) {
			foreach ( self::assignments( $statement ) as $clause ) {
				foreach ( array_keys( $writers ) as $column ) {
					if ( 1 === preg_match( '/(?<![\w.`])' . $column . '\s*=/', $clause ) ) {
						$writers[ $column ][] = $name;
					}
				}
			}
		}

		$this->assertSame(
			array(
				'status'         => array( 'MysqlOrderRepository::TRANSITION' ),
				'payment_status' => array( 'MysqlOrderRepository::RECORD_PAYMENT' ),
			),
			$writers,
			'The status registry is enforced by one statement, and the payment projection by one.'
		);
	}

	/**
	 * Tests that the scan's readers find what they are shown, so a clean result means something.
	 *
	 * @since 0.1.0
	 */
	public function test_the_scan_finds_the_assignments_it_is_shown(): void {
		$this->assertSame( array( 'status = %s, updated_at = NOW()' ), self::assignments( 'UPDATE {orders} SET status = %s, updated_at = NOW() WHERE id = %d' ) );
		$this->assertSame( array( 'payment_status = %s' ), self::assignments( 'INSERT INTO {orders} ( id ) VALUES ( %d ) ON DUPLICATE KEY UPDATE payment_status = %s' ) );
		$this->assertSame( array(), self::assignments( 'INSERT INTO {orders} SET status = %s' ) );
		$this->assertArrayHasKey( 'SequenceOrderNumberGenerator::ALLOCATE', self::statements(), 'Every class under src/Order that holds statements is found.' );
	}

	/**
	 * Tests that a multi-row insert is its one-row constant with the VALUES tuple repeated, and that an empty IN list matches nothing.
	 *
	 * @since 0.1.0
	 */
	public function test_a_multi_row_insert_repeats_the_tuple_and_an_empty_list_matches_nothing(): void {
		$this->assertSame(
			'INSERT INTO {order_line_options} ( order_line_id, axis_key_snapshot, axis_label_snapshot, value_key_snapshot, value_label_snapshot, locale_snapshot, position ) VALUES ( %d, %s, %s, %s, %s, %s, %d ), ( %d, %s, %s, %s, %s, %s, %d )',
			OrderStatements::forRows( MysqlOrderRepository::INSERT_LINE_OPTION, 2 )
		);

		list( $sql, $arguments ) = OrderStatements::expand( MysqlOrderRepository::TRANSITION, array( 'processing', 7, array(), 0, array( 'paid' ) ), static fn( string $name ): string => 'wp_seocart_' . $name );

		$this->assertSame( 'UPDATE %i SET status = %s, updated_at = UTC_TIMESTAMP(6) WHERE id = %d AND status IN (NULL) AND ( %d = 0 OR payment_status IN (%s) )', $sql );
		$this->assertSame( array( 'wp_seocart_orders', 'processing', 7, 0, 'paid' ), $arguments );

		$this->expectException( \LogicException::class );

		OrderStatements::expand( 'SELECT id FROM {variants} WHERE id = %d', array( 1 ), static fn( string $name ): string => $name );
	}

	/**
	 * Returns every statement the order module can send: every SQL constant of every class under src/Order.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, string> Each statement, by `Class::CONSTANT`.
	 */
	private static function statements(): array {
		$statements = array();

		foreach ( PhpSource::files( 'src/Order' ) as $source ) {
			foreach ( PhpSource::declarations( $source ) as $class ) {
				$reflection = new \ReflectionClass( $class );

				foreach ( $reflection->getReflectionConstants() as $constant ) {
					$value = $constant->getValue();

					if ( is_string( $value ) && 1 === preg_match( '/^\s*(SELECT|INSERT|UPDATE|DELETE)\b/i', $value ) ) {
						$statements[ $reflection->getShortName() . '::' . $constant->getName() ] = $value;
					}
				}
			}
		}

		self::assertGreaterThan( 20, count( $statements ), 'The scan found too few statements to prove anything.' );

		return $statements;
	}

	/**
	 * Returns the clauses of a statement that change an existing row: an UPDATE's SET clause, and an ON DUPLICATE KEY UPDATE clause.
	 *
	 * @since 0.1.0
	 *
	 * @param string $statement The statement.
	 * @return list<string> The clauses, as written.
	 */
	private static function assignments( string $statement ): array {
		$clauses = array();

		if ( 1 === preg_match( '/^\s*UPDATE\s.*?\sSET\s(.*?)(?:\sWHERE\s.*)?$/is', $statement, $set ) ) {
			$clauses[] = $set[1];
		}

		if ( 1 === preg_match( '/\sON DUPLICATE KEY UPDATE\s(.*)$/is', $statement, $duplicate ) ) {
			$clauses[] = $duplicate[1];
		}

		return $clauses;
	}
}
