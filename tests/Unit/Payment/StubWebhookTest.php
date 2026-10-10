<?php
/**
 * Tests how the stand-in gateway reads a webhook delivery: verified first, then its window, then decoded
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Payment;

use Brain\Monkey;
use PHPUnit\Framework\TestCase;
use SEOCart\Contracts\Payment\GatewayResult;
use SEOCart\Contracts\Payment\Mode;
use SEOCart\Contracts\Payment\Operation;
use SEOCart\Contracts\Payment\Operations;
use SEOCart\Contracts\Payment\Outcome;
use SEOCart\Contracts\Payment\WebhookEnvelope;
use SEOCart\Contracts\Payment\WebhookReading;
use SEOCart\Contracts\Payment\WebhookReadingKind;
use SEOCart\Payment\Infrastructure\Gateway\StubGateway;
use SEOCart\Support\Currency;
use SEOCart\Support\Money;
use SEOCart\Tests\Support\Payment\StubWebhooks;

/**
 * The stand-in verifies a delivery's signature over the raw body before it reads anything, checks the signed time against the arrival, and only then decodes the body.
 *
 * Planted violation: in StubGateway::readWebhook(), decode the body before checking the
 * signature, and reject a body that is not JSON as malformed there: a delivery that is not JSON
 * and carries a bad signature is then answered `malformed`, which only a verified delivery may be.
 *
 * @since 0.2.0
 */
final class StubWebhookTest extends TestCase {

	/**
	 * The intent the deliveries are about.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const INTENT = '01928c3e-7b3c-7d1e-9a2b-3c4d5e6f7a8c';

	/**
	 * Loads the WordPress helpers Brain Monkey stands in for, such as wp_json_encode().
	 *
	 * @since 0.2.0
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	/**
	 * Tears Brain Monkey down.
	 *
	 * @since 0.2.0
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Tests that a body that is not JSON is rejected for its bad signature, never as malformed: nothing of it is read before it verifies; verified, it is malformed.
	 *
	 * @since 0.2.0
	 */
	public function test_a_body_is_verified_before_it_is_read(): void {
		$garbage = StubWebhooks::event( 'evt_1', 'capture.approved', array() )->withBody( 'not json at all' );

		$this->assertSame( array( WebhookReadingKind::Rejected, WebhookReading::BAD_SIGNATURE ), self::kindOf( $garbage->withBadSignature() ) );
		$this->assertSame( array( WebhookReadingKind::Rejected, WebhookReading::MALFORMED ), self::kindOf( $garbage ) );
		$this->assertSame(
			array( WebhookReadingKind::Rejected, WebhookReading::MALFORMED ),
			self::kindOf(
				StubWebhooks::event(
					'evt_2',
					'capture.approved',
					array(
						'amount_minor' => 700,
						'currency'     => 'XXX',
					)
				)
			),
			'An event that verifies but cannot make a result.'
		);
	}

	/**
	 * Tests that a delivery with no signature, or one of another scheme, is rejected for its signature.
	 *
	 * @since 0.2.0
	 */
	public function test_a_delivery_without_a_signature_of_the_stand_ins_scheme_is_rejected(): void {
		$approval = StubWebhooks::of( self::result( Operation::Capture, Outcome::Approved ) );
		$at       = self::arrival();
		$stub     = new StubGateway();
		$unsigned = new WebhookEnvelope( StubGateway::ID, Mode::Test, array(), $approval->body(), $at );
		$headers  = $approval->headers( $at->getTimestamp() );
		$v0       = new WebhookEnvelope( StubGateway::ID, Mode::Test, array( StubGateway::SIGNATURE_HEADER => str_replace( 'v1=', 'v0=', $headers[ StubGateway::SIGNATURE_HEADER ] ) ), $approval->body(), $at );

		foreach ( array( $unsigned, $v0 ) as $envelope ) {
			$reading = $stub->readWebhook( $envelope );

			$this->assertSame( array( WebhookReadingKind::Rejected, WebhookReading::BAD_SIGNATURE, null ), array( $reading->kind, $reading->reason, $reading->eventId ) );
		}
	}

	/**
	 * Tests that a delivery signed more than the tolerance before or after it arrived is rejected as stale, and one just within it is read.
	 *
	 * @since 0.2.0
	 */
	public function test_the_window_is_the_signed_time_against_the_arrival(): void {
		$approval = StubWebhooks::of( self::result( Operation::Capture, Outcome::Approved ) );

		foreach ( array( 301, -301 ) as $skew ) {
			$this->assertSame( array( WebhookReadingKind::Rejected, WebhookReading::STALE ), self::kindOf( $approval->staleBy( $skew ) ), 'Signed ' . $skew . ' s before it arrived.' );
		}

		foreach ( array( 300, -300 ) as $skew ) {
			$this->assertSame( WebhookReadingKind::Result, self::kindOf( $approval->staleBy( $skew ) )[0], 'Signed ' . $skew . ' s before it arrived.' );
		}
	}

	/**
	 * Tests that a verified result is the result it reports, every field kept, and that a refund carries the uuid the provider echoed.
	 *
	 * @since 0.2.0
	 */
	public function test_a_verified_result_is_read_as_it_was_reported(): void {
		$stub = new StubGateway();

		foreach ( StubGateway::WEBHOOK_EVENTS as $type => $answer ) {
			$result  = self::result( Operation::from( $answer[0] ), Outcome::from( $answer[1] ) );
			$refund  = Operation::Refund === $result->operation ? '01928c3e-0000-7000-8000-0000000000ef' : null;
			$reading = $stub->readWebhook( StubWebhooks::of( $result, $refund )->envelope( self::arrival() ) );

			$this->assertSame( array( WebhookReadingKind::Result, $type ), array( $reading->kind, $reading->eventType ), $type );
			$this->assertEquals( $result, $reading->result, $type );
			$this->assertSame( $refund, $reading->refundUuid, $type );
			$this->assertEquals( new \DateTimeImmutable( '@1760000000' ), $reading->occurredAt );
		}
	}

	/**
	 * Tests that a dispute is ignored as one, naming its payment, and that an event of another type is ignored.
	 *
	 * @since 0.2.0
	 */
	public function test_a_dispute_and_an_unknown_type_are_ignored(): void {
		$stub    = new StubGateway();
		$dispute = $stub->readWebhook( StubWebhooks::dispute( self::INTENT, 'stub-pi-approve-' . self::INTENT )->envelope( self::arrival() ) );
		$payout  = $stub->readWebhook( StubWebhooks::event( 'evt_payout', 'payout.paid', array() )->envelope( self::arrival() ) );

		$this->assertSame( array( WebhookReadingKind::Ignored, WebhookReading::DISPUTE, self::INTENT, 'stub-pi-approve-' . self::INTENT ), array( $dispute->kind, $dispute->reason, $dispute->intentUuid, $dispute->providerIntentId ) );
		$this->assertSame( array( WebhookReadingKind::Ignored, 'payout.paid', StubGateway::UNKNOWN_EVENT ), array( $payout->kind, $payout->eventType, $payout->reason ) );
	}

	/**
	 * Tests that the stand-in declares webhooks in every cell of its matrix.
	 *
	 * @since 0.2.0
	 */
	public function test_the_stand_in_declares_webhooks(): void {
		foreach ( ( new StubGateway() )->describe()->matrix->rows as $row ) {
			$this->assertTrue( $row->supports( Operations::WEBHOOKS ), $row->currency->code() );
		}
	}

	/**
	 * Reads a delivery arriving now and returns its kind and its reason.
	 *
	 * @since 0.2.0
	 *
	 * @param StubWebhooks $delivery The delivery.
	 * @return array{0: WebhookReadingKind, 1: string|null} The kind and the reason.
	 */
	private static function kindOf( StubWebhooks $delivery ): array {
		$reading = ( new StubGateway() )->readWebhook( $delivery->envelope( self::arrival() ) );

		return array( $reading->kind, $reading->reason );
	}

	/**
	 * Returns when the deliveries arrive.
	 *
	 * @since 0.2.0
	 *
	 * @return \DateTimeImmutable 2026-10-09 12:00:00 UTC.
	 */
	private static function arrival(): \DateTimeImmutable {
		return new \DateTimeImmutable( '2026-10-09 12:00:00', new \DateTimeZone( 'UTC' ) );
	}

	/**
	 * Builds a result of the stand-in about the test's intent.
	 *
	 * @since 0.2.0
	 *
	 * @param Operation $operation The operation.
	 * @param Outcome   $outcome   The outcome.
	 * @return GatewayResult The result.
	 */
	private static function result( Operation $operation, Outcome $outcome ): GatewayResult {
		$money  = in_array( $outcome, Outcome::moneyFacts(), true );
		$object = $money ? 'stub-' . $operation->value . '-' . self::INTENT : null;
		$code   = Outcome::Declined === $outcome ? 'card_declined' : null;

		return new GatewayResult( StubGateway::ID, $operation, $outcome, self::INTENT, Money::of( 3080, Currency::of( 'EUR' ) ), $object, 'stub-pi-approve-' . self::INTENT, $code );
	}
}
