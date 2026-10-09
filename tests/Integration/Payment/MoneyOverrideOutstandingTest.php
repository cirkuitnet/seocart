<?php
/**
 * Tests that settling a refund claim and clearing an order's unreconciled money are refused by the schema gate, before any statement, while the migrations they need are outstanding
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Payment;

use SEOCart\Contracts\Payment\PaymentGateway;
use SEOCart\Order\Application\Orders;
use SEOCart\Order\Infrastructure\Migrations\AddMoneyReconciliation;
use SEOCart\Order\Infrastructure\OrderTables;
use SEOCart\Payment\Application\ClaimStatement;
use SEOCart\Payment\Application\RefundService;
use SEOCart\Payment\Infrastructure\Gateway\StubGateway;
use SEOCart\Payment\Infrastructure\Migrations\AddRefundClaimSettlement;
use SEOCart\Payment\Infrastructure\RefundClaimTables;
use SEOCart\Platform\Database\Database;
use SEOCart\Platform\Database\Schema\DdlGenerator;
use SEOCart\Platform\Database\Schema\SchemaVerifier;
use SEOCart\Platform\Database\SchemaOperations;
use SEOCart\Platform\Database\TransactionManager;
use SEOCart\Platform\Events\EventPublisher;
use SEOCart\Platform\Kernel\Container;
use SEOCart\Platform\Kernel\GatedTransactionManager;
use SEOCart\Platform\Kernel\GateState;
use SEOCart\Platform\Kernel\KernelError;
use SEOCart\Platform\Kernel\SchemaGate;
use SEOCart\Support\Error\CodedException;
use SEOCart\Tests\Support\Doubles\RememberingGateway;
use SEOCart\Tests\Support\KernelContainer;
use SEOCart\Tests\Support\Payment\RefundOrders;
use SEOCart\Tests\Support\Payment\RefundTestCase;
use SEOCart\Tests\Support\Pricing\PricesInCurrencies;

/**
 * A site upgraded to this version whose settlement columns, or order clearance columns, are not added yet: the settlement and the clearance are refused `store.unavailable` by the schema gate before their first statement, never by the database's error about a missing column, and nothing is asked of the gateway or written.
 *
 * The kernel here keeps its gated transaction manager, which the other refund tests replace.
 *
 * The clearance sends nothing before its one transaction, which the gated transaction manager
 * refuses at its start; the settlement reads the claim and the order, and asks the gateway,
 * before its transaction, so it asks the gate itself first.
 *
 * Planted violation, shown red and removed: in RefundService::settleClaim(), drop the call to
 * refuseWhileClosed(): the claim and the order are read and the gateway is asked before the
 * transaction is refused.
 *
 * @since 0.2.0
 */
final class MoneyOverrideOutstandingTest extends RefundTestCase {

	use PricesInCurrencies;

	/**
	 * Puts the columns back, and the boot record as the test found it.
	 *
	 * @since 0.2.0
	 */
	public function tear_down(): void {
		$operations = new SchemaOperations( $this->db, new DdlGenerator(), new SchemaVerifier( $this->db ) );

		( new AddMoneyReconciliation() )->up( $operations );
		( new AddRefundClaimSettlement() )->up( $operations );
		$this->removeBootRecord();

		parent::tear_down();
	}

	/**
	 * Tests that a settlement is refused by the gate before any statement while the settlement's columns are outstanding, with the claim left open.
	 *
	 * @since 0.2.0
	 */
	public function test_a_settlement_is_refused_by_the_gate_while_its_columns_are_outstanding(): void {
		list( $order ) = $this->placePaid( self::order() );
		list( $tee )   = $this->lineUuids( $order->id );
		$provider      = new RememberingGateway( new StubGateway() );
		$uuid          = $this->openClaim( $order->uuid, $tee, $provider, $this->refundsOver( $this->db, $this->ids, $provider ) );

		$this->recordHeadBefore( AddRefundClaimSettlement::ID );
		$this->db->execute( 'ALTER TABLE %i DROP COLUMN statement, DROP COLUMN gateway_reading, DROP COLUMN settled_by, DROP COLUMN settlement_note', $this->table( RefundClaimTables::CLAIMS ) );

		$kernel  = $this->gatedKernel( $provider );
		$manager = $this->manager();

		$this->assertSame( GateState::CodeNewer, $kernel->get( SchemaGate::class )->state(), 'The migration is outstanding, and holds the store.' );
		$this->assertRefusedBeforeAnyStatement( fn() => $kernel->get( RefundService::class )->settleClaim( $uuid, new ClaimStatement( false, 'No refund.' ), $manager ) );
		$this->assertSame( 'claimed', $this->db->fetchValue( 'SELECT state FROM %i WHERE uuid = %s', $this->table( RefundClaimTables::CLAIMS ), $uuid ) );
	}

	/**
	 * Tests that a clearance, and a refund, which reads the clearance, are refused by the gate before any statement while the clearance's columns are outstanding, with the flag left up.
	 *
	 * @since 0.2.0
	 */
	public function test_a_clearance_and_a_refund_are_refused_by_the_gate_while_the_clearance_is_outstanding(): void {
		list( $order, $intent ) = $this->placePaid( self::order() );
		list( $tee )            = $this->lineUuids( $order->id );

		$this->keepUnappliedRefund( $intent, 500, 'EUR', 'external-re-1' );
		$this->recordHeadBefore( AddMoneyReconciliation::ID );
		$this->db->execute( 'ALTER TABLE %i DROP COLUMN money_reconciled_at, DROP COLUMN money_reconciliation_note', $this->table( OrderTables::ORDERS ) );

		$kernel  = $this->gatedKernel( $this->gateway );
		$manager = $this->manager();
		$agent   = $this->agent();

		$this->assertSame( GateState::CodeNewer, $kernel->get( SchemaGate::class )->state(), 'The migrations are outstanding, and hold the store.' );
		$this->assertRefusedBeforeAnyStatement(
			fn() => $kernel->get( Orders::class )->clearUnreconciledMoney(
				array(
					'order_uuid' => $order->uuid,
					'note'       => 'Reconciled.',
				),
				$manager
			)
		);
		$this->assertRefusedBeforeAnyStatement( fn() => $kernel->get( RefundService::class )->refund( self::request( $order->uuid, array( $tee => 1 ), false ), $agent ) );
		$this->assertSame( '1', (string) $this->orderRow( $order->id )['has_unreconciled_money'] );
	}

	/**
	 * Asserts that a call is refused `store.unavailable`, for the code being newer than the schema, before it sends a statement.
	 *
	 * @since 0.2.0
	 *
	 * @param callable $call The call.
	 */
	private function assertRefusedBeforeAnyStatement( callable $call ): void {
		$sent = $this->captureQueries(
			function () use ( $call ): void {
				try {
					$call();
					$this->fail( 'The call was let through.' );
				} catch ( CodedException $refused ) {
					$this->assertSame( array( KernelError::StoreUnavailable, array( 'reason' => GateState::CodeNewer->value ) ), array( $refused->errorCode(), $refused->context() ) );
				}
			}
		)->matching( self::STATEMENTS );

		$this->assertQueryCount( 0, $sent, 'Statements sent before the refusal' );
	}

	/**
	 * Builds the kernel with the production transaction manager, which asks the schema gate, over a gateway.
	 *
	 * @since 0.2.0
	 *
	 * @param PaymentGateway $gateway The gateway.
	 * @return Container The kernel.
	 */
	private function gatedKernel( PaymentGateway $gateway ): Container {
		$events = $this->publisherOver( $this->db );

		return KernelContainer::build(
			$this->db,
			$this->reporter(),
			array(
				TransactionManager::class => static fn( Container $c ): TransactionManager => new GatedTransactionManager( $c->get( Database::class ), $c->get( SchemaGate::class ) ),
				PaymentGateway::class     => static fn(): PaymentGateway => $gateway,
				EventPublisher::class     => static fn(): EventPublisher => $events,
			)
		);
	}

	/**
	 * Builds the fixture order: three tees, taxed, shipped.
	 *
	 * @since 0.2.0
	 *
	 * @return \SEOCart\Order\Domain\NewOrder The document.
	 */
	private static function order(): \SEOCart\Order\Domain\NewOrder {
		return RefundOrders::priced( array( RefundOrders::line( 'tee', '12.34', 3, 'standard' ) ) );
	}
}
