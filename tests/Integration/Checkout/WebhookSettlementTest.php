<?php
/**
 * Tests what each delivery of the stand-in's webhook settles, through the one money path
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Checkout;

use SEOCart\Checkout\Infrastructure\CheckoutTables;
use SEOCart\Contracts\Payment\Operation;
use SEOCart\Contracts\Payment\Outcome;
use SEOCart\Inventory\Infrastructure\InventoryTables;
use SEOCart\Order\Infrastructure\OrderTables;
use SEOCart\Payment\Application\PaymentError;
use SEOCart\Payment\Application\PaymentService;
use SEOCart\Payment\Domain\Webhook\ReceiptResult;
use SEOCart\Payment\Infrastructure\Gateway\StubGateway;
use SEOCart\Payment\Infrastructure\PaymentTables;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Support\Error\CodedException;
use SEOCart\Tests\Support\Checkout\WebhookTestCase;
use SEOCart\Tests\Support\CreatesUsers;
use SEOCart\Tests\Support\Payment\StubWebhooks;

/**
 * Each delivery is settled as the one money path decides: an authorization with its placement, a capture or a void of an accepted order on its own, and a result about no intent of this address ignored.
 *
 * Planted violations, each named on its test.
 *
 * @since 0.2.0
 */
final class WebhookSettlementTest extends WebhookTestCase {

	use CreatesUsers;

	/**
	 * Deletes the users the test created.
	 *
	 * @since 0.2.0
	 */
	public function tear_down(): void {
		$this->deleteCreatedUsers();

		parent::tear_down();
	}

	/**
	 * Tests that an approval delivered for a placement whose gateway never answered settles it: the order accepted, its unit allocated, one ledger row, and the receipt applied with the row.
	 *
	 * @since 0.2.0
	 */
	public function test_an_approval_settles_a_waiting_placement(): void {
		$placed   = $this->placed( StubGateway::THROW );
		$approval = StubWebhooks::of( $this->approvalOf( $placed ) );

		$this->assertSame( 'created', $placed['status'], 'The placement waits for its answer.' );
		$this->assertSame( ReceiptResult::Applied, $this->deliver( $approval ) );

		$receipt = $this->receiptOf( $approval->eventId() );

		$this->assertSame( array( 'processing', 'authorized', '0' ), array_values( $this->orderState( $placed['order_id'] ) ) );
		$this->assertSame( array( 'authorize:approved:1:stub-ch-' . $placed['intent_uuid'] ), $this->ledgerOf( $placed['order_id'] ) );
		$this->assertSame( array( 'applied', 'applied', $placed['intent_uuid'] ), array( $receipt['result'], $receipt['result_code'], $receipt['intent_uuid'] ) );
		$this->assertNotNull( $receipt['transaction_id'] );
		$this->assertSame( array( 5, 1, 0 ), $this->committedStock( $this->secondConnection(), $placed['variant'] ), 'The unit is allocated to the order.' );
		$this->assertSame( ReceiptResult::Applied, $this->deliver( $approval ), 'The same event again is answered from its receipt.' );
		$this->assertSame( ReceiptResult::Duplicate, $this->deliver( $approval->withEventId( 'evt_second_of_one_charge' ) ), 'Another event about the same charge meets the ledger\'s key.' );
		$this->assertCount( 1, $this->ledgerOf( $placed['order_id'] ) );
	}

	/**
	 * Tests that a decline, a request to act and a gateway still deciding are each settled as the placement settles them, and recorded with their kind.
	 *
	 * @since 0.2.0
	 */
	public function test_a_decline_and_a_wait_are_settled_with_their_kind(): void {
		$declined = $this->placed( StubGateway::THROW, 'declined' );
		$waiting  = $this->placed( StubGateway::THROW, 'waiting' );
		$deciding = $this->placed( StubGateway::THROW, 'deciding' );
		$decline  = StubWebhooks::of( $this->resultAbout( $declined, Operation::Authorize, Outcome::Declined, 'stub-ch-' . $declined['intent_uuid'], StubGateway::CARD_DECLINED ) );
		$action   = StubWebhooks::of( $this->resultAbout( $waiting, Operation::Authorize, Outcome::RequiresAction, null, null, 'stub-pi-requires_action-' . $waiting['intent_uuid'] ) );
		$pending  = StubWebhooks::of( $this->resultAbout( $deciding, Operation::Authorize, Outcome::Pending, null, null, 'stub-pi-pending-' . $deciding['intent_uuid'] ) );

		foreach ( array( $decline, $action, $pending ) as $delivery ) {
			$this->assertSame( ReceiptResult::Applied, $this->deliver( $delivery ) );
		}

		$this->assertSame( 'failed', $this->orderState( $declined['order_id'] )['status'], 'The declined placement failed, and released its unit.' );
		$this->assertSame( array( 5, 0, 0 ), $this->committedStock( $this->secondConnection(), $declined['variant'] ) );
		$this->assertSame( array( 'declined', 'requires_action', 'pending' ), array( $this->receiptOf( $decline->eventId() )['result_code'], $this->receiptOf( $action->eventId() )['result_code'], $this->receiptOf( $pending->eventId() )['result_code'] ) );
		$this->assertSame( array( 'requires_action', 'processing' ), array( $this->intentRow( $waiting['intent_uuid'] )['status'], $this->intentRow( $deciding['intent_uuid'] )['status'] ) );
	}

	/**
	 * Tests that a decline of an earlier attempt delivered after the approval is stale: nothing moves, and the receipt says the state it found.
	 *
	 * @since 0.2.0
	 */
	public function test_a_late_decline_after_the_approval_is_stale(): void {
		$placed = $this->placed( StubGateway::APPROVE );
		$late   = StubWebhooks::of( $this->resultAbout( $placed, Operation::Authorize, Outcome::Declined, 'stub-ch-first-card', StubGateway::CARD_DECLINED ) );

		$this->assertSame( ReceiptResult::Stale, $this->deliver( $late ) );
		$this->assertSame( array( 'stale', 'authorized', null ), array( $this->receiptOf( $late->eventId() )['result'], $this->receiptOf( $late->eventId() )['result_code'], $this->receiptOf( $late->eventId() )['transaction_id'] ) );
		$this->assertSame( array( 'processing', 'authorized', '0' ), array_values( $this->orderState( $placed['order_id'] ) ) );
		$this->assertSame( array( 5, 1, 0 ), $this->committedStock( $this->secondConnection(), $placed['variant'] ) );
	}

	/**
	 * Tests that the ledger compares the outcome of a provider object: a delivered approval of the object whose delivered decline failed the placement is kept for a person, the same approval again is a duplicate, and a decline of the object whose approval accepted a placement is stale.
	 *
	 * Planted violation: in PaymentTables::transactions(), key the ledger by provider, object and
	 * operation, as before: the approval after the decline is answered as a duplicate, with no row
	 * and no flag.
	 *
	 * @since 0.2.0
	 */
	public function test_the_ledger_compares_the_outcome_of_the_same_object(): void {
		$failed   = $this->placed( StubGateway::THROW, 'failed' );
		$object   = 'stub-ch-' . $failed['intent_uuid'];
		$decline  = StubWebhooks::of( $this->resultAbout( $failed, Operation::Authorize, Outcome::Declined, $object, StubGateway::CARD_DECLINED ) );
		$approval = StubWebhooks::of( $this->resultAbout( $failed, Operation::Authorize, Outcome::Approved, $object ) );

		$this->assertSame( ReceiptResult::Applied, $this->deliver( $decline ) );
		$this->assertSame( ReceiptResult::Unapplied, $this->deliver( $approval ), 'The approval after the decline is kept for a person.' );
		$this->assertSame( ReceiptResult::Duplicate, $this->deliver( $approval->withEventId( 'evt_the_same_approval_again' ) ), 'The same approval again is a duplicate.' );
		$this->assertSame( array( 'authorize:declined:1:' . $object, 'authorize:approved:0:' . $object ), $this->ledgerOf( $failed['order_id'] ) );
		$this->assertSame( '1', $this->orderState( $failed['order_id'] )['has_unreconciled_money'] );

		$accepted = $this->placed( StubGateway::APPROVE, 'accepted' );
		$late     = StubWebhooks::of( $this->resultAbout( $accepted, Operation::Authorize, Outcome::Declined, 'stub-ch-' . $accepted['intent_uuid'], StubGateway::CARD_DECLINED ) );

		$this->assertSame( ReceiptResult::Stale, $this->deliver( $late ), 'A decline of the object that was approved is stale.' );
		$this->assertCount( 1, $this->ledgerOf( $accepted['order_id'] ) );
		$this->assertSame( '0', $this->orderState( $accepted['order_id'] )['has_unreconciled_money'] );
	}

	/**
	 * Tests that a capture delivered for an accepted order is applied on its own, and the capture the merchant then asks for is answered as done.
	 *
	 * @since 0.2.0
	 */
	public function test_a_capture_is_applied_on_its_own(): void {
		$placed  = $this->placed( StubGateway::APPROVE );
		$capture = StubWebhooks::of( $this->captureOf( $placed ) );

		$this->assertSame( ReceiptResult::Applied, $this->deliver( $capture ) );
		$this->assertSame( 'captured', $this->intentRow( $placed['intent_uuid'] )['status'] );
		$this->assertSame( 'paid', $this->orderState( $placed['order_id'] )['payment_status'] );
		$this->assertSame( array( 'authorize:approved:1:stub-ch-' . $placed['intent_uuid'], 'capture:approved:1:stub-cap-' . $placed['intent_uuid'] ), $this->ledgerOf( $placed['order_id'] ) );
	}

	/**
	 * Tests that a void delivered for a placement still waiting for its shopper settles it as the window's end does: what it holds released, the order cancelled.
	 *
	 * @since 0.2.0
	 */
	public function test_a_void_of_a_waiting_placement_releases_it(): void {
		$placed = $this->placed( StubGateway::REQUIRES_ACTION );
		$void   = StubWebhooks::of( $this->voidOf( $placed ) );

		$this->assertSame( 'requires_action', $placed['status'] );
		$this->assertSame( ReceiptResult::Applied, $this->deliver( $void ) );
		$this->assertSame( array( 'cancelled', 'voided', '0' ), array_values( $this->orderState( $placed['order_id'] ) ) );
		$this->assertSame( array( 'voided', 'voided_externally' ), array( $this->intentRow( $placed['intent_uuid'] )['status'], $this->intentRow( $placed['intent_uuid'] )['voided_reason'] ) );
		$this->assertSame( array( 5, 0, 0 ), $this->committedStock( $this->secondConnection(), $placed['variant'] ), 'The hold is released.' );
	}

	/**
	 * Tests that a void delivered for an accepted order, which nobody asked the store for, parks the order for a person with the flag and keeps its allocation.
	 *
	 * Planted violation: in PaymentService::parkReasonOf(), answer null for VoidReason::VoidedExternally:
	 * the order is then left processing with its payment voided, and nobody is told.
	 *
	 * @since 0.2.0
	 */
	public function test_a_void_of_an_accepted_order_parks_it_for_a_person(): void {
		$placed = $this->placed( StubGateway::APPROVE );
		$void   = StubWebhooks::of( $this->voidOf( $placed ) );

		$this->assertSame( ReceiptResult::Applied, $this->deliver( $void ) );
		$this->assertSame( array( 'on_hold', 'voided', '1' ), array_values( $this->orderState( $placed['order_id'] ) ) );
		$this->assertContains( 'order:processing>on_hold:' . PaymentService::VOIDED_EXTERNALLY, $this->eventsOf( $placed['order_id'] ) );
		$this->assertSame( array( 5, 1, 0 ), $this->committedStock( $this->secondConnection(), $placed['variant'] ), 'The allocation is kept for a person.' );
	}

	/**
	 * Tests that a capture of an intent never authorized moves money the ledger did not expect: kept for a person, the order flagged, and the payment refusing what a person must reconcile first.
	 *
	 * @since 0.2.0
	 */
	public function test_a_capture_of_an_intent_never_authorized_is_kept_for_a_person(): void {
		$placed  = $this->placed( StubGateway::THROW );
		$capture = StubWebhooks::of( $this->resultAbout( $placed, Operation::Capture, Outcome::Approved, 'stub-cap-' . $placed['intent_uuid'] ) );

		$this->assertSame( ReceiptResult::Unapplied, $this->deliver( $capture ) );
		$this->assertSame( array( 'unapplied', PaymentService::UNEXPECTED_RESULT ), array( $this->receiptOf( $capture->eventId() )['result'], $this->receiptOf( $capture->eventId() )['result_code'] ) );
		$this->assertSame( array( 'capture:approved:0:stub-cap-' . $placed['intent_uuid'] ), $this->ledgerOf( $placed['order_id'] ) );
		$this->assertSame( '1', $this->orderState( $placed['order_id'] )['has_unreconciled_money'] );
		$this->assertSame( 'created', $this->intentRow( $placed['intent_uuid'] )['status'], 'The intent did not move.' );
	}

	/**
	 * Tests that an approval arriving after the placement ended without it is kept for a person under the one word for it, which the receipt names too.
	 *
	 * @since 0.2.0
	 */
	public function test_an_approval_after_the_placement_failed_is_a_late_approval(): void {
		$placed = $this->placed( StubGateway::DECLINE );
		$late   = StubWebhooks::of( $this->resultAbout( $placed, Operation::Authorize, Outcome::Approved, 'stub-ch-second-card' ) )->withEventId( 'evt_late_approval' );

		$this->assertSame( 'failed', $placed['status'] );
		$this->assertSame( ReceiptResult::Unapplied, $this->deliver( $late ) );
		$this->assertSame( array( 'unapplied', PaymentService::LATE_APPROVAL ), array( $this->receiptOf( 'evt_late_approval' )['result'], $this->receiptOf( 'evt_late_approval' )['result_code'] ) );
		$this->assertSame( '1', $this->orderState( $placed['order_id'] )['has_unreconciled_money'] );
	}

	/**
	 * Tests that an approval of a placement that failed, arriving after another order was placed from the cart its decline opened again, is kept for a person and leaves that other order alone: its statuses, its allocation, the stock, the cart and its key.
	 *
	 * Two rounds: a guest whose next attempt sends a key never seen; and a signed-in user whose key a
	 * visitor then sends, which places under a key row of the visitor's own. In both, the approval is
	 * the first intent's, and only the first order and its key's answer change.
	 *
	 * @since 0.2.0
	 */
	public function test_a_late_approval_after_another_order_was_placed_from_the_reopened_cart_leaves_that_order_alone(): void {
		$user   = $this->createUser( 'subscriber' );
		$rounds = array(
			'a guest, then a key never seen' => array( self::guest(), 'late-1', 'late-1, again' ),
			'a signed-in user, then a visitor with the same key' => array( Actor::user( $user ), 'late-2', null ),
		);

		foreach ( $rounds as $round => list( $first, $key, $nextKey ) ) {
			$variant = $this->sellable();
			$cart    = $this->readyCart( array( $variant => 1 ) );

			try {
				$this->placement->place( $this->placeInput( $key, StubGateway::DECLINE ), $first );
				$this->fail( "{$round}: the first attempt was not declined." );
			} catch ( CodedException $declined ) {
				$this->assertSame( 'checkout.payment_declined', $declined->errorCode()->value, $round );
			}

			$failed = $this->latestPlaced();

			try {
				$next = $this->placement->place( $this->placeInput( $nextKey ?? $key ), self::guest() );
			} catch ( CodedException $refused ) {
				$this->fail( "{$round}: the order from the cart opened again was refused " . $refused->errorCode()->value );
			}

			$this->assertSame( 'approved', $next['outcome'] ?? null, $round );

			$second   = (int) $this->db->fetchValue( 'SELECT id FROM %i WHERE uuid = %s', $this->table( OrderTables::ORDERS ), $next['order_uuid'] );
			$around   = $this->around( $second, $variant, $cart->id );
			$keptNext = $this->keyOf( $second );
			$late     = StubWebhooks::of( $this->resultAbout( $failed, Operation::Authorize, Outcome::Approved, 'stub-ch-late-' . $failed['intent_uuid'] ) )->withEventId( 'evt_late_' . substr( md5( $round ), 0, 12 ) );

			$this->assertSame( array( 'converted', $second ), array( $around['cart']['status'] ?? null, $around['cart']['order_id'] ?? null ), "{$round}: the cart is converted, naming the second order." );
			$this->assertSame( ReceiptResult::Unapplied, $this->deliver( $late ), $round );
			$this->assertSame( array( 'unapplied', PaymentService::LATE_APPROVAL ), array( $this->receiptOf( $late->eventId() )['result'] ?? null, $this->receiptOf( $late->eventId() )['result_code'] ?? null ), $round );
			$this->assertSame( array( 'failed', '1' ), array( $this->orderState( $failed['order_id'] )['status'], $this->orderState( $failed['order_id'] )['has_unreconciled_money'] ), "{$round}: the first order is still failed, and flagged." );
			$this->assertSame( $around, $this->around( $second, $variant, $cart->id ), "{$round}: the second order, its allocation, the stock and the cart are as they were." );
			$this->assertSame( 'late_approval', $this->keptOutcome( $failed['order_id'] ), "{$round}: the first key keeps the late approval." );
			$this->assertSame( $keptNext, $this->keyOf( $second ), "{$round}: the second key is as it was." );
		}
	}

	/**
	 * Tests that a result naming only the provider's own reference to the intent is settled on the intent that reference belongs to.
	 *
	 * @since 0.2.0
	 */
	public function test_a_result_naming_only_the_providers_reference_finds_its_intent(): void {
		$placed  = $this->placed( StubGateway::APPROVE );
		$intent  = $this->intentRow( $placed['intent_uuid'] );
		$capture = StubWebhooks::of( self::stubResult( '', Operation::Capture, Outcome::Approved, (int) $intent['amount_minor'], (string) $intent['currency'], 'stub-cap-' . $placed['intent_uuid'], $placed['provider_intent_id'] ) );

		$this->assertNotNull( $placed['provider_intent_id'] );
		$this->assertSame( ReceiptResult::Applied, $this->deliver( $capture ) );
		$this->assertSame( $placed['intent_uuid'], $this->receiptOf( $capture->eventId() )['intent_uuid'] );
		$this->assertSame( 'captured', $this->intentRow( $placed['intent_uuid'] )['status'] );
	}

	/**
	 * Tests that a result naming no intent of the store's is ignored, and one naming an intent of the other mode, as a second site on a shared test account sees, is ignored without moving it.
	 *
	 * Planted violation: in ReceiveWebhook::decide(), drop the comparison of the intent's mode with
	 * the address's: the live intent is then authorized by a delivery to the test address.
	 *
	 * @since 0.2.0
	 */
	public function test_a_result_about_no_intent_of_this_address_is_ignored(): void {
		$placed  = $this->placed( StubGateway::THROW );
		$unknown = StubWebhooks::of( self::stubResult( '01928c3e-0000-7000-8000-00000000dead', Operation::Authorize, Outcome::Approved, 1000, 'EUR', 'stub-ch-nobody' ) );
		$foreign = StubWebhooks::of( self::stubResult( '', Operation::Capture, Outcome::Approved, 1000, 'EUR', 'stub-cap-nobody', 'pi_of_another_site' ) );

		$this->db->execute( "UPDATE %i SET mode = 'live' WHERE uuid = %s", $this->table( PaymentTables::INTENTS ), $placed['intent_uuid'] );

		$live = StubWebhooks::of( $this->approvalOf( $placed ) );

		foreach ( array( $unknown, $foreign, $live ) as $delivery ) {
			$this->assertSame( ReceiptResult::Ignored, $this->deliver( $delivery ) );
		}

		$this->assertSame( array( 'ignored', 'unknown_intent' ), array( $this->receiptOf( $unknown->eventId() )['result'], $this->receiptOf( $unknown->eventId() )['result_code'] ) );
		$this->assertSame( 'unknown_intent', $this->receiptOf( $foreign->eventId() )['result_code'] );
		$this->assertSame( array( 'ignored', 'mode_mismatch', $placed['intent_uuid'] ), array( $this->receiptOf( $live->eventId() )['result'], $this->receiptOf( $live->eventId() )['result_code'], $this->receiptOf( $live->eventId() )['intent_uuid'] ) );
		$this->assertSame( 'created', $this->intentRow( $placed['intent_uuid'] )['status'], 'The live intent did not move.' );
		$this->assertSame( array(), $this->ledgerOf( $placed['order_id'] ) );
	}

	/**
	 * Tests that a dispute is ignored with its type and never flags the order, and that an event the stand-in does not report on is ignored too.
	 *
	 * Planted violation: in ReceiveWebhook::settle(), park the disputed order for a person, in a
	 * transaction of its own (`$this->orders->park( $this->orders->lockForPayment( $disputed->orderId ), 'disputed', … )`):
	 * the order is then flagged as holding money a person must reconcile, and on hold.
	 *
	 * @since 0.2.0
	 */
	public function test_a_dispute_is_ignored_and_never_flags_the_order(): void {
		$placed  = $this->placed( StubGateway::APPROVE );
		$dispute = StubWebhooks::dispute( $placed['intent_uuid'] );
		$payout  = StubWebhooks::event( 'evt_payout', 'payout.paid', array() );

		$this->assertSame( ReceiptResult::Ignored, $this->deliver( $dispute ) );
		$this->assertSame( ReceiptResult::Ignored, $this->deliver( $payout ) );
		$this->assertSame( array( 'ignored', StubGateway::DISPUTE_EVENT, $placed['intent_uuid'] ), array( $this->receiptOf( $dispute->eventId() )['result'], $this->receiptOf( $dispute->eventId() )['result_code'], $this->receiptOf( $dispute->eventId() )['intent_uuid'] ) );
		$this->assertSame( array( 'ignored', 'payout.paid', null ), array( $this->receiptOf( 'evt_payout' )['result'], $this->receiptOf( 'evt_payout' )['result_code'], $this->receiptOf( 'evt_payout' )['intent_uuid'] ) );
		$this->assertSame( array( 'processing', 'authorized', '0' ), array_values( $this->orderState( $placed['order_id'] ) ), 'A dispute changes nothing in the store.' );

		$warning = $this->logged( 'payment.webhook_ignored' );

		$this->assertSame( $placed['order_uuid'], $warning[0]['order_uuid'] ?? null, 'The dispute names its order in the log.' );
	}

	/**
	 * Reads what a delivery about another order must leave alone: an order's statuses and flag, its allocation rows, its variant's stock and its cart's row, as committed.
	 *
	 * @since 0.2.0
	 *
	 * @param int $orderId The order.
	 * @param int $variant The variant it holds.
	 * @param int $cartId  The cart it was placed from.
	 * @return array<string, mixed> The four.
	 */
	private function around( int $orderId, int $variant, int $cartId ): array {
		$b = $this->secondConnection();

		return array(
			'order'       => $this->orderState( $orderId ),
			'allocations' => $this->db->fetchAll( 'SELECT * FROM %i WHERE order_id = %d ORDER BY id', $this->table( InventoryTables::ALLOCATIONS ), $orderId ),
			'stock'       => $this->committedStock( $b, $variant ),
			'cart'        => $this->committedCart( $b, $cartId ),
		);
	}

	/**
	 * Reads the row of the key an order was placed with, whole.
	 *
	 * @since 0.2.0
	 *
	 * @param int $orderId The order.
	 * @return array<string, mixed>|null The row, or null.
	 */
	private function keyOf( int $orderId ): ?array {
		return $this->db->fetchRow( 'SELECT * FROM %i WHERE order_id = %d', $this->table( CheckoutTables::IDEMPOTENCY_KEYS ), $orderId );
	}
}
