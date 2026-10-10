<?php
/**
 * Tests that a delivery killed between its receipt and its money leaves the receipt undecided, and the provider's next delivery settles it once
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Checkout;

use SEOCart\Payment\Domain\Webhook\ReceiptResult;
use SEOCart\Payment\Infrastructure\Gateway\StubGateway;
use SEOCart\Tests\Support\Checkout\WebhookTestCase;
use SEOCart\Tests\Support\Payment\StubWebhooks;

/**
 * The receipt is recorded before the money's transaction and settled after it, so a request that dies in between leaves an undecided receipt and no money: the server rolls the open transaction back. The provider's retry finds the receipt undecided and processes the event again, once.
 *
 * A real crash: the delivery runs in a process of its own, which kills itself with SIGKILL just
 * before it sends its lock of the order, inside the money's transaction, after its lock of the intent.
 *
 * Planted violation: in ReceiveWebhook::settle(), settle the receipt before deciding, with a
 * decision taken in advance (`$this->receipts->settle( $receipt->id, ReceiptDecision::ignored( 'pending' ) );`
 * before the dispatch): the killed delivery then leaves a settled receipt and no money, and the
 * retry is answered from the receipt, so the money never moves.
 *
 * @since 0.2.0
 *
 * @group concurrency
 */
final class WebhookCrashTest extends WebhookTestCase {

	/**
	 * Tests that a delivery killed inside its money's transaction leaves its receipt undecided and no money, and the retry settles the payment once.
	 *
	 * @since 0.2.0
	 */
	public function test_a_delivery_killed_before_its_money_is_settled_by_the_retry(): void {
		$placed   = $this->placed( StubGateway::THROW );
		$delivery = StubWebhooks::of( $this->approvalOf( $placed ) );
		$probe    = $this->startDelivery( $delivery, '/^SELECT id, uuid, order_number, channel, status, .+ FOR UPDATE$/s' );

		$this->awaitEnd( $probe );

		$this->assertSame( '', $probe->reportSoFar(), "The delivery answered: it was not killed.\n" . $probe->output() );
		$receipt = $this->receiptOf( $delivery->eventId() );

		$this->assertNotNull( $receipt, 'The receipt was recorded before the money.' );
		$this->assertNull( $receipt['result'], 'The receipt is undecided.' );
		$this->assertSame( array(), $this->ledgerOf( $placed['order_id'] ), 'The money\'s transaction rolled back.' );
		$this->assertSame( 'created', $this->intentRow( $placed['intent_uuid'] )['status'] );

		$this->assertSame( ReceiptResult::Applied, $this->deliver( $delivery ), 'The retry processes the undecided event.' );
		$this->assertSame( 'applied', $this->receiptOf( $delivery->eventId() )['result'] ?? null );
		$this->assertSame( array( 'authorize:approved:1:stub-ch-' . $placed['intent_uuid'] ), $this->ledgerOf( $placed['order_id'] ) );
		$this->assertSame( 'processing', $this->orderState( $placed['order_id'] )['status'] );
		$this->assertSame( 1, $this->receiptCount() );
	}
}
