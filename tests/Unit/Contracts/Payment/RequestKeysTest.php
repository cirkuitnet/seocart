<?php
/**
 * Tests the idempotency keys the requests derive, and what a webhook reading may carry
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Contracts\Payment;

use PHPUnit\Framework\TestCase;
use SEOCart\Contracts\Payment\CaptureRequest;
use SEOCart\Contracts\Payment\GatewayRefund;
use SEOCart\Contracts\Payment\GatewayResult;
use SEOCart\Contracts\Payment\Mode;
use SEOCart\Contracts\Payment\Operation;
use SEOCart\Contracts\Payment\Outcome;
use SEOCart\Contracts\Payment\PaymentRequest;
use SEOCart\Contracts\Payment\VoidRequest;
use SEOCart\Contracts\Payment\WebhookReading;
use SEOCart\Contracts\Payment\WebhookReadingKind;
use SEOCart\Support\Currency;
use SEOCart\Support\Money;

/**
 * Each request derives its provider key from the plugin's own identifiers, the same every time it is built: the intent's uuid for an authorization, with `:capture` or `:void` for those, and the refund's uuid for a refund. A webhook reading carries what its kind allows.
 *
 * Planted violation, shown red and removed: make CaptureRequest::idempotencyKey() return a fresh
 * uuid: two requests for the same capture then carry different keys.
 *
 * @since 0.2.0
 */
final class RequestKeysTest extends TestCase {

	/**
	 * The intent every request is about.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const INTENT = '01928c3e-7b3c-7d1e-9a2b-3c4d5e6f7a8c';

	/**
	 * The refund the refund request is for.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const REFUND = '01928c3e-0000-7000-8000-0000000000e1';

	/**
	 * Tests each request's key, and that building the same request again gives the same key.
	 *
	 * @since 0.2.0
	 */
	public function test_each_request_derives_its_key_from_the_plugins_identifiers(): void {
		$amount  = Money::of( 3080, Currency::of( 'EUR' ) );
		$capture = static fn(): CaptureRequest => new CaptureRequest( self::INTENT, 'pi_1', $amount, Mode::Live );

		$this->assertSame( self::INTENT, ( new PaymentRequest( self::INTENT, $amount, 'tok_visa', Mode::Live, '01928c3e-0000-7000-8000-0000000000a1', '1001' ) )->idempotencyKey() );
		$this->assertSame( self::INTENT . ':capture', $capture()->idempotencyKey() );
		$this->assertSame( $capture()->idempotencyKey(), $capture()->idempotencyKey(), 'A capture asked again carries the same key.' );
		$this->assertSame( self::INTENT . ':void', ( new VoidRequest( self::INTENT, 'pi_1', $amount, Mode::Live, 'requested' ) )->idempotencyKey() );
		$this->assertSame( self::REFUND, ( new GatewayRefund( self::INTENT, 'pi_1', $amount, self::REFUND, Mode::Live ) )->idempotencyKey() );
	}

	/**
	 * Tests that a reading carries what its kind allows, and refuses what it does not.
	 *
	 * @since 0.2.0
	 */
	public function test_a_webhook_reading_carries_what_its_kind_allows(): void {
		$refund = new GatewayResult( 'example', Operation::Refund, Outcome::Approved, self::INTENT, Money::of( 500, Currency::of( 'EUR' ) ), 're_1' );
		$read   = WebhookReading::result( 'evt_1', 'refund.updated', null, $refund, self::REFUND );

		$this->assertSame( array( WebhookReadingKind::Result, 'evt_1', self::REFUND ), array( $read->kind, $read->eventId, $read->refundUuid ) );
		$this->assertSame( array( WebhookReadingKind::Rejected, WebhookReading::STALE, null ), array( WebhookReading::rejected( WebhookReading::STALE )->kind, WebhookReading::rejected( WebhookReading::STALE )->reason, WebhookReading::rejected( WebhookReading::STALE )->eventId ) );
		$this->assertSame( 'dispute', WebhookReading::ignored( 'evt_2', 'charge.dispute.created', 'dispute' )->reason );

		$capture = new GatewayResult( 'example', Operation::Capture, Outcome::Approved, self::INTENT, Money::of( 500, Currency::of( 'EUR' ) ), 'ch_1' );

		$this->expectException( \InvalidArgumentException::class );

		WebhookReading::result( 'evt_3', 'payment_intent.succeeded', null, $capture, self::REFUND );
	}
}
