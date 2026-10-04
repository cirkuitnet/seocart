<?php
/**
 * Tests applying gateway results: approvals, duplicates, waits, declines, mismatches and refusals
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Payment;

use SEOCart\Contracts\Payment\Mode;
use SEOCart\Contracts\Payment\Operation;
use SEOCart\Contracts\Payment\Outcome;
use SEOCart\Order\Domain\Event\OrderPlaced;
use SEOCart\Order\Domain\Event\OrderStatusChanged;
use SEOCart\Order\Domain\OrderStatus;
use SEOCart\Order\Domain\PaymentStatus;
use SEOCart\Payment\Application\PaymentError;
use SEOCart\Payment\Domain\ApplicationKind;
use SEOCart\Payment\Domain\Event\PaymentAuthorized;
use SEOCart\Payment\Domain\Event\PaymentFailed;
use SEOCart\Payment\Domain\Event\PaymentStatusChanged;
use SEOCart\Payment\Domain\IntentRef;
use SEOCart\Payment\Domain\IntentStatus;
use SEOCart\Payment\Infrastructure\Gateway\StubGateway;
use SEOCart\Payment\Infrastructure\PaymentTables;
use SEOCart\Platform\Events\OutboxTable;
use SEOCart\Support\Currency;
use SEOCart\Support\Error\CodedException;
use SEOCart\Support\Money;
use SEOCart\Tests\Support\Order\NewOrders;
use SEOCart\Tests\Support\Payment\PaymentTestCase;

/**
 * One gateway result is applied once, moves the money only when it matches, and leaves every row it touches consistent.
 *
 * Each result is delivered as the caller's unit of work delivers it, in a transaction of its own,
 * and each path's statements are counted from the query log: an approved authorization takes 13
 * (two locking reads, the ledger insert, the intent's update, the order's projection and its
 * payment event, the acceptance's lock, update and event, and four outbox rows); a duplicate 4 (two
 * locking reads, the refused insert, the read of the first row); a decline 12; a mismatch 7; a wait
 * 6.
 *
 * Planted violations, each shown red and removed:
 * - in PaymentTables, drop the `provider_object_operation` unique key: the refund delivered twice
 *   is refunded twice;
 * - in Orders::park(), transition whatever the order's status, as before: the second mismatch and
 *   the one on a failed order are refused with `order.transition_illegal`, and their rows go;
 * - in MysqlPaymentRepository::appendResult(), add the settlement's amount to the base amount:
 *   the settled approval's base figures move;
 * - in PaymentService::applyWait(), append the result to the ledger as well: the wait sends a
 *   seventh statement, the ledger row;
 * - in PaymentService::decline(), skip the order's transition: the decline sends four statements
 *   fewer, and the order stays pending;
 * - in AmountCheck::accepts(), return true: the wrong amount is no longer parked; it goes on down
 *   the money path, where, for this order in a converted currency, the base-share guard throws;
 * - in AmountCheck::accepts(), compare the currency with the intent's only: the approval in the
 *   intent's currency but not the order's is no longer parked, and adding it to the order's
 *   amounts throws a CurrencyMismatchException;
 * - in PaymentService::approve(), return a duplicate instead of refusing: the refused capture's
 *   ledger row stays.
 *
 * @since 0.1.0
 */
final class ApplyGatewayResultTest extends PaymentTestCase {

	/**
	 * Tests that an approved authorization records the money, authorizes the intent and the order's projection, and accepts the order, in thirteen statements.
	 *
	 * @since 0.1.0
	 */
	public function test_an_approval_moves_the_money_and_accepts_the_order(): void {
		list( $order, $intent ) = $this->placeWithIntent();

		$result      = $this->authorizeWith( $intent, StubGateway::APPROVE );
		$outboxRows  = $this->outboxCount();
		$application = null;
		$log         = $this->captureQueries(
			function () use ( $result, &$application ): void {
				$application = $this->deliver( $result );
			}
		);

		$this->assertQueryCount( 13, $log->ofType( 'SELECT', 'INSERT', 'UPDATE', 'DELETE' ), 'an approved authorization' );

		$ledger = $this->ledgerOf( $order->id );

		$this->assertCount( 1, $ledger );
		$this->assertSame(
			array(
				'operation'               => 'authorize',
				'amount_minor'            => '3080',
				'currency'                => 'EUR',
				'base_currency'           => 'USD',
				'base_amount_minor'       => '2464',
				'provider'                => 'stub',
				'provider_object_id'      => 'stub-ch-' . $intent->uuid,
				'result'                  => 'approved',
				'applied'                 => '1',
				'error_code'              => null,
				'settlement_currency'     => null,
				'settlement_amount_minor' => null,
				'actor_type'              => 'system',
				'actor_id'                => '3',
			),
			self::pick( $ledger[0], 'operation', 'amount_minor', 'currency', 'base_currency', 'base_amount_minor', 'provider', 'provider_object_id', 'result', 'applied', 'error_code', 'settlement_currency', 'settlement_amount_minor', 'actor_type', 'actor_id' )
		);

		$this->assertNotNull( $application );
		$this->assertSame( ApplicationKind::Applied, $application->kind );
		$this->assertSame( (int) $ledger[0]['id'], $application->transactionId );
		$this->assertSame( array( $order->id, NewOrders::HOLD_GROUP ), array( $application->orderId, $application->holdGroup ), 'The caller finds the order\'s hold through the Application.' );
		$this->assertSame( array( IntentStatus::Created, IntentStatus::Authorized ), array( $application->intentFrom, $application->intentTo ) );
		$this->assertSame( array( PaymentStatus::Unpaid, PaymentStatus::Authorized, OrderStatus::Processing ), array( $application->paymentFrom, $application->paymentTo, $application->orderStatusTo ) );

		$this->assertIntent( $intent, 'authorized', 3080, 0, 0, 2464 );
		$this->assertSame( 'stub-pi-approve-' . $intent->uuid, $this->intentRow( $intent->uuid )['provider_intent_id'] );
		$this->assertOrder( $order->id, 'processing', 'authorized', 3080, 0, 0, 3080, 2464, 0 );
		$this->assertSame( array( 'order:>pending_payment:placed', 'payment:unpaid>authorized:payment_authorized', 'order:pending_payment>processing:payment_approved' ), $this->eventsOf( $order->id ) );

		$this->assertSame( $outboxRows + 4, $this->outboxCount(), 'Four outbox rows: authorized, payment status, order status and placed.' );

		foreach ( array( PaymentAuthorized::eventName(), PaymentStatusChanged::eventName(), OrderStatusChanged::eventName(), OrderPlaced::eventName() ) as $event ) {
			$this->assertSame( 1, $this->outboxRows( $event ), $event );
		}

		$this->assertSame(
			array(
				'intent_id'      => (int) $this->intentRow( $intent->uuid )['id'],
				'order_id'       => $order->id,
				'amount_minor'   => 3080,
				'currency'       => 'EUR',
				'transaction_id' => (int) $ledger[0]['id'],
			),
			$this->latestPayload( PaymentAuthorized::eventName() )
		);
		$this->assertSame(
			array(
				'order_id'         => $order->id,
				'from'             => 'unpaid',
				'to'               => 'authorized',
				'authorized_minor' => 3080,
				'paid_minor'       => 0,
				'refunded_minor'   => 0,
				'due_minor'        => 3080,
				'currency'         => 'EUR',
			),
			$this->latestPayload( PaymentStatusChanged::eventName() )
		);
		$this->assertSame( array( $order->uuid, 3080, 'USD', 2464 ), array_values( self::pick( $this->latestPayload( OrderPlaced::eventName() ), 'order_uuid', 'grand_total_minor', 'base_currency', 'base_grand_total_minor' ) ) );
	}

	/**
	 * Tests that a settlement the gateway reports is stored as the provider's fact and changes none of the plugin's amounts.
	 *
	 * @since 0.1.0
	 */
	public function test_a_reported_settlement_is_stored_and_never_used(): void {
		list( $order, $intent ) = $this->placeWithIntent();

		$this->deliver( $this->authorizeWith( $intent, StubGateway::APPROVE_SETTLED ) );

		$this->assertSame(
			array(
				'base_amount_minor'       => '2464',
				'settlement_currency'     => 'CHF',
				'settlement_amount_minor' => '1234',
				'settlement_rate'         => '0.912345678901',
				'settlement_fee_minor'    => '56',
				'settlement_source'       => 'stub',
			),
			self::pick( $this->ledgerOf( $order->id )[0], 'base_amount_minor', 'settlement_currency', 'settlement_amount_minor', 'settlement_rate', 'settlement_fee_minor', 'settlement_source' )
		);
		$this->assertIntent( $intent, 'authorized', 3080, 0, 0, 2464 );
		$this->assertOrder( $order->id, 'processing', 'authorized', 3080, 0, 0, 3080, 2464, 0 );
	}

	/**
	 * Tests that the same approval delivered again, by the redirect after the webhook, changes nothing and names the first row, in four statements.
	 *
	 * @since 0.1.0
	 */
	public function test_a_duplicate_approval_changes_nothing(): void {
		list( , $intent ) = $this->placeWithIntent();

		$result = $this->authorizeWith( $intent, StubGateway::APPROVE );
		$first  = $this->deliver( $result );
		$before = $this->snapshot();
		$again  = null;
		$log    = $this->captureQueries(
			function () use ( $result, &$again ): void {
				$again = $this->deliver( $result );
			}
		);

		$this->assertQueryCount( 4, $log->ofType( 'SELECT', 'INSERT', 'UPDATE', 'DELETE' ), 'a duplicate result' );
		$this->assertNotNull( $again );
		$this->assertSame( ApplicationKind::Duplicate, $again->kind );
		$this->assertSame( $first->transactionId, $again->transactionId, 'The duplicate names the row the result was first recorded in.' );
		$this->assertSame( array( IntentStatus::Authorized, IntentStatus::Authorized ), array( $again->intentFrom, $again->intentTo ) );
		$this->assertNull( $again->orderStatusTo );
		$this->assertSame( $before, $this->snapshot(), 'A duplicate changes no row and adds no event.' );
	}

	/**
	 * Tests that a refund delivered twice is refunded once: the ledger's key, not the intent's state, refuses the second.
	 *
	 * @since 0.1.0
	 */
	public function test_a_duplicate_refund_is_refunded_once(): void {
		list( $order, $intent ) = $this->placeCaptured();

		$refund = self::stubResult( $intent, Operation::Refund, Outcome::Approved, 1000, 'USD', 'stub-re-1' );

		$this->assertSame( ApplicationKind::Applied, $this->deliver( $refund )->kind );
		$this->assertSame( ApplicationKind::Duplicate, $this->deliver( $refund )->kind );
		$this->assertIntent( $intent, 'partially_refunded', 3080, 3080, 1000, 3080 );
		$this->assertOrder( $order->id, 'processing', 'partially_refunded', 3080, 3080, 1000, 1000, 3080, 0 );
	}

	/**
	 * Tests that a request for the customer to act moves the intent alone, that the completion delivered by the webhook applies once, and that every later delivery is a duplicate.
	 *
	 * @since 0.1.0
	 */
	public function test_a_webhook_before_the_redirect_applies_the_completion_once(): void {
		list( $order, $intent ) = $this->placeWithIntent();

		$waiting = $this->authorizeWith( $intent, StubGateway::REQUIRES_ACTION );
		$applied = null;
		$log     = $this->captureQueries(
			function () use ( $waiting, &$applied ): void {
				$applied = $this->deliver( $waiting );
			}
		);

		$this->assertQueryCount( 6, $log->ofType( 'SELECT', 'INSERT', 'UPDATE', 'DELETE' ), 'a wait: two locks, the intent, the projection, its event and one outbox row' );
		$this->assertNotNull( $applied );
		$this->assertSame( ApplicationKind::RequiresAction, $applied->kind );
		$this->assertSame( array(), $this->ledgerOf( $order->id ), 'A wait moves no money, so the ledger has no row.' );

		$row = $this->intentRow( $intent->uuid );

		$this->assertSame( array( 'requires_action', 'stub-pi-requires_action-' . $intent->uuid ), array( $row['status'], $row['provider_intent_id'] ) );
		$this->assertSame(
			'1',
			(string) $this->db->fetchValue( 'SELECT TIMESTAMPDIFF( SECOND, UTC_TIMESTAMP(), customer_action_expires_at ) BETWEEN 0 AND 900 FROM %i WHERE uuid = %s', $this->table( PaymentTables::INTENTS ), $intent->uuid ),
			'The customer has 900 seconds to act, by the database clock.'
		);
		$this->assertOrder( $order->id, 'pending_payment', 'pending', 0, 0, 0, 3080, 0, 0 );

		$before = $this->snapshot();

		$this->assertSame( ApplicationKind::Duplicate, $this->deliver( $waiting )->kind, 'The same request delivered again changes nothing.' );
		$this->assertSame( $before, $this->snapshot() );

		$completion = $this->payments->queryGateway( new IntentRef( $intent->uuid, $order->id, StubGateway::ID, Mode::Test, IntentStatus::RequiresAction, (string) $row['provider_intent_id'], $intent->amount ) );

		$this->assertNotNull( $completion );
		$this->assertSame( ApplicationKind::Applied, $this->deliver( $completion )->kind, 'The webhook delivers the completion first.' );
		$this->assertSame( ApplicationKind::Duplicate, $this->deliver( $completion )->kind, 'The redirect delivers it again.' );
		$this->assertCount( 1, $this->ledgerOf( $order->id ) );
		$this->assertIntent( $intent, 'authorized', 3080, 0, 0, 2464 );
		$this->assertOrder( $order->id, 'processing', 'authorized', 3080, 0, 0, 3080, 2464, 0 );

		$before = $this->snapshot();

		try {
			$this->deliver( $waiting );
			$this->fail( 'A request to act was applied to an authorized intent.' );
		} catch ( CodedException $refused ) {
			$this->assertSame( PaymentError::UnexpectedResult, $refused->errorCode(), 'A late request to act finds the intent past waiting.' );
		}

		$this->assertSame( $before, $this->snapshot() );
	}

	/**
	 * Tests that a decline fails the intent and the pending order, keeps the order's number, and is applied once, in twelve statements.
	 *
	 * @since 0.1.0
	 */
	public function test_a_decline_fails_the_intent_and_the_order(): void {
		list( $order, $intent ) = $this->placeWithIntent();

		$decline     = $this->authorizeWith( $intent, StubGateway::DECLINE );
		$application = null;
		$log         = $this->captureQueries(
			function () use ( $decline, &$application ): void {
				$application = $this->deliver( $decline );
			}
		);

		$this->assertQueryCount( 12, $log->ofType( 'SELECT', 'INSERT', 'UPDATE', 'DELETE' ), 'a decline' );
		$this->assertNotNull( $application );
		$this->assertSame( ApplicationKind::Declined, $application->kind );
		$this->assertSame( array( PaymentStatus::Failed, OrderStatus::Failed ), array( $application->paymentTo, $application->orderStatusTo ) );

		$ledger = $this->ledgerOf( $order->id );

		$this->assertSame( array( 'declined', '1', 'card_declined' ), array( $ledger[0]['result'], $ledger[0]['applied'], $ledger[0]['error_code'] ) );
		$this->assertIntent( $intent, 'failed', 0, 0, 0, 0 );
		$this->assertOrder( $order->id, 'failed', 'failed', 0, 0, 0, 3080, 0, 0 );
		$this->assertSame( $order->orderNumber, $this->orderRow( $order->id )['order_number'], 'A failed order keeps its number.' );
		$this->assertSame( array( 'order:>pending_payment:placed', 'payment:unpaid>failed:payment_declined', 'order:pending_payment>failed:payment_declined' ), $this->eventsOf( $order->id ) );
		$this->assertSame( 'card_declined', $this->latestPayload( PaymentFailed::eventName() )['machine_code'] ?? null );
		$this->assertSame( ApplicationKind::Duplicate, $this->deliver( $decline )->kind );
	}

	/**
	 * Tests that an approval of the wrong amount is recorded, moves no money, and parks the order for a person, in seven statements.
	 *
	 * @since 0.1.0
	 */
	public function test_a_wrong_amount_parks_the_order_and_moves_nothing(): void {
		list( $order, $intent ) = $this->placeWithIntent();

		$wrong       = $this->authorizeWith( $intent, StubGateway::WRONG_AMOUNT );
		$application = null;
		$log         = $this->captureQueries(
			function () use ( $wrong, &$application ): void {
				$application = $this->deliver( $wrong );
			}
		);

		$this->assertQueryCount( 7, $log->ofType( 'SELECT', 'INSERT', 'UPDATE', 'DELETE' ), 'a mismatch' );
		$this->assertNotNull( $application );
		$this->assertSame( ApplicationKind::Mismatch, $application->kind );
		$this->assertParkedWithNothingMoved( $order->id, $intent, '3081', 'EUR' );
		$this->assertSame( ApplicationKind::Duplicate, $this->deliver( $wrong )->kind, 'The mismatch delivered again is a duplicate too.' );
	}

	/**
	 * Tests that a second wrong approval of an order already parked keeps its row and the first, in four statements: the order is flagged again and stays on hold.
	 *
	 * @since 0.1.0
	 */
	public function test_a_second_mismatch_on_a_parked_order_keeps_both_rows(): void {
		list( $order, $intent ) = $this->placeWithIntent();

		$this->deliver( $this->authorizeWith( $intent, StubGateway::WRONG_AMOUNT ) );

		$second      = self::stubResult( $intent, Operation::Authorize, Outcome::Approved, 3079, 'EUR', 'stub-ch-second' );
		$application = null;
		$log         = $this->captureQueries(
			function () use ( $second, &$application ): void {
				$application = $this->deliver( $second );
			}
		);

		$this->assertQueryCount( 4, $log->ofType( 'SELECT', 'INSERT', 'UPDATE', 'DELETE' ), 'a mismatch on an order already on hold: two locks, the row and the flag' );
		$this->assertNotNull( $application );
		$this->assertSame( array( ApplicationKind::Mismatch, null ), array( $application->kind, $application->orderStatusTo ), 'The order is already on hold; it does not move.' );
		$this->assertSame( array( array( '3081', '0' ), array( '3079', '0' ) ), array_map( static fn( array $row ): array => array( (string) $row['amount_minor'], (string) $row['applied'] ), $this->ledgerOf( $order->id ) ), 'Both results are recorded, and neither moved money.' );
		$this->assertSame( array( 'on_hold', 1 ), array( $this->orderRow( $order->id )['status'], (int) $this->orderRow( $order->id )['has_unreconciled_money'] ) );
		$this->assertSame( array( 'order:>pending_payment:placed', 'order:pending_payment>on_hold:amount_mismatch' ), $this->eventsOf( $order->id ) );
	}

	/**
	 * Tests that a wrong approval reaching an order in a final status keeps its row and flags the order, which stays as it is.
	 *
	 * @since 0.1.0
	 */
	public function test_a_mismatch_on_an_order_in_a_final_status_keeps_its_row(): void {
		list( $order, $intent ) = $this->placeWithIntent();

		$this->deliver( $this->authorizeWith( $intent, StubGateway::DECLINE ) );

		$late = $this->deliver( self::stubResult( $intent, Operation::Authorize, Outcome::Approved, 3081, 'EUR', 'stub-ch-late' ) );

		$this->assertSame( array( ApplicationKind::Mismatch, null ), array( $late->kind, $late->orderStatusTo ) );
		$this->assertSame( array( array( 'declined', '1' ), array( 'approved', '0' ) ), array_map( static fn( array $row ): array => array( (string) $row['result'], (string) $row['applied'] ), $this->ledgerOf( $order->id ) ) );
		$this->assertSame( array( 'failed', 'failed', 1 ), array( $this->orderRow( $order->id )['status'], $this->intentRow( $intent->uuid )['status'], (int) $this->orderRow( $order->id )['has_unreconciled_money'] ), 'The failed order stays failed, and is flagged for the money its provider reported.' );
	}

	/**
	 * Tests that an approval in another currency than the order's and the intent's parks the order and moves nothing.
	 *
	 * @since 0.1.0
	 */
	public function test_a_wrong_currency_parks_the_order_and_moves_nothing(): void {
		list( $order, $intent ) = $this->placeWithIntent();

		$this->assertSame( ApplicationKind::Mismatch, $this->deliver( $this->authorizeWith( $intent, StubGateway::WRONG_CURRENCY ) )->kind );
		$this->assertParkedWithNothingMoved( $order->id, $intent, '3080', 'USD' );
	}

	/**
	 * Tests that an approval in the currency of only one of the intent and the order is a mismatch, either way.
	 *
	 * @since 0.1.0
	 */
	public function test_a_currency_must_be_both_the_orders_and_the_intents(): void {
		foreach ( array( 'USD', 'EUR' ) as $reported ) {
			list( $order, $intent ) = $this->placeWithIntentIn( 'USD' );

			$application = $this->deliver( self::stubResult( $intent, Operation::Authorize, Outcome::Approved, 3080, $reported, 'stub-ch-' . $intent->uuid ) );

			$this->assertSame( ApplicationKind::Mismatch, $application->kind, "An approval in {$reported}, for an EUR order and a USD intent." );
			$this->assertParkedWithNothingMoved( $order->id, $intent, '3080', $reported );
		}
	}

	/**
	 * Tests that a result the intent's state cannot take is refused, and that its ledger row goes with the savepoint even when the caller commits.
	 *
	 * @since 0.1.0
	 */
	public function test_a_result_the_intent_cannot_take_is_refused_and_leaves_no_row(): void {
		list( $order, $intent ) = $this->placeWithIntent();

		$capture = self::stubResult( $intent, Operation::Capture, Outcome::Approved, 3080, 'EUR', 'stub-cap-' . $intent->uuid );
		$before  = $this->snapshot();

		try {
			$this->deliver( $capture );
			$this->fail( 'A capture of an intent never authorized was applied.' );
		} catch ( CodedException $refused ) {
			$this->assertSame( PaymentError::UnexpectedResult, $refused->errorCode() );
			$this->assertSame(
				array(
					'intent_status' => 'created',
					'operation'     => 'capture',
				),
				$refused->context()
			);
		}

		$this->assertSame( $before, $this->snapshot(), 'The refused result left no ledger row and no event.' );

		$this->db->transaction(
			function () use ( $capture ): void {
				try {
					$this->payments->applyGatewayResult( $capture, self::system() );
				} catch ( CodedException $refused ) {
					$this->assertSame( PaymentError::UnexpectedResult, $refused->errorCode() );
				}
			}
		);

		$this->assertSame( array(), $this->ledgerOf( $order->id ), 'A caller that catches the refusal and commits commits no ledger row: it went with the savepoint.' );
	}

	/**
	 * Tests that applying a result outside a transaction is refused before any statement.
	 *
	 * @since 0.1.0
	 */
	public function test_a_result_is_applied_only_inside_the_callers_transaction(): void {
		list( , $intent ) = $this->placeWithIntent();

		$result = $this->authorizeWith( $intent, StubGateway::APPROVE );
		$log    = $this->captureQueries(
			function () use ( $result ): void {
				try {
					$this->payments->applyGatewayResult( $result, self::system() );
					$this->fail( 'A result was applied outside a transaction.' );
				} catch ( \LogicException $refused ) {
					$this->assertStringContainsString( 'inside the caller\'s transaction', $refused->getMessage() );
				}
			}
		);

		$this->assertQueryCount( 0, $log, 'statements before the refusal' );
	}

	/**
	 * Asserts the state a mismatch leaves: its row unapplied, the intent and the projection untouched, the order parked and flagged, and no payment event.
	 *
	 * @since 0.1.0
	 *
	 * @param int       $orderId  The order.
	 * @param IntentRef $intent   Its intent.
	 * @param string    $amount   The amount the gateway reported, in minor units.
	 * @param string    $currency The currency it reported.
	 */
	private function assertParkedWithNothingMoved( int $orderId, IntentRef $intent, string $amount, string $currency ): void {
		$ledger = $this->ledgerOf( $orderId );

		$this->assertCount( 1, $ledger );
		$this->assertSame( array( 'approved', '0', $amount, $currency, '0' ), array( $ledger[0]['result'], $ledger[0]['applied'], $ledger[0]['amount_minor'], $ledger[0]['currency'], $ledger[0]['base_amount_minor'] ), 'The row records what the gateway reported, and moved nothing.' );
		$this->assertSame( array( 'created', '0' ), array( $this->intentRow( $intent->uuid )['status'], $this->intentRow( $intent->uuid )['authorized_minor'] ) );
		$this->assertOrder( $orderId, 'on_hold', 'unpaid', 0, 0, 0, 3080, 0, 1 );
		$this->assertSame( array( 'order:>pending_payment:placed', 'order:pending_payment>on_hold:amount_mismatch' ), $this->eventsOf( $orderId ) );
		$this->assertSame( 0, $this->outboxRows( PaymentStatusChanged::eventName() ) );
		$this->assertSame( 0, $this->outboxRows( PaymentAuthorized::eventName() ) );
		$this->assertSame( 0, $this->outboxRows( OrderPlaced::eventName() ) );
	}

	/**
	 * Asserts an intent's state and amounts.
	 *
	 * @since 0.1.0
	 *
	 * @param IntentRef $intent         The intent.
	 * @param string    $status         Its state.
	 * @param int       $authorized     Authorized.
	 * @param int       $captured       Captured.
	 * @param int       $refunded       Refunded.
	 * @param int       $baseAuthorized Authorized in the base currency.
	 */
	private function assertIntent( IntentRef $intent, string $status, int $authorized, int $captured, int $refunded, int $baseAuthorized ): void {
		$row = $this->intentRow( $intent->uuid );

		$this->assertSame( array( $status, $authorized, $captured, $refunded, $baseAuthorized ), array( $row['status'], (int) $row['authorized_minor'], (int) $row['captured_minor'], (int) $row['refunded_minor'], (int) $row['base_authorized_minor'] ) );
	}

	/**
	 * Asserts an order's statuses, payment amounts and flag.
	 *
	 * @since 0.1.0
	 *
	 * @param int    $orderId        The order.
	 * @param string $status         Its status.
	 * @param string $paymentStatus  Its payment status.
	 * @param int    $authorized     Authorized.
	 * @param int    $paid           Paid.
	 * @param int    $refunded       Refunded.
	 * @param int    $due            Due.
	 * @param int    $baseAuthorized Authorized in the base currency.
	 * @param int    $unreconciled   1 when flagged for a person.
	 */
	private function assertOrder( int $orderId, string $status, string $paymentStatus, int $authorized, int $paid, int $refunded, int $due, int $baseAuthorized, int $unreconciled ): void {
		$row = $this->orderRow( $orderId );

		$this->assertSame(
			array( $status, $paymentStatus, $authorized, $paid, $refunded, $due, $baseAuthorized, $unreconciled ),
			array( $row['status'], $row['payment_status'], (int) $row['authorized_minor'], (int) $row['paid_minor'], (int) $row['refunded_minor'], (int) $row['due_minor'], (int) $row['base_authorized_minor'], (int) $row['has_unreconciled_money'] )
		);
	}

	/**
	 * Places the EUR fixture order with its intent in another currency, as no placement would: the planted case of an intent and an order that disagree.
	 *
	 * @since 0.1.0
	 *
	 * @param string $intentCurrency The intent's currency.
	 * @return array{0: \SEOCart\Order\Domain\InsertedOrder, 1: IntentRef} The order and its intent.
	 */
	private function placeWithIntentIn( string $intentCurrency ): array {
		$order = NewOrders::forTwoLines( self::CURRENCY, self::BASE );

		return $this->db->transaction(
			function () use ( $order, $intentCurrency ): array {
				$inserted = $this->orders->insert( $order, self::system() );
				$intent   = $this->payments->createIntent( $inserted->id, StubGateway::ID, Mode::Test, Money::of( self::GRAND_TOTAL, Currency::of( $intentCurrency ) ), $order->totals->baseGrandTotal, $inserted->conversionContextId );

				return array( $inserted, $intent );
			}
		);
	}

	/**
	 * Counts the outbox rows.
	 *
	 * @since 0.1.0
	 *
	 * @return int The count.
	 */
	private function outboxCount(): int {
		return (int) $this->db->fetchValue( 'SELECT COUNT(*) FROM %i', $this->table( OutboxTable::NAME ) );
	}
}
