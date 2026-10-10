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

use SEOCart\Contracts\Payment\Mode;
use SEOCart\Contracts\Payment\Operation;
use SEOCart\Contracts\Payment\Outcome;
use SEOCart\Payment\Application\PaymentService;
use SEOCart\Payment\Infrastructure\Doctor\PaymentLedgerCheck;
use SEOCart\Payment\Infrastructure\Gateway\StubGateway;
use SEOCart\Payment\Infrastructure\MysqlWebhookReceipts;
use SEOCart\Payment\Infrastructure\WebhookReceiptTables;
use SEOCart\Tests\Support\Checkout\WebhookTestCase;
use SEOCart\Tests\Support\Payment\StubWebhooks;

/**
 * Doctor's payments check warns of an event received longer ago than any delivery takes and never settled, and notes, never failing for them, the events of the last thirty days that were ignored, by type or reason, and those whose money was kept for a person, by reason.
 *
 * Planted violation: in PaymentLedgerCheck::run(), leave out the unsettled receipts: the event a
 * fault left undecided is then reported nowhere a merchant looks.
 *
 * @since 0.2.0
 */
final class WebhookDoctorTest extends WebhookTestCase {

	/**
	 * Tests the warning of an undecided receipt and the two information lines, beside what the check already says of the order a delivery flagged.
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

		$undecided = $this->kernel->get( MysqlWebhookReceipts::class )->record( StubGateway::ID, Mode::Test, 'evt_faulted', 'capture.approved', null, str_repeat( 'b', 64 ), '' );

		$this->db->execute( 'UPDATE %i SET received_at = UTC_TIMESTAMP(6) - INTERVAL %d SECOND WHERE id = %d', $this->table( WebhookReceiptTables::RECEIPTS ), PaymentService::STALE_SECONDS + 60, $undecided->id );

		$result = $this->kernel->get( PaymentLedgerCheck::class )->run();

		$this->assertFalse( $result->passed, 'The undecided event and the flagged order are problems.' );
		$this->assertContains( 'Info: webhook events ignored in the last 30 days: dispute.created: 2, payout.paid: 1.', $result->findings );
		$this->assertContains( 'Info: webhook events whose money was kept for a person in the last 30 days: ' . PaymentService::UNEXPECTED_RESULT . ': 1.', $result->findings );
		$this->assertCount( 1, preg_grep( '/^Warning: the webhook event evt_faulted \(capture\.approved\) of stub in test mode was received \d+ seconds ago and never settled/', $result->findings ) );
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
		$this->assertSame( array( 'Info: webhook events ignored in the last 30 days: dispute.created: 1.' ), $result->findings );
	}
}
