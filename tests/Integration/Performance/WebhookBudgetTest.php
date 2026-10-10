<?php
/**
 * Tests what a webhook delivery costs, in statements, for each thing it can come to
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Performance;

use SEOCart\Checkout\Application\ReceiveWebhook;
use SEOCart\Contracts\Payment\GatewayUnavailable;
use SEOCart\Contracts\Payment\Operation;
use SEOCart\Contracts\Payment\Outcome;
use SEOCart\Order\Infrastructure\OrderTables;
use SEOCart\Payment\Application\PaymentService;
use SEOCart\Payment\Application\RefundService;
use SEOCart\Payment\Domain\Refund\RefundLineRequest;
use SEOCart\Payment\Domain\Refund\RefundRequest;
use SEOCart\Payment\Domain\Webhook\ReceiptResult;
use SEOCart\Payment\Infrastructure\Gateway\StubGateway;
use SEOCart\Payment\Infrastructure\RefundClaimTables;
use SEOCart\Support\Error\CodedException;
use SEOCart\Tests\Support\Checkout\WebhookTestCase;
use SEOCart\Tests\Support\Payment\StubWebhooks;
use SEOCart\Tests\Support\QueryLog;

/**
 * Each delivery is measured cold, in a request of its own, every statement counted, the receipt's and the log's included, and the count pinned exactly, so a statement more or less is seen.
 *
 * Planted violation: in ReceiveWebhook::settle(), read the intent a second time before deciding
 * (`$this->payments->intentRef( (string) $reading->result?->intentUuid );`): every result's
 * budget is one statement over.
 *
 * @since 0.2.0
 *
 * @group performance
 */
final class WebhookBudgetTest extends WebhookTestCase {

	/**
	 * The statements of each delivery, as measured.
	 *
	 * @since 0.2.0
	 *
	 * @var array<string, int>
	 */
	private const BUDGETS = array(
		'a capture of an accepted order'            => 18,
		'a void of an accepted order'               => 22,
		'a duplicate by the ledger'                 => 15,
		'a stale decline'                           => 14,
		'an event of a type the stand-in ignores'   => 3,
		'a dispute about a payment of the store\'s' => 5,
		'a result about no intent of the store\'s'  => 4,
		'a delivery of an event decided before'     => 2,
		'a rejected delivery'                       => 2,
		'a refund recorded through its open claim'  => 36,
		'a refund no claim accounts for'            => 17,
	);

	/**
	 * What the delivery measured last came to; null for a rejection.
	 *
	 * @since 0.2.0
	 *
	 * @var ReceiptResult|null
	 */
	private ?ReceiptResult $result = null;

	/**
	 * The code the delivery measured last was refused with; null when it was taken.
	 *
	 * @since 0.2.0
	 *
	 * @var string|null
	 */
	private ?string $refused = null;

	/**
	 * Tests that a capture and a void of an accepted order, delivered, stay within their budgets.
	 *
	 * @since 0.2.0
	 */
	public function test_a_capture_and_a_void_delivered(): void {
		$captured = $this->placed( StubGateway::APPROVE, 'captured' );
		$voided   = $this->placed( StubGateway::APPROVE, 'voided' );

		$this->assertBudget( 'a capture of an accepted order', StubWebhooks::of( $this->captureOf( $captured ) ), ReceiptResult::Applied );
		$this->assertBudget( 'a void of an accepted order', StubWebhooks::of( $this->voidOf( $voided ) ), ReceiptResult::Applied );
	}

	/**
	 * Tests that a duplicate by the ledger, a stale result and the ignored deliveries stay within their budgets.
	 *
	 * @since 0.2.0
	 */
	public function test_what_changes_nothing_delivered(): void {
		$placed   = $this->placed( StubGateway::APPROVE );
		$approval = StubWebhooks::of( $this->approvalOf( $placed ) );

		$this->assertBudget( 'a duplicate by the ledger', $approval, ReceiptResult::Duplicate );
		$this->assertBudget( 'a stale decline', StubWebhooks::of( $this->resultAbout( $placed, Operation::Authorize, Outcome::Declined, 'stub-ch-first-card', StubGateway::CARD_DECLINED ) ), ReceiptResult::Stale );
		$this->assertBudget( 'an event of a type the stand-in ignores', StubWebhooks::event( 'evt_payout', 'payout.paid', array() ), ReceiptResult::Ignored );
		$this->assertBudget( 'a dispute about a payment of the store\'s', StubWebhooks::dispute( $placed['intent_uuid'] ), ReceiptResult::Ignored );
		$this->assertBudget( 'a result about no intent of the store\'s', StubWebhooks::of( self::stubResult( '01928c3e-0000-7000-8000-00000000dead', Operation::Capture, Outcome::Approved, 1000, 'EUR', 'stub-cap-nobody' ) ), ReceiptResult::Ignored );
		$this->assertBudget( 'a delivery of an event decided before', $approval, ReceiptResult::Duplicate );
	}

	/**
	 * Tests that a refund delivered for its open claim, recorded as the refund's own answer would be, and one no claim accounts for, kept for a person, stay within their budgets.
	 *
	 * @since 0.2.0
	 *
	 * @throws GatewayUnavailable From the refund's call to the provider, which the test makes unreachable.
	 */
	public function test_a_refund_delivered(): void {
		$placed = $this->placed( StubGateway::APPROVE );

		$this->kernel->get( PaymentService::class )->capture( $placed['intent_uuid'], $this->capturer() );

		$line = (string) $this->db->fetchValue( 'SELECT line_uuid FROM %i WHERE order_id = %d ORDER BY sort_order LIMIT 1', $this->table( OrderTables::LINES ), $placed['order_id'] );

		// The provider cannot be reached when the refund is asked, so its claim stays open for the delivery.
		$this->gateway->during( static fn() => throw new GatewayUnavailable( 'The test made the provider unreachable.' ) );

		try {
			$this->kernel->get( RefundService::class )->refund( new RefundRequest( $placed['order_uuid'], array( new RefundLineRequest( $line, 1 ) ), false, 'customer_return' ), $this->userGranted( RefundService::CAPABILITY ) );
			$this->fail( 'The refund reached the provider.' );
		} catch ( GatewayUnavailable $unreached ) {
			unset( $unreached );
		}

		$claim    = (array) $this->db->fetchRow( 'SELECT uuid, amount_minor, currency FROM %i WHERE order_id = %d', $this->table( RefundClaimTables::CLAIMS ), $placed['order_id'] );
		$refunded = self::stubResult( $placed['intent_uuid'], Operation::Refund, Outcome::Approved, (int) $claim['amount_minor'], (string) $claim['currency'], 'stub-re-' . $claim['uuid'] );

		$this->assertBudget( 'a refund recorded through its open claim', StubWebhooks::of( $refunded, (string) $claim['uuid'] ), ReceiptResult::Applied );
		$this->assertBudget( 'a refund no claim accounts for', StubWebhooks::of( self::stubResult( $placed['intent_uuid'], Operation::Refund, Outcome::Approved, 500, (string) $claim['currency'], 'external-re-1' ) ), ReceiptResult::Unapplied );
	}

	/**
	 * Tests that a rejected delivery costs the count of its client's rejections and the line that logs it.
	 *
	 * @since 0.2.0
	 */
	public function test_a_rejected_delivery(): void {
		$placed = $this->placed( StubGateway::THROW );

		$this->assertBudget( 'a rejected delivery', StubWebhooks::of( $this->approvalOf( $placed ) )->withBadSignature(), null );
	}

	/**
	 * Delivers in a request of its own, cold, and asserts what it came to and its exact count of statements, after printing them.
	 *
	 * @since 0.2.0
	 *
	 * @param string             $name     The delivery's name in BUDGETS.
	 * @param StubWebhooks       $delivery The delivery.
	 * @param ReceiptResult|null $expected What it comes to; null for a rejection.
	 */
	private function assertBudget( string $name, StubWebhooks $delivery, ?ReceiptResult $expected ): void {
		$receiver = $this->kernelOver( $this->db, $this->tokens )->get( ReceiveWebhook::class );
		$envelope = $delivery->envelope( new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) ) );

		$this->result  = null;
		$this->refused = null;

		wp_cache_flush();
		wp_load_alloptions();

		$log = $this->captureQueries(
			function () use ( $receiver, $envelope ): void {
				try {
					$this->result = $receiver->receive( $envelope );
				} catch ( CodedException $refused ) {
					$this->refused = (string) $refused->errorCode()->value;
				}
			}
		);

		self::report( $name, $log );

		$this->assertSame( $expected, $this->result, $name . ': ' . ( $this->refused ?? '' ) );
		$this->assertQueryCount( self::BUDGETS[ $name ], $log, $name );
	}

	/**
	 * Prints a delivery's statements, for the record of the budgets.
	 *
	 * @since 0.2.0
	 *
	 * @param string   $name The delivery.
	 * @param QueryLog $log  Its statements.
	 */
	private static function report( string $name, QueryLog $log ): void {
		$lines = array( sprintf( "\nDelivering %s: %d statements.", $name, $log->count() ) );

		foreach ( $log->sqls() as $sql ) {
			$lines[] = '  ' . substr( (string) preg_replace( '/\s+/', ' ', $sql ), 0, 110 );
		}

		fwrite( STDOUT, implode( "\n", $lines ) . "\n" );
	}
}
