<?php
/**
 * Tests that a refund delivery is settled through the refund's claim, and money no claim accounts for is kept for a person, as the receiver writes them down
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Checkout;

use SEOCart\Contracts\Payment\Operation;
use SEOCart\Contracts\Payment\Outcome;
use SEOCart\Order\Infrastructure\OrderTables;
use SEOCart\Payment\Application\PaymentService;
use SEOCart\Payment\Application\RefundService;
use SEOCart\Payment\Domain\Refund\ProviderRefundOutcome;
use SEOCart\Payment\Domain\Refund\RefundLineRequest;
use SEOCart\Payment\Domain\Refund\RefundRequest;
use SEOCart\Payment\Domain\Webhook\ReceiptResult;
use SEOCart\Payment\Infrastructure\Doctor\PaymentLedgerCheck;
use SEOCart\Payment\Infrastructure\Gateway\StubGateway;
use SEOCart\Payment\Infrastructure\RefundClaimTables;
use SEOCart\Tests\Support\Checkout\WebhookTestCase;
use SEOCart\Tests\Support\Payment\StubWebhooks;

/**
 * Through the receiver as the kernel wires it: the refund's own answer delivered again is a duplicate with its row on the receipt; a refund made in the provider's dashboard is money kept for a person, `external_refund`, the order flagged, and doctor counts it; a decline nobody asked for here is ignored; the provider's decline of the refund it made is kept for a person, `refund_reversed`, and doctor counts it too.
 *
 * The refund service's own table of outcomes is ProviderRefundTest's; the race with a person's
 * settlement is ProviderRefundRaceTest's.
 *
 * @since 0.2.0
 */
final class WebhookRefundTest extends WebhookTestCase {

	/**
	 * Tests the outcomes of a refund delivery about an order the store refunded once, each written on its receipt, and doctor's line of the money kept: the refund's own answer again, a refund made in the dashboard, a decline nobody asked for here, and the provider's decline of the refund it had made.
	 *
	 * @since 0.2.0
	 */
	public function test_a_refund_delivery_is_settled_through_its_claim_or_kept_for_a_person(): void {
		$placed = $this->placed( StubGateway::APPROVE );

		$this->kernel->get( PaymentService::class )->capture( $placed['intent_uuid'], $this->capturer() );

		$line     = (string) $this->db->fetchValue( 'SELECT line_uuid FROM %i WHERE order_id = %d ORDER BY sort_order LIMIT 1', $this->table( OrderTables::LINES ), $placed['order_id'] );
		$refund   = $this->kernel->get( RefundService::class )->refund( new RefundRequest( $placed['order_uuid'], array( new RefundLineRequest( $line, 1 ) ), false, 'customer_return' ), $this->userGranted( RefundService::CAPABILITY ) );
		$intent   = $this->intentRow( $placed['intent_uuid'] );
		$currency = (string) $intent['currency'];
		$row      = (int) $this->db->fetchValue( 'SELECT transaction_id FROM %i WHERE uuid = %s', $this->table( RefundClaimTables::CLAIMS ), $refund->uuid );

		$again    = StubWebhooks::of( self::stubResult( $placed['intent_uuid'], Operation::Refund, Outcome::Approved, $refund->total->minorUnits(), $currency, 'stub-re-' . $refund->uuid ), $refund->uuid );
		$external = StubWebhooks::of( self::stubResult( $placed['intent_uuid'], Operation::Refund, Outcome::Approved, 500, $currency, 'external-re-1' ) );
		$decline  = StubWebhooks::of( self::stubResult( $placed['intent_uuid'], Operation::Refund, Outcome::Declined, 500, $currency, 'external-re-2', null, StubGateway::REFUND_DECLINED ) );

		$this->assertSame( ReceiptResult::Duplicate, $this->deliver( $again ) );
		$this->assertSame( array( 'duplicate', null, $placed['intent_uuid'], (string) $row ), array_values( $this->receiptColumns( $again->eventId() ) ) );
		$this->assertSame( '0', $this->orderState( $placed['order_id'] )['has_unreconciled_money'], 'The refund\'s own answer changes nothing.' );

		$this->assertSame( ReceiptResult::Unapplied, $this->deliver( $external ) );
		$this->assertSame( array( 'unapplied', PaymentService::EXTERNAL_REFUND ), array_slice( array_values( $this->receiptColumns( $external->eventId() ) ), 0, 2 ) );
		$this->assertSame( '1', $this->orderState( $placed['order_id'] )['has_unreconciled_money'], 'Money nobody asked for here flags the order.' );

		$this->assertSame( ReceiptResult::Ignored, $this->deliver( $decline ) );
		$this->assertSame( array( 'ignored', ProviderRefundOutcome::NO_CLAIM ), array_slice( array_values( $this->receiptColumns( $decline->eventId() ) ), 0, 2 ) );

		$reversal = StubWebhooks::of( self::stubResult( $placed['intent_uuid'], Operation::Refund, Outcome::Declined, $refund->total->minorUnits(), $currency, 'stub-re-' . $refund->uuid, null, StubGateway::REFUND_DECLINED ), $refund->uuid );

		$this->assertSame( ReceiptResult::Unapplied, $this->deliver( $reversal ), 'The provider took back a refund the store counts as made.' );
		$this->assertSame( array( 'unapplied', PaymentService::REFUND_REVERSED ), array_slice( array_values( $this->receiptColumns( $reversal->eventId() ) ), 0, 2 ) );

		$findings = $this->kernel->get( PaymentLedgerCheck::class )->run()->findings;
		$kept     = array_values( (array) preg_grep( '/^Info: webhook events whose money was kept for a person in the last 30 days: /', $findings ) );

		$this->assertCount( 1, $kept, 'Doctor\'s line of the money kept.' );
		$this->assertStringContainsString( 'external_refund: 1', $kept[0] );
		$this->assertStringContainsString( 'refund_reversed: 1', $kept[0] );
		$this->assertContains( 'Info: webhook events ignored in the last 30 days: no_claim: 1.', $findings );
	}

	/**
	 * Reads what a receipt says: the decision, the word, the intent and the ledger row.
	 *
	 * @since 0.2.0
	 *
	 * @param string $eventId The event.
	 * @return array{result: string|null, result_code: string|null, intent_uuid: string|null, transaction_id: string|null} The columns.
	 */
	private function receiptColumns( string $eventId ): array {
		$receipt = $this->receiptOf( $eventId ) ?? array();

		return array(
			'result'         => $receipt['result'] ?? null,
			'result_code'    => $receipt['result_code'] ?? null,
			'intent_uuid'    => $receipt['intent_uuid'] ?? null,
			'transaction_id' => $receipt['transaction_id'] ?? null,
		);
	}
}
