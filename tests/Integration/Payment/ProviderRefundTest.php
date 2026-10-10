<?php
/**
 * Tests the provider's own word of a refund: recorded through the refund's open claim, or kept for a person when no open claim accounts for it
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Payment;

use SEOCart\Contracts\Payment\Mode;
use SEOCart\Contracts\Payment\Operation;
use SEOCart\Contracts\Payment\Operations;
use SEOCart\Contracts\Payment\Outcome;
use SEOCart\Order\Infrastructure\OrderTables;
use SEOCart\Payment\Application\ClaimStatement;
use SEOCart\Payment\Application\PaymentError;
use SEOCart\Payment\Application\PaymentService;
use SEOCart\Payment\Application\RefundService;
use SEOCart\Payment\Domain\Refund\ProviderRefundKind;
use SEOCart\Payment\Domain\Refund\ProviderRefundOutcome;
use SEOCart\Payment\Infrastructure\Gateway\StubGateway;
use SEOCart\Payment\Infrastructure\PaymentTables;
use SEOCart\Payment\Infrastructure\RefundClaimTables;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Support\Error\CodedException;
use SEOCart\Tests\Support\Doubles\DeclaredGateway;
use SEOCart\Tests\Support\Doubles\RememberingGateway;
use SEOCart\Tests\Support\Payment\RefundOrders;
use SEOCart\Tests\Support\Payment\RefundTestCase;

/**
 * C3 §8's table, one row each: a result for the refund's open claim is its answer, recorded as the refund's own would be; a result no open claim accounts for (no claim, an unknown one, another payment's, or one that has ended, by its own answer or a person's settlement) is money kept for a person, once, or changes nothing.
 *
 * Each claim is left open as a real one is: the refund was asked while the gateway could not be
 * reached, so nothing reached the ledger. The provider's word is then delivered to the refund
 * service as the webhook receiver delivers it, at transaction depth 0, as the webhook.
 *
 * Planted violations, each shown red and removed:
 *
 * - in RefundService::keepProviderRefund(), answer every result kept `Unexpected`: the claim's own
 *   answer delivered again reads as money nobody asked for;
 * - in RefundService::keepProviderRefund(), keep a result with no open claim through
 *   PaymentService::applyGatewayResult() instead of recordUnapplied(): a refund made in the
 *   provider's dashboard is taken for one of the store's, which the money path refuses with no
 *   base share worked out for it, and the delivery fails instead of keeping the money;
 * - in PaymentService::keepUnapplied(), date the row by the database clock alone (pass null for
 *   the order's clearance): a refund delivered after a clearance dated ahead of the clock is dated
 *   before it, and the payment refunds again with the flag up;
 * - and the four of the open claim's own tests, each named on its test.
 *
 * @since 0.2.0
 */
final class ProviderRefundTest extends RefundTestCase {

	/**
	 * Why the person says so, in every statement of the test.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const NOTE = 'The provider\'s dashboard shows what became of the refund.';

	/**
	 * The gateway the claims were asked of: it remembers what it made, and can be made unreachable.
	 *
	 * @since 0.2.0
	 *
	 * @var RememberingGateway
	 */
	private RememberingGateway $provider;

	/**
	 * The refund service over that gateway.
	 *
	 * @since 0.2.0
	 *
	 * @var RefundService
	 */
	private RefundService $service;

	/**
	 * Builds the gateway and the service over it.
	 *
	 * @since 0.2.0
	 */
	public function set_up(): void {
		parent::set_up();

		$this->provider = new RememberingGateway( new StubGateway() );
		$this->service  = $this->refundsOver( $this->db, $this->ids, $this->provider );
	}

	/**
	 * Tests that an approval for the refund's open claim records the refund as its own answer would: the money, the document, the claim recorded, and nothing asked of the gateway.
	 *
	 * @since 0.2.0
	 */
	public function test_an_approval_for_an_open_claim_records_the_refund(): void {
		list( $order, $intent ) = $this->placePaid( self::order() );
		list( $tee )            = $this->lineUuids( $order->id );
		$uuid                   = $this->openClaim( $order->uuid, $tee, $this->provider, $this->service );
		$asked                  = array( $this->provider->refundsMade(), $this->provider->queries );

		$outcome = $this->providerSays( $uuid, $this->claimedResult( $intent, $uuid, Outcome::Approved ) );
		$ledger  = $this->refundLedgerRows( $order->id );

		$this->assertSame( array( ProviderRefundKind::Recorded, $uuid, (int) $ledger[0]['id'], null ), array( $outcome->kind, $outcome->claimUuid, $outcome->transactionId, $outcome->reason ) );
		$this->assertSame( array( array( 'stub-re-' . $uuid, 'approved', '1' ) ), self::moneyOf( $ledger ) );
		$this->assertCount( 1, $this->refundRows( $order->id ), 'The refund\'s document.' );
		$this->assertSame( array( 'recorded', (string) $ledger[0]['id'] ), $this->claimEnd( $uuid ) );
		$this->assertSame( '0', (string) $this->orderRow( $order->id )['has_unreconciled_money'] );
		$this->assertSame( $asked, array( $this->provider->refundsMade(), $this->provider->queries ), 'The gateway was asked nothing.' );
	}

	/**
	 * Tests that an approval of another amount than the open claim asked is kept for a person, the mismatch stated: the row applied to nothing, the claim unreconciled naming it, the order flagged, and no document.
	 *
	 * Planted violation: in RefundService::keptReason(), drop the comparison of the amounts: the
	 * refund's own answer keeps the money under `payment_unrecorded`, and the mismatch is not stated.
	 *
	 * @since 0.2.0
	 */
	public function test_an_approval_of_another_amount_for_an_open_claim_is_kept_for_a_person(): void {
		list( $order, $intent ) = $this->placePaid( self::order() );
		list( $tee )            = $this->lineUuids( $order->id );
		$uuid                   = $this->openClaim( $order->uuid, $tee, $this->provider, $this->service );

		$outcome = $this->providerSays( $uuid, $this->claimedResult( $intent, $uuid, Outcome::Approved, 100 ) );
		$ledger  = $this->refundLedgerRows( $order->id );

		$this->assertSame( array( ProviderRefundKind::Unreconciled, (int) $ledger[0]['id'], PaymentService::AMOUNT_MISMATCH ), array( $outcome->kind, $outcome->transactionId, $outcome->reason ) );
		$this->assertSame( array( array( 'stub-re-' . $uuid, 'approved', '0' ) ), self::moneyOf( $ledger ) );
		$this->assertSame( array( 'unreconciled', (string) $ledger[0]['id'] ), $this->claimEnd( $uuid ) );
		$this->assertSame( array(), $this->refundRows( $order->id ) );
		$this->assertSame( '1', (string) $this->orderRow( $order->id )['has_unreconciled_money'] );
	}

	/**
	 * Tests that a claim whose request now works out to another refund, as a claim changed outside the refund service does, is ended rather than thrown: an approval kept for a person, `external_refund`, the order flagged, and a decline ending the claim declined.
	 *
	 * Planted violation: in RefundService::planOfClaim(), work the delivery's refund out as a
	 * person's settlement does (`$delivered` false): the delivery throws, and its receipt would stay
	 * undecided.
	 *
	 * @since 0.2.0
	 */
	public function test_a_claim_worked_out_to_another_refund_is_ended_not_thrown(): void {
		list( $approved, $approvedIntent ) = $this->placePaid( self::order() );
		list( $approvedTee )               = $this->lineUuids( $approved->id );
		$keptClaim                         = $this->openClaim( $approved->uuid, $approvedTee, $this->provider, $this->service );
		list( $declined, $declinedIntent ) = $this->placePaid( self::order() );
		list( $declinedTee )               = $this->lineUuids( $declined->id );
		$declinedClaim                     = $this->openClaim( $declined->uuid, $declinedTee, $this->provider, $this->service );

		// Each claim now asks for two units: what it asked works out to another refund.
		$this->db->execute( 'UPDATE %i SET quantity = 2', $this->table( RefundClaimTables::CLAIM_LINES ) );

		$kept   = $this->providerSays( $keptClaim, $this->claimedResult( $approvedIntent, $keptClaim, Outcome::Approved ) );
		$ledger = $this->refundLedgerRows( $approved->id );

		$this->assertSame( array( ProviderRefundKind::Unreconciled, $keptClaim, (int) $ledger[0]['id'], PaymentService::EXTERNAL_REFUND ), array( $kept->kind, $kept->claimUuid, $kept->transactionId, $kept->reason ) );
		$this->assertSame( array( array( 'stub-re-' . $keptClaim, 'approved', '0' ) ), self::moneyOf( $ledger ) );
		$this->assertSame( array( 'unreconciled', (string) $ledger[0]['id'] ), $this->claimEnd( $keptClaim ) );
		$this->assertSame( array( 'on_hold', '1' ), array( $this->orderRow( $approved->id )['status'], (string) $this->orderRow( $approved->id )['has_unreconciled_money'] ) );
		$this->assertSame( array(), $this->refundRows( $approved->id ) );

		$decline = $this->providerSays( $declinedClaim, $this->claimedResult( $declinedIntent, $declinedClaim, Outcome::Declined ) );
		$ledger  = $this->refundLedgerRows( $declined->id );

		$this->assertSame( array( ProviderRefundKind::Declined, (int) $ledger[0]['id'] ), array( $decline->kind, $decline->transactionId ) );
		$this->assertSame( array( array( 'stub-re-' . $declinedClaim, 'declined', '1' ) ), self::moneyOf( $ledger ) );
		$this->assertSame( array( 'declined', (string) $ledger[0]['id'] ), $this->claimEnd( $declinedClaim ) );
	}

	/**
	 * Tests that money a person has not reconciled does not hold the provider's word back: the claim's own approval is recorded, as the delivery is itself money landing.
	 *
	 * Planted violation: in RefundService::plan(), refuse money a person has not reconciled for a
	 * claim being ended too: the delivery is refused `payment.unreconciled`.
	 *
	 * @since 0.2.0
	 */
	public function test_money_a_person_has_not_reconciled_does_not_hold_the_providers_word_back(): void {
		list( $order, $intent ) = $this->placePaid( self::order() );
		list( $tee )            = $this->lineUuids( $order->id );
		$uuid                   = $this->openClaim( $order->uuid, $tee, $this->provider, $this->service );

		$this->keepUnappliedRefund( $intent, 500, 'EUR', 'external-re-1' );

		$outcome = $this->providerSays( $uuid, $this->claimedResult( $intent, $uuid, Outcome::Approved ) );

		$this->assertSame( ProviderRefundKind::Recorded, $outcome->kind );
		$this->assertSame( array( array( 'external-re-1', 'approved', '0' ), array( 'stub-re-' . $uuid, 'approved', '1' ) ), self::moneyOf( $this->refundLedgerRows( $order->id ) ) );
		$this->assertCount( 1, $this->refundRows( $order->id ) );
		$this->assertSame( 'recorded', $this->claimEnd( $uuid )[0] );
	}

	/**
	 * Tests that an approval of a refund the gateway's capability matrix no longer declares is kept for a person under that reason, never thrown.
	 *
	 * The claim was made while the gateway declared partial refunds; the provider's word arrives
	 * once its matrix declares only the required operations.
	 *
	 * Planted violation: in RefundService::keptReason(), drop the matrix's check: the refund is
	 * recorded though the gateway does not declare it.
	 *
	 * @since 0.2.0
	 */
	public function test_a_refund_the_gateway_no_longer_declares_is_kept_for_a_person(): void {
		list( $order, $intent ) = $this->placePaid( self::order() );
		list( $tee )            = $this->lineUuids( $order->id );
		$uuid                   = $this->openClaim( $order->uuid, $tee, $this->provider, $this->service );
		$currency               = (string) $this->db->fetchValue( 'SELECT currency FROM %i WHERE uuid = %s', $this->table( RefundClaimTables::CLAIMS ), $uuid );
		$narrowed               = $this->refundsOver( $this->db, $this->ids, new DeclaredGateway( DeclaredGateway::descriptor( StubGateway::ID, array( Mode::Test ), array(), DeclaredGateway::matrix( array( $currency ), Operations::REQUIRED ) ) ) );

		$outcome = $narrowed->recordProviderRefund( $uuid, $this->claimedResult( $intent, $uuid, Outcome::Approved ), Actor::system( 'webhook', 0 ) );
		$ledger  = $this->refundLedgerRows( $order->id );

		$this->assertSame( array( ProviderRefundKind::Unreconciled, PaymentService::OPERATION_UNSUPPORTED, (int) $ledger[0]['id'] ), array( $outcome->kind, $outcome->reason, $outcome->transactionId ) );
		$this->assertSame( array( array( 'stub-re-' . $uuid, 'approved', '0' ) ), self::moneyOf( $ledger ) );
		$this->assertSame( array( 'unreconciled', (string) $ledger[0]['id'] ), $this->claimEnd( $uuid ) );
		$this->assertSame( '1', (string) $this->orderRow( $order->id )['has_unreconciled_money'] );
		$this->assertSame( array(), $this->refundRows( $order->id ) );
	}

	/**
	 * Tests that a decline for the refund's open claim ends it declined, with the decline's row, and that a result moving no money leaves the claim open.
	 *
	 * @since 0.2.0
	 */
	public function test_a_decline_for_an_open_claim_declines_it_and_a_pending_result_changes_nothing(): void {
		list( $order, $intent ) = $this->placePaid( self::order() );
		list( $tee )            = $this->lineUuids( $order->id );
		$uuid                   = $this->openClaim( $order->uuid, $tee, $this->provider, $this->service );

		$pending = $this->providerSays( $uuid, $this->claimedResult( $intent, $uuid, Outcome::Pending ) );

		$this->assertSame( array( ProviderRefundKind::Ignored, Outcome::Pending->value ), array( $pending->kind, $pending->reason ) );
		$this->assertSame( array( 'claimed', '' ), $this->claimEnd( $uuid ), 'A pending refund leaves the claim open.' );

		$declined = $this->providerSays( $uuid, $this->claimedResult( $intent, $uuid, Outcome::Declined ) );
		$ledger   = $this->refundLedgerRows( $order->id );

		$this->assertSame( array( ProviderRefundKind::Declined, (int) $ledger[0]['id'] ), array( $declined->kind, $declined->transactionId ) );
		$this->assertSame( array( array( 'stub-re-' . $uuid, 'declined', '1' ) ), self::moneyOf( $ledger ) );
		$this->assertSame( array( 'declined', (string) $ledger[0]['id'] ), $this->claimEnd( $uuid ) );
		$this->assertSame( array(), $this->refundRows( $order->id ) );
	}

	/**
	 * Tests that the claim's own answer delivered after the refund recorded it is a duplicate, and that a decline then changes nothing.
	 *
	 * Planted violation: in RefundService::keepProviderRefund(), answer every result kept
	 * `Unexpected`: the duplicate reads as money nobody asked for.
	 *
	 * @since 0.2.0
	 */
	public function test_the_claims_own_answer_delivered_again_is_a_duplicate(): void {
		list( $order, $intent ) = $this->placePaid( self::order() );
		list( $tee )            = $this->lineUuids( $order->id );
		$refund                 = $this->refund( $order->uuid, array( $tee => 1 ), false, $this->service );
		$before                 = $this->refundLedgerRows( $order->id );

		$again   = $this->providerSays( $refund->uuid, $this->claimedResult( $intent, $refund->uuid, Outcome::Approved ) );
		$decline = $this->providerSays( $refund->uuid, $this->claimedResult( $intent, $refund->uuid, Outcome::Declined, 0, 'stub-re-decline' ) );

		$this->assertSame( array( ProviderRefundKind::Duplicate, (int) $before[0]['id'] ), array( $again->kind, $again->transactionId ) );
		$this->assertSame( array( ProviderRefundKind::Ignored, ProviderRefundOutcome::CLAIM_ENDED ), array( $decline->kind, $decline->reason ) );
		$this->assertSame( $before, $this->refundLedgerRows( $order->id ), 'Nothing reached the ledger.' );
		$this->assertCount( 1, $this->refundRows( $order->id ) );
		$this->assertSame( '0', (string) $this->orderRow( $order->id )['has_unreconciled_money'] );
	}

	/**
	 * Tests that an approval no open claim accounts for is money kept for a person, the order flagged and parked, once: with no uuid, an unknown one, another payment's claim, and the refund's own claim already recorded with another refund.
	 *
	 * Planted violation: in RefundService::keepProviderRefund(), apply the result through
	 * PaymentService::applyGatewayResult(): the money path refuses it as a refund of the store's
	 * with no base share, and nothing is kept.
	 *
	 * @since 0.2.0
	 */
	public function test_money_no_open_claim_accounts_for_is_kept_for_a_person_once(): void {
		list( $otherOrder )     = $this->placePaid( self::order() );
		list( $otherTee )       = $this->lineUuids( $otherOrder->id );
		$othersClaim            = $this->openClaim( $otherOrder->uuid, $otherTee, $this->provider, $this->service );
		list( $order, $intent ) = $this->placePaid( self::order() );
		list( $tee )            = $this->lineUuids( $order->id );
		$recorded               = $this->refund( $order->uuid, array( $tee => 1 ), false, $this->service );
		$cases                  = array(
			'no uuid'          => null,
			'an unknown uuid'  => '01928c3e-0000-7000-8000-00000000beef',
			'another\'s claim' => $othersClaim,
			'an ended claim'   => $recorded->uuid,
		);
		$made                   = 0;

		foreach ( $cases as $case => $refundUuid ) {
			++$made;

			$object = 'external-re-' . $made;
			$result = self::stubResult( $intent, Operation::Refund, Outcome::Approved, 500, 'EUR', $object );
			$first  = $this->providerSays( $refundUuid, $result );
			$row    = (string) $this->db->fetchValue( 'SELECT id FROM %i WHERE provider_object_id = %s', $this->table( PaymentTables::TRANSACTIONS ), $object );

			$this->assertSame( array( ProviderRefundKind::Unexpected, PaymentService::EXTERNAL_REFUND, $row ), array( $first->kind, $first->reason, (string) $first->transactionId ), $case );
			$this->assertSame( array( ProviderRefundKind::Duplicate, $row ), array( $this->providerSays( $refundUuid, $result )->kind, $row ), $case . ', again' );
		}

		$this->assertSame( array( '1', '0', '0', '0', '0' ), array_column( self::moneyOf( $this->refundLedgerRows( $order->id ) ), 2 ), 'One refund of the store\'s applied, and each of the four kept, once.' );
		$this->assertSame( array( 'on_hold', '1' ), array( $this->orderRow( $order->id )['status'], (string) $this->orderRow( $order->id )['has_unreconciled_money'] ), 'The order is flagged and parked for a person.' );
		$this->assertSame( array( 'claimed', '' ), $this->claimEnd( $othersClaim ), 'Another payment\'s claim is left as it stands.' );
		$this->assertCount( 1, $this->refundRows( $order->id ), 'No document but the store\'s own refund.' );
	}

	/**
	 * Tests that the provider's decline of a refund the ledger holds as made is kept for a person, once: the decline in a row of its own applied to nothing, the order flagged and parked, and the refund's figures left for the person to put right.
	 *
	 * Planted violation: in RefundService::keepProviderRefund(), drop the reversal: the decline is
	 * ignored, and nobody is told that the refund the store counts was not made.
	 *
	 * @since 0.2.0
	 */
	public function test_a_decline_of_a_refund_the_ledger_holds_as_made_is_kept_for_a_person(): void {
		list( $order, $intent ) = $this->placePaid( self::order() );
		list( $tee )            = $this->lineUuids( $order->id );
		$refund                 = $this->refund( $order->uuid, array( $tee => 1 ), false, $this->service );
		$refunded               = (string) $this->orderRow( $order->id )['refunded_minor'];
		$reversal               = $this->claimedResult( $intent, $refund->uuid, Outcome::Declined );

		$first  = $this->providerSays( $refund->uuid, $reversal );
		$again  = $this->providerSays( null, $reversal );
		$ledger = $this->refundLedgerRows( $order->id );

		$this->assertSame( array( ProviderRefundKind::Reversed, PaymentService::REFUND_REVERSED, (int) $ledger[1]['id'] ), array( $first->kind, $first->reason, $first->transactionId ) );
		$this->assertSame( ProviderRefundKind::Duplicate, $again->kind, 'The reversal delivered again, with no uuid, is a duplicate.' );
		$this->assertSame( array( array( 'stub-re-' . $refund->uuid, 'approved', '1' ), array( 'stub-re-' . $refund->uuid, 'declined', '0' ) ), self::moneyOf( $ledger ) );
		$this->assertSame( array( 'on_hold', '1', $refunded ), array( (string) $this->orderRow( $order->id )['status'], (string) $this->orderRow( $order->id )['has_unreconciled_money'], (string) $this->orderRow( $order->id )['refunded_minor'] ) );
		$this->assertCount( 1, $this->refundRows( $order->id ), 'The refund\'s document stays, for the person to reconcile.' );
	}

	/**
	 * Tests that a result moving no money with no open claim changes nothing: a decline with no claim, and one for a claim that has ended.
	 *
	 * @since 0.2.0
	 */
	public function test_a_decline_no_open_claim_accounts_for_changes_nothing(): void {
		list( $order, $intent ) = $this->placePaid( self::order() );
		list( $tee )            = $this->lineUuids( $order->id );
		$recorded               = $this->refund( $order->uuid, array( $tee => 1 ), false, $this->service );
		$before                 = $this->refundLedgerRows( $order->id );
		$decline                = self::stubResult( $intent, Operation::Refund, Outcome::Declined, 500, 'EUR', 'external-re-declined' );

		$none  = $this->providerSays( null, $decline );
		$ended = $this->providerSays( $recorded->uuid, $decline );

		$this->assertSame( array( ProviderRefundKind::Ignored, null, ProviderRefundOutcome::NO_CLAIM ), array( $none->kind, $none->claimUuid, $none->reason ) );
		$this->assertSame( array( ProviderRefundKind::Ignored, $recorded->uuid, ProviderRefundOutcome::CLAIM_ENDED ), array( $ended->kind, $ended->claimUuid, $ended->reason ) );
		$this->assertSame( $before, $this->refundLedgerRows( $order->id ) );
		$this->assertSame( '0', (string) $this->orderRow( $order->id )['has_unreconciled_money'] );
	}

	/**
	 * Tests that a claim a person settled meets the provider's word as ended: the refund the person named is a duplicate, never a second row applied; one they did not name is kept for a person; and an approval of a claim declined on their word is kept for a person, the money having moved after all.
	 *
	 * @since 0.2.0
	 */
	public function test_a_claim_a_person_settled_meets_the_providers_word_as_ended(): void {
		list( $order, $intent ) = $this->placePaid( self::order() );
		list( $tee )            = $this->lineUuids( $order->id );
		$stated                 = $this->openClaim( $order->uuid, $tee, $this->provider, $this->service );
		$amount                 = (int) $this->db->fetchValue( 'SELECT amount_minor FROM %i WHERE uuid = %s', $this->table( RefundClaimTables::CLAIMS ), $stated );

		$this->provider->knows = false;
		$this->service->settleClaim( $stated, new ClaimStatement( true, self::NOTE, 're_stated', $amount ), $this->manager() );

		$named   = $this->providerSays( $stated, $this->claimedResult( $intent, $stated, Outcome::Approved, 0, 're_stated' ) );
		$unnamed = $this->providerSays( $stated, $this->claimedResult( $intent, $stated, Outcome::Approved ) );

		$this->assertSame( ProviderRefundKind::Duplicate, $named->kind, 'The refund the person named.' );
		$this->assertSame( array( ProviderRefundKind::Unexpected, PaymentService::EXTERNAL_REFUND ), array( $unnamed->kind, $unnamed->reason ), 'A refund the person did not name.' );
		$this->assertSame( array( array( 're_stated', 'approved', '1' ), array( 'stub-re-' . $stated, 'approved', '0' ) ), self::moneyOf( $this->refundLedgerRows( $order->id ) ), 'Never a second row applied.' );

		list( $other, $otherIntent ) = $this->placePaid( self::order() );
		list( $otherTee )            = $this->lineUuids( $other->id );
		$declined                    = $this->openClaim( $other->uuid, $otherTee, $this->provider, $this->service );

		$this->service->settleClaim( $declined, new ClaimStatement( false, self::NOTE ), $this->manager() );

		$after = $this->providerSays( $declined, $this->claimedResult( $otherIntent, $declined, Outcome::Approved ) );

		$this->assertSame( array( ProviderRefundKind::Unexpected, PaymentService::EXTERNAL_REFUND ), array( $after->kind, $after->reason ), 'The money moved after all.' );
		$this->assertSame( array( array( 'stub-re-' . $declined, 'approved', '0' ) ), self::moneyOf( $this->refundLedgerRows( $other->id ) ) );
		$this->assertSame( '1', (string) $this->orderRow( $other->id )['has_unreconciled_money'] );
	}

	/**
	 * Tests that a refund delivered after a clearance dated ahead of the database clock, as a clock that stepped back since leaves it, is dated after it: the flag goes up and the payment's refunds are held back, together.
	 *
	 * The provider's word keeps the money through the one writer of such rows, which locks the
	 * order, appends the row dated after the order's last clearance, and only then raises the flag.
	 *
	 * Planted violation: in PaymentService::keepUnapplied(), date the row by the database clock
	 * alone (`null` for the order's clearance): the delivered money is dated before the clearance,
	 * and the payment refunds with the flag up.
	 *
	 * @since 0.2.0
	 */
	public function test_a_refund_delivered_after_a_clearance_dated_ahead_of_the_clock_is_dated_after_it(): void {
		list( $order, $intent ) = $this->placePaid( self::order() );
		list( $tee )            = $this->lineUuids( $order->id );

		$this->providerSays( null, self::stubResult( $intent, Operation::Refund, Outcome::Approved, 500, 'EUR', 'external-re-1' ) );
		$this->ordersOver( $this->db, $this->ids )->clearUnreconciledMoney(
			array(
				'order_uuid' => $order->uuid,
				'note'       => self::NOTE,
			),
			$this->manager()
		);

		// The clearance, and the order's row with it, an hour ahead of the clock.
		$this->db->execute( 'UPDATE %i SET money_reconciled_at = money_reconciled_at + INTERVAL 1 HOUR, updated_at = updated_at + INTERVAL 1 HOUR WHERE id = %d', $this->table( OrderTables::ORDERS ), $order->id );

		$this->providerSays( null, self::stubResult( $intent, Operation::Refund, Outcome::Approved, 300, 'EUR', 'external-re-2' ) );

		$landed  = (string) $this->db->fetchValue( "SELECT created_at FROM %i WHERE provider_object_id = 'external-re-2'", $this->table( PaymentTables::TRANSACTIONS ) );
		$cleared = (string) $this->db->fetchValue( 'SELECT money_reconciled_at FROM %i WHERE id = %d', $this->table( OrderTables::ORDERS ), $order->id );
		$refused = null;

		try {
			$this->refund( $order->uuid, array( $tee => 1 ), false, $this->service );
		} catch ( CodedException $refusal ) {
			$refused = $refusal->errorCode()->value;
		}

		$this->assertSame( '1', (string) $this->orderRow( $order->id )['has_unreconciled_money'], 'The delivered money flags the order again.' );
		$this->assertSame( PaymentError::Unreconciled->value, $refused, sprintf( 'With the flag up, the money holds the refunds back: it is dated %1$s, the clearance %2$s.', $landed, $cleared ) );
		$this->assertGreaterThan( $cleared, $landed, 'The money is dated after the clearance.' );
	}

	/**
	 * Tests that the provider's word is refused inside a transaction, and for a result of another operation, before any statement.
	 *
	 * @since 0.2.0
	 */
	public function test_it_is_refused_inside_a_transaction_and_for_another_operation(): void {
		list( , $intent ) = $this->placePaid( self::order() );
		$refunded         = self::stubResult( $intent, Operation::Refund, Outcome::Approved, 500, 'EUR', 'external-re-1' );
		$captured         = self::stubResult( $intent, Operation::Capture, Outcome::Approved, 500, 'EUR', 'external-cap-1' );
		$refused          = array();

		$log = $this->captureQueries(
			function () use ( $refunded, $captured, &$refused ): void {
				foreach ( array(
					'inside'  => $refunded,
					'capture' => $captured,
				) as $case => $result ) {
					try {
						if ( 'inside' === $case ) {
							$this->db->transaction( fn() => $this->providerSays( null, $result ) );
						} else {
							$this->providerSays( null, $result );
						}
					} catch ( \LogicException $refusal ) {
						$refused[] = $case;
					}
				}
			}
		);

		$this->assertSame( array( 'inside', 'capture' ), $refused );
		$this->assertQueryCount( 0, $log->forTable( $this->table( PaymentTables::TRANSACTIONS ) ), 'Nothing reached the ledger' );
		$this->assertQueryCount( 0, $log->forTable( $this->table( RefundClaimTables::CLAIMS ) ), 'No claim was read' );
	}

	/**
	 * Delivers the provider's word of a refund to the refund service, as the webhook receiver does.
	 *
	 * @since 0.2.0
	 *
	 * @param string|null                              $refundUuid The refund uuid the provider echoes, or null.
	 * @param \SEOCart\Contracts\Payment\GatewayResult $result     The provider's result.
	 * @return ProviderRefundOutcome What it came to.
	 */
	private function providerSays( ?string $refundUuid, \SEOCart\Contracts\Payment\GatewayResult $result ): ProviderRefundOutcome {
		return $this->service->recordProviderRefund( $refundUuid, $result, Actor::system( 'webhook', 0 ) );
	}

	/**
	 * Reads how a claim ended: its state and the ledger row it names, '' for none.
	 *
	 * @since 0.2.0
	 *
	 * @param string $uuid The claim.
	 * @return array{0: string, 1: string} The state and the row.
	 */
	private function claimEnd( string $uuid ): array {
		$claim = (array) $this->db->fetchRow( 'SELECT state, transaction_id FROM %i WHERE uuid = %s', $this->table( RefundClaimTables::CLAIMS ), $uuid );

		return array( (string) $claim['state'], (string) $claim['transaction_id'] );
	}

	/**
	 * Lists the ledger's refund rows as their provider object, result, and whether they were applied.
	 *
	 * @since 0.2.0
	 *
	 * @param list<array<string, mixed>> $ledger The rows, as refundLedgerRows() reads them.
	 * @return list<array{0: string, 1: string, 2: string}> The rows.
	 */
	private static function moneyOf( array $ledger ): array {
		return array_map( static fn( array $row ): array => array( (string) $row['provider_object_id'], (string) $row['result'], (string) $row['applied'] ), $ledger );
	}

	/**
	 * Builds the fixture order: three tees, taxed, shipped.
	 *
	 * @since 0.2.0
	 *
	 * @return \SEOCart\Order\Domain\NewOrder The document.
	 */
	private static function order(): \SEOCart\Order\Domain\NewOrder {
		return RefundOrders::priced( array( RefundOrders::line( 'tee', '12.34', 3, 'standard' ) ) );
	}
}
