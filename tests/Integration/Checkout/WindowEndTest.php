<?php
/**
 * Tests the end of a shopper's time to act: the payment voided at its gateway before anything the order holds is released
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Checkout;

use SEOCart\Checkout\Infrastructure\CheckoutTables;
use SEOCart\Checkout\Infrastructure\Jobs\ReconcileStalePlacements;
use SEOCart\Contracts\Payment\GatewayResult;
use SEOCart\Contracts\Payment\Operation;
use SEOCart\Contracts\Payment\Outcome;
use SEOCart\Contracts\Payment\VoidRequest;
use SEOCart\Order\Infrastructure\OrderTables;
use SEOCart\Payment\Infrastructure\Gateway\StubGateway;
use SEOCart\Payment\Infrastructure\PaymentTables;
use SEOCart\Platform\Database\Schema\DdlGenerator;
use SEOCart\Platform\Database\Schema\SchemaVerifier;
use SEOCart\Platform\Database\SchemaOperations;
use SEOCart\Platform\Database\TransactionManager;
use SEOCart\Platform\Kernel\GateState;
use SEOCart\Platform\Logging\LogsTable;
use SEOCart\Platform\Logging\Migrations\CreateLogsMigration;
use SEOCart\Promotion\Infrastructure\PromotionTables;
use SEOCart\Tests\Support\Checkout\PlacementKernel;
use SEOCart\Tests\Support\Doubles\ClosedGateTransactions;
use SEOCart\Tests\Support\Checkout\PlacementTestCase;

/**
 * A payment whose shopper was asked to act, and whose time to act ran out, is voided at its gateway by the reconciliation run, and the void is settled as the placement's own settlement settles a decline: the hold, the promotion's use and the cart given back, the order cancelled.
 *
 * A real provider keeps such a payment waiting until something cancels it: releasing the order
 * without the void could leave the shopper's money held at the provider, or charged by a late
 * confirmation. A provider that had approved meanwhile answers the void with the authorization's
 * approval, which the run settles as an approval: the order goes on, and nothing is released. A
 * provider that refuses to cancel leaves the placement as it was, reported deferred. A payment the
 * gateway itself is still deciding is never voided because time passed; nor is one whose shopper
 * still has time.
 *
 * Planted violations, each shown red and removed:
 * - in ReconcileStalePlacements::settle(), release the placement as a decline before asking the
 *   gateway to void: the shopper who finished in time loses the order's units;
 * - in ReconcileStalePlacements::settle(), void every intent whose wait ended, processing included:
 *   the gateway is asked to void a payment it is still deciding.
 *
 * @since 0.2.0
 */
final class WindowEndTest extends PlacementTestCase {

	/**
	 * Creates the log's table, so what the run reports can be read.
	 *
	 * @since 0.2.0
	 */
	public function set_up(): void {
		parent::set_up();

		( new CreateLogsMigration() )->up( new SchemaOperations( $this->db, new DdlGenerator(), new SchemaVerifier( $this->db ) ) );
	}

	/**
	 * Tests that a payment whose shopper's time ran out is voided at the gateway, and the order cancelled with everything it held given back, on no user's authority.
	 *
	 * @since 0.2.0
	 */
	public function test_a_payment_whose_shopper_never_acted_is_voided_and_released(): void {
		$placement = $this->waiting( StubGateway::REQUIRES_ACTION, 'SAVE5' );

		$this->windowEnded( $placement['order_uuid'] );
		$this->assertNull( $this->kernel->get( ReconcileStalePlacements::class )->handle( array() ) );

		$b     = $this->secondConnection();
		$order = $this->committedOrder( $b, $placement['order_uuid'] );

		$this->assertNotNull( $order );
		$this->assertSame( array( 'cancelled', 'voided', 0 ), array( $order['status'], $order['payment_status'], $order['has_unreconciled_money'] ) );
		$this->assertSame( array( 'voided', 'action_window_ended' ), array_values( (array) $b->fetchRow( sprintf( 'SELECT status, voided_reason FROM `%s` WHERE order_id = %d', $this->table( PaymentTables::INTENTS ), $order['id'] ) ) ) );
		$this->assertSame( array( 'void', 'approved', '1', 'system', null ), array_values( (array) $b->fetchRow( sprintf( 'SELECT operation, result, applied, actor_type, actor_id FROM `%s` WHERE order_id = %d', $this->table( PaymentTables::TRANSACTIONS ), $order['id'] ) ) ), 'One ledger row, the void, recorded as the store\'s.' );
		$this->assertSame( array( 5, 0, 0 ), $this->committedStock( $b, $placement['variant'] ), 'The hold is given back.' );
		$this->assertSame( 'released', $b->fetchValue( sprintf( 'SELECT state FROM `%s` WHERE order_id = %d', $this->table( PromotionTables::USAGE ), $order['id'] ) ) );
		$this->assertSame( 'open', $this->committedCart( $b, $placement['cart_id'] )['status'] ?? null );
		$this->assertSame( 'voided', $this->kept( $order['id'] )['outcome'] ?? null, 'A retry of the placement is told it was voided.' );
		$this->assertContains( 'void', array_column( $this->gateway->calls, 'method' ) );
		$this->assertSame( array(), $this->logged( ReconcileStalePlacements::DEFERRED ), 'Nothing was deferred.' );
	}

	/**
	 * Tests that a provider that approved before the void reached it answers with the approval, which the run settles as an approval: allocated, accepted, never released.
	 *
	 * @since 0.2.0
	 */
	public function test_a_payment_approved_before_the_void_reached_its_provider_goes_on(): void {
		$placement = $this->waiting( StubGateway::REQUIRES_ACTION_COMPLETED );

		$this->windowEnded( $placement['order_uuid'] );
		$this->kernel->get( ReconcileStalePlacements::class )->handle( array() );

		$b     = $this->secondConnection();
		$order = $this->committedOrder( $b, $placement['order_uuid'] );

		$this->assertNotNull( $order );
		$this->assertSame( array( 'processing', 'authorized' ), array( $order['status'], $order['payment_status'] ) );
		$this->assertSame( 'authorize', $b->fetchValue( sprintf( 'SELECT GROUP_CONCAT( operation ) FROM `%s` WHERE order_id = %d', $this->table( PaymentTables::TRANSACTIONS ), $order['id'] ) ), 'No void was recorded: the provider kept the approval.' );
		$this->assertSame( array( 5, 1, 0 ), $this->committedStock( $b, $placement['variant'] ), 'The unit is allocated, never released.' );
		$this->assertSame( 'converted', $this->committedCart( $b, $placement['cart_id'] )['status'] ?? null );
		$this->assertSame( 'approved', $this->kept( $order['id'] )['outcome'] ?? null );
	}

	/**
	 * Tests that a payment the gateway is still deciding is asked about, never voided, however long it waited; and that one whose shopper still has time is asked about too.
	 *
	 * @since 0.2.0
	 */
	public function test_only_a_shopper_whose_time_ran_out_has_the_payment_voided(): void {
		$deciding  = $this->waiting( StubGateway::PENDING );
		$confirmed = $this->waiting( StubGateway::REQUIRES_ACTION );

		$this->windowEnded( $deciding['order_uuid'] );
		$this->db->execute( 'UPDATE %i SET updated_at = UTC_TIMESTAMP(6) - INTERVAL 11 MINUTE', $this->table( PaymentTables::INTENTS ) );
		$this->kernel->get( ReconcileStalePlacements::class )->handle( array() );

		$b = $this->secondConnection();

		$this->assertSame( array( 'query', 'query' ), array_values( array_filter( array_column( $this->gateway->calls, 'method' ), static fn( string $method ): bool => in_array( $method, array( 'query', 'void' ), true ) ) ), 'Both were asked about; neither was voided.' );
		$this->assertSame( 'failed', $this->committedOrder( $b, $deciding['order_uuid'] )['status'] ?? null, 'The gateway answered the pending payment expired, a decline.' );
		$this->assertSame( 'processing', $this->committedOrder( $b, $confirmed['order_uuid'] )['status'] ?? null, 'The shopper still had time, and confirmed.' );
	}

	/**
	 * Tests that a provider that refuses to cancel leaves the placement waiting as it was, reported deferred for the next run to ask again; and that a closed schema gate defers it too, before the gateway is asked.
	 *
	 * @since 0.2.0
	 */
	public function test_a_void_the_provider_refuses_or_the_gate_holds_is_deferred(): void {
		$placement = $this->waiting( StubGateway::REQUIRES_ACTION );

		$this->windowEnded( $placement['order_uuid'] );

		$this->gateway->voids = static fn( VoidRequest $request ): GatewayResult => new GatewayResult( StubGateway::ID, Operation::Void, Outcome::Declined, $request->intentUuid, $request->amount, 'stub-void-' . $request->intentUuid, $request->providerIntentId, 'intent_not_cancelable' );

		$this->kernel->get( ReconcileStalePlacements::class )->handle( array() );

		$b = $this->secondConnection();

		$this->assertSame( array( 'pending_payment', 'requires_action' ), array( $this->committedOrder( $b, $placement['order_uuid'] )['status'] ?? null, $b->fetchValue( sprintf( "SELECT i.status FROM `%s` i JOIN `%s` o ON o.id = i.order_id WHERE o.uuid = '%s'", $this->table( PaymentTables::INTENTS ), $this->table( OrderTables::ORDERS ), $placement['order_uuid'] ) ) ) );
		$this->assertSame( array( 5, 0, 1 ), $this->committedStock( $b, $placement['variant'] ), 'The order keeps its hold.' );
		$this->assertSame( array( 'payment.operation_declined' ), array_column( $this->logged( ReconcileStalePlacements::DEFERRED ), 'reason' ) );

		$calls = count( $this->gateway->calls );
		$gated = PlacementKernel::over( $this->db, $this->tokens, $this->identities, $this->wake, $this->reporter(), array( TransactionManager::class => fn(): TransactionManager => new ClosedGateTransactions( $this->db, GateState::CodeNewer ) ) );

		$gated->get( ReconcileStalePlacements::class )->handle( array() );

		$this->assertSame( array( 'payment.operation_declined', 'store.unavailable' ), array_column( $this->logged( ReconcileStalePlacements::DEFERRED ), 'reason' ) );
		$this->assertSame( $calls, count( $this->gateway->calls ), 'The gate refused before the gateway was asked.' );
	}

	/**
	 * Places an order for one unit of a new variant whose payment waits, with a promotion when one is named.
	 *
	 * @since 0.2.0
	 *
	 * @param string      $paymentToken The stub's script.
	 * @param string|null $code         Optional. A promotion code to plant and apply. Default none.
	 * @return array{order_uuid: string, variant: int, cart_id: int} The placement.
	 */
	private function waiting( string $paymentToken, ?string $code = null ): array {
		$variant = $this->sellable();

		if ( null !== $code ) {
			$this->plantPromotion( $code );
		}

		$cart   = $this->readyCart( array( $variant => 1 ), null === $code ? array() : array( $code ) );
		$answer = $this->placement->place( $this->placeInput( 'attempt-' . $variant, $paymentToken ), self::guest() );

		$this->assertContains( $answer['outcome'], array( 'requires_action', 'processing' ) );

		return array(
			'order_uuid' => (string) $answer['order_uuid'],
			'variant'    => $variant,
			'cart_id'    => $cart->id,
		);
	}

	/**
	 * Makes an order's payment stale, unchanged for eleven minutes, and its wait run out a minute ago, by the database clock.
	 *
	 * @since 0.2.0
	 *
	 * @param string $orderUuid The order.
	 */
	private function windowEnded( string $orderUuid ): void {
		$this->db->execute(
			'UPDATE %i i JOIN %i o ON o.id = i.order_id SET i.updated_at = UTC_TIMESTAMP(6) - INTERVAL 11 MINUTE, i.customer_action_expires_at = UTC_TIMESTAMP() - INTERVAL 1 MINUTE WHERE o.uuid = %s',
			$this->table( PaymentTables::INTENTS ),
			$this->table( OrderTables::ORDERS ),
			$orderUuid
		);
	}

	/**
	 * Returns the contexts of the lines the log holds under a code, in order.
	 *
	 * @since 0.2.0
	 *
	 * @param string $code The code.
	 * @return list<array<string, mixed>> The contexts.
	 */
	private function logged( string $code ): array {
		return array_map(
			static fn( array $line ): array => (array) json_decode( (string) $line['context_json'], true ),
			$this->db->fetchAll( 'SELECT context_json FROM %i WHERE machine_code = %s ORDER BY id', $this->table( LogsTable::NAME ), $code )
		);
	}

	/**
	 * Reads the answer an order's placement key keeps.
	 *
	 * @since 0.2.0
	 *
	 * @param int $orderId The order.
	 * @return array<string, mixed> The answer.
	 */
	private function kept( int $orderId ): array {
		return (array) json_decode( (string) $this->db->fetchValue( 'SELECT response_json FROM %i WHERE order_id = %d', $this->table( CheckoutTables::IDEMPOTENCY_KEYS ), $orderId ), true );
	}
}
