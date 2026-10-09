<?php
/**
 * Tests a settlement of a refund claim killed between asking the gateway and its transaction: the claim is left open, and the retry settles it once
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Payment;

use SEOCart\Payment\Application\ClaimStatement;
use SEOCart\Payment\Application\PaymentError;
use SEOCart\Payment\Application\RefundService;
use SEOCart\Payment\Application\SettledClaim;
use SEOCart\Payment\Infrastructure\Gateway\StubGateway;
use SEOCart\Payment\Infrastructure\RefundClaimTables;
use SEOCart\Support\Error\CodedException;
use SEOCart\Tests\Support\Doubles\RememberingGateway;
use SEOCart\Tests\Support\Payment\RefundOrders;
use SEOCart\Tests\Support\Payment\RefundTestCase;

// phpcs:disable WordPress.WP.AlternativeFunctions -- The test reads the call log the killed process wrote.

/**
 * A settlement writes nothing before its one transaction, so a process killed after the gateway answered and before the transaction leaves the claim as it was, and the settlement asked again settles it, once; asked again after that, it is told how the claim ended.
 *
 * The settlement runs in a process of its own (tests/Support/Payment/refund-probe.php), over the
 * kernel's wiring and the stub gateway, and kills itself with SIGKILL just before it would send
 * its transaction's first statement.
 *
 * Planted violation, shown red and removed: in RefundService::settleClaim(), end the claim
 * declined in a transaction of its own before the gateway is asked: the killed settlement leaves
 * the claim ended with nothing noted, and the gateway's answer is never recorded.
 *
 * @since 0.2.0
 *
 * @group concurrency
 */
final class SettleClaimRecoveryTest extends RefundTestCase {

	/**
	 * Tests that the killed settlement leaves the claim open with nothing written, that the retry settles it once, and that a retry after that is told how it ended.
	 *
	 * @since 0.2.0
	 */
	public function test_a_settlement_killed_before_its_transaction_is_settled_once_by_the_retry(): void {
		list( $order ) = $this->placePaid( RefundOrders::priced( array( RefundOrders::line( 'tee', '12.34', 3, 'standard' ) ) ) );
		list( $tee )   = $this->lineUuids( $order->id );
		$statement     = new ClaimStatement( false, 'Checked with the provider.' );
		$provider      = new RememberingGateway( new StubGateway() );
		$uuid          = $this->openClaim( $order->uuid, $tee, $provider, $this->refundsOver( $this->db, $this->ids, $provider ) );

		$log = (string) tempnam( sys_get_temp_dir(), 'seocart-settle-calls-' );

		try {
			$probe = $this->startSettleProbe( $uuid, $statement, $this->manager(), 'START TRANSACTION', $log );

			$this->awaitProbeEnd( $probe );
			$this->assertSame( '', $probe->reportSoFar(), "The settlement answered: it was not killed.\n" . $probe->output() );
			$this->assertSame( "query {$uuid}\n", (string) file_get_contents( $log ), 'The settlement was killed after it asked the gateway.' );
		} finally {
			unlink( $log );
		}

		$this->assertSame( array( array( 'claimed', null ) ), $this->settlements( $order->id ), 'The claim is open, with nothing noted.' );
		$this->assertSame( array(), $this->refundLedgerRows( $order->id ), 'Nothing reached the ledger.' );
		$this->assertSame( 0, $this->settlementEvents( $order->id ) );

		// The same settlement asked again: the gateway, asked once more, made the refund, and it is recorded once.
		$settled = $this->refunds->settleClaim( $uuid, $statement, $this->manager() );

		$this->assertSame( array( 'recorded', SettledClaim::BY_GATEWAY ), array( $settled->state->value, $settled->decidedBy ) );
		$this->assertSame( array( array( 'recorded', ClaimStatement::NOT_REFUNDED ) ), $this->settlements( $order->id ) );
		$this->assertCount( 1, $this->refundLedgerRows( $order->id ) );
		$this->assertCount( 1, $this->refundRows( $order->id ) );
		$this->assertSame( 1, $this->settlementEvents( $order->id ) );

		try {
			$this->refunds->settleClaim( $uuid, $statement, $this->manager() );
			$this->fail( 'The claim was settled twice.' );
		} catch ( CodedException $ended ) {
			$this->assertSame( array( PaymentError::RefundClaimEnded, array( 'state' => 'recorded' ) ), array( $ended->errorCode(), $ended->context() ) );
		}
	}

	/**
	 * Reads an order's claims: how each ended, and the statement its settlement kept.
	 *
	 * @since 0.2.0
	 *
	 * @param int $orderId The order.
	 * @return list<array{0: string, 1: string|null}> The state and the statement of each claim.
	 */
	private function settlements( int $orderId ): array {
		return array_map(
			static fn( array $claim ): array => array( (string) $claim['state'], $claim['statement'] ),
			$this->db->fetchAll( 'SELECT state, statement FROM %i WHERE order_id = %d ORDER BY id', $this->table( RefundClaimTables::CLAIMS ), $orderId )
		);
	}

	/**
	 * Counts an order's events of a settlement.
	 *
	 * @since 0.2.0
	 *
	 * @param int $orderId The order.
	 * @return int The count.
	 */
	private function settlementEvents( int $orderId ): int {
		return count( array_filter( $this->eventsOf( $orderId ), static fn( string $event ): bool => str_ends_with( $event, ':' . RefundService::SETTLED_REASON ) ) );
	}
}
