<?php
/**
 * Tests how an authorization request reads back after it was serialized
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Contracts\Payment;

use PHPUnit\Framework\TestCase;
use SEOCart\Contracts\Payment\BillingContact;
use SEOCart\Contracts\Payment\Mode;
use SEOCart\Contracts\Payment\PaymentRequest;
use SEOCart\Support\Currency;
use SEOCart\Support\Money;

// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize, WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- How a request reads back from PHP's serialization is under test.

/**
 * A request reads back as it was written, and one kept from before the billing contact was added reads with none.
 *
 * Planted violation, shown red and removed: take PaymentRequest::__unserialize() away: the request
 * kept from before the billing contact throws when its contact is read.
 *
 * @group contract
 * @since 0.2.0
 */
final class PaymentRequestTest extends TestCase {

	/**
	 * Returns a request of seven members: no billing contact.
	 *
	 * @since 0.2.0
	 *
	 * @return PaymentRequest The request.
	 */
	private static function request(): PaymentRequest {
		return new PaymentRequest( '01928c3e-0000-7000-8000-000000000001', Money::of( 2000, Currency::of( 'USD' ) ), 'tok', Mode::Test, '01928c3e-0000-7000-8000-0000000000a1', '1001', 'https://shop.example/return' );
	}

	/**
	 * Tests that a request serialized before the billing contact was added, its seven members alone, reads back with no contact and every other member as it was.
	 *
	 * @since 0.2.0
	 */
	public function test_a_request_kept_from_before_the_billing_contact_reads_with_none(): void {
		$written = serialize( self::request() );
		$header  = '"' . PaymentRequest::class . '":';

		// The shape a request had before the billing contact: its first seven members, and no `billing`.
		$kept = str_replace( array( $header . '8:{', 's:7:"billing";N;' ), array( $header . '7:{', '' ), $written );

		$this->assertSame( 1, substr_count( $written, $header . '8:{' ), 'The request is written with eight members.' );
		$this->assertStringNotContainsString( 'billing', $kept, 'The kept request names no billing contact.' );

		$read = unserialize( $kept, array( 'allowed_classes' => array( PaymentRequest::class, Mode::class, Money::class, Currency::class ) ) );

		$this->assertInstanceOf( PaymentRequest::class, $read );
		$this->assertNull( $read->billing );
		$this->assertEquals( self::request(), $read );
	}

	/**
	 * Tests that a request with a billing contact, and one without a return address, read back as they were written.
	 *
	 * @since 0.2.0
	 */
	public function test_a_request_reads_back_as_it_was_written(): void {
		$base     = self::request();
		$contact  = new BillingContact( 'US', 'Ada', 'Lovelace', '', '1 Main Street', '', 'Austin', 'TX', '78701' );
		$requests = array(
			new PaymentRequest( $base->intentUuid, $base->amount, $base->paymentToken, $base->mode, $base->orderUuid, $base->orderNumber, $base->returnUrl, $contact ),
			new PaymentRequest( $base->intentUuid, $base->amount, null, Mode::Live, $base->orderUuid, $base->orderNumber ),
		);

		foreach ( $requests as $request ) {
			$this->assertEquals( $request, unserialize( serialize( $request ), array( 'allowed_classes' => array( PaymentRequest::class, BillingContact::class, Mode::class, Money::class, Currency::class ) ) ) );
		}
	}
}
