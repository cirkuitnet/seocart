<?php
/**
 * Tests that a delivery that fails verification keeps nothing, and that rejections are logged within a bound and never refused for their number
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Checkout;

use SEOCart\Checkout\Application\ReceiveWebhook;
use SEOCart\Payment\Domain\Webhook\ReceiptResult;
use SEOCart\Payment\Infrastructure\Gateway\StubGateway;
use SEOCart\Tests\Support\Checkout\WebhookTestCase;
use SEOCart\Tests\Support\Payment\StubWebhooks;

/**
 * A bad signature, a signed time outside the window and a body that verifies but cannot be read are each refused `payment.webhook_rejected` and write no receipt; each is logged with the address, the reason and the body's hash and length, and nothing else, for the first REJECTIONS_LOGGED of a client's window; past that they are counted only, and a genuine delivery is still taken.
 *
 * Planted violation: in ReceiveWebhook::receive(), record the receipt before the gateway reads the
 * delivery (`$this->receipts->record( $envelope->gatewayId, $envelope->mode, 'evt-unverified', 'unverified', null, hash( 'sha256', $envelope->rawBody ), '' );`
 * as its first line): a rejected delivery then leaves a row.
 *
 * @since 0.2.0
 */
final class WebhookRejectionTest extends WebhookTestCase {

	/**
	 * Tests that each kind of rejection keeps nothing, and is logged with exactly the address, the reason, and the body's hash and length.
	 *
	 * @since 0.2.0
	 */
	public function test_a_rejected_delivery_keeps_nothing_and_logs_only_what_identifies_it(): void {
		$placed   = $this->placed( StubGateway::THROW );
		$approval = StubWebhooks::of( $this->approvalOf( $placed ) );
		$rejected = array(
			'bad_signature' => $approval->withBadSignature(),
			'stale'         => $approval->staleBy( 400 ),
			'malformed'     => StubWebhooks::event( 'evt_x', 'capture.approved', array() )->withBody( '{"neither":"id nor type"}' ),
		);

		foreach ( $rejected as $reason => $delivery ) {
			$this->assertSame( 'payment.webhook_rejected', $this->refusalOf( $delivery ), $reason );
		}

		$this->assertSame( 0, $this->receiptCount(), 'No receipt for a delivery that failed verification.' );
		$this->assertSame( 'pending_payment', $this->orderState( $placed['order_id'] )['status'] );

		$lines = $this->logged( 'payment.webhook_rejected' );

		$this->assertSame( array_keys( $rejected ), array_column( $lines, 'reason' ) );

		foreach ( $lines as $index => $line ) {
			$delivery = array_values( $rejected )[ $index ];

			$this->assertSame( array( 'gateway_id', 'mode', 'reason', 'body_sha256', 'body_length' ), array_keys( $line ) );
			$this->assertSame( array( StubGateway::ID, 'test', hash( 'sha256', $delivery->body() ), strlen( $delivery->body() ) ), array( $line['gateway_id'], $line['mode'], $line['body_sha256'], $line['body_length'] ) );
		}
	}

	/**
	 * Tests that a flood of rejections logs the first REJECTIONS_LOGGED of the window and counts the rest, and that a genuine delivery after it is taken: the counter never refuses.
	 *
	 * @since 0.2.0
	 */
	public function test_a_flood_of_rejections_is_logged_within_a_bound_and_never_refused(): void {
		$placed = $this->placed( StubGateway::THROW );
		$forged = StubWebhooks::of( $this->approvalOf( $placed ) )->withBadSignature();

		for ( $delivery = 0; $delivery < ReceiveWebhook::REJECTIONS_LOGGED + 5; $delivery++ ) {
			$this->assertSame( 'payment.webhook_rejected', $this->refusalOf( $forged ) );
		}

		$this->assertCount( ReceiveWebhook::REJECTIONS_LOGGED, $this->logged( 'payment.webhook_rejected' ) );
		$this->assertSame( ReceiptResult::Applied, $this->deliver( StubWebhooks::of( $this->approvalOf( $placed ) ) ), 'The provider\'s own delivery is taken after the flood.' );
	}
}
