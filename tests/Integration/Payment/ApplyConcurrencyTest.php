<?php
/**
 * Tests results applied at once over two connections: a duplicate waits and is refused, a released claim is taken, a second refund finds the cap
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Payment;

use SEOCart\Contracts\Payment\GatewayResult;
use SEOCart\Contracts\Payment\Operation;
use SEOCart\Contracts\Payment\Outcome;
use SEOCart\Order\Infrastructure\MysqlOrderRepository;
use SEOCart\Payment\Domain\Application;
use SEOCart\Payment\Domain\ApplicationKind;
use SEOCart\Payment\Domain\IntentStatus;
use SEOCart\Payment\Domain\IntentTransitions;
use SEOCart\Payment\Infrastructure\Gateway\StubGateway;
use SEOCart\Payment\Infrastructure\MysqlPaymentRepository;
use SEOCart\Payment\Infrastructure\MysqlRefundRepository;
use SEOCart\Platform\Database\MysqlErrno;
use SEOCart\Tests\Support\Doubles\SequentialIdGenerator;
use SEOCart\Tests\Support\Payment\PaymentTestCase;
use SEOCart\Tests\Support\SecondConnection;

/**
 * Two connections apply results about one intent at once; the ledger's key and the intent's caps decide, under the locks.
 *
 * Connection A is the payment service over wpdb, applying a result in its own transaction.
 * Just before A writes a later statement, connection B sends the payment module's own statement,
 * prepared from its constant: the ledger insert of the same result, or a refund of the same
 * intent. The server shows B waiting for A's lock; when A commits, B meets what A left, and when
 * A rolls back, B gets what A gave up. Then B runs the service over a connection of its own.
 * Barriers, never pauses.
 *
 * Planted violations, each shown red and removed:
 * - in PaymentTables, drop the `provider_object_operation` unique key: B's insert of the same
 *   result lands beside A's;
 * - in MysqlPaymentRepository::APPLY_REFUND, neutralise both caps, `( captured_minor -
 *   refunded_minor >= %d OR 1 = 1 )` and its base twin: B's refund lands on what A already
 *   refunded.
 *
 * @since 0.1.0
 *
 * @group concurrency
 */
final class ApplyConcurrencyTest extends PaymentTestCase {

	/**
	 * Tests that the same approval sent by B while A applies it waits for A's claim, and is refused as a duplicate once A commits.
	 *
	 * @since 0.1.0
	 */
	public function test_a_concurrent_duplicate_waits_and_is_refused(): void {
		list( $order, $intent ) = $this->placeWithIntent();

		$result = $this->authorizeWith( $intent, StubGateway::APPROVE );
		$b      = $this->secondConnection();
		$claim  = $this->claimOf( $result, $order->id );
		$raced  = $this->beforeStatement(
			self::shapeOf( MysqlOrderRepository::APPEND_EVENT ),
			function () use ( $b, $claim ): void {
				$b->queryAsync( $claim );
				$this->awaitWaiting( $b, $claim, 'update' );
			}
		);

		$this->assertSame( ApplicationKind::Applied, $this->deliver( $result )->kind );
		$this->assertTrue( $raced->fired, 'B never raced A.' );
		$this->assertSame( MysqlErrno::DUPLICATE_ENTRY, $this->reapError( $b ), 'B\'s claim of the same result must meet A\'s row.' );
		$this->assertCount( 1, $this->ledgerOf( $order->id ) );

		list( $second, $secondDb ) = $this->secondPayments();

		$again = $secondDb->transaction( static fn(): Application => $second->applyGatewayResult( $result, self::system() ) );

		$this->assertSame( ApplicationKind::Duplicate, $again->kind, 'The service on B is told the result was applied.' );
		$this->assertSame( '3080', (string) $this->intentRow( $intent->uuid )['authorized_minor'] );
	}

	/**
	 * Tests that a claim A rolls back is released: B's waiting insert of the same result lands, and B's service then applies it.
	 *
	 * @since 0.1.0
	 */
	public function test_a_claim_rolled_back_is_released_to_the_next_applier(): void {
		list( $order, $intent ) = $this->placeWithIntent();

		$result = $this->authorizeWith( $intent, StubGateway::APPROVE );
		$b      = $this->secondConnection();
		$claim  = $this->claimOf( $result, $order->id );

		$b->query( 'START TRANSACTION' );

		$raced = $this->beforeStatement(
			self::shapeOf( MysqlPaymentRepository::APPLY_AUTHORIZE ),
			function () use ( $b, $claim ): void {
				$b->queryAsync( $claim );
				$this->awaitWaiting( $b, $claim, 'update' );

				throw new \RuntimeException( 'A fails after its claim.' );
			}
		);

		try {
			$this->deliver( $result );
			$this->fail( 'A was meant to fail after its claim.' );
		} catch ( \RuntimeException $failed ) {
			$this->assertSame( 'A fails after its claim.', $failed->getMessage() );
		}

		$this->assertTrue( $raced->fired, 'B never raced A.' );
		$this->assertTrue( $b->isReady( 5000 ), 'B\'s insert must go through once A rolls back.' );
		$this->assertSame( 1, $b->reap(), 'B took the claim A gave up.' );

		$b->query( 'ROLLBACK' );

		list( $second, $secondDb ) = $this->secondPayments();

		$applied = $secondDb->transaction( static fn(): Application => $second->applyGatewayResult( $result, self::system() ) );

		$this->assertSame( ApplicationKind::Applied, $applied->kind );
		$this->assertCount( 1, $this->ledgerOf( $order->id ) );
		$this->assertSame( array( 'authorized', '3080' ), array( $this->intentRow( $intent->uuid )['status'], (string) $this->intentRow( $intent->uuid )['authorized_minor'] ) );
	}

	/**
	 * Tests that two refunds of the same captured money at once refund it once: B's refund waits for A's, then finds the cap.
	 *
	 * @since 0.1.0
	 */
	public function test_two_concurrent_refunds_never_exceed_the_capture(): void {
		list( , $intent ) = $this->placeCaptured();

		$intentId = (int) $this->intentRow( $intent->uuid )['id'];
		$b        = $this->secondConnection();
		$refund   = $this->rawPayment(
			MysqlPaymentRepository::APPLY_REFUND,
			2000,
			2000,
			2000,
			$intentId,
			'USD',
			'USD',
			IntentTransitions::values( IntentTransitions::allowedFrom( IntentStatus::Refunded ) ),
			2000,
			2000,
			2000
		);
		$raced    = $this->beforeStatement(
			self::shapeOf( MysqlOrderRepository::RECORD_PAYMENT ),
			function () use ( $b, $refund ): void {
				$b->queryAsync( $refund );
				$this->awaitWaiting( $b, $refund, 'updating' );
			}
		);

		$this->assertSame( ApplicationKind::Applied, $this->deliver( self::stubResult( $intent, Operation::Refund, Outcome::Approved, 2000, 'USD', 'stub-re-a' ) )->kind );
		$this->assertTrue( $raced->fired, 'B never raced A.' );
		$this->assertTrue( $b->isReady( 5000 ), 'B\'s refund must go through once A commits.' );
		$this->assertSame( 0, $b->reap(), 'B refunded money A had already refunded.' );
		$this->assertSame( array( 'partially_refunded', '2000' ), array( $this->intentRow( $intent->uuid )['status'], (string) $this->intentRow( $intent->uuid )['refunded_minor'] ) );
	}

	/**
	 * Returns the ledger insert of a result, as connection B sends it: another row's uuid, the same provider, object and operation.
	 *
	 * @since 0.1.0
	 *
	 * @param GatewayResult $result  The result.
	 * @param int           $orderId The intent's order.
	 * @return string The statement.
	 */
	private function claimOf( GatewayResult $result, int $orderId ): string {
		$intent = $this->intentRow( $result->intentUuid );

		return $this->rawPayment(
			MysqlPaymentRepository::INSERT_TRANSACTION,
			SequentialIdGenerator::nth( 990001 ),
			(int) $intent['id'],
			$orderId,
			$result->operation->value,
			$result->amount->minorUnits(),
			$result->amount->currency()->code(),
			(int) $intent['conversion_context_id'],
			(string) $intent['base_currency'],
			(int) $intent['base_amount_minor'],
			'',
			0,
			0,
			'',
			0,
			0,
			'',
			$result->provider,
			(string) $result->providerObjectId,
			$result->outcome->value,
			1,
			'',
			'system',
			3,
			'',
			MysqlRefundRepository::NEVER_RECONCILED
		);
	}

	/**
	 * Waits for B's asynchronous statement and returns the MySQL error it failed with.
	 *
	 * @since 0.1.0
	 *
	 * @param SecondConnection $b Connection B.
	 * @return int The error number, or 0 when the statement succeeded.
	 */
	private function reapError( SecondConnection $b ): int {
		$this->assertTrue( $b->isReady( 5000 ), 'B\'s statement must be answered once A commits.' );

		try {
			$b->reap();
		} catch ( \RuntimeException $failed ) {
			return $failed->getCode();
		}

		return 0;
	}
}
