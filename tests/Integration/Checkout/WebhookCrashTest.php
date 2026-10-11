<?php
/**
 * Tests that a delivery killed before its transaction commits leaves neither its receipt nor its money, and the provider's next delivery settles it once
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
 * The receipt is claimed and settled in the transaction that applies the event, so a request that dies before that transaction commits leaves neither the receipt nor the money: the server rolls the open transaction back. The provider's retry finds no receipt and processes the event once.
 *
 * A real crash: the delivery runs in a process of its own, which kills itself with SIGKILL just
 * before it settles the receipt, its money written. A receiver that settled the receipt after the
 * money's transaction had committed left the money applied and the receipt undecided there.
 *
 * Planted violation: in ReceiveWebhook, settle the receipt in a transaction of its own once the
 * money's has committed (decideOnce() returns the decision without settling it, and settle() calls
 * `$this->tx->transaction( fn() => $this->receipts->settle( $receiptId, $decision ) )`): the killed
 * delivery then leaves the money committed and the receipt undecided.
 *
 * @since 0.2.0
 *
 * @group concurrency
 */
final class WebhookCrashTest extends WebhookTestCase {

	/**
	 * The receipt's settlement, as the receiver sends it.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const SETTLE_RECEIPT = '/^UPDATE `\w+webhook_receipts` SET result = /';

	/**
	 * Tests that a delivery killed just before it settles its receipt leaves no receipt and no money, and the retry settles the payment once.
	 *
	 * @since 0.2.0
	 */
	public function test_a_delivery_killed_before_it_settles_its_receipt_leaves_nothing_and_the_retry_settles_once(): void {
		$placed   = $this->placed( StubGateway::THROW );
		$delivery = StubWebhooks::of( $this->approvalOf( $placed ) );
		$probe    = $this->startDelivery( $delivery, self::SETTLE_RECEIPT );

		$this->awaitEnd( $probe );

		$this->assertSame( '', $probe->reportSoFar(), "The delivery answered: it was not killed.\n" . $probe->output() );
		$this->assertNull( $this->receiptOf( $delivery->eventId() ), 'No receipt outlived the transaction.' );
		$this->assertSame( array(), $this->ledgerOf( $placed['order_id'] ), 'The money rolled back with it: nothing applied, nothing to apply again.' );
		$this->assertSame( 'created', $this->intentRow( $placed['intent_uuid'] )['status'] );

		$this->assertSame( ReceiptResult::Applied, $this->deliver( $delivery ), 'The retry processes the event.' );
		$this->assertSame( 'applied', $this->receiptOf( $delivery->eventId() )['result'] ?? null );
		$this->assertSame( array( 'authorize:approved:1:stub-ch-' . $placed['intent_uuid'] ), $this->ledgerOf( $placed['order_id'] ) );
		$this->assertSame( 'processing', $this->orderState( $placed['order_id'] )['status'] );
		$this->assertSame( 1, $this->receiptCount() );
	}
}
