<?php
/**
 * Tests the order document: what it accepts, and the malformed documents it refuses before anything is written
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Order;

use PHPUnit\Framework\TestCase;
use SEOCart\Order\Domain\AmountBasis;
use SEOCart\Order\Domain\NewOrderAdjustment;
use SEOCart\Order\Domain\PaymentDelta;
use SEOCart\Support\ConversionContext;
use SEOCart\Support\Currency;
use SEOCart\Support\CurrencyMismatchException;
use SEOCart\Support\Money;
use SEOCart\Tests\Support\Order\NewOrders;

/**
 * A document is refused whole when an amount is in the wrong currency or a reference dangles.
 *
 * The order copies what the document says, so a document whose base twin is in the order
 * currency, or whose adjustment names a line it does not have, would be copied wrong; it is
 * refused at construction instead, before any statement.
 *
 * Planted violation: in NewOrder::checkCurrencies(), skip the base amounts: the document with a
 * base twin in the order currency is accepted.
 *
 * @since 0.1.0
 */
final class NewOrderTest extends TestCase {

	/**
	 * Tests that the fixture documents are accepted, in one currency and in two.
	 *
	 * @since 0.1.0
	 */
	public function test_a_document_in_one_or_two_currencies_is_accepted(): void {
		$same      = NewOrders::forTwoLines();
		$converted = NewOrders::forTwoLines( 'EUR', 'USD' );

		$this->assertSame( 'USD', $same->currency()->code() );
		$this->assertSame( 'EUR', $converted->currency()->code() );
		$this->assertSame( 'USD', $converted->baseCurrency()->code() );
		$this->assertSame( 2464, $converted->totals->baseGrandTotal->minorUnits() );
	}

	/**
	 * Tests that amounts in a currency other than the context's quote currency are refused.
	 *
	 * @since 0.1.0
	 */
	public function test_amounts_in_another_currency_than_the_orders_are_refused(): void {
		$usd = ConversionContext::identity( Currency::of( 'USD' ) );

		$this->expectException( CurrencyMismatchException::class );

		NewOrders::document( ConversionContext::identity( Currency::of( 'EUR' ) ), NewOrders::lines( $usd ), NewOrders::adjustments( $usd ), NewOrders::totals( $usd ) );
	}

	/**
	 * Tests that a base twin in the order currency is refused.
	 *
	 * @since 0.1.0
	 */
	public function test_a_base_amount_in_the_order_currency_is_refused(): void {
		$converted = NewOrders::context( Currency::of( 'EUR' ), Currency::of( 'USD' ) );
		$wrong     = new NewOrderAdjustment( 'order', 'fee', 'fee:handling', 'Handling', AmountBasis::Net, NewOrders::money( $converted, 100 ), NewOrders::taxed( $converted, 100, 0 ), NewOrders::money( $converted, 100 ), NewOrders::taxed( $converted, 100, 0 ), null, null, false );

		$this->expectException( CurrencyMismatchException::class );

		NewOrders::document( $converted, NewOrders::lines( $converted ), array( $wrong ), NewOrders::totals( $converted ) );
	}

	/**
	 * Tests the malformed documents: no line, two lines with one key, an adjustment naming no line, an adjustment without a source.
	 *
	 * @since 0.1.0
	 */
	public function test_malformed_documents_are_refused(): void {
		$context = ConversionContext::identity( Currency::of( 'USD' ) );
		$lines   = NewOrders::lines( $context );
		$refused = array();

		foreach (
			array(
				'no line'        => static fn() => NewOrders::document( $context, array(), array(), NewOrders::totals( $context ) ),
				'a shared key'   => static fn() => NewOrders::document( $context, array( $lines[0], $lines[0] ), array(), NewOrders::totals( $context ) ),
				'a missing line' => static fn() => NewOrders::document( $context, array( $lines[1] ), NewOrders::adjustments( $context ), NewOrders::totals( $context ) ),
				'no source'      => static fn() => new NewOrderAdjustment( 'order', 'fee', '', 'Handling', AmountBasis::Net, NewOrders::money( $context, 100 ), NewOrders::taxed( $context, 100, 0 ), NewOrders::money( $context, 100 ), NewOrders::taxed( $context, 100, 0 ), null, null, false ),
				'a bad hold'     => static fn() => NewOrders::forTwoLines( holdGroup: 'not-a-uuid' ),
				'a bad customer' => static fn() => NewOrders::forTwoLines( customerId: 0 ),
			) as $case => $build
		) {
			try {
				$build();
			} catch ( \InvalidArgumentException $expected ) {
				$refused[] = $case;
			}
		}

		$this->assertSame( array( 'no line', 'a shared key', 'a missing line', 'no source', 'a bad hold', 'a bad customer' ), $refused );
	}

	/**
	 * Tests that a payment delta refuses a negative amount and mixed currencies.
	 *
	 * @since 0.1.0
	 */
	public function test_a_payment_delta_refuses_a_negative_amount_and_mixed_currencies(): void {
		$usd = static fn( int $minor ): Money => Money::of( $minor, Currency::of( 'USD' ) );
		$eur = static fn( int $minor ): Money => Money::of( $minor, Currency::of( 'EUR' ) );

		$delta = new PaymentDelta( $usd( 100 ), $usd( 0 ), $usd( 0 ), $eur( 80 ), $eur( 0 ), $eur( 0 ) );

		$this->assertSame( 'USD', $delta->currency()->code() );
		$this->assertSame( 'EUR', $delta->baseCurrency()->code() );

		try {
			new PaymentDelta( $usd( -1 ), $usd( 0 ), $usd( 0 ), $usd( 0 ), $usd( 0 ), $usd( 0 ) );
			$this->fail( 'A negative delta was accepted.' );
		} catch ( \InvalidArgumentException $expected ) {
			$this->assertStringContainsString( 'negative', $expected->getMessage() );
		}

		$this->expectException( CurrencyMismatchException::class );

		new PaymentDelta( $usd( 100 ), $eur( 0 ), $usd( 0 ), $usd( 0 ), $usd( 0 ), $usd( 0 ) );
	}
}
