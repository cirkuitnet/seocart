<?php
/**
 * Tests that an audit row is recorded under the machine it names, copying that machine's status
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Order;

use SEOCart\Order\Domain\Machine;
use SEOCart\Order\Domain\OrderStatus;
use SEOCart\Order\Domain\PaymentStatus;
use SEOCart\Order\Infrastructure\OrderTables;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Tests\Support\Order\OrderTestCase;

defined( 'ABSPATH' ) || exit;

/**
 * One writer for every machine: the row names the machine and copies its status as from and to; the payment's stays the default, byte for byte.
 *
 * Planted violation: in MysqlOrderRepository::APPEND_AUDIT, select `payment_status` in place of the
 * two identifier placeholders. The fulfillment and order rows then copy the payment status.
 *
 * @since 0.2.0
 */
final class OrdersAuditTest extends OrderTestCase {

	/**
	 * The uuid every row of the test names.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const REFERENCE = '01928c3e-7b3c-7d1e-9a2b-00000000a0d1';

	/**
	 * Tests that each machine's row names the machine and copies that machine's status, and no other.
	 *
	 * @since 0.2.0
	 */
	public function test_each_machine_s_row_copies_its_own_status(): void {
		$order = $this->place();

		// Three statuses that differ, so a row that copies the wrong one shows it.
		$this->plantStatus( $order->id, OrderStatus::Processing, PaymentStatus::Paid );

		foreach ( Machine::cases() as $machine ) {
			$this->db->transaction( fn(): int => $this->orders->appendAudit( $order->id, 'audited_' . $machine->value, self::REFERENCE, Actor::user( 1 ), $machine ) );
		}

		$this->assertSame(
			array(
				'order:processing>processing:audited_order',
				'payment:paid>paid:audited_payment',
				'fulfillment:unfulfilled>unfulfilled:audited_fulfillment',
			),
			array_values( array_filter( $this->eventsOf( $order->id ), static fn( string $event ): bool => str_contains( $event, ':audited_' ) ) )
		);
	}

	/**
	 * Tests that the payment's row, the default, is what the writer recorded before it took a machine: the same columns, the same values.
	 *
	 * @since 0.2.0
	 */
	public function test_the_default_writes_the_payment_row_as_before(): void {
		$order = $this->place();
		$actor = Actor::user( 1 );

		$this->plantStatus( $order->id, OrderStatus::Processing, PaymentStatus::Paid );

		$this->db->transaction(
			function () use ( $order, $actor ): void {
				$this->orders->appendAudit( $order->id, 'refund_recorded', self::REFERENCE, $actor );
				$this->orders->flagUnreconciled( $order->id, 'refund_settled_by_statement', self::REFERENCE, $actor );

				// The statement as it was before it named a machine, sent with the same values.
				$this->db->execute(
					'INSERT INTO %i ( order_id, machine, from_status, to_status, reason, reference, actor_type, actor_id, correlation_id, created_at ) '
					. "SELECT id, %s, payment_status, payment_status, %s, %s, %s, NULLIF( %d, 0 ), NULLIF( %s, '' ), UTC_TIMESTAMP(6) FROM %i WHERE id = %d",
					$this->table( OrderTables::EVENTS ),
					Machine::Payment->value,
					'refund_recorded',
					self::REFERENCE,
					'user',
					1,
					$this->correlation->current(),
					$this->table( OrderTables::ORDERS ),
					$order->id
				);
			}
		);

		$rows = $this->db->fetchAll( 'SELECT machine, from_status, to_status, reason, reference, actor_type, actor_id, correlation_id FROM %i WHERE order_id = %d AND reference = %s ORDER BY id', $this->table( OrderTables::EVENTS ), $order->id, self::REFERENCE );

		$this->assertCount( 3, $rows );
		$this->assertSame( $rows[2], $rows[0], 'The default row differs from the one the writer recorded before it took a machine.' );
		$this->assertSame( array( 'payment', 'paid', 'paid', 'refund_settled_by_statement' ), array( $rows[1]['machine'], $rows[1]['from_status'], $rows[1]['to_status'], $rows[1]['reason'] ), 'The flag\'s row is still the payment\'s.' );
	}
}
