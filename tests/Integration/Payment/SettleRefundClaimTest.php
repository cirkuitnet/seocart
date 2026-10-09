<?php
/**
 * Tests settling a refund claim on a person's say-so: the gateway asked once more decides when it can, the statement only when it cannot
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Payment;

use SEOCart\Contracts\Payment\GatewayResult;
use SEOCart\Contracts\Payment\Operation;
use SEOCart\Contracts\Payment\Outcome;
use SEOCart\Payment\Application\ClaimStatement;
use SEOCart\Payment\Application\PaymentError;
use SEOCart\Payment\Application\RefundService;
use SEOCart\Payment\Application\SettledClaim;
use SEOCart\Payment\Infrastructure\Gateway\StubGateway;
use SEOCart\Payment\Infrastructure\PaymentTables;
use SEOCart\Payment\Infrastructure\RefundClaimTables;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Platform\Authorization\AuthorizationError;
use SEOCart\Support\Error\CodedException;
use SEOCart\Support\Money;
use SEOCart\Tests\Support\Doubles\RememberingGateway;
use SEOCart\Tests\Support\Payment\RefundOrders;
use SEOCart\Tests\Support\Payment\RefundTestCase;

/**
 * A claim the gateway could not account for is settled by a person: the gateway is asked once more, and a refund it made or declined is recorded as it says, whatever the person states; only when it cannot say, or cannot be asked, does the statement decide.
 *
 * Each claim is left open as a real one is: the refund was asked while the gateway could not be
 * reached, so nothing reached the ledger. The gateway's later answer is then what the test gives
 * it: a refund it made under the claim's uuid, one of another amount, a decline, none at all
 * (`not_found`), no answer (`cannot_say`), or no gateway (`unavailable`).
 *
 * Planted violations, each shown red and removed:
 *
 * - in RefundService::settleClaim(), skip the gateway: take askOnceMore()'s answer as
 *   `cannot_say`; the refund the gateway made is declined on the person's word, and the ledger
 *   misses the money;
 * - in MysqlRefundRepository::DECLINED_REFUNDS, count the ledger's declined rows again: after a
 *   person's `not_refunded`, the same units asked again are the declined claim's refund, refused
 *   for good;
 * - in RefundService::plan(), drop requireClaimedRefund(): a claim whose lines were changed is
 *   answered `payment.refund_unresolved` instead of being reported as the programming error it is;
 * - in RefundService::settleClaim(), drop the capability check: an order agent settles the claim;
 * - in RefundService::plan(), refuse money a person has not reconciled when a claim is settled too:
 *   a claim of a payment that holds such money can no longer be settled;
 * - in RefundService::settleClaim(), skip the read of the ledger's key: a statement naming another
 *   refund's provider refund ends the claim unreconciled, with no row and no flag, for good.
 *
 * @since 0.2.0
 */
final class SettleRefundClaimTest extends RefundTestCase {

	/**
	 * Why the person says so, in every statement of the test.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const NOTE = 'The provider\'s dashboard shows what became of the refund.';

	/**
	 * The gateway the claims were asked of: it remembers what it made, and can be made unreachable or unable to say.
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
	 * Tests that a refund the gateway made under the claim's uuid is recorded as the gateway says, though the person states it was not made: the refund, its document and the claim, with the settlement noted and its event, and no flag.
	 *
	 * @since 0.2.0
	 */
	public function test_the_gateway_decides_a_refund_it_made_over_a_statement_that_it_was_not(): void {
		list( $order, $intent ) = $this->placePaid( self::order() );
		list( $tee )            = $this->lineUuids( $order->id );
		$uuid                   = $this->openClaim( $order->uuid, $tee, $this->provider, $this->service );

		$this->gatewayMade( $intent, $uuid, Outcome::Approved );

		$settled = $this->settle( $uuid, self::notRefunded() );
		$ledger  = $this->refundLedgerRows( $order->id );

		$this->assertSame( array( 'recorded', SettledClaim::BY_GATEWAY, SettledClaim::APPROVED, false ), array( $settled->state->value, $settled->decidedBy, $settled->gatewayReading, $settled->hasUnreconciledMoney ) );
		$this->assertSame( array( array( 'stub-re-' . $uuid, 'approved', '1' ) ), array_map( static fn( array $row ): array => array( (string) $row['provider_object_id'], (string) $row['result'], (string) $row['applied'] ), $ledger ), 'The refund the gateway made is on the ledger, applied.' );
		$this->assertCount( 1, $this->refundRows( $order->id ), 'Its document.' );
		$this->assertSame(
			array( 'recorded', (string) $ledger[0]['id'], ClaimStatement::NOT_REFUNDED, SettledClaim::APPROVED, (string) $this->manager()->userId(), self::NOTE ),
			array_values( $this->settlementOf( $uuid ) ),
			'The claim ended recorded, with the person\'s statement kept beside what the gateway said.'
		);
		$this->assertContains( 'payment:partially_refunded>partially_refunded:' . RefundService::SETTLED_REASON, $this->eventsOf( $order->id ) );
		$this->assertSame( '0', (string) $this->orderRow( $order->id )['has_unreconciled_money'] );
	}

	/**
	 * Tests that an approval of another amount than was claimed is left for a person: the money kept on the ledger applied to nothing, the claim unreconciled, the order flagged and parked.
	 *
	 * @since 0.2.0
	 */
	public function test_an_approval_of_another_amount_is_left_for_a_person(): void {
		list( $order, $intent ) = $this->placePaid( self::order() );
		list( $tee )            = $this->lineUuids( $order->id );
		$uuid                   = $this->openClaim( $order->uuid, $tee, $this->provider, $this->service );

		$this->gatewayMade( $intent, $uuid, Outcome::Approved, 1 );

		$settled = $this->settle( $uuid, self::refundedAs( 'stub-re-' . $uuid, 1234 ) );

		$this->assertSame( array( 'unreconciled', SettledClaim::BY_GATEWAY, SettledClaim::APPROVED, true ), array( $settled->state->value, $settled->decidedBy, $settled->gatewayReading, $settled->hasUnreconciledMoney ) );
		$this->assertSame( array( '0' ), array_map( static fn( array $row ): string => (string) $row['applied'], $this->refundLedgerRows( $order->id ) ), 'The money is kept, applied to nothing.' );
		$this->assertSame( array(), $this->refundRows( $order->id ), 'No document.' );
		$this->assertSame( array( '1', 'on_hold' ), array( (string) $this->orderRow( $order->id )['has_unreconciled_money'], (string) $this->orderRow( $order->id )['status'] ) );
	}

	/**
	 * Tests that a decline the gateway reports ends the claim declined, with its ledger row, and that the same units asked again are a new refund.
	 *
	 * @since 0.2.0
	 */
	public function test_a_decline_the_gateway_reports_ends_the_claim_and_the_next_ask_is_a_new_refund(): void {
		list( $order, $intent ) = $this->placePaid( self::order() );
		list( $tee )            = $this->lineUuids( $order->id );
		$uuid                   = $this->openClaim( $order->uuid, $tee, $this->provider, $this->service );

		$this->gatewayMade( $intent, $uuid, Outcome::Declined );

		$settled = $this->settle( $uuid, self::refundedAs( 'manual-re-1', 1234 ) );

		$this->assertSame( array( 'declined', SettledClaim::BY_GATEWAY, SettledClaim::DECLINED, false ), array( $settled->state->value, $settled->decidedBy, $settled->gatewayReading, $settled->hasUnreconciledMoney ) );
		$this->assertSame( array( 'declined' ), array_map( static fn( array $row ): string => (string) $row['result'], $this->refundLedgerRows( $order->id ) ), 'The decline is on the ledger.' );

		$again = $this->refund( $order->uuid, array( $tee => 1 ), false, $this->service );

		$this->assertNotSame( $uuid, $again->uuid, 'The same units asked again are a new refund.' );
		$this->assertCount( 1, $this->refundRows( $order->id ) );
	}

	/**
	 * Tests that, when the gateway cannot account for the refund, a person's statement that it was made records it as the gateway's approval would be, with the provider's refund and amount they name, by them, and flags the order without parking it.
	 *
	 * @since 0.2.0
	 *
	 * @dataProvider gatewaysThatCannotSay
	 *
	 * @param string $reading What the gateway says.
	 * @param bool   $knows   Whether it can say what became of a refund.
	 * @param bool   $answers Whether a question reaches it.
	 */
	public function test_a_refund_stated_made_is_recorded_on_the_persons_word_and_flags_the_order( string $reading, bool $knows, bool $answers ): void {
		list( $order ) = $this->placePaid( self::order() );
		list( $tee )   = $this->lineUuids( $order->id );
		$uuid          = $this->openClaim( $order->uuid, $tee, $this->provider, $this->service );
		$claimed       = (int) $this->claimOf( $uuid )['amount_minor'];
		$status        = (string) $this->orderRow( $order->id )['status'];

		$this->provider->knows          = $knows;
		$this->provider->answersQueries = $answers;

		$settled = $this->settle( $uuid, self::refundedAs( 'manual-re-1', $claimed ) );
		$ledger  = $this->refundLedgerRows( $order->id );

		$this->assertSame( array( 'recorded', SettledClaim::BY_STATEMENT, $reading, true ), array( $settled->state->value, $settled->decidedBy, $settled->gatewayReading, $settled->hasUnreconciledMoney ) );
		$this->assertSame( array( array( 'manual-re-1', 'approved', '1' ) ), array_map( static fn( array $row ): array => array( (string) $row['provider_object_id'], (string) $row['result'], (string) $row['applied'] ), $ledger ), 'The stated refund is on the ledger, applied.' );
		$this->assertSame( (string) $this->manager()->userId(), (string) $this->db->fetchValue( 'SELECT actor_id FROM %i WHERE id = %d', $this->table( PaymentTables::TRANSACTIONS ), (int) $ledger[0]['id'] ), 'By the person who stated it.' );
		$this->assertCount( 1, $this->refundRows( $order->id ), 'Its document.' );
		$this->assertSame( array( '1', $status ), array( (string) $this->orderRow( $order->id )['has_unreconciled_money'], (string) $this->orderRow( $order->id )['status'] ), 'The order is flagged, and stays where it was.' );
		$this->assertSame(
			array( RefundService::SETTLED_REASON, RefundService::SETTLED_BY_STATEMENT ),
			array_slice( array_map( static fn( string $event ): string => substr( $event, (int) strrpos( $event, ':' ) + 1 ), $this->eventsOf( $order->id ) ), -2 ),
			'The settlement, then the flag, are the order\'s last events.'
		);
		$this->assertSame( ClaimStatement::REFUNDED, $this->settlementOf( $uuid )['statement'] );
	}

	/**
	 * Tests that, when the gateway cannot account for the refund, a person's statement that it was not made ends the claim declined with no ledger row, and that the same units asked again are a new refund.
	 *
	 * @since 0.2.0
	 *
	 * @dataProvider gatewaysThatCannotSay
	 *
	 * @param string $reading What the gateway says.
	 * @param bool   $knows   Whether it can say what became of a refund.
	 * @param bool   $answers Whether a question reaches it.
	 */
	public function test_a_refund_stated_not_made_ends_the_claim_declined_and_the_next_ask_is_a_new_refund( string $reading, bool $knows, bool $answers ): void {
		list( $order ) = $this->placePaid( self::order() );
		list( $tee )   = $this->lineUuids( $order->id );
		$uuid          = $this->openClaim( $order->uuid, $tee, $this->provider, $this->service );

		$this->provider->knows          = $knows;
		$this->provider->answersQueries = $answers;

		$settled = $this->settle( $uuid, self::notRefunded() );

		$this->assertSame( array( 'declined', SettledClaim::BY_STATEMENT, $reading, false ), array( $settled->state->value, $settled->decidedBy, $settled->gatewayReading, $settled->hasUnreconciledMoney ) );
		$this->assertSame( array(), $this->refundLedgerRows( $order->id ), 'A person\'s statement is no gateway\'s decline: nothing on the ledger.' );
		$this->assertNull( $this->settlementOf( $uuid )['transaction_id'] );

		$this->provider->knows          = true;
		$this->provider->answersQueries = true;

		$again = $this->refund( $order->uuid, array( $tee => 1 ), false, $this->service );

		$this->assertNotSame( $uuid, $again->uuid, 'The same units asked again are a new refund.' );
		$this->assertCount( 1, $this->refundRows( $order->id ) );
	}

	/**
	 * Returns the gateways that cannot account for a refund: one that made none under the claim's uuid, one that cannot say, and one a question does not reach.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, array{0: string, 1: bool, 2: bool}> The reading, whether it knows, and whether a question reaches it.
	 */
	public static function gatewaysThatCannotSay(): array {
		return array(
			'a gateway that made no such refund'  => array( SettledClaim::NOT_FOUND, true, true ),
			'a gateway that cannot say'           => array( SettledClaim::CANNOT_SAY, false, true ),
			'a gateway a question does not reach' => array( SettledClaim::UNAVAILABLE, true, false ),
		);
	}

	/**
	 * Tests that a claim whose gateway's plugin was removed is settled on the person's statement: the gateway is `unavailable:not_registered`, and neither its absence nor its capability matrix holds the settlement back.
	 *
	 * @since 0.2.0
	 */
	public function test_a_claim_whose_gateway_is_gone_is_settled_on_the_statement(): void {
		list( $order ) = $this->placePaid( self::order() );
		list( $tee )   = $this->lineUuids( $order->id );
		$uuid          = $this->openClaim( $order->uuid, $tee, $this->provider, $this->service );
		$claimed       = (int) $this->claimOf( $uuid )['amount_minor'];

		// The gateway plugin the payment was taken by has been removed.
		$this->db->execute( "UPDATE %i SET gateway_id = 'gone'", $this->table( PaymentTables::INTENTS ) );

		$settled = $this->settle( $uuid, self::refundedAs( 'manual-re-1', $claimed ) );

		$this->assertSame( array( 'recorded', SettledClaim::BY_STATEMENT, SettledClaim::UNAVAILABLE . ':not_registered' ), array( $settled->state->value, $settled->decidedBy, $settled->gatewayReading ) );
		$this->assertSame( array( array( 'manual-re-1', 'approved', '1' ) ), array_map( static fn( array $row ): array => array( (string) $row['provider_object_id'], (string) $row['result'], (string) $row['applied'] ), $this->refundLedgerRows( $order->id ) ) );
		$this->assertSame( 0, $this->refundQueries() + count( $this->provider->made ), 'No gateway was asked anything.' );
	}

	/**
	 * Tests that money of the payment a person has not reconciled, which holds every refund back, does not hold the settlement back: settling the claim is reconciling.
	 *
	 * The money is a refund the provider made that no claim asked for, kept on the ledger applied to
	 * nothing, with the order flagged, after the claim was left open.
	 *
	 * @since 0.2.0
	 */
	public function test_money_a_person_has_not_reconciled_does_not_hold_the_settlement_back(): void {
		list( $order, $intent ) = $this->placePaid( self::order() );
		list( $tee )            = $this->lineUuids( $order->id );
		$uuid                   = $this->openClaim( $order->uuid, $tee, $this->provider, $this->service );

		$this->keepUnappliedRefund( $intent, 500, 'EUR', 'external-re-1' );

		$this->provider->knows = false;

		$settled = $this->settle( $uuid, self::notRefunded() );

		$this->assertSame( array( 'declined', SettledClaim::BY_STATEMENT, SettledClaim::CANNOT_SAY, true ), array( $settled->state->value, $settled->decidedBy, $settled->gatewayReading, $settled->hasUnreconciledMoney ), 'The claim is settled, and the money stays flagged for a person.' );
		$this->assertSame( array( 'declined', ClaimStatement::NOT_REFUNDED ), array( $this->settlementOf( $uuid )['state'], $this->settlementOf( $uuid )['statement'] ) );
	}

	/**
	 * Tests that a claim worked out again to another refund than it claimed, because a row was changed outside the refund service, is a programming error, and that nothing is written.
	 *
	 * @since 0.2.0
	 */
	public function test_a_claim_worked_out_to_another_refund_is_a_programming_error(): void {
		list( $order ) = $this->placePaid( self::order() );
		list( $tee )   = $this->lineUuids( $order->id );
		$uuid          = $this->openClaim( $order->uuid, $tee, $this->provider, $this->service );

		$this->db->execute( 'UPDATE %i SET quantity = 2', $this->table( RefundClaimTables::CLAIM_LINES ) );

		try {
			$this->settle( $uuid, self::notRefunded() );
			$this->fail( 'The settlement went on.' );
		} catch ( \LogicException $changed ) {
			$this->assertStringContainsString( $uuid, $changed->getMessage() );
		}

		$this->assertSame( 'claimed', $this->settlementOf( $uuid )['state'], 'The claim is left as it was.' );
	}

	/**
	 * Tests that the settlement refuses, before any statement, a user who may not override what the plugin knows of the money, a note with a card number, and a statement that cannot settle a claim.
	 *
	 * @since 0.2.0
	 */
	public function test_the_settlement_refuses_before_any_statement(): void {
		list( $order ) = $this->placePaid( self::order() );
		list( $tee )   = $this->lineUuids( $order->id );
		$uuid          = $this->openClaim( $order->uuid, $tee, $this->provider, $this->service );

		$cases = array(
			'an order agent'                       => array( $this->agent(), self::notRefunded(), AuthorizationError::Denied->value, null ),
			'a note with a card number'            => array( $this->manager(), new ClaimStatement( false, 'Customer paid with 4111 1111 1111 1111.' ), PaymentError::RefundNoteRejected->value, null ),
			'a note of spaces'                     => array( $this->manager(), new ClaimStatement( false, '  ' ), PaymentError::RefundStatementIncomplete->value, ClaimStatement::INCOMPLETE ),
			'made, with no provider refund'        => array( $this->manager(), new ClaimStatement( true, self::NOTE, null, 1234 ), PaymentError::RefundStatementIncomplete->value, ClaimStatement::INCOMPLETE ),
			'made, with no amount'                 => array( $this->manager(), new ClaimStatement( true, self::NOTE, 'manual-re-1' ), PaymentError::RefundStatementIncomplete->value, ClaimStatement::INCOMPLETE ),
			'not made, naming a provider refund'   => array( $this->manager(), new ClaimStatement( false, self::NOTE, 'manual-re-1' ), PaymentError::RefundStatementIncomplete->value, ClaimStatement::CONTRADICTORY ),
			'a provider refund with a card number' => array( $this->manager(), self::refundedAs( 're_4111111111111111', 1234 ), PaymentError::RefundStatementIncomplete->value, ClaimStatement::CARD_NUMBER ),
			'a note of 501 characters'             => array( $this->manager(), new ClaimStatement( false, str_repeat( 'x', 501 ) ), PaymentError::RefundStatementIncomplete->value, ClaimStatement::NOTE_TOO_LONG ),
		);

		foreach ( $cases as $case => list( $actor, $statement, $code, $problem ) ) {
			$sent = $this->captureQueries(
				function () use ( $uuid, $statement, $actor, $code, $problem, $case ): void {
					try {
						$this->settle( $uuid, $statement, $actor );
						$this->fail( "{$case}: the settlement went on." );
					} catch ( CodedException $refused ) {
						$this->assertSame( array( $code, $problem ), array( $refused->errorCode()->value, $refused->context()['problem'] ?? null ), $case );
					}
				}
			)->matching( self::STATEMENTS );

			$this->assertQueryCount( 0, $sent, $case );
		}

		$this->assertSame( array( 'claimed', null ), array( $this->settlementOf( $uuid )['state'], $this->settlementOf( $uuid )['statement'] ), 'The claim is left as it was.' );
	}

	/**
	 * Tests that a claim that is not there is refused, and so is one that has ended, with how it ended.
	 *
	 * @since 0.2.0
	 */
	public function test_a_claim_not_there_or_ended_is_refused(): void {
		list( $order ) = $this->placePaid( self::order() );
		list( $tee )   = $this->lineUuids( $order->id );
		$uuid          = $this->openClaim( $order->uuid, $tee, $this->provider, $this->service );

		$this->settle( $uuid, self::notRefunded() );

		foreach ( array(
			'0192a4b3-7c5d-7e8f-9a0b-1c2d3e4f5a6c' => array( PaymentError::RefundClaimNotFound->value, array( 'refund_uuid' => '0192a4b3-7c5d-7e8f-9a0b-1c2d3e4f5a6c' ) ),
			$uuid                                  => array( PaymentError::RefundClaimEnded->value, array( 'state' => 'declined' ) ),
		) as $claim => $refusal ) {
			try {
				$this->settle( (string) $claim, self::notRefunded() );
				$this->fail( 'The settlement went on.' );
			} catch ( CodedException $refused ) {
				$this->assertSame( $refusal, array( $refused->errorCode()->value, $refused->context() ) );
			}
		}
	}

	/**
	 * Tests that a statement naming a provider's refund the ledger holds already, another refund's, is refused before the gateway is asked, with nothing written, and that the claim is then still a person's to settle.
	 *
	 * Recorded for this claim, that refund would meet the ledger's key, and the claim would end
	 * unreconciled with no ledger row and no flag: the same units refused for good, with nothing
	 * a person could settle or clear.
	 *
	 * @since 0.2.0
	 */
	public function test_a_statement_naming_a_refund_the_ledger_holds_is_refused_before_the_gateway_is_asked(): void {
		list( $order ) = $this->placePaid( self::order() );
		list( $tee )   = $this->lineUuids( $order->id );

		$this->refund( $order->uuid, array( $tee => 1 ), false, $this->service );

		$held    = (string) $this->refundLedgerRows( $order->id )[0]['provider_object_id'];
		$uuid    = $this->openClaim( $order->uuid, $tee, $this->provider, $this->service );
		$claimed = (int) $this->claimOf( $uuid )['amount_minor'];

		$this->provider->knows = false;

		try {
			$this->settle( $uuid, self::refundedAs( $held, $claimed ) );
			$this->fail( 'The settlement went on.' );
		} catch ( CodedException $refused ) {
			$this->assertSame( array( PaymentError::RefundStatementIncomplete->value, ClaimStatement::ALREADY_RECORDED ), array( $refused->errorCode()->value, $refused->context()['problem'] ?? null ) );
		}

		$this->assertSame( 0, $this->provider->queries, 'The gateway was not asked.' );
		$this->assertSame( array( 'claimed', null ), array( $this->settlementOf( $uuid )['state'], $this->settlementOf( $uuid )['statement'] ), 'The claim is left as it was.' );
		$this->assertCount( 1, $this->refundLedgerRows( $order->id ), 'Nothing reached the ledger.' );
		$this->assertSame( '0', (string) $this->orderRow( $order->id )['has_unreconciled_money'], 'The order is not flagged.' );
		$this->assertSame( 'declined', $this->settle( $uuid, self::notRefunded() )->state->value, 'The claim is still a person\'s to settle.' );
	}

	/**
	 * Tests that a statement that the refund was made, of another amount than was claimed, is left for a person as the gateway's approval of another amount is: the money kept on the ledger applied to nothing, the claim unreconciled naming that row, and the order flagged and parked.
	 *
	 * The statement may be the truth, the provider having given back another amount, so the money
	 * is kept for a person to reconcile rather than refused.
	 *
	 * @since 0.2.0
	 */
	public function test_a_statement_of_another_amount_is_left_for_a_person(): void {
		list( $order ) = $this->placePaid( self::order() );
		list( $tee )   = $this->lineUuids( $order->id );
		$uuid          = $this->openClaim( $order->uuid, $tee, $this->provider, $this->service );
		$claimed       = (int) $this->claimOf( $uuid )['amount_minor'];

		$this->provider->knows = false;

		$settled = $this->settle( $uuid, self::refundedAs( 'manual-re-9', $claimed - 100 ) );
		$ledger  = $this->refundLedgerRows( $order->id );

		$this->assertSame( array( 'unreconciled', SettledClaim::BY_STATEMENT, SettledClaim::CANNOT_SAY, true ), array( $settled->state->value, $settled->decidedBy, $settled->gatewayReading, $settled->hasUnreconciledMoney ) );
		$this->assertSame( array( array( 'manual-re-9', 'approved', '0' ) ), array_map( static fn( array $row ): array => array( (string) $row['provider_object_id'], (string) $row['result'], (string) $row['applied'] ), $ledger ), 'The stated money is kept, applied to nothing.' );
		$this->assertSame( array( 'unreconciled', (string) $ledger[0]['id'], ClaimStatement::REFUNDED ), array( $this->settlementOf( $uuid )['state'], (string) $this->settlementOf( $uuid )['transaction_id'], $this->settlementOf( $uuid )['statement'] ), 'The claim ended unreconciled, naming the row.' );
		$this->assertSame( array(), $this->refundRows( $order->id ), 'No document.' );
		$this->assertSame( array( '1', 'on_hold' ), array( (string) $this->orderRow( $order->id )['has_unreconciled_money'], (string) $this->orderRow( $order->id )['status'] ), 'The order is flagged and parked.' );
	}

	/**
	 * Has the gateway remember a refund it made under a claim's uuid: an approval, of the claimed amount or less, or a decline.
	 *
	 * @since 0.2.0
	 *
	 * @param \SEOCart\Payment\Domain\IntentRef $intent  The claim's intent.
	 * @param string                            $uuid    The claim's uuid.
	 * @param Outcome                           $outcome An approval or a decline.
	 * @param int                               $less    Optional. How much less than claimed it gave back. Default 0.
	 */
	private function gatewayMade( \SEOCart\Payment\Domain\IntentRef $intent, string $uuid, Outcome $outcome, int $less = 0 ): void {
		$claim = $this->claimOf( $uuid );

		$this->provider->made[ $uuid ][] = new GatewayResult(
			StubGateway::ID,
			Operation::Refund,
			$outcome,
			$intent->uuid,
			Money::of( (int) $claim['amount_minor'] - $less, \SEOCart\Support\Currency::of( (string) $claim['currency'] ) ),
			'stub-re-' . $uuid,
			(string) $this->intentRow( $intent->uuid )['provider_intent_id'],
			Outcome::Declined === $outcome ? StubGateway::REFUND_DECLINED : null
		);
	}

	/**
	 * Settles a claim through the test's refund service, as a store manager unless another is given.
	 *
	 * @since 0.2.0
	 *
	 * @param string         $uuid      The claim.
	 * @param ClaimStatement $statement What the person states.
	 * @param Actor|null     $actor     Optional. Who settles it. Default the manager.
	 * @return SettledClaim How it ended.
	 */
	private function settle( string $uuid, ClaimStatement $statement, ?Actor $actor = null ): SettledClaim {
		return $this->service->settleClaim( $uuid, $statement, $actor ?? $this->manager() );
	}

	/**
	 * Reads a claim's row.
	 *
	 * @since 0.2.0
	 *
	 * @param string $uuid The claim.
	 * @return array<string, mixed> The row.
	 */
	private function claimOf( string $uuid ): array {
		return (array) $this->db->fetchRow( 'SELECT * FROM %i WHERE uuid = %s', $this->table( RefundClaimTables::CLAIMS ), $uuid );
	}

	/**
	 * Reads how a claim ended and how a person settled it.
	 *
	 * @since 0.2.0
	 *
	 * @param string $uuid The claim.
	 * @return array{state: string, transaction_id: string|null, statement: string|null, gateway_reading: string|null, settled_by: string|null, settlement_note: string|null} The columns.
	 */
	private function settlementOf( string $uuid ): array {
		$claim = $this->claimOf( $uuid );

		return array(
			'state'           => (string) $claim['state'],
			'transaction_id'  => $claim['transaction_id'],
			'statement'       => $claim['statement'],
			'gateway_reading' => $claim['gateway_reading'],
			'settled_by'      => $claim['settled_by'],
			'settlement_note' => $claim['settlement_note'],
		);
	}

	/**
	 * Builds a statement that the provider never made the refund.
	 *
	 * @since 0.2.0
	 *
	 * @return ClaimStatement The statement.
	 */
	private static function notRefunded(): ClaimStatement {
		return new ClaimStatement( false, self::NOTE );
	}

	/**
	 * Builds a statement that the provider made the refund, naming it and what it gave back.
	 *
	 * @since 0.2.0
	 *
	 * @param string $providerRefundId The provider's refund.
	 * @param int    $amountMinor      What it gave back, in minor units.
	 * @return ClaimStatement The statement.
	 */
	private static function refundedAs( string $providerRefundId, int $amountMinor ): ClaimStatement {
		return new ClaimStatement( true, self::NOTE, $providerRefundId, $amountMinor );
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
