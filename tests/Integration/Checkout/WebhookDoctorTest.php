<?php
/**
 * Tests what doctor's payments check says of the webhook receipts
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Checkout;

use SEOCart\Contracts\Payment\Operation;
use SEOCart\Contracts\Payment\Outcome;
use SEOCart\Payment\Application\PaymentService;
use SEOCart\Payment\Infrastructure\Doctor\PaymentLedgerCheck;
use SEOCart\Payment\Infrastructure\Gateway\StubGateway;
use SEOCart\Tests\Support\Checkout\WebhookTestCase;
use SEOCart\Tests\Support\Payment\StubWebhooks;

/**
 * Doctor's payments check notes, never failing for them, the events of the last thirty days that were ignored, by type or reason, and those whose money was kept for a person, by reason; and says that a delivery failing on every retry shows only in the log.
 *
 * Planted violation: in PaymentLedgerCheck::webhookEvents(), leave out FAULTED_DELIVERIES: a
 * delivery that fails on every retry is then mentioned nowhere a merchant looks.
 *
 * @since 0.2.0
 */
final class WebhookDoctorTest extends WebhookTestCase {

	/**
	 * Tests the information lines, beside what the check already says of the order a delivery flagged.
	 *
	 * @since 0.2.0
	 */
	public function test_doctor_reports_the_receipts(): void {
		$approved = $this->placed( StubGateway::APPROVE, 'approved' );
		$waiting  = $this->placed( StubGateway::THROW, 'waiting' );

		$this->deliver( StubWebhooks::dispute( $approved['intent_uuid'] ) );
		$this->deliver( StubWebhooks::dispute( $approved['intent_uuid'] )->withEventId( 'evt_second_dispute' ) );
		$this->deliver( StubWebhooks::event( 'evt_payout', 'payout.paid', array() ) );
		$this->deliver( StubWebhooks::of( $this->resultAbout( $waiting, Operation::Capture, Outcome::Approved, 'stub-cap-early' ) ) );

		$result = $this->kernel->get( PaymentLedgerCheck::class )->run();

		$this->assertFalse( $result->passed, 'The flagged order is a problem.' );
		$this->assertContains( 'Info: webhook events ignored in the last 30 days: dispute.created: 2, payout.paid: 1.', $result->findings );
		$this->assertContains( 'Info: webhook events whose money was kept for a person in the last 30 days: ' . PaymentService::UNEXPECTED_RESULT . ': 1.', $result->findings );
		$this->assertSame( PaymentLedgerCheck::FAULTED_DELIVERIES, array_slice( $result->findings, -1 )[0] ?? null, 'Doctor says where a delivery failing on every retry shows.' );
	}

	/**
	 * Tests that ignored events alone are information: the check still passes.
	 *
	 * @since 0.2.0
	 */
	public function test_ignored_events_alone_leave_the_check_passing(): void {
		$approved = $this->placed( StubGateway::APPROVE );

		$this->deliver( StubWebhooks::dispute( $approved['intent_uuid'] ) );

		$result = $this->kernel->get( PaymentLedgerCheck::class )->run();

		$this->assertTrue( $result->passed, implode( "\n", $result->findings ) );
		$this->assertSame( array( 'Info: webhook events ignored in the last 30 days: dispute.created: 1.', PaymentLedgerCheck::FAULTED_DELIVERIES ), $result->findings );
	}
}
