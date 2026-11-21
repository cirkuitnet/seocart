<?php
/**
 * Tests the rows of the checkout field values an order is placed with: one statement, one row per field, read back a list of orders at a time
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Order;

use SEOCart\Order\Domain\NewOrder;
use SEOCart\Order\Domain\OrderFieldValue;
use SEOCart\Order\Infrastructure\MysqlOrderRepository;
use SEOCart\Order\Infrastructure\OrderStatements;
use SEOCart\Order\Infrastructure\OrderTables;
use SEOCart\Platform\Database\Exception\QueryFailed;
use SEOCart\Tests\Support\Order\NewOrders;
use SEOCart\Tests\Support\Order\OrderTestCase;

/**
 * An order's checkout field values are written by its insert as one statement, in form order with the order's locale, nothing for an order without any; a unit retried after its values reached the table writes no second row for a field; and one read answers the values of a list of orders.
 *
 * Planted violation, shown red and removed: in OrderTables::fieldValues(), leave out the unique key
 * `order_field`. The values sent again for the same order are then written a second time.
 *
 * @since 0.2.0
 */
final class OrderFieldValuesTest extends OrderTestCase {

	/**
	 * Tests that the insert writes the values in one statement, in order, read back as written; an order without values sends nothing for them.
	 *
	 * @since 0.2.0
	 */
	public function test_the_values_are_written_in_one_statement_and_read_back(): void {
		$withValues = self::withValues();
		$log        = $this->captureQueries( fn() => $this->place( $withValues ) );
		$inserts    = $log->matching( '/^INSERT INTO `' . preg_quote( $this->table( OrderTables::FIELD_VALUES ), '/' ) . '`/' );
		$plain      = $this->captureQueries( fn() => $this->place() );
		$orderIds   = array_map( 'intval', array_column( $this->db->fetchAll( 'SELECT id FROM %i ORDER BY id', $this->table( OrderTables::ORDERS ) ), 'id' ) );
		$read       = ( new MysqlOrderRepository( new OrderStatements( $this->db ), $this->ids ) )->fieldValuesOf( $orderIds );

		$this->assertSame( 1, $inserts->count(), 'The values are not one statement.' );
		$this->assertSame( $log->count() - 1, $plain->count(), 'An order without values sends a statement for them.' );
		$this->assertSame( array( $orderIds[0] ), array_keys( $read ), 'An order without values is answered.' );
		$this->assertSame(
			array(
				array(
					'key'         => 'order_note',
					'section'     => 'order',
					'type'        => 'textarea',
					'label'       => 'Order note',
					'field_value' => "Ring twice.\nThank you.",
					'value_label' => null,
					'locale'      => $withValues->locale->toString(),
				),
				array(
					'key'         => 'acme_delivery_slot',
					'section'     => 'order',
					'type'        => 'select',
					'label'       => 'Delivery slot',
					'field_value' => 'morning',
					'value_label' => 'Morning',
					'locale'      => $withValues->locale->toString(),
				),
			),
			$read[ $orderIds[0] ]
		);
	}

	/**
	 * Tests that the values sent again for an order that has them, as a unit retried after its insert reached the server would, are refused by the key and leave one row per field.
	 *
	 * @since 0.2.0
	 */
	public function test_values_sent_again_leave_one_row_per_field(): void {
		$order      = self::withValues();
		$inserted   = $this->place( $order );
		$repository = new MysqlOrderRepository( new OrderStatements( $this->db ), $this->ids );

		$refused = null;

		try {
			$this->db->transaction( static fn() => $repository->insertFieldValues( $inserted->id, $order ) );
		} catch ( QueryFailed $failed ) {
			$refused = $failed;
		}

		$this->assertSame( '2', (string) $this->db->fetchValue( 'SELECT COUNT(*) FROM %i WHERE order_id = %d', $this->table( OrderTables::FIELD_VALUES ), $inserted->id ), 'A field has two rows.' );
		$this->assertNotNull( $refused, 'The values were written a second time.' );
	}

	/**
	 * Returns the two-line fixture order with two field values: the order note and an extension's select.
	 *
	 * @since 0.2.0
	 *
	 * @return NewOrder The document.
	 */
	private static function withValues(): NewOrder {
		$values = array(
			new OrderFieldValue( 'order_note', 'order', 'textarea', 'Order note', "Ring twice.\nThank you.", null ),
			new OrderFieldValue( 'acme_delivery_slot', 'order', 'select', 'Delivery slot', 'morning', 'Morning' ),
		);

		return new NewOrder( ...array_merge( get_object_vars( NewOrders::forTwoLines() ), array( 'fieldValues' => $values ) ) );
	}
}
