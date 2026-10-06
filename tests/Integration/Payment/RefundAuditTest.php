<?php
/**
 * Tests the order event every refund records: one row per refund, naming it, whether or not the payment status moved
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Payment;

use SEOCart\Order\Infrastructure\OrderTables;
use SEOCart\Payment\Application\RefundService;
use SEOCart\Tests\Support\Payment\RefundOrders;
use SEOCart\Tests\Support\Payment\RefundTestCase;

/**
 * Every refund writes one `refund_recorded` event of the order's payment, in the transaction that records the refund: from and to are the payment status the refund left, its reference is the refund's uuid, and it names the actor and the request's correlation id. A refund that moves the payment status writes its status change as well, in a row of its own; one that leaves it as it was writes the refund's row alone.
 *
 * Planted violation, shown red and removed: in RefundService::writeDocument(), append the event
 * only for a refund that moves the payment status (the first): the second refund records no event.
 *
 * @since 0.2.0
 */
final class RefundAuditTest extends RefundTestCase {

	/**
	 * Tests that a first refund, which moves the payment status, and a second, which leaves it as it is, each write one refund event naming them, beside the first's status change.
	 *
	 * @since 0.2.0
	 */
	public function test_every_refund_records_one_event_naming_it(): void {
		list( $order ) = $this->placePaid( RefundOrders::priced( array( RefundOrders::line( 'tee', '12.34', 3, 'standard' ) ) ) );
		list( $tee )   = $this->lineUuids( $order->id );
		$first         = $this->refund( $order->uuid, array( $tee => 1 ) );
		$second        = $this->refund( $order->uuid, array( $tee => 1 ) );
		$agent         = (string) $this->agent()->userId();
		$events        = $this->db->fetchAll(
			"SELECT machine, from_status, to_status, reason, reference, actor_type, actor_id, correlation_id FROM %i WHERE order_id = %d AND reason IN ( 'payment_refunded', %s ) ORDER BY id",
			$this->table( OrderTables::EVENTS ),
			$order->id,
			RefundService::AUDIT_REASON
		);

		$this->assertCount( 3, $events, 'The first refund\'s status change and refund, and the second\'s refund.' );

		$correlation = $events[0]['correlation_id'];

		$this->assertNotNull( $correlation, 'The status change names the request.' );
		$this->assertSame(
			array(
				array( 'payment', 'paid', 'partially_refunded', 'payment_refunded', null, 'user', $agent, $correlation ),
				array( 'payment', 'partially_refunded', 'partially_refunded', RefundService::AUDIT_REASON, $first->uuid, 'user', $agent, $correlation ),
				array( 'payment', 'partially_refunded', 'partially_refunded', RefundService::AUDIT_REASON, $second->uuid, 'user', $agent, $correlation ),
			),
			array_map( 'array_values', $events )
		);
	}
}
