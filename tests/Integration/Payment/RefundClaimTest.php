<?php
/**
 * Tests a refund's claim: committed before the gateway is asked, ended once by the answer, and never a reason to ask the gateway for the refund twice
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Payment;

use SEOCart\Order\Domain\NewOrder;
use SEOCart\Payment\Application\PaymentError;
use SEOCart\Payment\Application\PaymentService;
use SEOCart\Payment\Domain\Gateway\GatewayUnavailable;
use SEOCart\Payment\Domain\Refund\ClaimState;
use SEOCart\Payment\Infrastructure\Gateway\StubGateway;
use SEOCart\Payment\Infrastructure\MysqlRefundRepository;
use SEOCart\Payment\Infrastructure\RefundClaimTables;
use SEOCart\Support\Error\CodedException;
use SEOCart\Tests\Support\Doubles\AnswerLosingGateway;
use SEOCart\Tests\Support\Doubles\BarrierTransactions;
use SEOCart\Tests\Support\Doubles\OvergivingGateway;
use SEOCart\Tests\Support\Doubles\RecordingGateway;
use SEOCart\Tests\Support\Doubles\RememberingGateway;
use SEOCart\Tests\Support\Doubles\ReplayingGateway;
use SEOCart\Tests\Support\Doubles\SequentialIdGenerator;
use SEOCart\Tests\Support\Payment\RefundOrders;
use SEOCart\Tests\Support\Payment\RefundTestCase;

/**
 * A refund claims itself under its intent's lock before the gateway is asked; a request for a refund already claimed asks the gateway what became of it, never for it again; a claim the gateway cannot account for waits for a person; another refund of the intent waits for the claim; every answer ends the claim once, naming only a ledger row of its own.
 *
 * Planted violations, each shown red and removed:
 *
 * - in RefundService::refund(), make the claim inside the transaction that records the answer:
 *   connection B sees no claim while the gateway is asked;
 * - in RefundService::askedBefore(), ask the gateway for the refund again instead of asking what
 *   became of it: a gateway that ignores the key gives the money back twice;
 * - in RefundService::plan(), name the refund without its count of declines: asked again after a
 *   decline, the refund is answered from the declined claim and the gateway is not asked again;
 * - in RefundService::askWhatBecameOf(), record a not-found as a decline: while the first request
 *   is still on its way, the same units asked again are a new refund, and the gateway gives the
 *   money back twice;
 * - in RefundService::claim(), drop the check of what the refund's uuid is named by: a refund
 *   claimed from figures another refund moved can never be reached again, and is never recorded;
 * - in RefundService::claim(), drop the check for money kept for a person: a refund read before
 *   another refund's money was kept is claimed and asked of the gateway;
 * - in RefundService::endWithout(), end a duplicate's claim by its outcome: a refund answered with
 *   another refund's decline ends `declined`, and doctor says nothing;
 * - in RefundService::endWithout(), end only a decline's claim: the claim of money kept for a
 *   person stays claimed;
 * - in RefundService::plan() and RefundService::claim(), drop the wait for another refund's open
 *   claim: a refund recorded meanwhile renames the first, and the gateway gives its money back
 *   twice;
 * - in RefundService::endWithout(), name a duplicate's ledger row: the claim answered with another
 *   refund's result names that refund's row;
 * - in MysqlRefundRepository::SETTLE_CLAIM, drop `AND state = 'claimed'`: an ended claim ends
 *   again;
 * - in MysqlPaymentRepository::UNSETTLED_REFUND_CLAIMS, drop `c.state = 'claimed'`, then the age
 *   condition: doctor reports a settled claim, then a young one.
 *
 * @since 0.1.0
 */
final class RefundClaimTest extends RefundTestCase {

	/**
	 * How the requests a test collects ended: their refusals, or how else they ended.
	 *
	 * @since 0.1.0
	 *
	 * @var list<CodedException|string>
	 */
	private array $outcomes = array();

	/**
	 * Tests that the claim is committed before the gateway is asked, which is asked at depth 0, and that the refund's transaction ends it with the ledger row.
	 *
	 * While the gateway gives the money back, connection B reads the claim: it is committed, with
	 * the refund's uuid, the amount asked and who asked.
	 *
	 * @since 0.1.0
	 */
	public function test_the_claim_is_committed_before_the_gateway_is_asked_and_ended_with_the_refund(): void {
		list( $order ) = $this->placePaid( self::order() );
		list( $tee )   = $this->lineUuids( $order->id );
		list( , $b )   = $this->secondOrders();
		$seen          = array();

		$this->gateway->during(
			function () use ( $b, $order, &$seen ): void {
				$seen = $b->fetchAll( 'SELECT uuid, state, amount_minor, actor_type, actor_id FROM %i WHERE order_id = %d', $this->table( RefundClaimTables::CLAIMS ), $order->id );
			}
		);

		$refund = $this->refund( $order->uuid, array( $tee => 1 ) );

		$this->assertSame(
			array(
				array(
					'uuid'         => $refund->uuid,
					'state'        => 'claimed',
					'amount_minor' => (string) $refund->total->minorUnits(),
					'actor_type'   => 'user',
					'actor_id'     => (string) $this->agent()->userId(),
				),
			),
			$seen,
			'Connection B saw the claim while the gateway was asked: it was committed before.'
		);
		$this->assertSame(
			array(
				array(
					'method' => 'refund',
					'depth'  => 0,
				),
			),
			$this->gateway->calls
		);
		$this->assertSame(
			array(
				'state'          => 'recorded',
				'transaction_id' => (string) $this->refundLedgerRows( $order->id )[0]['id'],
				'settled'        => '1',
			),
			self::pick( $this->claimRows( $order->id )[0], 'state', 'transaction_id', 'settled' )
		);
	}

	/**
	 * Tests that a gateway that makes a new refund every time it is asked gives the money back once for one claim: asked again after the answer was lost, it is asked what became of the refund.
	 *
	 * @since 0.1.0
	 */
	public function test_a_gateway_that_ignores_the_key_gives_the_money_back_once_per_claim(): void {
		list( $order )        = $this->placePaid( self::order() );
		list( $tee )          = $this->lineUuids( $order->id );
		$provider             = new RememberingGateway( new StubGateway() );
		$provider->ignoresKey = true;
		$losing               = $this->refundsOver( $this->db, $this->ids, new AnswerLosingGateway( $provider ) );

		$this->assertUnanswered( fn() => $this->refund( $order->uuid, array( $tee => 1 ), false, $losing ) );
		$this->assertSame( array( 'claimed' ), array_column( $this->claimRows( $order->id ), 'state' ) );

		$refund = $this->refund( $order->uuid, array( $tee => 1 ), false, $losing );

		$this->assertSame( 1, $provider->refundsMade(), 'The gateway gave the money back once.' );
		$this->assertSame( array( 'stub-re-' . $refund->uuid . '-1' ), array_column( $this->refundLedgerRows( $order->id ), 'provider_object_id' ), 'The ledger has the one refund it made.' );
		$this->assertCount( 1, $this->refundRows( $order->id ) );
		$this->assertSame( array( 'recorded' ), array_column( $this->claimRows( $order->id ), 'state' ) );
	}

	/**
	 * Tests that a declined refund ends its claim declined, and that asking again is a new refund, with its own claim and key, which the gateway is asked for.
	 *
	 * @since 0.1.0
	 */
	public function test_a_declined_refund_ends_its_claim_and_asking_again_is_a_new_attempt(): void {
		list( $order ) = $this->placePaid( self::order(), StubGateway::REFUND_DECLINE );
		list( $tee )   = $this->lineUuids( $order->id );

		foreach ( array( 1, 2 ) as $attempt ) {
			$this->assertRefused( PaymentError::RefundDeclined, fn() => $this->refund( $order->uuid, array( $tee => 1 ) ) );
			$this->assertSame( $attempt, $this->refundCalls(), "Attempt {$attempt} asked the gateway." );
		}

		$claims = $this->claimRows( $order->id );
		$ledger = $this->refundLedgerRows( $order->id );

		$this->assertSame( array( 'declined', 'declined' ), array_column( $claims, 'state' ) );
		$this->assertNotSame( $claims[0]['uuid'], $claims[1]['uuid'], 'The second attempt is a new refund.' );
		$this->assertSame( array_map( 'strval', array_column( $ledger, 'id' ) ), array_column( $claims, 'transaction_id' ), 'Each claim ends with its decline\'s ledger row.' );
		$this->assertSame( array( 'declined', 'declined' ), array_column( $ledger, 'result' ) );
		$this->assertSame( array(), $this->refundRows( $order->id ) );
		$this->assertClaimsNameOnlyTheirOwnRows( $order->id );
	}

	/**
	 * Tests that a gateway's not-found leaves the refund's claim open for a person, however old the claim: the refund is refused, nothing is written, and the gateway is never asked for it again.
	 *
	 * @since 0.1.0
	 */
	public function test_a_not_found_leaves_the_claim_for_a_person(): void {
		list( $order, $intent ) = $this->placePaid( self::order() );
		list( $tee )            = $this->lineUuids( $order->id );
		$provider               = new RememberingGateway( new StubGateway() );
		$provider->reachable    = false;
		$service                = $this->refundsOver( $this->db, $this->ids, $provider );

		$this->assertUnanswered( fn() => $this->refund( $order->uuid, array( $tee => 1 ), false, $service ) );

		$claim               = $this->claimRows( $order->id )[0];
		$provider->reachable = true;
		$ages                = array(
			'young' => 0,
			'stale' => PaymentService::STALE_SECONDS + 60,
		);

		foreach ( $ages as $age => $seconds ) {
			$this->ageClaims( $seconds );

			$refused = $this->assertRefused( PaymentError::RefundUnresolved, fn() => $this->refund( $order->uuid, array( $tee => 1 ), false, $service ) );

			$this->assertSame( array( 'refund_uuid' => $claim['uuid'] ), $refused->context(), "The refusal of the {$age} claim names the refund." );
			$this->assertSame( array(), $this->refundLedgerRows( $order->id ), "The {$age} claim's not-found writes nothing." );
			$this->assertSame( array( 'claimed' ), array_column( $this->claimRows( $order->id ), 'state' ), "The {$age} claim stays open for a person." );
		}

		$this->assertSame( 0, $provider->refundsMade(), 'The gateway was never asked for the refund again.' );

		$findings = $this->ledgerCheck()->run()->findings;

		$this->assertCount( 1, $findings );
		$this->assertStringStartsWith( "Warning: refund {$claim['uuid']} of payment {$intent->uuid}, ", $findings[0] );
	}

	/**
	 * Tests that a gateway that cannot say what became of a refund leaves its claim for a person: refused, nothing written, and doctor names it once it is stale.
	 *
	 * @since 0.1.0
	 */
	public function test_a_gateway_that_cannot_say_leaves_the_claim_for_a_person(): void {
		list( $order, $intent ) = $this->placePaid( self::order() );
		list( $tee )            = $this->lineUuids( $order->id );
		$provider               = new RememberingGateway( new StubGateway() );
		$provider->reachable    = false;
		$provider->knows        = false;
		$service                = $this->refundsOver( $this->db, $this->ids, $provider );

		$this->assertUnanswered( fn() => $this->refund( $order->uuid, array( $tee => 1 ), false, $service ) );

		$uuid = $this->claimRows( $order->id )[0]['uuid'];

		$this->ageClaims( PaymentService::STALE_SECONDS + 60 );

		$refused = $this->assertRefused( PaymentError::RefundUnresolved, fn() => $this->refund( $order->uuid, array( $tee => 1 ), false, $service ) );

		$this->assertSame( array( 'refund_uuid' => $uuid ), $refused->context() );
		$this->assertSame( array(), $this->refundLedgerRows( $order->id ) );
		$this->assertSame( array( 'claimed' ), array_column( $this->claimRows( $order->id ), 'state' ) );
		$this->assertSame( 0, $provider->refundsMade(), 'The gateway was never asked for the refund again.' );

		$findings = $this->ledgerCheck()->run()->findings;

		$this->assertCount( 1, $findings );
		$this->assertStringStartsWith( "Warning: refund {$uuid} of payment {$intent->uuid}, ", $findings[0] );
	}

	/**
	 * Tests that doctor names only the claims never settled that are older than any call to the gateway takes, with the amount asked.
	 *
	 * Three orders: one whose refund never reached the gateway, claimed eleven minutes ago; one whose
	 * refund was recorded, its claim as old; and one whose refund never reached the gateway a minute
	 * ago. Only the first is named.
	 *
	 * @since 0.1.0
	 */
	public function test_doctor_names_only_claims_never_settled_past_the_stale_age(): void {
		$provider            = new RememberingGateway( new StubGateway() );
		$provider->reachable = false;
		$unreached           = $this->refundsOver( $this->db, $this->ids, $provider );
		$claims              = array();

		foreach ( array( 'stale', 'settled', 'young' ) as $which ) {
			list( $order, $intent ) = $this->placePaid( self::order() );
			list( $tee )            = $this->lineUuids( $order->id );

			if ( 'settled' === $which ) {
				$this->refund( $order->uuid, array( $tee => 1 ) );
			} else {
				$this->assertUnanswered( fn() => $this->refund( $order->uuid, array( $tee => 1 ), false, $unreached ) );
			}

			$claims[ $which ] = $this->claimRows( $order->id )[0] + array( 'intent_uuid' => $intent->uuid );
		}

		$this->ageClaims( PaymentService::STALE_SECONDS + 60 );
		$this->db->execute( 'UPDATE %i SET created_at = UTC_TIMESTAMP(6) - INTERVAL 60 SECOND WHERE uuid = %s', $this->table( RefundClaimTables::CLAIMS ), $claims['young']['uuid'] );

		$findings = $this->ledgerCheck()->run()->findings;
		$stale    = $claims['stale'];

		$this->assertSame( array( 'claimed', 'recorded', 'claimed' ), array_column( $claims, 'state' ) );
		$this->assertCount( 1, $findings, implode( "\n", $findings ) );
		$this->assertSame(
			1,
			preg_match( sprintf( '/^Warning: refund %1$s of payment %2$s, %3$d EUR, was asked of the gateway (\d+) seconds ago and its answer was never recorded; the gateway may have given the money back\. /', $stale['uuid'], $stale['intent_uuid'], (int) $stale['amount_minor'] ), $findings[0], $age ),
			$findings[0]
		);

		// The database clock's whole seconds may cut the age to one second under what it was made.
		$this->assertGreaterThanOrEqual( PaymentService::STALE_SECONDS + 59, (int) $age[1] );
		$this->assertLessThan( PaymentService::STALE_SECONDS + 120, (int) $age[1] );
	}

	/**
	 * Tests that what the gateway answered that cannot be recorded as the refund ends its claim unreconciled: an approval of another amount, naming the ledger row that keeps it; and another refund's answer, naming no row, which doctor reports.
	 *
	 * @since 0.1.0
	 */
	public function test_money_kept_for_a_person_ends_the_claim_unreconciled(): void {
		list( $order ) = $this->placePaid( self::order() );
		list( $tee )   = $this->lineUuids( $order->id );

		$this->assertRefused( PaymentError::Unreconciled, fn() => $this->refund( $order->uuid, array( $tee => 1 ), false, $this->refundsOver( $this->db, $this->ids, new OvergivingGateway( $this->gateway ) ) ) );

		$kept = $this->refundLedgerRows( $order->id )[0];

		$this->assertSame( '0', (string) $kept['applied'] );
		$this->assertSame( array( 'unreconciled', (string) $kept['id'], '1' ), array_values( self::pick( $this->claimRows( $order->id )[0], 'state', 'transaction_id', 'settled' ) ) );

		list( $other, $otherIntent ) = $this->placePaid( self::order() );
		list( $mug )                 = array_slice( $this->lineUuids( $other->id ), 1 );
		$replaying                   = $this->refundsOver( $this->db, $this->ids, new ReplayingGateway( $this->gateway ) );

		$this->refund( $other->uuid, array( $mug => 1 ), false, $replaying );
		$this->assertRefused( PaymentError::Unreconciled, fn() => $this->refund( $other->uuid, array( $mug => 1 ), false, $replaying ) );

		$first  = $this->refundLedgerRows( $other->id )[0];
		$claims = $this->claimRows( $other->id );

		$this->assertSame(
			array( array( 'recorded', (string) $first['id'] ), array( 'unreconciled', null ) ),
			array_map( static fn( array $claim ): array => array( $claim['state'], $claim['transaction_id'] ), $claims ),
			'The second refund was answered with the first one\'s ledger row, which its claim does not name: no row is its own.'
		);
		$this->assertClaimsNameOnlyTheirOwnRows( $order->id );
		$this->assertClaimsNameOnlyTheirOwnRows( $other->id );

		$findings = $this->ledgerCheck()->run()->findings;

		$this->assertContains(
			sprintf( 'Warning: refund %1$s of payment %2$s, %3$d EUR, was answered by the gateway with another refund\'s result, so what it did with this one is not known; a person must reconcile it.', $claims[1]['uuid'], $otherIntent->uuid, (int) $claims[1]['amount_minor'] ),
			$findings,
			implode( "\n", $findings )
		);
	}

	/**
	 * Tests that a refund the gateway answered with another refund's decline ends its claim unreconciled, naming no row, which doctor reports, and is answered so when asked again.
	 *
	 * The mug's refund is declined. Asked again, it is a new refund, which the gateway answers with
	 * the first one's decline: the ledger has it already, and it says nothing of what the gateway
	 * did with this one. Ended `declined`, the claim would name no decline of its own, so the
	 * count of declines a refund's uuid is named by would not move: the refund would be answered
	 * declined from that claim every time, the gateway never asked again, and doctor silent.
	 *
	 * @since 0.1.0
	 */
	public function test_a_refund_answered_with_another_refunds_decline_ends_its_claim_unreconciled(): void {
		list( $order, $intent ) = $this->placePaid( self::order(), StubGateway::REFUND_DECLINE );
		list( $mug )            = array_slice( $this->lineUuids( $order->id ), 1 );
		$replaying              = $this->refundsOver( $this->db, $this->ids, new ReplayingGateway( $this->gateway ) );
		$this->outcomes         = array();

		for ( $attempt = 1; $attempt <= 3; ++$attempt ) {
			$this->outcomes[] = $this->outcomeOf( fn() => $this->refund( $order->uuid, array( $mug => 1 ), false, $replaying ) );
		}

		$this->assertSame(
			array( PaymentError::RefundDeclined->value, PaymentError::Unreconciled->value, PaymentError::Unreconciled->value ),
			array_map( array( self::class, 'nameOf' ), $this->outcomes ),
			'Declined; then answered with that decline and left for a person; then answered from that claim.'
		);

		$declines = $this->refundLedgerRows( $order->id );
		$claims   = $this->claimRows( $order->id );

		$this->assertCount( 1, $declines, 'One decline on the ledger: the first refund\'s.' );
		$this->assertSame(
			array( array( 'declined', (string) $declines[0]['id'] ), array( 'unreconciled', null ) ),
			array_map( static fn( array $claim ): array => array( $claim['state'], $claim['transaction_id'] ), $claims ),
			'The second refund\'s claim names no row: no row is its own.'
		);
		$this->assertClaimsNameOnlyTheirOwnRows( $order->id );

		$findings = $this->ledgerCheck()->run()->findings;

		$this->assertContains(
			sprintf( 'Warning: refund %1$s of payment %2$s, %3$d EUR, was answered by the gateway with another refund\'s result, so what it did with this one is not known; a person must reconcile it.', $claims[1]['uuid'], $intent->uuid, (int) $claims[1]['amount_minor'] ),
			$findings,
			implode( "\n", $findings )
		);
	}

	/**
	 * Tests that a refund of an intent with another refund still claimed waits for it, so that refund, asked again, is the same refund, and the gateway gives its money back once.
	 *
	 * The tee's refund is made by the gateway and its answer lost: its claim stays open. Then the
	 * mug is asked for, twice, the second time once the tee's claim is stale. Were it let through, a
	 * mug recorded would move the intent's refunded amount and rename the tee's refund, which, asked
	 * again, would be a new refund, asked of the gateway a second time. Instead the mug is refused,
	 * naming the tee's claim, every time; the tee asked again finds its claim and is recorded once;
	 * and the mug is then let through.
	 *
	 * @since 0.1.0
	 */
	public function test_another_refund_waits_for_an_open_claim_so_the_first_is_given_back_once(): void {
		list( $order )     = $this->placePaid( self::order() );
		list( $tee, $mug ) = $this->lineUuids( $order->id );
		$provider          = new RememberingGateway( new StubGateway() );
		$service           = $this->refundsOver( $this->db, $this->ids, $provider );
		$losing            = $this->refundsOver( $this->db, $this->ids, new AnswerLosingGateway( $provider ) );
		$this->outcomes    = array();

		$this->assertUnanswered( fn() => $this->refund( $order->uuid, array( $tee => 1 ), false, $losing ) );

		$teeClaim         = (string) $this->claimRows( $order->id )[0]['uuid'];
		$this->outcomes[] = $this->outcomeOf( fn() => $this->refund( $order->uuid, array( $mug => 1 ), false, $service ) );

		$this->ageClaims( PaymentService::STALE_SECONDS + 60 );

		$this->outcomes[] = $this->outcomeOf( fn() => $this->refund( $order->uuid, array( $mug => 1 ), false, $service ) );
		$refund           = $this->refund( $order->uuid, array( $tee => 1 ), false, $service );

		$this->assertSame( 1, $provider->refundsMade(), 'The gateway gave money back once: the tee\'s, whose answer was lost.' );
		$this->assertSame( $teeClaim, $refund->uuid, 'The tee asked again is the same refund.' );

		foreach ( $this->outcomes as $outcome ) {
			$this->assertInstanceOf( CodedException::class, $outcome, 'The mug was let through: ' . ( is_string( $outcome ) ? $outcome : '' ) );
			$this->assertSame( PaymentError::RefundUnresolved, $outcome->errorCode() );
			$this->assertSame( array( 'refund_uuid' => $teeClaim ), $outcome->context(), 'The mug\'s refusal names the tee\'s open claim.' );
		}

		$this->assertSame( array( array( $teeClaim, 'recorded' ) ), array_map( static fn( array $claim ): array => array( $claim['uuid'], $claim['state'] ), $this->claimRows( $order->id ) ), 'The mug never made a claim.' );
		$this->assertClaimsNameOnlyTheirOwnRows( $order->id );

		$this->refund( $order->uuid, array( $mug => 1 ), false, $service );
		$this->assertSame( 2, $provider->refundsMade(), 'Once the tee\'s claim ended, the mug was let through.' );
	}

	/**
	 * Tests that requests told the gateway made no such refund, while the first request's call is still on its way, leave its claim open, and that the first request's refund then lands and is recorded once: the gateway gives the money back once.
	 *
	 * A commits its claim and stalls on its way to the gateway: the barrier ages the claim far past
	 * PaymentService::STALE_SECONDS, then asks for the same refund twice on a second connection,
	 * whose gateway has no record of it yet and says so. Both requests are refused
	 * `payment.refund_unresolved`, naming the refund, and write nothing: the claim stays open. Then
	 * A's call lands, the gateway gives the money back, and A records the refund, once. Were the
	 * not-found taken for a decline, the second request would be a new refund, which the gateway
	 * would give back too.
	 *
	 * @since 0.1.0
	 */
	public function test_a_not_found_while_the_first_request_is_on_its_way_leaves_it_to_be_recorded_once(): void {
		list( $order )         = $this->placePaid( self::order() );
		list( $tee )           = $this->lineUuids( $order->id );
		list( , $connectionB ) = $this->secondOrders();
		$gatewayB              = new RememberingGateway( new StubGateway() );
		$b                     = $this->refundsOver( $connectionB, new SequentialIdGenerator( 810000 ), $gatewayB );
		$this->outcomes        = array();

		// The barrier: A's claim is committed and A's call is on its way; A stalls, and the same refund is asked twice meanwhile.
		$this->gateway->during(
			function () use ( $order, $tee, $b ): void {
				if ( array() === $this->outcomes ) {
					$this->ageClaims( PaymentService::STALE_SECONDS * 10 );

					$this->outcomes[] = $this->outcomeOf( fn() => $this->refund( $order->uuid, array( $tee => 1 ), false, $b ) );
					$this->outcomes[] = $this->outcomeOf( fn() => $this->refund( $order->uuid, array( $tee => 1 ), false, $b ) );
				}
			}
		);

		$outcomeOfA = self::nameOf( $this->outcomeOf( fn() => $this->refund( $order->uuid, array( $tee => 1 ) ) ) );

		$this->gateway->during( static function (): void {} );

		$this->assertSame( 0, $gatewayB->refundsMade(), 'The gateway gave the money back once: A\'s refund, and no second one.' );
		$this->assertSame( 'recorded', $outcomeOfA, 'A\'s refund was recorded.' );
		$this->assertCount( 2, $this->outcomes );

		$uuid = (string) $this->claimRows( $order->id )[0]['uuid'];

		foreach ( $this->outcomes as $outcome ) {
			$this->assertInstanceOf( CodedException::class, $outcome, 'The request meanwhile was answered: ' . ( is_string( $outcome ) ? $outcome : '' ) );
			$this->assertSame( PaymentError::RefundUnresolved, $outcome->errorCode() );
			$this->assertSame( array( 'refund_uuid' => $uuid ), $outcome->context(), 'The refusal names the open claim.' );
		}

		$this->assertSame( array( array( 'stub-re-' . $uuid, 'approved', '1' ) ), array_map( static fn( array $row ): array => array( (string) $row['provider_object_id'], (string) $row['result'], (string) $row['applied'] ), $this->refundLedgerRows( $order->id ) ), 'One ledger row: A\'s refund, applied.' );
		$this->assertSame( array( array( $uuid, 'recorded' ) ), array_map( static fn( array $claim ): array => array( $claim['uuid'], $claim['state'] ), $this->claimRows( $order->id ) ), 'One claim, recorded.' );
		$this->assertCount( 1, $this->refundRows( $order->id ) );
		$this->assertClaimsNameOnlyTheirOwnRows( $order->id );
	}

	/**
	 * Tests that a refund whose figures another refund moved between its reads and its claim is sent back before the gateway, and that each later request for it reaches its claim: asked again after its answer was lost, it is recorded once.
	 *
	 * A reads the order; just before A's claim's transaction, the barrier records a mug refund on a
	 * second connection, which moves the intent's refunded amount. Under the lock, A's
	 * claim finds it moved: A writes nothing, asks nothing of the gateway, and is answered
	 * `payment.refund_retry`. Asked again, A is worked out anew and claimed; the gateway makes the
	 * refund and the answer is lost. Asked again, A is the same refund, finds its claim, and asks the
	 * gateway what became of it: recorded, once. Without the check, A would be claimed under a uuid
	 * named by figures that had moved, and every later request for it would be named differently:
	 * its claim could never be reached again, and the money never recorded.
	 *
	 * @since 0.1.0
	 */
	public function test_a_refund_whose_figures_moved_before_its_claim_is_sent_back_and_its_retry_reaches_its_claim(): void {
		list( $order )     = $this->placePaid( self::order() );
		list( $tee, $mug ) = $this->lineUuids( $order->id );
		$provider          = new RememberingGateway( new StubGateway() );
		$recording         = new RecordingGateway( $provider, $this->db );
		$transactions      = new BarrierTransactions( $this->db );
		$a                 = $this->refundsOver( $this->db, $this->ids, new AnswerLosingGateway( $recording ), tx: $transactions );
		$b                 = $this->secondRefunds();
		$this->outcomes    = array();

		// The barrier: after A's reads and before A's claim's transaction, a mug refund is recorded.
		$transactions->beforeTransaction(
			function () use ( $order, $mug, $b ): void {
				if ( array() === $this->outcomes ) {
					$this->outcomes[] = $this->outcomeOf( fn() => $this->refund( $order->uuid, array( $mug => 1 ), false, $b ) );
				}
			}
		);

		$attempts = array();

		for ( $attempt = 1; $attempt <= 3; ++$attempt ) {
			$attempts[] = self::nameOf( $this->outcomeOf( fn() => $this->refund( $order->uuid, array( $tee => 1 ), false, $a ) ) );
		}

		$this->assertSame( array( 'recorded' ), array_map( array( self::class, 'nameOf' ), $this->outcomes ), 'The mug refund was recorded.' );
		$this->assertSame(
			array( PaymentError::RefundRetry->value, 'gateway_unavailable', 'recorded' ),
			$attempts,
			'A was sent back, then claimed and its answer lost, then found its claim and was recorded.'
		);
		$this->assertSame( 1, $provider->refundsMade(), 'The gateway gave the tee back once.' );
		$this->assertSame( array( 'refund', 'queryRefund' ), array_column( $recording->calls, 'method' ), 'The last request asked the gateway what became of the refund.' );
		$this->assertSame( array( 'recorded', 'recorded' ), array_column( $this->claimRows( $order->id ), 'state' ), 'Two claims, the mug\'s and the tee\'s, both recorded; none of A\'s first request.' );
		$this->assertClaimsNameOnlyTheirOwnRows( $order->id );
	}

	/**
	 * Tests that a refund read before another refund's money was kept for a person is refused under the lock, writes nothing, and asks nothing of the gateway.
	 *
	 * A reads the order; just before A's claim's transaction, the barrier refunds the mug on a
	 * second connection through a gateway that gives back more than was asked: the money is kept on
	 * the ledger for a person, and the mug's claim ends `unreconciled`. Nothing a refund's uuid is
	 * named by moved, and no claim is open, so only the lock's own read of that money stops A:
	 * refused `payment.unreconciled`, as the reads would have refused it.
	 *
	 * @since 0.1.0
	 */
	public function test_money_kept_for_a_person_after_the_reads_refuses_the_refund_under_the_lock(): void {
		list( $order )         = $this->placePaid( self::order() );
		list( $tee, $mug )     = $this->lineUuids( $order->id );
		list( , $connectionB ) = $this->secondOrders();
		$transactions          = new BarrierTransactions( $this->db );
		$a                     = $this->refundsOver( $this->db, $this->ids, $this->gateway, tx: $transactions );
		$b                     = $this->refundsOver( $connectionB, new SequentialIdGenerator( 810000 ), new OvergivingGateway( new StubGateway() ) );
		$this->outcomes        = array();

		// The barrier: after A's reads and before A's claim's transaction, the mug's money is kept for a person.
		$transactions->beforeTransaction(
			function () use ( $order, $mug, $b ): void {
				if ( array() === $this->outcomes ) {
					$this->outcomes[] = $this->outcomeOf( fn() => $this->refund( $order->uuid, array( $mug => 1 ), false, $b ) );
				}
			}
		);

		$outcomeOfA = self::nameOf( $this->outcomeOf( fn() => $this->refund( $order->uuid, array( $tee => 1 ), false, $a ) ) );

		$this->assertSame( array( PaymentError::Unreconciled->value ), array_map( array( self::class, 'nameOf' ), $this->outcomes ), 'The mug\'s money was kept for a person.' );
		$this->assertSame( 0, $this->refundCalls(), 'A was sent to the gateway.' );
		$this->assertSame( PaymentError::Unreconciled->value, $outcomeOfA );

		$kept = $this->refundLedgerRows( $order->id );

		$this->assertSame( array( '0' ), array_map( static fn( array $row ): string => (string) $row['applied'], $kept ), 'One ledger row: the mug\'s money, unapplied.' );
		$this->assertSame(
			array( array( 'unreconciled', (string) $kept[0]['id'] ) ),
			array_map( static fn( array $claim ): array => array( $claim['state'], $claim['transaction_id'] ), $this->claimRows( $order->id ) ),
			'One claim, the mug\'s: A wrote none.'
		);
	}

	/**
	 * Tests that a claim ends only from `claimed`, and only to an ending, as ClaimState's table says: every pair of states, in the database.
	 *
	 * @since 0.1.0
	 *
	 * @dataProvider pairs
	 *
	 * @param ClaimState $from Where the claim stands.
	 * @param ClaimState $to   Where it is asked to go.
	 */
	public function test_a_claim_moves_only_as_its_transition_table_allows( ClaimState $from, ClaimState $to ): void {
		$uuid = '7aa67fde-0000-5000-8000-' . str_pad( (string) ( 1 + array_search( $from, ClaimState::cases(), true ) ), 12, '0', STR_PAD_LEFT );

		$this->db->execute(
			"INSERT INTO %i ( uuid, intent_id, order_id, state, amount_minor, currency, actor_type, created_at ) VALUES ( %s, 1, 1, %s, 100, 'EUR', 'user', UTC_TIMESTAMP(6) )",
			$this->table( RefundClaimTables::CLAIMS ),
			$uuid,
			$from->value
		);

		$allowed = in_array( $to, ClaimState::allowedFrom( $from ), true );
		$claims  = new MysqlRefundRepository( $this->db );

		try {
			$moved = $this->db->transaction( fn(): bool => $claims->settleClaim( $uuid, $to, 7 ) );
		} catch ( \LogicException $refused ) {
			$moved = false;
		}

		$this->assertSame( $allowed, $moved, "{$from->value} to {$to->value}" );
		$this->assertSame( ( $allowed ? $to : $from )->value, (string) $this->db->fetchValue( 'SELECT state FROM %i WHERE uuid = %s', $this->table( RefundClaimTables::CLAIMS ), $uuid ) );
	}

	/**
	 * Returns every pair of claim states, generated from the enum.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{0: ClaimState, 1: ClaimState}> The pairs.
	 */
	public static function pairs(): array {
		$pairs = array();

		foreach ( ClaimState::cases() as $from ) {
			foreach ( ClaimState::cases() as $to ) {
				$pairs[ "{$from->value} to {$to->value}" ] = array( $from, $to );
			}
		}

		return $pairs;
	}

	/**
	 * Asserts that a refund is refused with a code, and returns the refusal.
	 *
	 * @since 0.1.0
	 *
	 * @param PaymentError $code   The code.
	 * @param callable     $refund Asks for the refund.
	 * @return CodedException The refusal.
	 */
	private function assertRefused( PaymentError $code, callable $refund ): CodedException {
		$refused = null;

		try {
			$refund();
		} catch ( CodedException $thrown ) {
			$refused = $thrown;
		}

		$this->assertInstanceOf( CodedException::class, $refused, "The refund was not refused {$code->value}." );
		$this->assertSame( $code, $refused->errorCode() );

		return $refused;
	}

	/**
	 * Runs a refund and says how it ended: `recorded`, `gateway_unavailable`, or the refusal.
	 *
	 * @since 0.1.0
	 *
	 * @param callable $refund Asks for the refund.
	 * @return CodedException|string The refusal, or how else it ended.
	 */
	private function outcomeOf( callable $refund ): CodedException|string {
		try {
			$refund();

			return 'recorded';
		} catch ( CodedException $refused ) {
			return $refused;
		} catch ( GatewayUnavailable $unavailable ) {
			return 'gateway_unavailable';
		}
	}

	/**
	 * Names how a refund ended: its refusal's code, or how else it ended.
	 *
	 * @since 0.1.0
	 *
	 * @param CodedException|string $outcome What outcomeOf() returned.
	 * @return string The code, `recorded` or `gateway_unavailable`.
	 */
	private static function nameOf( CodedException|string $outcome ): string {
		return $outcome instanceof CodedException ? $outcome->errorCode()->value : $outcome;
	}

	/**
	 * Asserts that a refund ended without the gateway's answer: GatewayUnavailable, the claim left as it was.
	 *
	 * @since 0.1.0
	 *
	 * @param callable $refund Asks for the refund.
	 */
	private function assertUnanswered( callable $refund ): void {
		$thrown = null;

		try {
			$refund();
		} catch ( GatewayUnavailable $unavailable ) {
			$thrown = $unavailable;
		}

		$this->assertInstanceOf( GatewayUnavailable::class, $thrown, 'The refund was answered though the gateway gave no answer.' );
	}

	/**
	 * Builds the fixture order: three tees and two mugs, taxed, shipped.
	 *
	 * @since 0.1.0
	 *
	 * @return NewOrder The document.
	 */
	private static function order(): NewOrder {
		return RefundOrders::priced( array( RefundOrders::line( 'tee', '12.34', 3, 'standard' ), RefundOrders::line( 'mug', '5.00', 2, 'standard', variantId: 502 ) ) );
	}
}
