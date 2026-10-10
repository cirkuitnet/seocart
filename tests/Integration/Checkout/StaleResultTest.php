<?php
/**
 * Tests that a gateway answer about a state the intent has left changes nothing: not the money, the order, the stock or the kept answer
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Checkout;

use SEOCart\Checkout\Application\SettlePlacement;
use SEOCart\Checkout\Domain\PlacementOutcome;
use SEOCart\Checkout\Infrastructure\CheckoutTables;
use SEOCart\Contracts\Payment\GatewayResult;
use SEOCart\Contracts\Payment\Operation;
use SEOCart\Contracts\Payment\Outcome;
use SEOCart\Inventory\Infrastructure\InventoryTables;
use SEOCart\Order\Infrastructure\OrderTables;
use SEOCart\Payment\Application\PaymentService;
use SEOCart\Payment\Domain\ApplicationKind;
use SEOCart\Payment\Infrastructure\Gateway\StubGateway;
use SEOCart\Payment\Infrastructure\PaymentTables;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Support\Currency;
use SEOCart\Support\Money;
use SEOCart\Tests\Support\Checkout\PlacementTestCase;

/**
 * An earlier attempt's decline delivered after a later attempt was authorized, or a request to act delivered after the authorization, is stale: the placement stays as the authorization left it.
 *
 * A provider may report an attempt's outcome late, by a webhook or a status query: a
 * `payment_failed` of a first card, generated before the shopper's second card was authorized,
 * can arrive after it. The payment path decides it under the intent's lock: a decline fails an
 * intent only from the states that decline may fail, so the authorized intent stays authorized,
 * the order processing and its units allocated, and no ledger row is written. The settlement then
 * does nothing, as for an answer applied before.
 *
 * Planted violations, each shown red and removed:
 * - in PaymentService::applyMoneyFact(), skip the stale check, so a decline reaches APPLY_DECLINE
 *   as before: the authorized intent fails;
 * - in PaymentService::applyWait(), raise `payment.unexpected_result` for a wait reported past
 *   waiting, as before: the late request to act is refused instead of being stale.
 *
 * @since 0.2.0
 */
final class StaleResultTest extends PlacementTestCase {

	/**
	 * Tests that a declined authorization of an earlier attempt, delivered after the approval, leaves the intent authorized, the order processing and its units allocated.
	 *
	 * @since 0.2.0
	 */
	public function test_an_earlier_attempts_decline_after_the_approval_changes_nothing(): void {
		$mug    = $this->sellable();
		$placed = $this->placeApproved( $mug );
		$late   = $this->result( $placed['intent'], Outcome::Declined, 'stub-ch-first-card', StubGateway::CARD_DECLINED );
		$before = $this->state( $placed['order_id'] );

		$settled = $this->kernel->get( SettlePlacement::class )->apply( $late, Actor::user( 0 ) );

		$this->assertSame( PlacementOutcome::Duplicate, $settled->outcome, 'The settlement does nothing for a stale answer.' );
		$this->assertSame( $before, $this->state( $placed['order_id'] ), 'Nothing moved: the intent, the ledger, the order, the stock and the kept answer are as the approval left them.' );
		$this->assertSame( array( 'authorized', 'processing', 'authorized' ), array( $before['intent']['status'], $before['order']['status'], $before['order']['payment_status'] ) );
		$this->assertSame( array( 5, 1, 0 ), $this->committedStock( $this->secondConnection(), $mug ), 'The unit stays allocated.' );
	}

	/**
	 * Tests that the payment path answers the late decline stale, and a request to act delivered after the authorization stale too, each writing nothing.
	 *
	 * @since 0.2.0
	 */
	public function test_the_payment_path_answers_each_late_answer_stale(): void {
		$placed = $this->placeApproved( $this->sellable() );
		$before = $this->state( $placed['order_id'] );
		$late   = array(
			'a decline of an earlier attempt' => $this->result( $placed['intent'], Outcome::Declined, 'stub-ch-first-card', StubGateway::CARD_DECLINED ),
			'a request to act'                => $this->result( $placed['intent'], Outcome::RequiresAction, null, null ),
			'a gateway still deciding'        => $this->result( $placed['intent'], Outcome::Pending, null, null ),
		);

		foreach ( $late as $what => $result ) {
			$this->assertSame( ApplicationKind::Stale, $this->db->transaction( fn() => $this->kernel->get( PaymentService::class )->applyGatewayResult( $result, Actor::user( 0 ) ) )->kind, $what );
			$this->assertSame( $before, $this->state( $placed['order_id'] ), $what . ' changed nothing.' );
		}
	}

	/**
	 * Places an order for one unit of a variant with a payment the stub approves.
	 *
	 * @since 0.2.0
	 *
	 * @param int $variant The variant.
	 * @return array{order_id: int, intent: array<string, mixed>} The order's id, and its intent's row.
	 */
	private function placeApproved( int $variant ): array {
		$this->readyCart( array( $variant => 1 ) );

		$answer = $this->placement->place( $this->placeInput(), self::guest() );

		$this->assertSame( 'approved', $answer['outcome'] );

		$intent = (array) $this->db->fetchRow(
			'SELECT i.* FROM %i i JOIN %i o ON o.id = i.order_id WHERE o.uuid = %s',
			$this->table( PaymentTables::INTENTS ),
			$this->table( OrderTables::ORDERS ),
			$answer['order_uuid']
		);

		return array(
			'order_id' => (int) $intent['order_id'],
			'intent'   => $intent,
		);
	}

	/**
	 * Builds an answer of the stub about the order's authorization, as a provider reports it late.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $intent    The intent's row.
	 * @param Outcome              $outcome   The outcome.
	 * @param string|null          $objectId  The provider's object, or null for a request to act or a pending answer.
	 * @param string|null          $errorCode The decline's code, or null.
	 * @return GatewayResult The answer.
	 */
	private function result( array $intent, Outcome $outcome, ?string $objectId, ?string $errorCode ): GatewayResult {
		return new GatewayResult( StubGateway::ID, Operation::Authorize, $outcome, (string) $intent['uuid'], Money::of( (int) $intent['amount_minor'], Currency::of( (string) $intent['currency'] ) ), $objectId, (string) $intent['provider_intent_id'], $errorCode );
	}

	/**
	 * Reads everything a stale answer must leave alone: the intent, its ledger, the order, its allocations and holds, and the answer its key keeps.
	 *
	 * @since 0.2.0
	 *
	 * @param int $orderId The order.
	 * @return array<string, mixed> The state.
	 */
	private function state( int $orderId ): array {
		return array(
			'intent'      => (array) $this->db->fetchRow( 'SELECT status, authorized_minor, captured_minor, updated_at FROM %i WHERE order_id = %d', $this->table( PaymentTables::INTENTS ), $orderId ),
			'ledger'      => $this->db->fetchAll( 'SELECT * FROM %i WHERE order_id = %d ORDER BY id', $this->table( PaymentTables::TRANSACTIONS ), $orderId ),
			'order'       => (array) $this->db->fetchRow( 'SELECT status, payment_status, authorized_minor, has_unreconciled_money, updated_at FROM %i WHERE id = %d', $this->table( OrderTables::ORDERS ), $orderId ),
			'events'      => $this->db->fetchAll( 'SELECT * FROM %i WHERE order_id = %d ORDER BY id', $this->table( OrderTables::EVENTS ), $orderId ),
			'allocations' => $this->db->fetchAll( 'SELECT * FROM %i ORDER BY id', $this->table( InventoryTables::ALLOCATIONS ) ),
			'holds'       => $this->db->fetchAll( 'SELECT * FROM %i ORDER BY id', $this->table( InventoryTables::HOLDS ) ),
			'kept'        => $this->db->fetchValue( 'SELECT response_json FROM %i WHERE order_id = %d', $this->table( CheckoutTables::IDEMPOTENCY_KEYS ), $orderId ),
		);
	}
}
