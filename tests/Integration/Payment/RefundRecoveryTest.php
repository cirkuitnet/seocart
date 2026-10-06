<?php
/**
 * Tests a refund killed after the gateway gave the money back: its claim survives, doctor finds it, and asking again records it once
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Payment;

use SEOCart\Order\Domain\NewOrder;
use SEOCart\Payment\Application\PaymentService;
use SEOCart\Payment\Infrastructure\RefundClaimTables;
use SEOCart\Tests\Support\Payment\RefundOrders;
use SEOCart\Tests\Support\Payment\RefundTestCase;

// phpcs:disable WordPress.WP.AlternativeFunctions -- The test reads the call log the killed process wrote.

/**
 * A refund whose process dies between the gateway's approval and the transaction that records it leaves its claim, which doctor reports and the next request for it settles, without a second refund at the gateway.
 *
 * The refund runs in a process of its own (tests/Support/Payment/refund-probe.php), over the
 * kernel's wiring and the stub gateway. Once the stub gave the money back, the process logs the
 * refund and kills itself with SIGKILL: nothing after the gateway's answer runs. The claim, committed
 * before the gateway was called, is all that is left; nothing else was recorded. Once it is older
 * than any call to the gateway takes, by the database clock, doctor's payments check names it. Then
 * the same refund is asked for again in the test's process: the gateway is asked what became of
 * it, never for it again, and the refund is recorded once, as the stub made it.
 *
 * A refund asked through the refund operation's service, with an idempotency key, is killed the
 * same way: its claim keeps the key, and the retry with the key finds it by the key, asks the
 * gateway what became of the refund, and records it once.
 *
 * Planted violations, each shown red and removed:
 *
 * - in RefundService::refund(), make the claim inside the transaction that records the answer
 *   (call RefundService::claim() at the start of recordAnswer() instead): the killed refund leaves
 *   no claim, and the keyed retry is a second refund at the gateway;
 * - in RefundService::askedBefore(), ask the gateway for the refund again, `$this->gateway->refund()`,
 *   instead of asking what became of it: the gateway is asked for the refund a second time.
 *
 * @since 0.1.0
 *
 * @group concurrency
 */
final class RefundRecoveryTest extends RefundTestCase {

	/**
	 * Tests that a refund killed after the gateway's approval leaves only its claim, which doctor names once it is stale, and which the same refund asked again settles: one refund at the gateway, one document.
	 *
	 * @since 0.1.0
	 */
	public function test_a_refund_killed_after_the_gateway_approved_is_found_and_recorded_once(): void {
		list( $order, $intent ) = $this->placePaid( self::order() );
		list( $tee )            = $this->lineUuids( $order->id );
		$log                    = (string) tempnam( sys_get_temp_dir(), 'seocart-refund-calls-' );

		try {
			$probe = $this->startRefundProbe( $order->uuid, array( $tee => 1 ), $log );

			$this->awaitProbeEnd( $probe );
			$this->assertSame( '', $probe->reportSoFar(), "The refund answered: it was not killed.\n" . $probe->output() );

			$made = array_values( array_filter( explode( "\n", (string) file_get_contents( $log ) ) ) );
		} finally {
			unlink( $log );
		}

		$this->assertCount( 1, $made, 'The gateway gave the money back once, in the killed process.' );

		list( $key, $object ) = explode( ' ', $made[0] );

		$this->assertSame( 'stub-re-' . $key, $object );
		$this->assertSame( array(), $this->refundRows( $order->id ), 'No document was written.' );
		$this->assertSame( array(), $this->refundLedgerRows( $order->id ), 'Nothing reached the ledger.' );
		$this->assertSame( '0', (string) $this->intentRow( $intent->uuid )['refunded_minor'] );
		$this->assertSame(
			array(
				array(
					'uuid'           => $key,
					'state'          => 'claimed',
					'actor_type'     => 'user',
					'actor_id'       => (string) $this->agent()->userId(),
					'transaction_id' => null,
					'settled'        => '0',
				),
			),
			array_map( static fn( array $claim ): array => self::pick( $claim, 'uuid', 'state', 'actor_type', 'actor_id', 'transaction_id', 'settled' ), $this->claimRows( $order->id ) ),
			'The claim, committed before the gateway was asked, is what is left: the refund\'s uuid, the key the gateway got, and who asked.'
		);

		// Nobody asks again: once the claim is older than any call to the gateway takes, doctor names it.
		$this->assertSame( array(), $this->ledgerCheck()->run()->findings, 'A claim younger than a gateway call is not reported.' );
		$this->ageClaims( PaymentService::STALE_SECONDS + 60 );

		$findings = $this->ledgerCheck()->run()->findings;

		$this->assertCount( 1, $findings );
		$this->assertStringStartsWith( "Warning: refund {$key} of payment {$intent->uuid}, ", $findings[0] );

		// The same refund asked for again: the gateway is asked what became of it, and its answer is recorded once.
		$refund = $this->refund( $order->uuid, array( $tee => 1 ) );

		$this->assertSame( $key, $refund->uuid );
		$this->assertSame( array( 0, 1 ), array( $this->refundCalls(), $this->refundQueries() ), 'The gateway was asked what became of the refund, never for it again.' );
		$this->assertCount( 1, $this->refundRows( $order->id ), 'One document.' );

		$ledger = $this->refundLedgerRows( $order->id );

		$this->assertSame( array( array( $object, 'approved', '1' ) ), array_map( static fn( array $row ): array => array( (string) $row['provider_object_id'], (string) $row['result'], (string) $row['applied'] ), $ledger ), 'One ledger row: the refund the killed process\'s gateway call made.' );
		$this->assertSame(
			array(
				'state'          => 'recorded',
				'transaction_id' => (string) $ledger[0]['id'],
				'settled'        => '1',
			),
			self::pick( $this->claimRows( $order->id )[0], 'state', 'transaction_id', 'settled' )
		);
		$this->assertSame( array(), $this->ledgerCheck()->run()->findings, 'The settled claim is reported no more.' );
	}

	/**
	 * Tests that a refund asked with a key and killed after the gateway's approval leaves its claim with the key, and that the retry with the key records it once, asking the gateway what became of it.
	 *
	 * @since 0.2.0
	 */
	public function test_a_keyed_refund_killed_after_the_gateway_approved_is_recorded_once_by_its_key(): void {
		list( $order ) = $this->placePaid( self::order() );
		list( $tee )   = $this->lineUuids( $order->id );
		$log           = (string) tempnam( sys_get_temp_dir(), 'seocart-refund-calls-' );

		try {
			$probe = $this->startRefundProbe( $order->uuid, array( $tee => 1 ), $log, null, 'attempt-1' );

			$this->awaitProbeEnd( $probe );
			$this->assertSame( '', $probe->reportSoFar(), "The refund answered: it was not killed.\n" . $probe->output() );

			$made = array_values( array_filter( explode( "\n", (string) file_get_contents( $log ) ) ) );
		} finally {
			unlink( $log );
		}

		$this->assertCount( 1, $made, 'The gateway gave the money back once, in the killed process.' );

		list( $key ) = explode( ' ', $made[0] );
		$claim       = $this->claimRows( $order->id );

		$this->assertSame( array( array( $key, 'claimed' ) ), array_map( static fn( array $row ): array => array( $row['uuid'], $row['state'] ), $claim ), 'The claim is what is left.' );
		$this->assertNotNull( $this->db->fetchValue( 'SELECT key_hash FROM %i WHERE uuid = %s', $this->table( RefundClaimTables::CLAIMS ), $key ), 'The claim keeps the key.' );

		// The client retries with the same key, as after a lost answer.
		$answer = $this->refunds->refundOrder(
			array(
				'order_uuid'      => $order->uuid,
				'lines'           => array(
					array(
						'line_uuid' => $tee,
						'quantity'  => 1,
					),
				),
				'reason_code'     => self::REASON,
				'idempotency_key' => 'attempt-1',
			),
			$this->agent()
		);

		$this->assertSame( $key, $answer['refund_uuid'] );
		$this->assertSame( array( 0, 1 ), array( $this->refundCalls(), $this->refundQueries() ), 'The gateway was asked what became of the refund, never for it again.' );
		$this->assertCount( 1, $this->refundRows( $order->id ), 'One document.' );
		$this->assertSame( 'recorded', (string) $this->claimRows( $order->id )[0]['state'] );
	}

	/**
	 * Builds the fixture order: three tees and two mugs, taxed, shipped.
	 *
	 * @since 0.1.0
	 *
	 * @return NewOrder The document.
	 */
	private static function order(): NewOrder {
		return RefundOrders::priced( array( RefundOrders::line( 'tee', '12.34', 3, 'standard' ), RefundOrders::line( 'mug', '5.00', 2, 'standard', variantId: 502 ) ) );
	}
}
