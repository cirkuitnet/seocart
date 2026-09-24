<?php
/**
 * Tests the parts of the query-plan run: statements, the recorder, the rule and the allow-list
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Performance;

use SEOCart\Platform\Database\Database;
use SEOCart\Tests\Support\DatabaseTestCase;
use SEOCart\Tests\Support\QueryPlan\AllowList;
use SEOCart\Tests\Support\QueryPlan\PlanRecorder;
use SEOCart\Tests\Support\QueryPlan\QueryPlan;
use SEOCart\Tests\Support\QueryPlan\Statement;

/**
 * The query-plan run's parts, on every run of the suite: the query-plan run itself (QueryPlanTest) needs the medium dataset and runs on its own.
 *
 * Planted violations, each shown red and removed:
 *
 * - Statement::shape() without the line that writes an IN list `( ?+ )`: a page of three ids and
 *   a page of one are two queries;
 * - Statement::key() without the lengths of the IN lists, or PlanRecorder::query() keyed by the
 *   query's id: a list of two and a list of three are one plan;
 * - Statement::referencesAt() reading one reference only: the second table of a comma join is
 *   not named, and its plan becomes a breach nobody can place;
 * - FORCE taken out of Statement::NOT_AN_ALIAS: `FROM t FORCE INDEX ( k )` names its table FORCE;
 * - Statement::tables() without its refusal of one name for two tables: a reused alias is judged
 *   against the wrong table;
 * - QueryPlan::judge() skipping a row for a table the statement does not name: an unjudged
 *   plan passes;
 * - QueryPlan::judge() without `'ALL' === $access['type'] ||`: a full scan that expects few rows
 *   passes;
 * - PlanRecorder::query() without `$statement->isPluginSelect() &&`: the recorder keeps the
 *   INSERT and the SELECT of a WordPress table too;
 * - in tests/query-plan-allow-list.json, empty the reason of an entry: the committed list is
 *   refused.
 *
 * @group performance
 *
 * @since 0.1.0
 */
final class QueryPlanRuleTest extends DatabaseTestCase {

	/**
	 * The table prefix of the statements under test.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const PREFIX = 'wptests_';

	/**
	 * Tests that a statement's shape leaves its values out, so that one query has one id whatever it was sent with.
	 *
	 * @since 0.1.0
	 */
	public function test_a_statement_shape_leaves_its_values_out(): void {
		$page = new Statement( "SELECT `variant_id` FROM `wptests_seocart_stock_items` WHERE variant_id IN (4001, 4002, 4003) AND reason = 'it\\'s' AND delta > -5 LIMIT 20", self::PREFIX );
		$one  = new Statement( "SELECT variant_id FROM wptests_seocart_stock_items  WHERE variant_id IN ( 7 ) AND reason = 'x' AND delta > 3 LIMIT 1", self::PREFIX );

		$this->assertSame( 'SELECT variant_id FROM {prefix}seocart_stock_items WHERE variant_id IN ( ?+ ) AND reason = ? AND delta > ? LIMIT ?', $page->shape() );
		$this->assertSame( $page->id(), $one->id() );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{12}$/', $page->id() );
		$this->assertSame( Statement::idOf( $page->shape() ), $page->id() );
		$this->assertSame( $page->id() . '[3]', $page->key(), 'A list of three is its own plan.' );
		$this->assertSame( $page->id() . '[1]', $one->key(), 'A list of one is another.' );
		$this->assertSame( $page->key(), ( new Statement( "SELECT variant_id FROM wptests_seocart_stock_items WHERE variant_id IN (7, 8, 9) AND reason = 'y' AND delta > 1 LIMIT 5", self::PREFIX ) )->key(), 'Other values of the same length are the same plan.' );

		$plain = new Statement( 'SELECT variant_id FROM wptests_seocart_stock_items WHERE variant_id = 1', self::PREFIX );

		$this->assertSame( $plain->id(), $plain->key(), 'A statement without an IN list is keyed by its id.' );
	}

	/**
	 * Tests that each table of a comma join is named, each under its alias or its name.
	 *
	 * @since 0.1.0
	 */
	public function test_every_table_of_a_comma_join_is_named(): void {
		$statement = new Statement( 'SELECT a.variant_id FROM wptests_seocart_stock_items a, wptests_seocart_stock_ledger b, wptests_seocart_stock_holds WHERE a.variant_id = b.variant_id', self::PREFIX );

		$this->assertSame(
			array(
				'a'                           => 'wptests_seocart_stock_items',
				'b'                           => 'wptests_seocart_stock_ledger',
				'wptests_seocart_stock_holds' => 'wptests_seocart_stock_holds',
			),
			$statement->tables()
		);
	}

	/**
	 * Tests that index hints and a partition list are not aliases.
	 *
	 * @since 0.1.0
	 */
	public function test_index_hints_and_partitions_are_not_aliases(): void {
		$forced = new Statement( 'SELECT variant_id FROM wptests_seocart_stock_items FORCE INDEX ( PRIMARY ) WHERE variant_id = 1', self::PREFIX );
		$hinted = new Statement( 'SELECT i.variant_id FROM wptests_seocart_stock_items PARTITION ( p0, p1 ) AS i USE INDEX FOR JOIN ( a, b ) IGNORE INDEX ( c ), wptests_seocart_stock_holds h WHERE i.variant_id = h.variant_id', self::PREFIX );

		$this->assertSame( array( 'wptests_seocart_stock_items' => 'wptests_seocart_stock_items' ), $forced->tables() );
		$this->assertSame(
			array(
				'i' => 'wptests_seocart_stock_items',
				'h' => 'wptests_seocart_stock_holds',
			),
			$hinted->tables()
		);
	}

	/**
	 * Tests that one name for two tables is refused, since their plans could not be told apart.
	 *
	 * @since 0.1.0
	 */
	public function test_one_name_for_two_tables_is_refused(): void {
		$statement = new Statement( 'SELECT i.variant_id FROM wptests_seocart_stock_items i WHERE EXISTS ( SELECT 1 FROM wptests_seocart_stock_ledger i WHERE i.variant_id = 1 )', self::PREFIX );

		$this->expectException( \UnexpectedValueException::class );
		$this->expectExceptionMessage( 'names both wptests_seocart_stock_items and wptests_seocart_stock_ledger "i"' );

		$statement->tables();
	}

	/**
	 * Tests that EXPLAIN reading a table the statement does not name is a breach, whatever its plan.
	 *
	 * @since 0.1.0
	 */
	public function test_a_plan_of_a_table_the_statement_does_not_name_is_a_breach(): void {
		$statement = new Statement( 'SELECT variant_id FROM wptests_seocart_stock_items i WHERE variant_id = 1', self::PREFIX );
		$plan      = QueryPlan::judge(
			$statement,
			array(
				self::explained( 'i', 'const', 'PRIMARY', 1 ),
				self::explained( 'x', 'const', 'PRIMARY', 1 ),
				self::explained( '<derived2>', 'ALL', null, 99999 ),
				self::explained( '', '', null, 0 ),
			),
			static fn(): int => 1
		);

		$this->assertSame( array( "x: EXPLAIN reads a table of this name, which the statement's FROM, JOIN and comma joins do not name, so its plan cannot be judged" ), $plan->breaches );
	}

	/**
	 * Tests that the tables a statement names are read under the names EXPLAIN gives them, and which statements are plugin SELECTs.
	 *
	 * @since 0.1.0
	 */
	public function test_tables_are_named_as_explain_names_them(): void {
		$facts = new Statement( 'SELECT v.id, EXISTS ( SELECT 1 FROM `wptests_seocart_variant_prices` vp WHERE vp.variant_id = v.id ) AS has_base_price FROM `wptests_seocart_variants` v JOIN `wptests_seocart_products` p ON p.id = v.product_id LEFT JOIN `wptests_posts` wp ON wp.ID = p.source_post_id WHERE v.id IN (1)', self::PREFIX );
		$plain = new Statement( 'SELECT product_id FROM wptests_seocart_product_posts WHERE post_id = 3 FOR UPDATE', self::PREFIX );

		$this->assertSame(
			array(
				'vp' => 'wptests_seocart_variant_prices',
				'v'  => 'wptests_seocart_variants',
				'p'  => 'wptests_seocart_products',
				'wp' => 'wptests_posts',
			),
			$facts->tables()
		);
		$this->assertSame( array( 'wptests_seocart_product_posts' => 'wptests_seocart_product_posts' ), $plain->tables() );
		$this->assertTrue( $facts->isPluginSelect() );
		$this->assertTrue( $plain->isPluginSelect() );
		$this->assertFalse( ( new Statement( 'SELECT ID FROM wptests_posts WHERE ID = 3', self::PREFIX ) )->isPluginSelect(), 'A SELECT of WordPress tables only is not the plugin\'s.' );
		$this->assertFalse( ( new Statement( 'UPDATE wptests_seocart_stock_items SET held = 1 WHERE variant_id = 3', self::PREFIX ) )->isPluginSelect() );
		$this->assertFalse( ( new Statement( 'EXPLAIN SELECT 1 FROM wptests_seocart_stock_items', self::PREFIX ) )->isPluginSelect() );
	}

	/**
	 * Tests the rule: a full scan, or more than MOST_ROWS rows, breaks it in a table of LARGE_TABLE rows or more, and in no smaller table.
	 *
	 * @since 0.1.0
	 */
	public function test_the_rule_judges_the_plans_of_large_tables(): void {
		$statement = new Statement( 'SELECT i.variant_id FROM wptests_seocart_stock_items i LEFT JOIN wptests_seocart_stock_ledger l ON l.variant_id = i.variant_id LEFT JOIN wptests_seocart_stock_holds h ON h.variant_id = i.variant_id WHERE i.variant_id IN (1) AND EXISTS ( SELECT 1 FROM wptests_seocart_products p )', self::PREFIX );
		$sizes     = array(
			'wptests_seocart_stock_items'  => QueryPlan::LARGE_TABLE,
			'wptests_seocart_stock_ledger' => 30000,
			'wptests_seocart_stock_holds'  => QueryPlan::LARGE_TABLE - 1,
			'wptests_seocart_products'     => 20000,
		);
		$plan      = QueryPlan::judge(
			$statement,
			array(
				self::explained( 'i', 'ALL', null, 10 ),
				self::explained( 'l', 'ref', 'variant_created', QueryPlan::MOST_ROWS + 1 ),
				self::explained( 'h', 'ALL', null, 9999 ),
				self::explained( 'p', 'index', 'uuid', QueryPlan::MOST_ROWS ),
				self::explained( '<subquery2>', 'ALL', null, 99999 ),
			),
			static fn( string $table ): int => $sizes[ $table ]
		);

		$this->assertSame(
			array(
				'seocart_stock_items as i: type ALL, key none, rows 10, of a table of 10000 rows',
				'seocart_stock_ledger as l: type ref, key variant_created, rows 5001, of a table of 30000 rows',
			),
			$plan->breaches
		);
		$this->assertCount( 4, $plan->accesses, 'The row for no table the query names is left out.' );
	}

	/**
	 * Tests that the committed allow-list is accepted: every entry has a reason and names its query by the query's own id.
	 *
	 * The query-plan run fails, besides, on an entry that names no query of the run that breaks the rule.
	 *
	 * @since 0.1.0
	 */
	public function test_the_committed_allow_list_gives_every_entry_a_reason(): void {
		$entries = AllowList::load( dirname( __DIR__, 3 ) . '/' . AllowList::FILE );

		foreach ( $entries as $id => $entry ) {
			$this->assertSame( $id, Statement::idOf( $entry['statement'] ) );
			$this->assertNotSame( '', $entry['reason'] );
		}
	}

	/**
	 * Tests that an allow-list entry without a reason, with an id that is not its statement's, or listed twice, is refused.
	 *
	 * @since 0.1.0
	 *
	 * @dataProvider refusedEntries
	 *
	 * @param list<array<string, string>> $entries The entries.
	 * @param string                      $refusal A part of the refusal.
	 */
	public function test_an_incomplete_allow_list_is_refused( array $entries, string $refusal ): void {
		$file = (string) tempnam( sys_get_temp_dir(), 'seocart-allow-list-' );

		file_put_contents( $file, (string) wp_json_encode( array( 'entries' => $entries ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a temporary fixture.

		try {
			$this->expectException( \UnexpectedValueException::class );
			$this->expectExceptionMessage( $refusal );

			AllowList::load( $file );
		} finally {
			unlink( $file );
		}
	}

	/**
	 * Lists allow-lists that must be refused.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{0: list<array<string, string>>, 1: string}> The cases.
	 */
	public static function refusedEntries(): array {
		$statement = 'SELECT variant_id FROM {prefix}seocart_stock_items WHERE on_hand = ?';
		$entry     = array(
			'id'        => Statement::idOf( $statement ),
			'statement' => $statement,
			'reason'    => 'A reason.',
		);

		return array(
			'no reason'    => array( array( array_diff_key( $entry, array( 'reason' => true ) ) ), 'gives no reason' ),
			'a blank one'  => array( array( array( 'reason' => ' ' ) + $entry ), 'gives no reason' ),
			'another id'   => array( array( array( 'id' => '000000000000' ) + $entry ), 'is not the id of its statement' ),
			'listed twice' => array( array( $entry, $entry ), 'is listed twice' ),
		);
	}

	/**
	 * Tests that the recorder keeps each plugin SELECT once, with the values it was first sent with, and nothing else.
	 *
	 * @since 0.1.0
	 */
	public function test_the_recorder_keeps_each_plugin_select_once(): void {
		global $wpdb;

		$recorder = PlanRecorder::open();

		try {
			$db = new Database( $recorder, true, $this->reporter() );

			$db->execute( 'INSERT INTO %i ( id, value ) VALUES ( %d, %s )', $this->rowsTable(), 1, 'one' );
			$db->fetchAll( 'SELECT value FROM %i WHERE id = %d', $this->rowsTable(), 1 );
			$db->fetchAll( 'SELECT value FROM %i WHERE id = %d', $this->rowsTable(), 2 );
			$db->fetchAll( 'SELECT value FROM %i WHERE id IN ( %d, %d )', $this->rowsTable(), 1, 2 );
			$db->fetchAll( 'SELECT value FROM %i WHERE id IN ( %d, %d )', $this->rowsTable(), 3, 4 );
			$db->fetchAll( 'SELECT value FROM %i WHERE id IN ( %d, %d, %d )', $this->rowsTable(), 1, 2, 3 );
			$db->fetchAll( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, 'siteurl' );
		} finally {
			$recorder->close();
		}

		$this->assertSame(
			array(
				'SELECT value FROM `' . $this->rowsTable() . '` WHERE id = 1',
				'SELECT value FROM `' . $this->rowsTable() . '` WHERE id IN ( 1, 2 )',
				'SELECT value FROM `' . $this->rowsTable() . '` WHERE id IN ( 1, 2, 3 )',
			),
			array_map( static fn( Statement $statement ): string => $statement->sql(), $recorder->statements() ),
			'Each query is kept once per length of its IN list, with the values it was first sent with.'
		);

		$plan = QueryPlan::explain( $wpdb, $recorder->statements()[0], static fn(): int => 1 );

		$this->assertSame( array(), $plan->breaches );
		$this->assertSame( $this->rowsTable(), $plan->accesses[0]['table'] );
	}

	/**
	 * Returns one row of EXPLAIN, in the traditional format.
	 *
	 * @since 0.1.0
	 *
	 * @param string      $table The table's name in the plan.
	 * @param string      $type  The access type.
	 * @param string|null $key   The index used, or null.
	 * @param int         $rows  The rows EXPLAIN expects to examine.
	 * @return array<string, mixed> The row.
	 */
	private static function explained( string $table, string $type, ?string $key, int $rows ): array {
		return array(
			'id'    => 1,
			'table' => $table,
			'type'  => $type,
			'key'   => $key,
			'rows'  => $rows,
		);
	}
}
