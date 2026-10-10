<?php
/**
 * Tests what a gateway's reading of a webhook delivery may carry
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Contracts\Payment;

use PHPUnit\Framework\TestCase;
use SEOCart\Contracts\Payment\GatewayResult;
use SEOCart\Contracts\Payment\Operation;
use SEOCart\Contracts\Payment\Outcome;
use SEOCart\Contracts\Payment\WebhookReading;
use SEOCart\Contracts\Payment\WebhookReadingKind;
use SEOCart\Support\Currency;
use SEOCart\Support\Money;

/**
 * An ignored reading may name the payment it is about, as a dispute does; no other reading names one apart from its result.
 *
 * Planted violation: in the WebhookReading constructor, delete the refusal of a payment named on
 * a reading that is not ignored: a result naming another intent than its result's is made.
 *
 * @since 0.2.0
 *
 * @group contract
 */
final class WebhookReadingTest extends TestCase {

	/**
	 * Tests that an ignored reading names the payment it is about by the plugin's uuid, the provider's reference, or neither.
	 *
	 * @since 0.2.0
	 */
	public function test_an_ignored_reading_may_name_its_payment(): void {
		$dispute = WebhookReading::ignored( 'evt_1', 'charge.dispute.created', WebhookReading::DISPUTE, null, '01928c3e-7b3c-7d1e-9a2b-3c4d5e6f7a8c', 'pi_123' );
		$payout  = WebhookReading::ignored( 'evt_2', 'payout.paid', 'not_a_payment' );

		$this->assertSame( array( WebhookReadingKind::Ignored, 'dispute', '01928c3e-7b3c-7d1e-9a2b-3c4d5e6f7a8c', 'pi_123' ), array( $dispute->kind, $dispute->reason, $dispute->intentUuid, $dispute->providerIntentId ) );
		$this->assertSame( array( null, null ), array( $payout->intentUuid, $payout->providerIntentId ) );
	}

	/**
	 * Tests that a result and a rejection never name a payment apart: a result names it in its result, and a rejection names nothing.
	 *
	 * @since 0.2.0
	 */
	public function test_only_an_ignored_reading_names_its_payment_apart(): void {
		$result  = new GatewayResult( 'stub', Operation::Capture, Outcome::Approved, '01928c3e-7b3c-7d1e-9a2b-3c4d5e6f7a8c', Money::of( 700, Currency::of( 'USD' ) ), 'ch_1' );
		$refused = 0;

		$readings = array(
			static fn() => new WebhookReading( WebhookReadingKind::Result, 'evt_3', 'charge.captured', null, $result, null, null, '01928c3e-0000-7000-8000-0000000000ff' ),
			static fn() => new WebhookReading( WebhookReadingKind::Result, 'evt_3', 'charge.captured', null, $result, null, null, null, 'pi_123' ),
			static fn() => new WebhookReading( WebhookReadingKind::Rejected, null, null, null, null, WebhookReading::BAD_SIGNATURE, null, '01928c3e-0000-7000-8000-0000000000ff' ),
		);

		foreach ( $readings as $reading ) {
			try {
				$reading();
			} catch ( \InvalidArgumentException $named ) {
				++$refused;
			}
		}

		$this->assertSame( 3, $refused, 'A reading other than an ignored one named its payment apart.' );
		$this->assertNull( WebhookReading::result( 'evt_3', 'charge.captured', null, $result )->intentUuid );
	}
}
