<?php
/**
 * Tests that a capture and a void ask the schema gate before their first read of the payment
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Payment;

use SEOCart\Order\Infrastructure\OrderTables;

use SEOCart\Payment\Domain\VoidReason;
use SEOCart\Payment\Infrastructure\Gateway\StubGateway;
use SEOCart\Payment\Infrastructure\PaymentTables;
use SEOCart\Platform\Kernel\GateState;
use SEOCart\Platform\Kernel\KernelError;
use SEOCart\Support\Error\CodedException;
use SEOCart\Tests\Support\Doubles\ClosedGateTransactions;
use SEOCart\Tests\Support\Payment\PaymentTestCase;
use SEOCart\Tests\Support\Payment\TestGateways;

/**
 * While the schema gate is closed, as when a migration that adds a column the payment's read names is outstanding, a capture and a void are refused `store.unavailable` before any statement reaches the payment or order tables, and nothing reaches the gateway.
 *
 * Both begin with plain reads, outside any transaction, which the gate does not see on its own: each
 * asks the gate first, so the refusal is the gate's and never the database's error about a column
 * it does not know. The same holds for their operations.
 *
 * Planted violation, shown red and removed: in PaymentService::askCapture(), ask the gate after the
 * intent's read: the read reaches the intents table.
 *
 * @since 0.2.0
 */
final class OperationGateTest extends PaymentTestCase {

	/**
	 * Tests that each, the services and their operations, is refused by the gate before its first read.
	 *
	 * @since 0.2.0
	 */
	public function test_each_is_refused_by_the_gate_before_its_first_read(): void {
		list( , $intent ) = $this->placeWithIntent();

		$this->deliver( $this->authorizeWith( $intent, StubGateway::APPROVE ) );

		$gated   = $this->paymentsWith( $this->db, $this->ids, TestGateways::of( $this->gateway ), new ClosedGateTransactions( $this->db, GateState::CodeNewer ) );
		$manager = $this->userWithRole( 'seocart_manager' );
		$calls   = array(
			'capture()'        => fn() => $gated->capture( $intent->uuid, $manager ),
			'void()'           => fn() => $gated->void( $intent->uuid, $manager, VoidReason::CustomerRequest ),
			'capturePayment()' => fn() => $gated->capturePayment( array( 'intent_uuid' => $intent->uuid ), $manager ),
			'voidPayment()'    => fn() => $gated->voidPayment(
				array(
					'intent_uuid' => $intent->uuid,
					'reason'      => 'customer_request',
				),
				$manager
			),
		);

		foreach ( $calls as $what => $call ) {
			$log = $this->captureQueries( fn() => $this->assertRefusedByTheGate( $call, $what ) );

			$this->assertQueryCount( 0, $log->forTable( $this->table( PaymentTables::INTENTS ) ), $what . ': reads of the payment before the refusal' );
			$this->assertQueryCount( 0, $log->forTable( $this->table( OrderTables::ORDERS ) ), $what . ': reads of the order before the refusal' );
		}

		$this->assertSame( array( 'authorize' ), array_column( $this->gateway->calls, 'method' ), 'Nothing reached the gateway.' );
		$this->assertSame( 'authorized', $this->intentRow( $intent->uuid )['status'] );
	}

	/**
	 * Asserts that a call is refused `store.unavailable`.
	 *
	 * @since 0.2.0
	 *
	 * @param \Closure $call The call.
	 * @param string   $what What it is.
	 */
	private function assertRefusedByTheGate( \Closure $call, string $what ): void {
		try {
			$call();
			$this->fail( $what . ' went on with the gate closed.' );
		} catch ( CodedException $refused ) {
			$this->assertSame( KernelError::StoreUnavailable, $refused->errorCode(), $what );
		}
	}
}
