<?php
/**
 * Tests placing an order: the whole document in fourteen statements, and its access key stored only as a hash
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Order;

use SEOCart\Order\Domain\AccessKeys;
use SEOCart\Order\Domain\Event\OrderCreated;
use SEOCart\Order\Domain\InsertedOrder;
use SEOCart\Order\Infrastructure\MysqlOrderRepository;
use SEOCart\Order\Infrastructure\OrderStatements;
use SEOCart\Order\Infrastructure\OrderTables;
use SEOCart\Order\Infrastructure\WordPressAccessKeys;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Platform\Database\Exception\DuplicateKey;
use SEOCart\Platform\Events\Outbox;
use SEOCart\Platform\Events\OutboxTable;
use SEOCart\Tests\Support\Doubles\SequentialIdGenerator;
use SEOCart\Tests\Support\Order\NewOrders;
use SEOCart\Tests\Support\Order\OrderTestCase;

/**
 * An order is written whole, from its document, in the caller's transaction, in fourteen statements.
 *
 * Planted violations, each shown red and removed:
 * - in OrderTables::totals(), drop the `order_current` unique key: a second current snapshot of
 *   one order is accepted;
 * - in Orders::insert(), store the raw access key instead of its hash: the key is found in the
 *   order's row, and it no longer verifies against what is stored;
 * - in MysqlOrderRepository::insertLines(), insert each line with a statement of its own: the
 *   six-line order takes more statements than the two-line one.
 *
 * @since 0.1.0
 */
final class InsertOrderTest extends OrderTestCase {

	/**
	 * Tests that a placed order has every row of its document, copied, in fourteen statements.
	 *
	 * @since 0.1.0
	 */
	public function test_an_order_is_written_whole_in_fourteen_statements(): void {
		$inserted = null;
		$log      = $this->captureQueries(
			function () use ( &$inserted ): void {
				$inserted = $this->db->transaction( fn(): InsertedOrder => $this->orders->insert( NewOrders::forTwoLines( 'EUR', 'USD' ), Actor::user( 7 ) ) );
			}
		);

		$this->assertInstanceOf( InsertedOrder::class, $inserted );
		$this->assertQueryCount( 14, $log->ofType( 'SELECT', 'INSERT', 'UPDATE', 'DELETE' ), 'placing an order' );
		$this->assertSame( '000001', $inserted->orderNumber );

		$order = $this->db->fetchRow( 'SELECT * FROM %i WHERE id = %d', $this->table( OrderTables::ORDERS ), $inserted->id );
		$this->assertNotNull( $order );
		$this->assertSame(
			array(
				'uuid'                       => $inserted->uuid,
				'order_number'               => '000001',
				'channel'                    => 'storefront',
				'actor_type'                 => 'user',
				'actor_id'                   => '7',
				'status'                     => 'pending_payment',
				'payment_status'             => 'unpaid',
				'customer_id'                => null,
				'email'                      => 'jane.doe@example.com',
				'currency'                   => 'EUR',
				'base_currency'              => 'USD',
				'locale'                     => 'en_GB',
				'tax_display_mode_snapshot'  => 'incl',
				'cross_zone_policy_snapshot' => 'fixed_net',
				'tax_rounding_mode_snapshot' => 'per_line',
				'grand_total_minor'          => '3080',
				'authorized_minor'           => '0',
				'due_minor'                  => '3080',
				'base_subtotal_minor'        => '2000',
				'base_discount_total_minor'  => '160',
				'base_tax_total_minor'       => '224',
				'base_grand_total_minor'     => '2464',
				'hold_group'                 => NewOrders::HOLD_GROUP,
				'client_ip'                  => '192.0.2.10',
			),
			array_intersect_key( $order, array_flip( array( 'uuid', 'order_number', 'channel', 'actor_type', 'actor_id', 'status', 'payment_status', 'customer_id', 'email', 'currency', 'base_currency', 'locale', 'tax_display_mode_snapshot', 'cross_zone_policy_snapshot', 'tax_rounding_mode_snapshot', 'grand_total_minor', 'due_minor', 'authorized_minor', 'base_subtotal_minor', 'base_discount_total_minor', 'base_tax_total_minor', 'base_grand_total_minor', 'hold_group', 'client_ip' ) ) )
		);
		$this->assertNotNull( $order['correlation_id'] );

		$totals = $this->db->fetchAll( 'SELECT id, version, is_current, conversion_context_id, grand_total_minor, base_grand_total_minor, rate_version, trace_json FROM %i WHERE order_id = %d', $this->table( OrderTables::TOTALS ), $inserted->id );
		$this->assertCount( 1, $totals );
		$this->assertSame( $order['current_totals_id'], $totals[0]['id'], 'The order names the snapshot its totals were copied from.' );
		$this->assertSame( array( '1', '1', '3080', '2464', '3' ), array( $totals[0]['version'], $totals[0]['is_current'], $totals[0]['grand_total_minor'], $totals[0]['base_grand_total_minor'], $totals[0]['rate_version'] ) );
		$this->assertSame( $order['conversion_context_id'], $totals[0]['conversion_context_id'] );
		$this->assertSame( NewOrders::TRACE, json_decode( (string) $totals[0]['trace_json'], true ) );

		$lines = $this->db->fetchAll( 'SELECT id, sku_snapshot, locale_snapshot, quantity, unit_compare_at_minor, line_net_minor, line_tax_minor, base_line_net_minor, base_line_discount_minor, base_line_tax_minor, base_line_gross_minor, tax_class_snapshot, sort_order FROM %i WHERE order_id = %d ORDER BY sort_order', $this->table( OrderTables::LINES ), $inserted->id );
		$this->assertSame(
			array(
				array( 'TEE-M', 'en_GB', '2', '1200', '1800', '180', '1440', '160', '144', '1584', null, '0' ),
				array( 'MUG-1', 'en_GB', '1', null, '500', '50', '400', '0', '40', '440', 'reduced', '1' ),
			),
			array_map( static fn( array $line ): array => array_values( array_slice( $line, 1 ) ), $lines )
		);

		$this->assertSame(
			array( array( $lines[0]['id'], 'size', 'Size', 'm', 'Medium', 'en_GB', '0' ) ),
			array_map( 'array_values', $this->db->fetchAll( 'SELECT order_line_id, axis_key_snapshot, axis_label_snapshot, value_key_snapshot, value_label_snapshot, locale_snapshot, position FROM %i', $this->table( OrderTables::LINE_OPTIONS ) ) )
		);

		$adjustments = $this->db->fetchAll( 'SELECT id, order_line_id, scope, source, amount_minor, base_amount_minor, base_gross_minor, sort_order FROM %i WHERE order_id = %d ORDER BY sort_order', $this->table( OrderTables::ADJUSTMENTS ), $inserted->id );
		$this->assertSame(
			array(
				array( $lines[0]['id'], 'line', 'promotion:01928c3e-0000-7000-8000-00000000abcd', '-200', '-160', '-160', '0' ),
				array( null, 'shipping', 'shipping:flat_rate', '500', '400', '440', '1' ),
			),
			array_map( static fn( array $adjustment ): array => array_values( array_slice( $adjustment, 1 ) ), $adjustments )
		);

		$this->assertSame(
			array(
				array( $lines[0]['id'], null, 'line', '1', '180', '144' ),
				array( $lines[1]['id'], null, 'line', '1', '50', '40' ),
				array( null, $adjustments[1]['id'], 'adjustment', '1', '50', '40' ),
			),
			array_map( 'array_values', $this->db->fetchAll( 'SELECT order_line_id, order_adjustment_id, scope, totals_version, tax_minor, base_tax_minor FROM %i WHERE order_id = %d ORDER BY id', $this->table( OrderTables::TAX_COMPONENTS ), $inserted->id ) )
		);

		$this->assertSame(
			array( array( 'billing', 'Jane', '1 High Street', 'GB' ), array( 'shipping', 'Jane', '2 Low Road', 'GB' ) ),
			array_map( 'array_values', $this->db->fetchAll( 'SELECT role, first_name, line1, country FROM %i WHERE order_id = %d ORDER BY role', $this->table( OrderTables::ADDRESSES ), $inserted->id ) )
		);

		$this->assertSame( array( 'order:>pending_payment:placed' ), $this->eventsOf( $inserted->id ) );

		$created = $this->db->fetchAll( 'SELECT aggregate_type, aggregate_id, payload_json FROM %i WHERE event_name = %s', $this->table( OutboxTable::NAME ), OrderCreated::eventName() );
		$this->assertCount( 1, $created );
		$this->assertSame( array( 'order', (string) $inserted->id ), array( $created[0]['aggregate_type'], $created[0]['aggregate_id'] ) );
		$this->assertSame(
			array(
				'order_id'          => $inserted->id,
				'order_uuid'        => $inserted->uuid,
				'order_number'      => '000001',
				'channel'           => 'storefront',
				'currency'          => 'EUR',
				'grand_total_minor' => 3080,
			),
			Outbox::decode( (string) $created[0]['payload_json'] )['p']
		);
	}

	/**
	 * Tests that the statement count does not grow with the number of lines.
	 *
	 * @since 0.1.0
	 */
	public function test_six_lines_take_the_statements_two_take(): void {
		$log = $this->captureQueries(
			function (): void {
				$this->place( NewOrders::forLines( 6 ) );
			}
		);

		$this->assertQueryCount( 14, $log->ofType( 'SELECT', 'INSERT', 'UPDATE', 'DELETE' ), 'placing a six-line order' );
		$this->assertSame( '6', (string) $this->db->fetchValue( 'SELECT COUNT(*) FROM %i', $this->table( OrderTables::LINES ) ) );
		$this->assertSame( '5', (string) $this->db->fetchValue( 'SELECT COUNT(*) FROM %i', $this->table( OrderTables::LINE_OPTIONS ) ) );
	}

	/**
	 * Tests that an order has at most one current totals snapshot, while older snapshots, marked NULL, may be many.
	 *
	 * @since 0.1.0
	 */
	public function test_an_order_has_at_most_one_current_totals_snapshot(): void {
		$inserted = $this->place();
		$totals   = $this->table( OrderTables::TOTALS );
		$copy     = 'INSERT INTO %i ( order_id, version, is_current, currency, base_currency, conversion_context_id, subtotal_minor, discount_total_minor, shipping_total_minor, fee_total_minor, tax_total_minor, grand_total_minor, base_subtotal_minor, base_discount_total_minor, base_shipping_total_minor, base_fee_total_minor, base_tax_total_minor, base_grand_total_minor, tax_rounding_mode, price_entry_mode, cross_zone_policy, locale, trace_json, created_at ) '
			. 'SELECT order_id, %d, NULLIF( %d, 0 ), currency, base_currency, conversion_context_id, subtotal_minor, discount_total_minor, shipping_total_minor, fee_total_minor, tax_total_minor, grand_total_minor, base_subtotal_minor, base_discount_total_minor, base_shipping_total_minor, base_fee_total_minor, base_tax_total_minor, base_grand_total_minor, tax_rounding_mode, price_entry_mode, cross_zone_policy, locale, trace_json, UTC_TIMESTAMP(6) FROM %i WHERE order_id = %d AND version = 1';

		$this->db->execute( $copy, $totals, 2, 0, $totals, $inserted->id );
		$this->db->execute( $copy, $totals, 3, 0, $totals, $inserted->id );

		$this->assertSame( '3', (string) $this->db->fetchValue( 'SELECT COUNT(*) FROM %i WHERE order_id = %d', $totals, $inserted->id ), 'Older snapshots are NULL, and many NULLs fit the key.' );

		$this->expectException( DuplicateKey::class );

		$this->db->execute( $copy, $totals, 4, 1, $totals, $inserted->id );
	}

	/**
	 * Tests that placing an order outside a transaction is refused before any statement.
	 *
	 * @since 0.1.0
	 */
	public function test_placing_outside_a_transaction_is_refused_before_any_statement(): void {
		$log = $this->captureQueries(
			function (): void {
				try {
					$this->orders->insert( NewOrders::forTwoLines(), Actor::user( 0 ) );
					$this->fail( 'An order was placed outside a transaction.' );
				} catch ( \LogicException $expected ) {
					$this->assertStringContainsString( 'caller\'s transaction', $expected->getMessage() );
				}
			}
		);

		$this->assertQueryCount( 0, $log, 'statements before the refusal' );
	}

	/**
	 * Tests that the access key is returned once, is stored nowhere but as its hash, and expires 72 hours after placement by the database clock.
	 *
	 * @since 0.1.0
	 */
	public function test_the_access_key_is_returned_once_and_stored_only_as_its_hash(): void {
		$inserted = $this->place();
		$keys     = new WordPressAccessKeys();

		$this->assertMatchesRegularExpression( '/^[0-9a-f]{32}$/', $inserted->accessKey, '128 random bits, safe in a URL.' );

		$row = $this->db->fetchRow( 'SELECT * FROM %i WHERE id = %d', $this->table( OrderTables::ORDERS ), $inserted->id );
		$this->assertNotNull( $row );

		foreach ( $row as $column => $value ) {
			$this->assertStringNotContainsString( $inserted->accessKey, (string) $value, "The raw key is stored in {$column}." );
		}

		$this->assertStringNotContainsString( $inserted->accessKey, (string) $this->db->fetchValue( 'SELECT GROUP_CONCAT( payload_json ) FROM %i', $this->table( OutboxTable::NAME ) ) );
		$this->assertTrue( $keys->verify( $inserted->accessKey, (string) $row['access_key_hash'] ) );
		$this->assertFalse( $keys->verify( $keys->generate(), (string) $row['access_key_hash'] ) );

		$times = $this->db->fetchRow( 'SELECT TIMESTAMPDIFF( SECOND, placed_at, access_key_expires_at ) AS lifetime, TIMESTAMPDIFF( SECOND, placed_at, UTC_TIMESTAMP() ) AS age FROM %i WHERE id = %d', $this->table( OrderTables::ORDERS ), $inserted->id );

		$this->assertSame( (string) AccessKeys::LIFETIME_SECONDS, $times['lifetime'] ?? null, 'The key lasts 72 hours from placement.' );
		$this->assertGreaterThanOrEqual( 0, (int) ( $times['age'] ?? -1 ) );
		$this->assertLessThanOrEqual( 5, (int) ( $times['age'] ?? 99 ), 'placed_at is the database\'s now.' );
	}

	/**
	 * Tests that the access check reads the owner and the key, and that the database clock decides whether the key expired.
	 *
	 * @since 0.1.0
	 */
	public function test_the_access_check_reads_the_key_and_the_database_clock_decides_its_expiry(): void {
		$inserted   = $this->place( NewOrders::forTwoLines( customerId: 12 ) );
		$repository = new MysqlOrderRepository( new OrderStatements( $this->db ), new SequentialIdGenerator( 700000 ) );
		$access     = $repository->findForAccess( $inserted->uuid );

		$this->assertNotNull( $access );
		$this->assertSame( array( $inserted->uuid, 12, false ), array( $access->uuid, $access->customerId, $access->keyExpired ) );
		$this->assertTrue( ( new WordPressAccessKeys() )->verify( $inserted->accessKey, (string) $access->accessKeyHash ) );

		$this->db->execute( 'UPDATE %i SET access_key_expires_at = UTC_TIMESTAMP() - INTERVAL 1 SECOND WHERE id = %d', $this->table( OrderTables::ORDERS ), $inserted->id );
		$expired = $repository->findForAccess( $inserted->uuid );

		$this->assertTrue( null !== $expired && $expired->keyExpired );

		$this->db->execute( 'UPDATE %i SET access_key_hash = NULL, access_key_expires_at = NULL WHERE id = %d', $this->table( OrderTables::ORDERS ), $inserted->id );
		$keyless = $repository->findForAccess( $inserted->uuid );

		$this->assertTrue( null !== $keyless && $keyless->keyExpired && null === $keyless->accessKeyHash, 'An order without a key has no key that works.' );

		$this->assertNull( $repository->findForAccess( SequentialIdGenerator::nth( 999999 ) ) );
		$this->assertNull( $repository->findForAccess( (string) $inserted->id ), 'An integer never names an order.' );
	}
}
