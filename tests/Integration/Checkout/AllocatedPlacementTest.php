<?php
/**
 * Tests that an answer about a placement whose units are allocated already, or were found gone, settles nothing more of its stock, its promotion uses or its cart, while the payment path's application of it stands
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Checkout;

use SEOCart\Checkout\Application\SettlePlacement;
use SEOCart\Checkout\Domain\PlacementOutcome;
use SEOCart\Checkout\Infrastructure\Jobs\ReconcileStalePlacements;
use SEOCart\Contracts\Payment\GatewayResult;
use SEOCart\Contracts\Payment\Operation;
use SEOCart\Contracts\Payment\Outcome;
use SEOCart\Inventory\Infrastructure\InventoryTables;
use SEOCart\Inventory\Infrastructure\Jobs\SweepHolds;
use SEOCart\Order\Infrastructure\OrderTables;
use SEOCart\Payment\Application\PaymentService;
use SEOCart\Payment\Domain\ApplicationKind;
use SEOCart\Payment\Domain\Webhook\ReceiptResult;
use SEOCart\Payment\Infrastructure\Gateway\StubGateway;
use SEOCart\Payment\Infrastructure\PaymentTables;
use SEOCart\Platform\Events\OutboxTable;
use SEOCart\Tests\Support\Checkout\PlacementSnapshots;
use SEOCart\Tests\Support\Checkout\ScriptedQueries;
use SEOCart\Tests\Support\Checkout\WebhookTestCase;
use SEOCart\Tests\Support\Payment\StubWebhooks;

/**
 * A placement's units are allocated once. A later answer about it, whatever it is and whatever the payment path does with it, settles nothing more of them, of its promotion uses or of its cart, and is acknowledged.
 *
 * - A provider may deliver the capture it made at once before the authorization it made with it,
 *   for a payment whose shopper was asked to act: the capture is kept for a person, with the units
 *   allocated while a person looks, and the authorization, by webhook or by the reconciliation
 *   run's query, is then applied by the payment path and allocates nothing a second time.
 * - A declined authorization reported then fails the payment, and gives back nothing the kept
 *   capture settled: the units stay allocated, the use committed and the cart converted.
 * - When the kept capture finds the units gone, its hold expired and taken, nothing is allocated
 *   and the order stays on hold for a person; the authorization that lands then is applied, finds
 *   the units still gone, and leaves the order on hold where it is.
 * - An order parked by a refund no claim of the store's accounted for holds no allocation, so the
 *   authorization that lands then still allocates its units, commits its use and converts its
 *   cart: the settlement asks Inventory whether the units are allocated, rather than guessing from
 *   the order's status.
 * - A second authorization, of an object of its own, for a payment authorized, captured or partly
 *   refunded already is kept for a person by the payment path, the order flagged and on hold, and
 *   allocates nothing a second time.
 *
 * Each test compares every row a settlement writes before and after (PlacementSnapshots).
 *
 * Planted violations, each shown red and removed, one at a time:
 * - in SettlePlacement::allocatedBefore(), never ask Inventory: the authorization after the kept
 *   capture allocates the units again, which fails on the order line's key, so the delivery is
 *   refused and the run defers the payment;
 * - in SettlePlacement::allocatedBefore(), answer true without asking whenever the order had left
 *   pending payment: the authorization after the external refund allocates nothing, and leaves the
 *   hold to expire;
 * - in SettlePlacement::settlesNothing(), exempt an authorization, as it once was: the second
 *   authorization allocates the units a second time, which fails, and its delivery is refused;
 * - in SettlePlacement::allocatedBefore(), leave a decline out of the answers that move stock:
 *   the declined authorization after the kept capture is settled as a decline, and the answer the
 *   placement's key keeps is rewritten to it;
 * - in SettlePlacement::accept(), put an order whose units are gone on hold whatever its status:
 *   the authorization after the capture kept with the units gone moves the order from on hold to
 *   on hold, which its states refuse, so the delivery is refused and the run defers the payment.
 *
 * @since 0.2.0
 */
final class AllocatedPlacementTest extends WebhookTestCase {

	use PlacementSnapshots;
	use ScriptedQueries;

	/**
	 * Tests that an authorization delivered after the capture kept for a person of a payment waiting on its shopper is applied, allocates nothing a second time, and is acknowledged each time it comes.
	 *
	 * @since 0.2.0
	 */
	public function test_an_authorization_delivered_after_a_kept_capture_allocates_nothing_more(): void {
		$waiting  = $this->keptCapture();
		$before   = $this->takeSnapshot();
		$delivery = StubWebhooks::of( $this->authorizationOf( $waiting ) );

		$this->assertSame(
			array( ReceiptResult::Applied, ReceiptResult::Applied, ReceiptResult::Duplicate ),
			array(
				$this->deliver( $delivery ),
				$this->deliver( $delivery ),
				$this->deliver( $delivery->withEventId( 'evt_the_same_authorization_again' ) ),
			),
			'The delivery is applied, the same event is answered from its receipt, and the same authorization under another event is a duplicate.'
		);
		$this->assertChanges( self::authorizedOnHold( 'waiting', $this->amountOf( $waiting ) ), $before, array( 'waiting' => $waiting ) );
		$this->assertSame( 'amount_mismatch', $this->keptOutcome( $waiting['order_id'] ), 'A retry of the placement is told what it was told when the capture was kept.' );
	}

	/**
	 * Tests that the reconciliation run's query answering the authorization after the capture kept for a person settles it as its delivery does, and defers nothing.
	 *
	 * @since 0.2.0
	 */
	public function test_an_authorization_the_run_is_told_after_a_kept_capture_allocates_nothing_more(): void {
		$waiting = $this->keptCapture();

		$this->answerQueries( array( $waiting['intent_uuid'] => $this->authorizationOf( $waiting ) ), true );

		$before = $this->takeSnapshot();

		$this->kernel->get( ReconcileStalePlacements::class )->handle( array() );

		$this->assertSame( array( $waiting['intent_uuid'] ), $this->asked );
		$this->assertSame( array(), $this->logged( ReconcileStalePlacements::DEFERRED ), 'Nothing was deferred.' );
		$this->assertChanges( self::authorizedOnHold( 'waiting', $this->amountOf( $waiting ) ), $before, array( 'waiting' => $waiting ) );
		$this->assertSame( 'amount_mismatch', $this->keptOutcome( $waiting['order_id'] ) );
	}

	/**
	 * Tests that a declined authorization reported after the capture kept for a person of a payment waiting on its shopper fails the payment and gives back nothing the kept capture settled.
	 *
	 * The units stay allocated, the use committed and the cart converted: a person decides what
	 * becomes of the kept capture. The answer is given to the settlement as the run gives it one.
	 *
	 * @since 0.2.0
	 */
	public function test_a_declined_authorization_after_a_kept_capture_gives_back_nothing(): void {
		$waiting = $this->keptCapture();
		$before  = $this->takeSnapshot();
		$settled = $this->kernel->get( SettlePlacement::class )->apply( $this->resultAbout( $waiting, Operation::Authorize, Outcome::Declined, 'stub-ch-' . $waiting['intent_uuid'], StubGateway::CARD_DECLINED ), SettlePlacement::store() );

		$this->assertSame( array( PlacementOutcome::Duplicate, ApplicationKind::Declined ), array( $settled->outcome, $settled->application?->kind ), 'The payment path failed the payment, and the placement gave back nothing.' );
		$this->assertChanges(
			array(
				PaymentTables::INTENTS      => self::change( array( 'waiting requires_action 0' ), array( 'waiting failed 0' ) ),
				PaymentTables::TRANSACTIONS => self::change( array(), array( self::ledgerRow( 'waiting', 'authorize:declined:1', $this->amountOf( $waiting ) ) ) ),
				OrderTables::ORDERS         => self::change( array( 'waiting on_hold/pending/1' ), array( 'waiting on_hold/failed/1' ) ),
				OrderTables::EVENTS         => self::change( array(), array( 'waiting payment:pending>failed:payment_declined' ) ),
				OutboxTable::NAME           => self::change( array(), array( 'waiting payment_failed', 'waiting payment_status_changed' ) ),
			),
			$before,
			array( 'waiting' => $waiting )
		);
		$this->assertSame( 'amount_mismatch', $this->keptOutcome( $waiting['order_id'] ), 'A retry of the placement is told what it was told when the capture was kept.' );
	}

	/**
	 * Tests that an authorization delivered after a capture kept for a person while the units were gone is applied, leaves the order on hold where the capture put it, and is acknowledged each time it comes.
	 *
	 * @since 0.2.0
	 *
	 * @dataProvider data_payments_still_waiting
	 *
	 * @param string $token         The payment token the placement is paid with.
	 * @param string $status        The intent's state the placement leaves.
	 * @param string $paymentStatus The order's payment status the placement leaves.
	 */
	public function test_an_authorization_delivered_after_a_capture_kept_with_the_units_gone_leaves_the_order_on_hold( string $token, string $status, string $paymentStatus ): void {
		$gone     = $this->keptCaptureWithTheUnitsGone( $token, $paymentStatus );
		$before   = $this->takeSnapshot();
		$delivery = StubWebhooks::of( $this->authorizationOf( $gone ) );

		$this->assertSame(
			array( ReceiptResult::Applied, ReceiptResult::Applied, ReceiptResult::Duplicate ),
			array(
				$this->deliver( $delivery ),
				$this->deliver( $delivery ),
				$this->deliver( $delivery->withEventId( 'evt_the_same_authorization_again' ) ),
			),
			'The delivery is applied, the same event is answered from its receipt, and the same authorization under another event is a duplicate.'
		);
		$this->assertChanges( self::authorizedOnHold( 'gone', $this->amountOf( $gone ), $status, $paymentStatus ), $before, array( 'gone' => $gone ) );
		$this->assertSame( 'stock_unavailable', $this->keptOutcome( $gone['order_id'] ), 'A retry of the placement is told its units are gone.' );
	}

	/**
	 * Tests that the reconciliation run's query answering the authorization after a capture kept for a person while the units were gone settles it as its delivery does, and defers nothing.
	 *
	 * @since 0.2.0
	 *
	 * @dataProvider data_payments_still_waiting
	 *
	 * @param string $token         The payment token the placement is paid with.
	 * @param string $status        The intent's state the placement leaves.
	 * @param string $paymentStatus The order's payment status the placement leaves.
	 */
	public function test_an_authorization_the_run_is_told_after_a_capture_kept_with_the_units_gone_leaves_the_order_on_hold( string $token, string $status, string $paymentStatus ): void {
		$gone = $this->keptCaptureWithTheUnitsGone( $token, $paymentStatus );

		$this->answerQueries( array( $gone['intent_uuid'] => $this->authorizationOf( $gone ) ), true );

		$before = $this->takeSnapshot();

		$this->kernel->get( ReconcileStalePlacements::class )->handle( array() );

		$this->assertSame( array( $gone['intent_uuid'] ), $this->asked );
		$this->assertSame( array(), $this->logged( ReconcileStalePlacements::DEFERRED ), 'Nothing was deferred.' );
		$this->assertSame( ReceiptResult::Duplicate, $this->deliver( StubWebhooks::of( $this->authorizationOf( $gone ) ) ), 'The provider\'s delivery of the same authorization is a duplicate.' );
		$this->assertChanges( self::authorizedOnHold( 'gone', $this->amountOf( $gone ), $status, $paymentStatus ), $before, array( 'gone' => $gone ) );
		$this->assertSame( 'stock_unavailable', $this->keptOutcome( $gone['order_id'] ), 'A retry of the placement is told its units are gone.' );
	}

	/**
	 * Returns the placements whose payment waits for an answer the gateway cannot give alone.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, array{0: string, 1: string, 2: string}> The payment token, and the intent's state and the order's
	 *                                                               payment status it leaves.
	 */
	public static function data_payments_still_waiting(): array {
		return array(
			'the shopper asked to act'   => array( StubGateway::REQUIRES_ACTION, 'requires_action', 'pending' ),
			'the gateway never answered' => array( StubGateway::THROW, 'created', 'unpaid' ),
		);
	}

	/**
	 * Tests that an authorization delivered after a refund no claim accounted for parked the order of a payment waiting on its shopper allocates the units, which no allocation holds yet, commits the use and converts the cart.
	 *
	 * @since 0.2.0
	 */
	public function test_an_authorization_after_an_external_refund_parked_the_order_still_allocates(): void {
		$parked = $this->placed( StubGateway::REQUIRES_ACTION, 'attempt-parked', 'SAVE-PARKED' );
		$refund = StubWebhooks::of( $this->resultAbout( $parked, Operation::Refund, Outcome::Approved, 'external-re-' . $parked['intent_uuid'] ) );

		$this->assertSame( ReceiptResult::Unapplied, $this->deliver( $refund ) );
		$this->assertSame( array( 'on_hold', 'pending', '1' ), array_values( $this->orderState( $parked['order_id'] ) ), 'The refund parked the order and allocated nothing.' );

		$before = $this->takeSnapshot();

		$this->assertSame( ReceiptResult::Applied, $this->deliver( StubWebhooks::of( $this->authorizationOf( $parked ) ) ) );
		$this->assertChanges(
			self::merged(
				self::authorizedOnHold( 'parked', $this->amountOf( $parked ) ),
				array( OutboxTable::NAME => self::change( array(), array( 'parked stock_allocated' ) ) ),
				self::allocated( 'parked' ),
				self::used( 'parked', 'committed' )
			),
			$before,
			array( 'parked' => $parked )
		);
		$this->assertSame( 'approved', $this->keptOutcome( $parked['order_id'] ) );
	}

	/**
	 * Tests that a second authorization delivered for a payment its authorization settled before is kept for a person, allocates nothing a second time, and is acknowledged each time it comes.
	 *
	 * @since 0.2.0
	 *
	 * @dataProvider data_settled_payments
	 *
	 * @param string $state         The intent's state when the second authorization lands.
	 * @param string $paymentStatus The order's payment status then, which keeping it leaves.
	 */
	public function test_a_second_authorization_delivered_for_a_settled_payment_is_kept_for_a_person( string $state, string $paymentStatus ): void {
		$settled  = $this->placedAndSettledAs( $state );
		$kept     = $this->keptOutcome( $settled['order_id'] );
		$before   = $this->takeSnapshot();
		$delivery = StubWebhooks::of( $this->resultAbout( $settled, Operation::Authorize, Outcome::Approved, 'stub-ch-second-' . $settled['intent_uuid'] ) );

		$this->assertSame(
			array( ReceiptResult::Unapplied, ReceiptResult::Duplicate ),
			array( $this->deliver( $delivery ), $this->deliver( $delivery->withEventId( 'evt_the_second_authorization_again' ) ) ),
			'The delivery is kept for a person, and the same authorization under another event is a duplicate.'
		);
		$this->assertSame( PaymentService::UNEXPECTED_RESULT, $this->receiptOf( $delivery->eventId() )['result_code'] ?? null );
		$this->assertChanges(
			array(
				PaymentTables::TRANSACTIONS => self::change( array(), array( self::ledgerRow( 'settled', 'authorize:approved:0', $this->amountOf( $settled ), 'stub-ch-second-{settled}' ) ) ),
				OrderTables::ORDERS         => self::change( array( 'settled processing/' . $paymentStatus . '/0' ), array( 'settled on_hold/' . $paymentStatus . '/1' ) ),
				OrderTables::EVENTS         => self::change( array(), array( 'settled order:processing>on_hold:' . PaymentService::UNEXPECTED_RESULT ) ),
				OutboxTable::NAME           => self::change( array(), array( 'settled order_status_changed' ) ),
			),
			$before,
			array( 'settled' => $settled )
		);
		$this->assertSame( $kept, $this->keptOutcome( $settled['order_id'] ), 'A retry of the placement is told what it was told before.' );
	}

	/**
	 * Returns the states of a payment its authorization settled before, each with the order's payment status then.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, array{0: string, 1: string}> The cases.
	 */
	public static function data_settled_payments(): array {
		return array(
			'authorized'      => array( 'authorized', 'authorized' ),
			'captured'        => array( 'captured', 'paid' ),
			'partly refunded' => array( 'partially_refunded', 'partially_refunded' ),
		);
	}

	/**
	 * Places an order whose shopper the stand-in asks to act, with a promotion code, and delivers the capture the provider made at once, which the payment path keeps for a person while the settlement allocates the order's units.
	 *
	 * @since 0.2.0
	 *
	 * @return array{order_uuid: string, order_id: int, intent_uuid: string, status: string, provider_intent_id: string|null, variant: int} What placed() returned.
	 */
	private function keptCapture(): array {
		$waiting = $this->placed( StubGateway::REQUIRES_ACTION, 'attempt-waiting', 'SAVE-WAITING' );

		$this->assertSame( ReceiptResult::Unapplied, $this->deliver( StubWebhooks::of( $this->captureOf( $waiting ) ) ) );
		$this->assertSame( array( 5, 1, 0 ), $this->committedStock( $this->secondConnection(), $waiting['variant'] ), 'The kept capture allocated the unit.' );

		return $waiting;
	}

	/**
	 * Places an order whose payment waits, with a promotion code; lets its hold expire and be swept and its units be sold meanwhile; and delivers the capture the provider made, which the payment path keeps for a person while the settlement finds nothing to allocate.
	 *
	 * @since 0.2.0
	 *
	 * @param string $token         The payment token the placement is paid with.
	 * @param string $paymentStatus The order's payment status the placement leaves.
	 * @return array{order_uuid: string, order_id: int, intent_uuid: string, status: string, provider_intent_id: string|null, variant: int} What placed() returned.
	 */
	private function keptCaptureWithTheUnitsGone( string $token, string $paymentStatus ): array {
		$gone = $this->placed( $token, 'attempt-gone', 'SAVE-GONE' );

		$this->db->execute( 'UPDATE %i SET expires_at = UTC_TIMESTAMP() - INTERVAL 1 MINUTE WHERE variant_id = %d', $this->table( InventoryTables::HOLDS ), $gone['variant'] );
		$this->kernel->get( SweepHolds::class )->handle( array() );
		// The units the hold gave back are sold meanwhile.
		$this->db->execute( 'UPDATE %i SET on_hand = 0 WHERE variant_id = %d', $this->table( InventoryTables::ITEMS ), $gone['variant'] );

		$this->assertSame( ReceiptResult::Unapplied, $this->deliver( StubWebhooks::of( $this->captureOf( $gone ) ) ) );
		$this->assertSame( array( 0, 0, 0 ), $this->committedStock( $this->secondConnection(), $gone['variant'] ), 'The kept capture allocated nothing: the units are gone.' );
		$this->assertSame( array( 'on_hold', $paymentStatus, '1' ), array_values( $this->orderState( $gone['order_id'] ) ), 'The kept capture parked the order.' );

		return $gone;
	}

	/**
	 * Builds the stand-in's approval of an order's authorization, under the reference the intent holds.
	 *
	 * @since 0.2.0
	 *
	 * @param array{intent_uuid: string} $placed What placed() returned.
	 * @return GatewayResult The approval.
	 */
	private function authorizationOf( array $placed ): GatewayResult {
		return $this->resultAbout( $placed, Operation::Authorize, Outcome::Approved, 'stub-ch-' . $placed['intent_uuid'] );
	}

	/**
	 * Returns what an authorization the payment path applies changes for a payment still waiting whose order a person must look at: the intent authorized, the applied row, the order's payment status, its record and events; the order stays on hold and flagged.
	 *
	 * @since 0.2.0
	 *
	 * @param string $name          The payment.
	 * @param int    $amount        The amount authorized, in minor units.
	 * @param string $status        Optional. The intent's state before. Default `requires_action`.
	 * @param string $paymentStatus Optional. The order's payment status before. Default `pending`.
	 * @return array<string, array{gone: list<string>, new: list<string>}> The changes.
	 */
	private static function authorizedOnHold( string $name, int $amount, string $status = 'requires_action', string $paymentStatus = 'pending' ): array {
		return array(
			PaymentTables::INTENTS      => self::change( array( $name . ' ' . $status . ' 0' ), array( $name . ' authorized 0' ) ),
			PaymentTables::TRANSACTIONS => self::change( array(), array( self::ledgerRow( $name, 'authorize:approved:1', $amount ) ) ),
			OrderTables::ORDERS         => self::change( array( $name . ' on_hold/' . $paymentStatus . '/1' ), array( $name . ' on_hold/authorized/1' ) ),
			OrderTables::EVENTS         => self::change( array(), array( $name . ' payment:' . $paymentStatus . '>authorized:payment_authorized' ) ),
			OutboxTable::NAME           => self::change( array(), array( $name . ' payment_authorized', $name . ' payment_status_changed' ) ),
		);
	}
}
