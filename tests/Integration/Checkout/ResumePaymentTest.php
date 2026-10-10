<?php
/**
 * Tests the resume of a placement's payment once the shopper is back from acting for the provider
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Checkout;

use SEOCart\Application\Operations\CompiledOperation;
use SEOCart\Cart\Application\CartError;
use SEOCart\Cart\Infrastructure\CartTables;
use SEOCart\Checkout\Application\ResumePayment;
use SEOCart\Checkout\Domain\CheckoutError;
use SEOCart\Checkout\Infrastructure\CheckoutTables;
use SEOCart\Checkout\Infrastructure\Jobs\ReconcileStalePlacements;
use SEOCart\Checkout\Interfaces\StoreApi\CheckoutOperations;
use SEOCart\Contracts\Payment\GatewayResult;
use SEOCart\Contracts\Payment\GatewayUnavailable;
use SEOCart\Contracts\Payment\Operation;
use SEOCart\Contracts\Payment\Outcome;
use SEOCart\Contracts\Payment\PaymentGateway;
use SEOCart\Contracts\Payment\PaymentQuery;
use SEOCart\Contracts\Payment\VoidRequest;
use SEOCart\Order\Infrastructure\OrderTables;
use SEOCart\Payment\Application\PaymentError;
use SEOCart\Payment\Infrastructure\Gateway\StubGateway;
use SEOCart\Payment\Infrastructure\PaymentTables;
use SEOCart\Platform\Database\TransactionManager;
use SEOCart\Platform\Kernel\GateState;
use SEOCart\Platform\Kernel\KernelError;
use SEOCart\Support\Error\CodedException;
use SEOCart\Tests\Support\Checkout\PlacementKernel;
use SEOCart\Tests\Support\Checkout\PlacementTestCase;
use SEOCart\Tests\Support\Doubles\ClosedGateTransactions;

/**
 * `checkout.resume_payment` asks the provider where a placement's payment stands and settles the answer through the placement's own settlement, once; the browser settles nothing itself.
 *
 * It answers the shopper whose cart made the placement: a payment of another cart is answered as
 * one that does not exist. A payment another path settled first, or one resumed twice, is answered
 * as it stands, outcome `duplicate`, and the provider is not asked again. A provider that cannot
 * answer is refused as the placement is, naming the order, which waits. One still waiting is
 * answered as waiting. A shopper back after their time to act ran out has the payment voided first.
 *
 * Planted violations, each shown red and removed:
 * - in ResumePayment::resume(), skip the check that the payment is the cart's: another cart's
 *   token resumes it;
 * - in ResumePayment::resume(), ask the provider whatever the payment's state: the second resume
 *   asks again;
 * - in ResumePayment::resume(), ask the gate after the cart's read: the read reaches the carts
 *   table;
 * - in ResumePayment::resume(), skip the void of a payment whose time to act ran out: the provider
 *   is asked where it stands, its expiry settles as a decline, and the hold is released with no void;
 * - in ResumePayment::resume(), settle every answer the provider gives: a provider still waiting
 *   for the shopper is answered `duplicate`.
 *
 * @since 0.2.0
 */
final class ResumePaymentTest extends PlacementTestCase {

	/**
	 * Tests that a resume settles the provider's approval once: the units allocated, the order accepted, the cart converted, the key's answer approved; asked again, it is a duplicate that asks the provider nothing.
	 *
	 * @since 0.2.0
	 */
	public function test_a_resume_settles_the_approval_once(): void {
		list( $answer, $intent, $variant ) = $this->placeActing();

		$resumed = $this->resume( $intent );
		$b       = $this->secondConnection();

		$this->assertSame(
			array(
				'intent_uuid'    => $intent,
				'outcome'        => 'approved',
				'status'         => 'processing',
				'payment_status' => 'authorized',
			),
			$resumed
		);
		$this->assertSame( array( 5, 1, 0 ), $this->committedStock( $b, $variant ) );
		$this->assertSame( 'approved', $this->kept( (string) $answer['order_uuid'] )['outcome'] ?? null );

		$queries = $this->queries();

		$this->assertSame( 'duplicate', $this->resume( $intent )['outcome'], 'Resumed again, the payment stands as settled.' );
		$this->assertSame( $queries, $this->queries(), 'The provider was not asked again.' );
		$this->assertSame( 1, $this->committedCount( $b, PaymentTables::TRANSACTIONS ), 'One ledger row.' );

		$valid = rest_validate_value_from_schema( $resumed, ( new CompiledOperation( CheckoutOperations::resumePayment() ) )->outputSchema(), 'resume' );

		$this->assertTrue( true === $valid, is_wp_error( $valid ) ? $valid->get_error_message() : 'The answer is refused by its declared schema.' );
	}

	/**
	 * Tests that a payment the reconciliation run settled first is answered as it stands, a duplicate, and the provider is not asked.
	 *
	 * @since 0.2.0
	 */
	public function test_a_payment_settled_first_by_another_path_is_a_duplicate(): void {
		list( , $intent ) = $this->placeActing();

		$this->db->execute( 'UPDATE %i SET updated_at = UTC_TIMESTAMP(6) - INTERVAL 11 MINUTE', $this->table( PaymentTables::INTENTS ) );
		$this->kernel->get( ReconcileStalePlacements::class )->handle( array() );

		$queries = $this->queries();

		$this->assertSame(
			array(
				'intent_uuid'    => $intent,
				'outcome'        => 'duplicate',
				'status'         => 'processing',
				'payment_status' => 'authorized',
			),
			$this->resume( $intent )
		);
		$this->assertSame( $queries, $this->queries() );
	}

	/**
	 * Tests that a payment of another cart is answered as one that does not exist, and that a request with no cart is refused, each before the provider is asked.
	 *
	 * @since 0.2.0
	 */
	public function test_only_the_placements_cart_resumes_its_payment(): void {
		list( , $intent ) = $this->placeActing();

		$theirs = $this->tokens->presented;

		// Another shopper's cart, which placed an order of its own.
		$this->readyCart( array( $this->sellable() => 1 ) );
		$this->placement->place( $this->placeInput( 'another-cart' ), self::guest() );

		$queries = $this->queries();

		$this->assertRefused( PaymentError::IntentNotFound, fn() => $this->resume( $intent ), 'Another cart\'s token.' );
		$this->assertRefused( PaymentError::IntentNotFound, fn() => $this->resume( '0192a4b3-7c5d-7e8f-9a0b-000000000000' ), 'No such payment.' );

		$this->tokens->presented = null;

		$this->assertRefused( CartError::NotFound, fn() => $this->resume( $intent ), 'No cart token.' );
		$this->assertSame( $queries, $this->queries(), 'The provider was never asked.' );

		$this->tokens->presented = $theirs;

		$this->assertSame( 'approved', $this->resume( $intent )['outcome'], 'The placement\'s own cart resumes it.' );
	}

	/**
	 * Tests that a provider that cannot answer is refused as the placement is, naming the order, which waits as it was.
	 *
	 * @since 0.2.0
	 *
	 * @throws GatewayUnavailable Only from the gateway the test scripts, which the resume codes.
	 */
	public function test_a_provider_that_cannot_answer_leaves_the_order_waiting(): void {
		list( $answer, $intent ) = $this->placeActing();

		$this->gateway->during(
			static function (): void {
				throw new GatewayUnavailable( 'The provider did not answer.' );
			}
		);

		try {
			$this->resume( $intent );
			$this->fail( 'A resume with no answer settled something.' );
		} catch ( CodedException $refused ) {
			$this->assertSame( array( CheckoutError::GatewayUnavailable, array( 'order_uuid' => $answer['order_uuid'] ) ), array( $refused->errorCode(), $refused->details() ) );
		}

		$this->assertSame( 'pending_payment', $this->committedOrder( $this->secondConnection(), (string) $answer['order_uuid'] )['status'] ?? null );
	}

	/**
	 * Tests that a resume is refused `store.unavailable` while the schema gate is closed, before any statement reaches the cart, the payment or the order, and before the provider is asked.
	 *
	 * @since 0.2.0
	 */
	public function test_a_resume_is_refused_by_the_gate_before_its_first_read(): void {
		list( , $intent ) = $this->placeActing();

		$gated   = PlacementKernel::over(
			$this->db,
			$this->tokens,
			$this->identities,
			$this->wake,
			$this->reporter(),
			array(
				TransactionManager::class => fn(): TransactionManager => new ClosedGateTransactions( $this->db, GateState::CodeNewer ),
				PaymentGateway::class     => fn(): PaymentGateway => $this->gateway,
			)
		);
		$queries = $this->queries();
		$log     = $this->captureQueries( fn() => $this->assertRefused( KernelError::StoreUnavailable, fn() => $gated->get( ResumePayment::class )->resume( array( 'intent_uuid' => $intent ), self::guest() ), 'A resume with the gate closed' ) );

		foreach ( array( CartTables::CARTS, PaymentTables::INTENTS, OrderTables::ORDERS ) as $table ) {
			$this->assertQueryCount( 0, $log->forTable( $this->table( $table ) ), $table . ': statements before the refusal' );
		}

		$this->assertSame( $queries, $this->queries(), 'The provider was not asked.' );
	}

	/**
	 * Tests that a shopper back after their time to act ran out has the payment voided at its gateway, as the store's, before anything is released, and the provider is not asked where it stands.
	 *
	 * @since 0.2.0
	 */
	public function test_a_resume_after_the_time_to_act_ran_out_voids_before_anything_is_released(): void {
		list( $answer, $intent, $variant ) = $this->placeActing();

		$this->endTheWait( $intent );

		$this->assertSame( 'voided', $this->resume( $intent )['outcome'] );

		$b = $this->secondConnection();

		$this->assertSame( array( 'void' ), $this->asked(), 'The gateway was asked to void the payment, and nothing else.' );
		$this->assertSame( array( 'voided', 'action_window_ended' ), array_values( (array) $this->db->fetchRow( 'SELECT status, voided_reason FROM %i WHERE uuid = %s', $this->table( PaymentTables::INTENTS ), $intent ) ) );
		$this->assertSame( array( 'cancelled', 'voided' ), array_values( (array) $this->db->fetchRow( 'SELECT status, payment_status FROM %i WHERE uuid = %s', $this->table( OrderTables::ORDERS ), $answer['order_uuid'] ) ) );
		$this->assertSame( array( 5, 0, 0 ), $this->committedStock( $b, $variant ), 'The hold is released once the void is made.' );
		$this->assertSame( 'system', $this->db->fetchValue( 'SELECT actor_type FROM %i WHERE operation = %s', $this->table( PaymentTables::TRANSACTIONS ), 'void' ), 'The void is the store\'s.' );
		$this->assertSame( 'voided', $this->kept( (string) $answer['order_uuid'] )['outcome'] ?? null );
	}

	/**
	 * Tests that a provider refusing the void of a payment whose time to act ran out leaves everything as it was, and the resume answers that the payment still waits for the shopper.
	 *
	 * @since 0.2.0
	 */
	public function test_a_void_the_provider_refuses_leaves_the_payment_waiting(): void {
		list( $answer, $intent, $variant ) = $this->placeActing();

		$this->endTheWait( $intent );

		$this->gateway->voids = static fn( VoidRequest $request ): GatewayResult => new GatewayResult( StubGateway::ID, Operation::Void, Outcome::Declined, $request->intentUuid, $request->amount, 'stub-void-' . $request->intentUuid, $request->providerIntentId, 'intent_not_cancelable' );

		$resumed = $this->resume( $intent );

		$this->assertSame( array( 'requires_action', 'pending_payment', 'pending' ), array( $resumed['outcome'], $resumed['status'], $resumed['payment_status'] ) );
		$this->assertSame( array( 'void' ), $this->asked() );
		$this->assertSame( 'requires_action', $this->db->fetchValue( 'SELECT status FROM %i WHERE uuid = %s', $this->table( PaymentTables::INTENTS ), $intent ) );
		$this->assertSame( array( 5, 0, 1 ), $this->committedStock( $this->secondConnection(), $variant ), 'The order keeps its hold.' );
		$this->assertSame( 'requires_action', $this->kept( (string) $answer['order_uuid'] )['outcome'] ?? null );
	}

	/**
	 * Tests that a provider still waiting, for the shopper or for itself, is answered as waiting, and nothing is settled.
	 *
	 * @since 0.2.0
	 */
	public function test_a_payment_the_provider_still_waits_on_is_answered_waiting(): void {
		list( , $intent ) = $this->placeActing();

		foreach ( array(
			'requires_action' => Outcome::RequiresAction,
			'processing'      => Outcome::Pending,
		) as $expected => $outcome ) {
			$this->gateway->queries = static fn( PaymentQuery $query ): GatewayResult => new GatewayResult( StubGateway::ID, Operation::Authorize, $outcome, $query->intentUuid, $query->amount, null, $query->providerIntentId );

			$resumed = $this->resume( $intent );

			$this->assertSame( array( $expected, 'pending_payment', 'pending' ), array( $resumed['outcome'], $resumed['status'], $resumed['payment_status'] ), $outcome->value );
		}

		$this->assertSame( 'requires_action', $this->db->fetchValue( 'SELECT status FROM %i WHERE uuid = %s', $this->table( PaymentTables::INTENTS ), $intent ), 'Nothing was settled.' );
		$this->assertSame( 0, $this->committedCount( $this->secondConnection(), PaymentTables::TRANSACTIONS ) );
	}

	/**
	 * Tests that the resume's input takes a payment's identifier only, so a card number given in its place is refused by the schema and never shown back.
	 *
	 * @since 0.2.0
	 */
	public function test_a_card_number_in_place_of_the_identifier_is_refused_without_showing_it(): void {
		$arguments = ( new CompiledOperation( CheckoutOperations::resumePayment() ) )->restArguments();
		$refused   = rest_validate_value_from_schema( '4111111111111111', $arguments['intent_uuid'], 'intent_uuid' );

		$this->assertWPError( $refused );
		$this->assertStringNotContainsString( '4111111111111111', (string) wp_json_encode( array( $refused->get_error_message(), $refused->get_error_data() ) ) );
	}

	/**
	 * Places an order for one unit whose payment the stub asks the shopper to act on.
	 *
	 * @since 0.2.0
	 *
	 * @return array{0: array<string, mixed>, 1: string, 2: int} The answer, the intent's uuid and the variant.
	 */
	private function placeActing(): array {
		$variant = $this->sellable();

		$this->readyCart( array( $variant => 1 ) );

		$answer = $this->placement->place( $this->placeInput( 'acting-' . $variant, StubGateway::REQUIRES_ACTION ), self::guest() );
		$intent = (string) $this->db->fetchValue( 'SELECT i.uuid FROM %i i JOIN %i o ON o.id = i.order_id WHERE o.uuid = %s', $this->table( PaymentTables::INTENTS ), $this->table( OrderTables::ORDERS ), $answer['order_uuid'] );

		$this->assertSame( 'requires_action', $answer['outcome'] );

		return array( $answer, $intent, $variant );
	}

	/**
	 * Resumes a payment as the shopper whose cart token the request presents.
	 *
	 * @since 0.2.0
	 *
	 * @param string $intentUuid The payment.
	 * @return array<string, mixed> The answer.
	 */
	private function resume( string $intentUuid ): array {
		return $this->kernel->get( ResumePayment::class )->resume( array( 'intent_uuid' => $intentUuid ), self::guest() );
	}

	/**
	 * Ends the time the shopper had to act, by the database clock.
	 *
	 * @since 0.2.0
	 *
	 * @param string $intentUuid The payment.
	 */
	private function endTheWait( string $intentUuid ): void {
		$this->db->execute( 'UPDATE %i SET customer_action_expires_at = UTC_TIMESTAMP() - INTERVAL 1 MINUTE WHERE uuid = %s', $this->table( PaymentTables::INTENTS ), $intentUuid );
		$this->gateway->calls = array();
	}

	/**
	 * Returns what the gateway was asked since the placement, other than the authorization: queries and voids, in order.
	 *
	 * @since 0.2.0
	 *
	 * @return list<string> The methods.
	 */
	private function asked(): array {
		return array_values( array_diff( array_column( $this->gateway->calls, 'method' ), array( 'authorize' ) ) );
	}

	/**
	 * Counts the status queries the gateway was asked.
	 *
	 * @since 0.2.0
	 *
	 * @return int The count.
	 */
	private function queries(): int {
		return count( array_keys( array_column( $this->gateway->calls, 'method' ), 'query', true ) );
	}

	/**
	 * Reads the answer the key of an order's placement keeps.
	 *
	 * @since 0.2.0
	 *
	 * @param string $orderUuid The order.
	 * @return array<string, mixed> The answer.
	 */
	private function kept( string $orderUuid ): array {
		return (array) json_decode( (string) $this->db->fetchValue( 'SELECT k.response_json FROM %i k JOIN %i o ON o.id = k.order_id WHERE o.uuid = %s', $this->table( CheckoutTables::IDEMPOTENCY_KEYS ), $this->table( OrderTables::ORDERS ), $orderUuid ), true );
	}

	/**
	 * Asserts that a call is refused with a code.
	 *
	 * @since 0.2.0
	 *
	 * @param \UnitEnum $code The code.
	 * @param \Closure  $call The call.
	 * @param string    $what What is refused.
	 */
	private function assertRefused( \UnitEnum $code, \Closure $call, string $what ): void {
		try {
			$call();
			$this->fail( $what . ': not refused.' );
		} catch ( CodedException $refused ) {
			$this->assertSame( $code, $refused->errorCode(), $what );
		}
	}
}
