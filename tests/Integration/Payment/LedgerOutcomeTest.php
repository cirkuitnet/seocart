<?php
/**
 * Tests that the ledger tells a provider object's outcomes apart
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Payment;

use SEOCart\Contracts\Payment\GatewayResult;
use SEOCart\Contracts\Payment\Operation;
use SEOCart\Contracts\Payment\Outcome;
use SEOCart\Payment\Domain\ApplicationKind;
use SEOCart\Payment\Domain\IntentRef;
use SEOCart\Tests\Support\Payment\PaymentTestCase;

/**
 * A provider reuses its object across outcomes: a shopper retries a declined card on the same payment intent and it is approved, or a failure follows a recorded success. The ledger records each outcome of a provider object once, by the outcome too.
 *
 * - A decline, then the approval of the same object: the approval is a result of its own, which
 *   the failed payment cannot take, so it is kept for a person: a row applied to nothing, and the
 *   order parked: flagged, and left failed, a final status on hold may not follow.
 * - An approval, then the decline of the same object: the decline is about a state the payment
 *   has left, so it is stale, and writes nothing; it is no duplicate of the approval.
 * - The same result twice: a duplicate, which changes nothing.
 *
 * Planted violations, each shown red and removed:
 * - in PaymentTables::transactions(), key the ledger by provider, object and operation, as before:
 *   the approval after the decline is answered as a duplicate, with no row and no flag;
 * - in MysqlPaymentRepository::FIND_TRANSACTION, read the row whatever its outcome: the decline
 *   after the approval is answered as a duplicate;
 * - in PaymentTables::transactions(), drop the claim key: the same result is recorded twice.
 *
 * @since 0.2.0
 */
final class LedgerOutcomeTest extends PaymentTestCase {

	/**
	 * Tests that the approval of an object whose decline failed the payment is kept for a person, in a row of its own.
	 *
	 * @since 0.2.0
	 */
	public function test_an_approval_after_the_decline_of_the_same_object_is_kept_for_a_person(): void {
		list( $order, $intent ) = $this->placeWithIntent();

		$this->assertSame( ApplicationKind::Declined, $this->deliver( self::ofTheSameObject( $intent, Outcome::Declined ) )->kind );

		$approval = $this->deliver( self::ofTheSameObject( $intent, Outcome::Approved ) );

		$this->assertSame( ApplicationKind::Mismatch, $approval->kind, 'Kept for a person.' );
		$this->assertSame( array( array( 'declined', '1' ), array( 'approved', '0' ) ), $this->rows( $order->id ), 'The approval has a row of its own, applied to nothing.' );
		$this->assertSame( array( 'failed', 'failed', '1' ), array( $this->intentRow( $intent->uuid )['status'], $this->orderRow( $order->id )['status'], (string) $this->orderRow( $order->id )['has_unreconciled_money'] ), 'The payment stays failed, and the order is parked: flagged, in its status, final, which on hold may not follow.' );
		$this->assertSame( ApplicationKind::Duplicate, $this->deliver( self::ofTheSameObject( $intent, Outcome::Approved ) )->kind, 'The approval delivered again is a duplicate.' );
		$this->assertCount( 2, $this->rows( $order->id ) );
	}

	/**
	 * Tests that the decline of an object whose approval authorized the payment is stale, and writes nothing.
	 *
	 * @since 0.2.0
	 */
	public function test_a_decline_after_the_approval_of_the_same_object_is_stale(): void {
		list( $order, $intent ) = $this->placeWithIntent();

		$this->assertSame( ApplicationKind::Applied, $this->deliver( self::ofTheSameObject( $intent, Outcome::Approved ) )->kind );

		$this->assertSame( ApplicationKind::Stale, $this->deliver( self::ofTheSameObject( $intent, Outcome::Declined ) )->kind );
		$this->assertSame( array( array( 'approved', '1' ) ), $this->rows( $order->id ), 'Nothing was written.' );
		$this->assertSame( array( 'authorized', '0' ), array( $this->intentRow( $intent->uuid )['status'], (string) $this->orderRow( $order->id )['has_unreconciled_money'] ) );
	}

	/**
	 * Tests that the same result of the same object delivered twice is a duplicate, for an approval and for a decline.
	 *
	 * @since 0.2.0
	 */
	public function test_the_same_result_twice_is_a_duplicate(): void {
		foreach ( array( Outcome::Approved, Outcome::Declined ) as $outcome ) {
			list( $order, $intent ) = $this->placeWithIntent();

			$this->deliver( self::ofTheSameObject( $intent, $outcome ) );

			$this->assertSame( ApplicationKind::Duplicate, $this->deliver( self::ofTheSameObject( $intent, $outcome ) )->kind, $outcome->value );
			$this->assertCount( 1, $this->rows( $order->id ), $outcome->value );
		}
	}

	/**
	 * Builds an authorization's outcome of the provider object `pi_same`, for the intent's whole amount.
	 *
	 * @since 0.2.0
	 *
	 * @param IntentRef $intent  The intent.
	 * @param Outcome   $outcome The outcome.
	 * @return GatewayResult The result.
	 */
	private static function ofTheSameObject( IntentRef $intent, Outcome $outcome ): GatewayResult {
		return self::stubResult( $intent, Operation::Authorize, $outcome, self::GRAND_TOTAL, self::CURRENCY, 'pi_same_' . $intent->uuid );
	}

	/**
	 * Reads an order's ledger rows: each one's outcome and whether it was applied, in order.
	 *
	 * @since 0.2.0
	 *
	 * @param int $orderId The order.
	 * @return list<array{0: string, 1: string}> The rows.
	 */
	private function rows( int $orderId ): array {
		return array_map( static fn( array $row ): array => array( (string) $row['result'], (string) $row['applied'] ), $this->ledgerOf( $orderId ) );
	}
}
