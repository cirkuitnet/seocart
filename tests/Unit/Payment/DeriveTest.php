<?php
/**
 * Tests how an order's payment status is derived from its amounts and its intent's state
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Payment;

use PHPUnit\Framework\TestCase;
use SEOCart\Order\Domain\LockedOrder;
use SEOCart\Order\Domain\OrderChannel;
use SEOCart\Order\Domain\OrderStatus;
use SEOCart\Order\Domain\PaymentDelta;
use SEOCart\Order\Domain\PaymentStatus;
use SEOCart\Payment\Domain\IntentStatus;
use SEOCart\Payment\Domain\Projection;
use SEOCart\Support\Currency;
use SEOCart\Support\Money;

/**
 * The payment status is a function of the amounts after the payment and the intent's state; the table is the specification.
 *
 * Each row is an order's grand total and its authorized, paid and refunded amounts before the
 * payment, what the payment adds, the intent's state after, and the status expected. The amount
 * due the projection reports moves by what is captured and what is refunded, as the order's own
 * update moves it.
 *
 * Planted violations, each shown red and removed:
 * - in Projection::status(), call the order paid only when more than the grand total was paid: the
 *   order captured in full reads as partly paid;
 * - in Projection::status(), leave out the zero grand total: an order with nothing due reads as
 *   unpaid.
 *
 * @since 0.1.0
 */
final class DeriveTest extends TestCase {

	/**
	 * Returns the table.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{0: array{int, int, int, int}, 1: array{int, int, int}, 2: IntentStatus|null, 3: PaymentStatus}> Before (total, authorized, paid, refunded), the payment (authorized, captured, refunded), the intent after (null for none), the status.
	 */
	public static function cases(): array {
		return array(
			'nothing due, so no intent'               => array( array( 0, 0, 0, 0 ), array( 0, 0, 0 ), null, PaymentStatus::Paid ),
			'something due and no intent yet'         => array( array( 3080, 0, 0, 0 ), array( 0, 0, 0 ), null, PaymentStatus::Unpaid ),
			'nothing happened yet'                    => array( array( 3080, 0, 0, 0 ), array( 0, 0, 0 ), IntentStatus::Created, PaymentStatus::Unpaid ),
			'the customer must act'                   => array( array( 3080, 0, 0, 0 ), array( 0, 0, 0 ), IntentStatus::RequiresAction, PaymentStatus::Pending ),
			'the gateway is deciding'                 => array( array( 3080, 0, 0, 0 ), array( 0, 0, 0 ), IntentStatus::Processing, PaymentStatus::Pending ),
			'declined, nothing tendered'              => array( array( 3080, 0, 0, 0 ), array( 0, 0, 0 ), IntentStatus::Failed, PaymentStatus::Failed ),
			'voided, nothing tendered'                => array( array( 3080, 0, 0, 0 ), array( 0, 0, 0 ), IntentStatus::Voided, PaymentStatus::Voided ),
			'authorized in full'                      => array( array( 3080, 0, 0, 0 ), array( 3080, 0, 0 ), IntentStatus::Authorized, PaymentStatus::Authorized ),
			'a declined capture of an authorization'  => array( array( 3080, 3080, 0, 0 ), array( 0, 0, 0 ), IntentStatus::Failed, PaymentStatus::Authorized ),
			'captured in full'                        => array( array( 3080, 3080, 0, 0 ), array( 0, 3080, 0 ), IntentStatus::Captured, PaymentStatus::Paid ),
			'captured in part'                        => array( array( 3080, 3080, 0, 0 ), array( 0, 1000, 0 ), IntentStatus::Captured, PaymentStatus::PartiallyPaid ),
			'refunded in part'                        => array( array( 3080, 3080, 3080, 0 ), array( 0, 0, 1000 ), IntentStatus::PartiallyRefunded, PaymentStatus::PartiallyRefunded ),
			'refunded in full'                        => array( array( 3080, 3080, 3080, 2080 ), array( 0, 0, 1000 ), IntentStatus::Refunded, PaymentStatus::Refunded ),
			'a refund past a partial capture is full' => array( array( 3080, 3080, 1000, 0 ), array( 0, 0, 1000 ), IntentStatus::Refunded, PaymentStatus::Refunded ),
		);
	}

	/**
	 * Tests each row of the table.
	 *
	 * @since 0.1.0
	 *
	 * @dataProvider cases
	 *
	 * @param int[]             $before   The grand total and the authorized, paid and refunded amounts before.
	 * @param int[]             $payment  What the payment authorizes, captures and refunds.
	 * @param IntentStatus|null $intent   The intent's state after; null for an order with none.
	 * @param PaymentStatus     $expected The status.
	 *
	 * @phpstan-param array{int, int, int, int} $before
	 * @phpstan-param array{int, int, int}      $payment
	 */
	public function test_the_status_is_derived_from_the_amounts_and_the_intent( array $before, array $payment, ?IntentStatus $intent, PaymentStatus $expected ): void {
		list( $total, $authorized, $paid, $refunded ) = $before;

		$order = self::order( $total, $authorized, $paid, $refunded );
		$after = Projection::after( $order, self::delta( ...$payment ) );

		$this->assertSame( $expected, $after->status( $intent ) );
		$this->assertSame( array( $authorized + $payment[0], $paid + $payment[1], $refunded + $payment[2] ), array( $after->authorized->minorUnits(), $after->paid->minorUnits(), $after->refunded->minorUnits() ) );
		$this->assertSame( $total - $paid + $refunded - $payment[1] + $payment[2], $after->due->minorUnits(), 'The amount due moves by what is captured and refunded.' );
	}

	/**
	 * Builds a locked order in EUR with a USD base.
	 *
	 * @since 0.1.0
	 *
	 * @param int $total      The grand total.
	 * @param int $authorized Authorized.
	 * @param int $paid       Paid.
	 * @param int $refunded   Refunded.
	 * @return LockedOrder The order.
	 */
	private static function order( int $total, int $authorized, int $paid, int $refunded ): LockedOrder {
		$eur = static fn( int $minor ): Money => Money::of( $minor, Currency::of( 'EUR' ) );
		$usd = static fn( int $minor ): Money => Money::of( $minor, Currency::of( 'USD' ) );

		return new LockedOrder( 7, '01928c3e-7b3c-7d1e-9a2b-3c4d5e6f7a8b', '000007', OrderChannel::Storefront, OrderStatus::Processing, PaymentStatus::Unpaid, $eur( $total ), $eur( $authorized ), $eur( $paid ), $eur( $refunded ), $eur( $total - $paid + $refunded ), $usd( 0 ), $usd( 0 ), $usd( 0 ), $usd( 0 ), null, 'user', null, null );
	}

	/**
	 * Builds a payment in EUR with a USD base of nothing.
	 *
	 * @since 0.1.0
	 *
	 * @param int $authorized Authorized.
	 * @param int $captured   Captured.
	 * @param int $refunded   Refunded.
	 * @return PaymentDelta The payment.
	 */
	private static function delta( int $authorized, int $captured, int $refunded ): PaymentDelta {
		$usd = Money::zero( Currency::of( 'USD' ) );

		return new PaymentDelta( Money::of( $authorized, Currency::of( 'EUR' ) ), Money::of( $captured, Currency::of( 'EUR' ) ), Money::of( $refunded, Currency::of( 'EUR' ) ), $usd, $usd, $usd );
	}
}
