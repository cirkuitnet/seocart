<?php
/**
 * Tests that cumulative refunds never exceed what an intent captured
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Payment;

use SEOCart\Contracts\Payment\Operation;
use SEOCart\Contracts\Payment\Outcome;
use SEOCart\Payment\Application\PaymentError;
use SEOCart\Payment\Domain\ApplicationKind;
use SEOCart\Payment\Domain\IntentStatus;
use SEOCart\Payment\Domain\IntentTransitions;
use SEOCart\Payment\Infrastructure\Gateway\StubGateway;
use SEOCart\Payment\Infrastructure\MysqlPaymentRepository;
use SEOCart\Support\Currency;
use SEOCart\Support\Error\CodedException;
use SEOCart\Support\Money;
use SEOCart\Tests\Support\Payment\PaymentTestCase;

/**
 * Refund results applied one after another refund what was captured and no more, through the one money path.
 *
 * The refund's own update carries the cap, `captured_minor - refunded_minor >= amount` and its base
 * twin, in its WHERE clause, so the database refuses a refund past the capture; the refusal is
 * classified from the intent's locked read, and the refund's ledger row goes back with the
 * savepoint.
 *
 * Planted violations, each shown red and removed:
 * - in MysqlPaymentRepository::APPLY_REFUND, neutralise the positive amount, `( %d > 0 OR 1 = 1 )`:
 *   the refund of nothing moves the captured intent to partially refunded;
 * - in MysqlPaymentRepository::APPLY_REFUND, neutralise
 * both caps, `( captured_minor - refunded_minor >= %d OR 1 = 1 )` and its base twin: the refund
 * past the capture lands on the intent, and only the order's projection refuses it, as a
 * conflict rather than the refund's own error.
 *
 * @since 0.1.0
 */
final class RefundCapTest extends PaymentTestCase {

	/**
	 * Tests that refunds of 2000 and then 1080 of 3080 captured are applied, and one of 1500 between them is refused and leaves no row.
	 *
	 * @since 0.1.0
	 */
	public function test_cumulative_refunds_never_exceed_the_capture(): void {
		list( $order, $intent ) = $this->placeCaptured();

		$this->assertSame( ApplicationKind::Applied, $this->deliver( self::stubResult( $intent, Operation::Refund, Outcome::Approved, 2000, 'USD', 'stub-re-1' ) )->kind );
		$this->assertSame( array( 'partially_refunded', '2000' ), array( $this->intentRow( $intent->uuid )['status'], (string) $this->intentRow( $intent->uuid )['refunded_minor'] ) );

		$rows = count( $this->ledgerOf( $order->id ) );

		try {
			$this->deliver( self::stubResult( $intent, Operation::Refund, Outcome::Approved, 1500, 'USD', 'stub-re-2' ) );
			$this->fail( 'A refund past what was captured was applied.' );
		} catch ( CodedException $refused ) {
			$this->assertSame( PaymentError::RefundExceedsCaptured, $refused->errorCode() );
			$this->assertSame(
				array(
					'captured'  => 3080,
					'refunded'  => 2000,
					'requested' => 1500,
				),
				$refused->context()
			);
		}

		$this->assertCount( $rows, $this->ledgerOf( $order->id ), 'The refused refund\'s row went back with its savepoint.' );

		$this->assertSame( ApplicationKind::Applied, $this->deliver( self::stubResult( $intent, Operation::Refund, Outcome::Approved, 1080, 'USD', 'stub-re-3' ) )->kind );

		$intentRow = $this->intentRow( $intent->uuid );
		$orderRow  = $this->orderRow( $order->id );

		$this->assertSame( array( 'refunded', '3080', '3080' ), array( $intentRow['status'], (string) $intentRow['refunded_minor'], (string) $intentRow['base_refunded_minor'] ) );
		$this->assertSame( array( 'refunded', '3080', '3080' ), array( $orderRow['payment_status'], (string) $orderRow['refunded_minor'], (string) $orderRow['base_refunded_minor'] ) );
		$this->assertSame( 'processing', $orderRow['status'], 'A refund changes the payment status, not the order status.' );

		$late = $this->deliver( self::stubResult( $intent, Operation::Refund, Outcome::Approved, 1, 'USD', 'stub-re-4' ) );

		$this->assertSame( ApplicationKind::Mismatch, $late->kind, 'A refunded intent takes no refund at all: one the gateway made all the same is kept for a person.' );
		$this->assertSame( array( 'refunded', '3080' ), array( $this->intentRow( $intent->uuid )['status'], (string) $this->intentRow( $intent->uuid )['refunded_minor'] ) );
		$this->assertSame( array( 'stub-re-4', '0' ), array( (string) $this->ledgerOf( $order->id )[ $rows + 1 ]['provider_object_id'], (string) $this->ledgerOf( $order->id )[ $rows + 1 ]['applied'] ) );
		$this->assertSame( '1', (string) $this->orderRow( $order->id )['has_unreconciled_money'] );
	}

	/**
	 * Tests that the refund statement refunds nothing of nothing: a refund of 0 changes no row, whatever reaches it.
	 *
	 * @since 0.1.0
	 */
	public function test_the_refund_statement_refuses_a_refund_of_nothing(): void {
		list( , $intent ) = $this->placeCaptured();

		$b = $this->secondConnection();

		$b->query(
			$this->rawPayment(
				MysqlPaymentRepository::APPLY_REFUND,
				0,
				0,
				0,
				(int) $this->intentRow( $intent->uuid )['id'],
				'USD',
				'USD',
				IntentTransitions::values( IntentTransitions::allowedFrom( IntentStatus::Refunded ) ),
				0,
				0,
				0
			)
		);

		$this->assertSame( 0, $b->affectedRows(), 'A refund of nothing is no refund.' );
		$this->assertSame( array( 'captured', '0' ), array( $this->intentRow( $intent->uuid )['status'], (string) $this->intentRow( $intent->uuid )['refunded_minor'] ) );
	}

	/**
	 * Tests that a partial refund of an order in another currency than its base is refused before any write: its base share is the refund service's to allocate.
	 *
	 * @since 0.1.0
	 */
	public function test_a_converted_partial_refund_needs_its_base_share(): void {
		list( $order, $intent ) = $this->placeWithIntent();

		$this->deliver( $this->authorizeWith( $intent, StubGateway::APPROVE ) );
		$this->deliver( self::stubResult( $intent, Operation::Capture, Outcome::Approved, self::GRAND_TOTAL, self::CURRENCY, 'stub-cap-' . $intent->uuid ) );

		$rows = count( $this->ledgerOf( $order->id ) );
		$log  = $this->captureQueries(
			function () use ( $intent ): void {
				try {
					$this->deliver( self::stubResult( $intent, Operation::Refund, Outcome::Approved, 1000, self::CURRENCY, 'stub-re-1' ) );
					$this->fail( 'A converted partial refund was applied without its base share.' );
				} catch ( \LogicException $refused ) {
					$this->assertStringContainsString( 'base share', $refused->getMessage() );
				}
			}
		);

		$this->assertQueryCount( 0, $log->ofType( 'INSERT', 'UPDATE', 'DELETE' ), 'writes before the refusal' );
		$this->assertCount( $rows, $this->ledgerOf( $order->id ) );
	}

	/**
	 * Tests that a refund of the intent's whole amount, given no base amount, still adds the intent's whole frozen base amount.
	 *
	 * @since 0.1.0
	 */
	public function test_a_whole_refund_given_no_base_adds_the_frozen_base(): void {
		list( $order, $intent ) = $this->placeConvertedCaptured();

		$this->deliver( self::stubResult( $intent, Operation::Refund, Outcome::Approved, self::GRAND_TOTAL, self::CURRENCY, 'stub-re-whole' ) );

		$this->assertSame( array( 'refunded', (string) self::GRAND_TOTAL, (string) self::BASE_GRAND_TOTAL ), array( $this->intentRow( $intent->uuid )['status'], (string) $this->intentRow( $intent->uuid )['refunded_minor'], (string) $this->intentRow( $intent->uuid )['base_refunded_minor'] ) );
		$this->assertSame( (string) self::BASE_GRAND_TOTAL, (string) $this->orderRow( $order->id )['base_refunded_minor'] );
	}

	/**
	 * Tests that a partial refund of an order in another currency adds the base amount it is given, as the refund service allocated it.
	 *
	 * @since 0.1.0
	 */
	public function test_a_partial_refund_adds_the_base_amount_it_is_given(): void {
		list( $order, $intent ) = $this->placeConvertedCaptured();

		$this->db->transaction( fn() => $this->payments->applyGatewayResult( self::stubResult( $intent, Operation::Refund, Outcome::Approved, 1000, self::CURRENCY, 'stub-re-part' ), self::system(), Money::of( 800, Currency::of( self::BASE ) ) ) );

		$this->assertSame( array( 'partially_refunded', '1000', '800' ), array( $this->intentRow( $intent->uuid )['status'], (string) $this->intentRow( $intent->uuid )['refunded_minor'], (string) $this->intentRow( $intent->uuid )['base_refunded_minor'] ) );
		$this->assertSame( '800', (string) $this->ledgerOf( $order->id )[2]['base_amount_minor'] );
	}

	/**
	 * Tests that a base amount given with an authorization is refused before any statement: an authorization is for the intent's frozen amounts.
	 *
	 * @since 0.1.0
	 */
	public function test_a_base_amount_is_given_with_a_refund_only(): void {
		list( , $intent ) = $this->placeWithIntent();

		$result = $this->authorizeWith( $intent, StubGateway::APPROVE );
		$log    = $this->captureQueries(
			function () use ( $result ): void {
				try {
					$this->db->transaction( fn() => $this->payments->applyGatewayResult( $result, self::system(), Money::of( 1, Currency::of( self::BASE ) ) ) );
					$this->fail( 'An authorization was given a base amount.' );
				} catch ( \LogicException $refused ) {
					$this->assertStringContainsString( 'Only a refund', $refused->getMessage() );
				}
			}
		);

		$this->assertQueryCount( 0, $log->matching( '/seocart_/' ), 'plugin statements before the refusal' );
	}

	/**
	 * Places the fixture order in EUR with a USD base, and authorizes and captures its whole amount.
	 *
	 * @since 0.1.0
	 *
	 * @return array{0: \SEOCart\Order\Domain\InsertedOrder, 1: \SEOCart\Payment\Domain\IntentRef} The order and its intent.
	 */
	private function placeConvertedCaptured(): array {
		list( $order, $intent ) = $this->placeWithIntent();

		$this->deliver( $this->authorizeWith( $intent, StubGateway::APPROVE ) );
		$this->deliver( self::stubResult( $intent, Operation::Capture, Outcome::Approved, self::GRAND_TOTAL, self::CURRENCY, 'stub-cap-' . $intent->uuid ) );

		return array( $order, $intent );
	}
}
