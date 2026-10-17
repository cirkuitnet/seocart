<?php
/**
 * Tests that a gateway's answer is applied only to the intent it was asked about, and only as the gateway asked
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Payment;

use SEOCart\Contracts\Payment\GatewayUnavailable;
use SEOCart\Contracts\Payment\Operations;
use SEOCart\Order\Domain\InsertedOrder;
use SEOCart\Order\Domain\NewOrder;
use SEOCart\Payment\Domain\IntentRef;
use SEOCart\Payment\Domain\VoidReason;
use SEOCart\Payment\Infrastructure\Gateway\StubGateway;
use SEOCart\Payment\Infrastructure\RefundClaimTables;
use SEOCart\Payment\Infrastructure\RefundTables;
use SEOCart\Tests\Support\Doubles\AnswerLosingGateway;
use SEOCart\Tests\Support\Doubles\MisaddressingGateway;
use SEOCart\Tests\Support\Doubles\RememberingGateway;
use SEOCart\Tests\Support\Payment\RefundOrders;
use SEOCart\Tests\Support\Payment\RefundTestCase;

/**
 * A gateway's answer to a capture, a void, a refund or a refund's query about one payment, A, that names another payment of the same gateway, B, or another gateway as its provider, is refused as the gateway's error, and neither payment changes.
 *
 * B waits in the state the answer would move it from: authorized for a capture or a void, paid
 * for a refund. The gateway still made what it was asked for A; only its answer is addressed
 * wrongly, as a misbehaving or buggy adapter could address it.
 *
 * Planted violations, each shown red and removed, one at a time:
 * - in CanonicalGateway::capture(), void(), refund() and queryRefund(), return the gateway's answer
 *   through DeliveredValues::canonical() alone, without answerTo(): B is then captured, released,
 *   or refunded, and A's refund document is written with B's ledger row;
 * - in CanonicalGateway::answerTo(), drop the comparison of the answer's provider with the
 *   gateway's id: A is then captured, its ledger row keyed by another gateway.
 *
 * @since 0.2.0
 */
final class MisaddressedAnswerTest extends RefundTestCase {

	/**
	 * Another gateway's id, which an answer of the stand-in names as its provider.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const OTHER_GATEWAY = 'second';

	/**
	 * The gateway the services call: the recorded stand-in, its answers addressed as the test sets.
	 *
	 * @since 0.2.0
	 *
	 * @var MisaddressingGateway
	 */
	private MisaddressingGateway $misaddressing;

	/**
	 * Builds the payment and refund services over the misaddressing gateway.
	 *
	 * @since 0.2.0
	 */
	public function set_up(): void {
		parent::set_up();

		$this->misaddressing = new MisaddressingGateway( $this->gateway );
		$this->payments      = $this->paymentsOver( $this->db, $this->ids, $this->misaddressing );
		$this->refunds       = $this->refundsOver( $this->db, $this->ids, $this->misaddressing );
	}

	/**
	 * Tests that a capture of A answered as a capture of B captures neither, and that A asked again of a gateway that answers rightly is captured.
	 *
	 * @since 0.2.0
	 */
	public function test_a_capture_answered_for_another_intent_captures_neither(): void {
		list( $orderA, $a ) = $this->placeAuthorized();
		list( $orderB, $b ) = $this->placeAuthorized();
		$agent              = $this->userWithRole();

		$this->misaddressing->answerFor( $b->uuid );

		$this->assertNothingApplied( Operations::CAPTURE, 'for another intent', fn() => $this->payments->capture( $a->uuid, $agent ), $a, $b );
		$this->assertStands( $orderA, $a, 'authorized', 1 );
		$this->assertStands( $orderB, $b, 'authorized', 1 );

		$this->paymentsOver( $this->db, $this->ids, $this->gateway )->capture( $a->uuid, $agent );

		$this->assertStands( $orderA, $a, 'captured', 2 );
		$this->assertStands( $orderB, $b, 'authorized', 1 );
	}

	/**
	 * Tests that a void of A answered as a void of B releases neither.
	 *
	 * @since 0.2.0
	 */
	public function test_a_void_answered_for_another_intent_releases_neither(): void {
		list( $orderA, $a ) = $this->placeAuthorized();
		list( $orderB, $b ) = $this->placeAuthorized();
		$manager            = $this->userWithRole( 'seocart_manager' );

		$this->misaddressing->answerFor( $b->uuid );

		$this->assertNothingApplied( Operations::VOID, 'for another intent', fn() => $this->payments->void( $a->uuid, $manager, VoidReason::merchant()[0] ), $a, $b );
		$this->assertStands( $orderA, $a, 'authorized', 1 );
		$this->assertStands( $orderB, $b, 'authorized', 1 );
	}

	/**
	 * Tests that a refund of A answered as a refund of B refunds neither, writes no refund document, and leaves A's refund claimed.
	 *
	 * @since 0.2.0
	 */
	public function test_a_refund_answered_for_another_intent_refunds_neither(): void {
		list( $orderA, $a ) = $this->placePaid( self::order() );
		list( $orderB, $b ) = $this->placePaid( self::order() );
		list( $tee )        = $this->lineUuids( $orderA->id );

		$this->misaddressing->answerFor( $b->uuid );

		// Nothing is written but the refund's claim, which is taken before the gateway is asked.
		$this->assertNothingApplied( Operations::REFUND, 'for another intent', fn() => $this->refund( $orderA->uuid, array( $tee => 1 ) ), $a, $b );
		$this->assertSame( array( array( 'claimed', null ) ), array_map( static fn( array $claim ): array => array( $claim['state'], $claim['transaction_id'] ), $this->claimRows( $orderA->id ) ), 'The refund stays claimed.' );
		$this->assertSame( array(), $this->claimRows( $orderB->id ) );
		$this->assertStands( $orderA, $a, 'captured', 2 );
		$this->assertStands( $orderB, $b, 'captured', 2 );
	}

	/**
	 * Tests that a refund of A whose answer was lost, asked again, is not recorded from the gateway's account of it addressed to B: neither payment is refunded, and the refund stays claimed.
	 *
	 * @since 0.2.0
	 */
	public function test_a_refunds_query_answered_for_another_intent_records_neither(): void {
		$provider           = new RememberingGateway( new StubGateway() );
		$misaddressing      = new MisaddressingGateway( new AnswerLosingGateway( $provider ) );
		$refunds            = $this->refundsOver( $this->db, $this->ids, $misaddressing );
		list( $orderA, $a ) = $this->placePaid( self::order() );
		list( $orderB, $b ) = $this->placePaid( self::order() );
		list( $tee )        = $this->lineUuids( $orderA->id );
		$asked              = fn() => $this->refund( $orderA->uuid, array( $tee => 1 ), false, $refunds );
		$lostAnswer         = null;

		try {
			$asked();
		} catch ( GatewayUnavailable $lost ) {
			$lostAnswer = $lost;
		}

		$this->assertNotNull( $lostAnswer, 'The first answer was lost.' );
		$this->assertSame( 1, $provider->refundsMade(), 'The gateway made the refund.' );

		$misaddressing->answerFor( $b->uuid );

		$before = $this->state();

		$this->assertNothingApplied( Operations::QUERY_REFUND, 'for another intent', $asked, $a, $b );
		$this->assertSame( $before, $this->state() );
		$this->assertSame( array( 'claimed' ), array_column( $this->claimRows( $orderA->id ), 'state' ), 'The refund stays claimed.' );
		$this->assertStands( $orderA, $a, 'captured', 2 );
		$this->assertStands( $orderB, $b, 'captured', 2 );
	}

	/**
	 * Tests that a capture of A answered about A, but as another gateway, captures nothing.
	 *
	 * @since 0.2.0
	 */
	public function test_a_capture_answered_as_another_gateway_captures_nothing(): void {
		list( $orderA, $a ) = $this->placeAuthorized();
		$agent              = $this->userWithRole();

		$this->misaddressing->answerAs( self::OTHER_GATEWAY );

		$this->assertNothingApplied( Operations::CAPTURE, 'as another provider', fn() => $this->payments->capture( $a->uuid, $agent ), $a );
		$this->assertStands( $orderA, $a, 'authorized', 1 );
	}

	/**
	 * Places an order and has the stand-in authorize it: an intent of 3080 EUR, authorized, with one ledger row.
	 *
	 * @since 0.2.0
	 *
	 * @return array{0: InsertedOrder, 1: IntentRef} The order and its intent.
	 */
	private function placeAuthorized(): array {
		list( $order, $intent ) = $this->placeWithIntent();

		$this->deliver( $this->authorizeWith( $intent, StubGateway::APPROVE ) );

		return array( $order, $intent );
	}

	/**
	 * Asserts that a call is refused as the gateway's error, naming the operation and what was wrong with the answer but no intent, and that nothing it could have written was.
	 *
	 * @since 0.2.0
	 *
	 * @param string    $operation The operation called: a name of Operations::ALL.
	 * @param string    $wrong     What the message says was wrong with the answer.
	 * @param \Closure  $call      The call.
	 * @param IntentRef ...$named  The intents the message must not name, nor any provider object of theirs.
	 */
	private function assertNothingApplied( string $operation, string $wrong, \Closure $call, IntentRef ...$named ): void {
		$before = self::withoutClaims( $this->state() );
		$thrown = null;

		try {
			$call();
		} catch ( \Throwable $failed ) {
			$thrown = $failed;
		}

		$this->assertSame( $before, self::withoutClaims( $this->state() ), 'Nothing of the answer was written.' );
		$this->assertInstanceOf( GatewayUnavailable::class, $thrown, "The {$operation} answered {$wrong} was not refused as the gateway's error." );
		$this->assertStringContainsString( "a call to {$operation} {$wrong}", $thrown->getMessage() );

		foreach ( $named as $intent ) {
			// Every object the stand-in names carries its intent's uuid.
			$this->assertStringNotContainsString( $intent->uuid, $thrown->getMessage(), 'The message names no intent and no provider object.' );
		}
	}

	/**
	 * Asserts an intent's status and its order's count of ledger rows.
	 *
	 * @since 0.2.0
	 *
	 * @param InsertedOrder $order  The order.
	 * @param IntentRef     $intent Its intent.
	 * @param string        $status The intent's status.
	 * @param int           $rows   The order's ledger rows.
	 */
	private function assertStands( InsertedOrder $order, IntentRef $intent, string $status, int $rows ): void {
		$this->assertSame( $status, $this->intentRow( $intent->uuid )['status'] );
		$this->assertCount( $rows, $this->ledgerOf( $order->id ) );
	}

	/**
	 * Takes every row a money answer could write: the payment tables, the orders and their events, the outbox's size, the refunds and the refund claims.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, mixed> The state.
	 */
	private function state(): array {
		$state = $this->snapshot();

		foreach ( array( ...RefundTables::names(), RefundClaimTables::CLAIMS, RefundClaimTables::CLAIM_LINES ) as $table ) {
			$state[ $table ] = $this->db->fetchAll( 'SELECT * FROM %i ORDER BY id', $this->table( $table ) );
		}

		return $state;
	}

	/**
	 * Leaves the refund claims out of a state: a refund's claim is written before the gateway is asked.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $state The state.
	 * @return array<string, mixed> The state without the claims.
	 */
	private static function withoutClaims( array $state ): array {
		unset( $state[ RefundClaimTables::CLAIMS ], $state[ RefundClaimTables::CLAIM_LINES ] );

		return $state;
	}

	/**
	 * Builds the fixture order: three tees and two mugs, taxed, shipped.
	 *
	 * @since 0.2.0
	 *
	 * @return NewOrder The order.
	 */
	private static function order(): NewOrder {
		return RefundOrders::priced( array( RefundOrders::line( 'tee', '12.34', 3, 'standard' ), RefundOrders::line( 'mug', '5.00', 2, 'standard', variantId: 502 ) ) );
	}
}
