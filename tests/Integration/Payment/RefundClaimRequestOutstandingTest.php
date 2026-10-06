<?php
/**
 * Tests that a refund on a site whose claim request migration is outstanding is refused by the schema gate before its first read
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Payment;

use SEOCart\Contracts\Payment\PaymentGateway;
use SEOCart\Payment\Application\RefundService;
use SEOCart\Payment\Infrastructure\Migrations\AddRefundClaimRequest;
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
use SEOCart\Tests\Support\KernelContainer;
use SEOCart\Tests\Support\Payment\RefundOrders;
use SEOCart\Tests\Support\Payment\RefundTestCase;
use SEOCart\Tests\Support\Pricing\PricesInCurrencies;

/**
 * A site upgraded to this version, whose refund claims have no `key_hash` yet because the migration that adds it is still outstanding: a refund asked with an idempotency key, whose first read looks the key up, is refused by the schema gate, `store.unavailable`, before that read, never by the database's error about the missing column; nothing is claimed and nothing is asked of the gateway.
 *
 * The kernel here keeps its gated transaction manager, which the other refund tests replace.
 *
 * Planted violation, shown red and removed: in RefundService::refund(), drop the call to
 * refuseWhileClosed(): the key's lookup fails with the database's error about `key_hash`.
 *
 * @since 0.2.0
 */
final class RefundClaimRequestOutstandingTest extends RefundTestCase {

	use PricesInCurrencies;

	/**
	 * Puts the column back, and the boot record as the test found it.
	 *
	 * @since 0.2.0
	 */
	public function tear_down(): void {
		( new AddRefundClaimRequest() )->up( new SchemaOperations( $this->db, new DdlGenerator(), new SchemaVerifier( $this->db ) ) );
		$this->removeBootRecord();

		parent::tear_down();
	}

	/**
	 * Tests that the refund is refused by the gate before its first read, and claims and asks nothing.
	 *
	 * @since 0.2.0
	 */
	public function test_a_refund_is_refused_by_the_gate_while_the_claim_request_is_outstanding(): void {
		list( $order ) = $this->placePaid( RefundOrders::priced( array( RefundOrders::line( 'tee', '12.34', 3, 'standard' ) ) ) );
		list( $tee )   = $this->lineUuids( $order->id );
		$request       = self::request( $order->uuid, array( $tee => 1 ), false );
		$key           = $this->requestKey( $request, 'attempt-1' );

		$this->recordHeadBefore( AddRefundClaimRequest::ID );
		$this->db->execute( 'ALTER TABLE %i DROP COLUMN key_hash', $this->table( RefundClaimTables::CLAIMS ) );

		$kernel = $this->gatedKernel();

		$this->assertSame( GateState::CodeNewer, $kernel->get( SchemaGate::class )->state(), 'The migration is outstanding, and holds the store.' );

		try {
			$kernel->get( RefundService::class )->refund( $request, $this->agent(), $key );
			$this->fail( 'The refund was let through.' );
		} catch ( CodedException $refused ) {
			$this->assertSame( array( KernelError::StoreUnavailable, array( 'reason' => GateState::CodeNewer->value ) ), array( $refused->errorCode(), $refused->context() ) );
		}

		$this->assertSame( 0, $this->refundCalls(), 'Nothing was asked of the gateway.' );
		$this->assertSame( array(), $this->claimRows( $order->id ), 'Nothing was claimed.' );
	}

	/**
	 * Builds the kernel with the production transaction manager, which asks the schema gate, over the test's gateway.
	 *
	 * @since 0.2.0
	 *
	 * @return Container The kernel.
	 */
	private function gatedKernel(): Container {
		$gateway = $this->gateway;
		$events  = $this->publisherOver( $this->db );

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
}
