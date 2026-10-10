<?php
/**
 * Tests the stub gateway's script: what each token answers, first and later
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Payment;

use PHPUnit\Framework\TestCase;
use SEOCart\Contracts\Payment\CaptureRequest;
use SEOCart\Contracts\Payment\GatewayDescriptor;
use SEOCart\Contracts\Payment\GatewayRefund;
use SEOCart\Contracts\Payment\GatewayUnavailable;
use SEOCart\Contracts\Payment\IdempotencyProfile;
use SEOCart\Contracts\Payment\MatrixRow;
use SEOCart\Contracts\Payment\Mode;
use SEOCart\Contracts\Payment\Operation;
use SEOCart\Contracts\Payment\Operations;
use SEOCart\Contracts\Payment\Outcome;
use SEOCart\Contracts\Payment\PaymentGateway;
use SEOCart\Contracts\Payment\PaymentQuery;
use SEOCart\Contracts\Payment\PaymentRequest;
use SEOCart\Contracts\Payment\VoidRequest;
use SEOCart\Contracts\Payment\WebhookEnvelope;
use SEOCart\Contracts\Payment\WebhookReading;
use SEOCart\Contracts\Payment\WebhookReadingKind;
use SEOCart\Payment\Infrastructure\Gateway\StubGateway;
use SEOCart\Support\Currency;
use SEOCart\Support\Money;

/**
 * The stub answers as its token says, names its objects the same way every time, and remembers each intent's scenario by the reference it gives it.
 *
 * Planted violation, shown red and removed: in StubGateway::firstAnswer(), approve the wrong-amount
 * token for the amount asked: its answer no longer differs.
 *
 * @since 0.1.0
 */
final class StubGatewayTest extends TestCase {

	/**
	 * The intent every request is about.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const INTENT = '01928c3e-7b3c-7d1e-9a2b-3c4d5e6f7a8c';

	/**
	 * Returns each token's first answer: the outcome, the amount and currency, the object, the reference and the code.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{string, array{Outcome, int, string, string|null, string|null, string|null}}> The token, and its answer.
	 */
	public static function tokens(): array {
		$charge = 'stub-ch-' . self::INTENT;

		return array(
			'approve'                    => array( StubGateway::APPROVE, array( Outcome::Approved, 3080, 'EUR', $charge, 'stub-pi-approve-' . self::INTENT, null ) ),
			'approve, settled'           => array( StubGateway::APPROVE_SETTLED, array( Outcome::Approved, 3080, 'EUR', $charge, 'stub-pi-approve_settled-' . self::INTENT, null ) ),
			'decline'                    => array( StubGateway::DECLINE, array( Outcome::Declined, 3080, 'EUR', $charge, 'stub-pi-decline-' . self::INTENT, 'card_declined' ) ),
			'requires action'            => array( StubGateway::REQUIRES_ACTION, array( Outcome::RequiresAction, 3080, 'EUR', null, 'stub-pi-requires_action-' . self::INTENT, null ) ),
			'requires action, completed' => array( StubGateway::REQUIRES_ACTION_COMPLETED, array( Outcome::RequiresAction, 3080, 'EUR', null, 'stub-pi-requires_action_completed-' . self::INTENT, null ) ),
			'pending'                    => array( StubGateway::PENDING, array( Outcome::Pending, 3080, 'EUR', null, 'stub-pi-pending-' . self::INTENT, null ) ),
			'wrong amount'               => array( StubGateway::WRONG_AMOUNT, array( Outcome::Approved, 3081, 'EUR', $charge, 'stub-pi-wrong_amount-' . self::INTENT, null ) ),
			'wrong currency'             => array( StubGateway::WRONG_CURRENCY, array( Outcome::Approved, 3080, 'USD', $charge, 'stub-pi-wrong_currency-' . self::INTENT, null ) ),
			'capture wrong amount'       => array( StubGateway::CAPTURE_WRONG_AMOUNT, array( Outcome::Approved, 3080, 'EUR', $charge, 'stub-pi-capture_wrong_amount-' . self::INTENT, null ) ),
			'refund decline'             => array( StubGateway::REFUND_DECLINE, array( Outcome::Approved, 3080, 'EUR', $charge, 'stub-pi-refund_decline-' . self::INTENT, null ) ),
			'a token not scripted'       => array( 'tok_visa', array( Outcome::Declined, 3080, 'EUR', $charge, null, StubGateway::INVALID_TOKEN ) ),
		);
	}

	/**
	 * Tests each token's first answer.
	 *
	 * @since 0.1.0
	 *
	 * @dataProvider tokens
	 *
	 * @param string $token    The token.
	 * @param array  $expected The outcome, the amount and currency, the object, the reference and the code.
	 *
	 * @phpstan-param array{Outcome, int, string, string|null, string|null, string|null} $expected
	 */
	public function test_each_token_answers_as_scripted( string $token, array $expected ): void {
		$result = ( new StubGateway() )->authorize( self::authorization( $token ) );

		$this->assertSame( array( StubGateway::ID, Operation::Authorize, self::INTENT ), array( $result->provider, $result->operation, $result->intentUuid ) );
		$this->assertSame( $expected, array( $result->outcome, $result->amount->minorUnits(), $result->amount->currency()->code(), $result->providerObjectId, $result->providerIntentId, $result->errorCode ) );
		$this->assertSame( StubGateway::APPROVE_SETTLED === $token, null !== $result->settlement, 'Only the settled approval reports a settlement.' );
		$this->assertSame( Outcome::RequiresAction === $result->outcome, null !== $result->nextAction, 'Only a request to act says what to do.' );
	}

	/**
	 * Tests what the stub asks a shopper to do: go back to the return address, with a handle made from the intent's uuid; with no return address, the handle alone.
	 *
	 * @since 0.2.0
	 */
	public function test_a_request_to_act_says_what_to_do(): void {
		$return     = 'https://shop.example.test/?seocart_payment=' . self::INTENT;
		$redirected = ( new StubGateway() )->authorize( new PaymentRequest( self::INTENT, self::amount(), StubGateway::REQUIRES_ACTION, Mode::Test, '01928c3e-0000-7000-8000-0000000000a1', '1001', $return ) )->nextAction;
		$scripted   = ( new StubGateway() )->authorize( self::authorization( StubGateway::REQUIRES_ACTION ) )->nextAction;

		$this->assertSame( array( 'redirect', $return, 'stub_cs_' . self::INTENT ), array( $redirected?->type, $redirected?->url, $redirected?->clientToken ) );
		$this->assertSame( array( 'sdk', null, 'stub_cs_' . self::INTENT ), array( $scripted?->type, $scripted?->url, $scripted?->clientToken ) );
	}

	/**
	 * Tests the later answers: the customer confirmed, the provider still decides, and the rest answer as they first did; an intent the stub gave no reference to has no record, which is a declined authorization `not_found`.
	 *
	 * @since 0.1.0
	 */
	public function test_a_query_answers_what_the_intent_came_to(): void {
		$stub = new StubGateway();

		$confirmed = $stub->query( self::query( 'stub-pi-requires_action-' . self::INTENT ) );

		$this->assertNotNull( $confirmed );
		$this->assertSame( array( Outcome::Approved, 'stub-ch-' . self::INTENT ), array( $confirmed->outcome, $confirmed->providerObjectId ) );
		$this->assertNull( $stub->query( self::query( 'stub-pi-pending-' . self::INTENT ) ), 'Still pending.' );
		$this->assertEquals( $stub->authorize( self::authorization( StubGateway::DECLINE ) ), $stub->query( self::query( 'stub-pi-decline-' . self::INTENT ) ) );
		$unheard = $stub->query( self::query( null ) );

		$this->assertNotNull( $unheard, 'An intent the stub gave no reference to is one it has no record of: an answer.' );
		$this->assertSame( array( Operation::Authorize, Outcome::Declined, StubGateway::NOT_FOUND, 'stub-nf-' . self::INTENT, null ), array( $unheard->operation, $unheard->outcome, $unheard->errorCode, $unheard->providerObjectId, $unheard->providerIntentId ) );
		$this->assertNull( $stub->query( self::query( 'pi_3OtherProvider' ) ), 'Another provider\'s reference.' );
		$this->assertNull( $stub->query( self::query( 'stub-pi-throw-' . self::INTENT ) ), 'A scenario the script does not know.' );
	}

	/**
	 * Tests that an intent waiting for the customer or pending is expired once the query says its wait ran out, and answered as a declined authorization `expired`; before then, and for any other scenario, the expiry changes nothing.
	 *
	 * Planted violations:
	 * - in StubGateway::query(), leave out the expiry: the expired intents are answered as before;
	 * - in StubGateway::query(), expire every waiting intent, whatever the query says: the ones not
	 *   yet expired are declined too.
	 *
	 * @since 0.1.0
	 */
	public function test_an_intent_waiting_past_its_expiry_is_answered_expired(): void {
		$stub = new StubGateway();

		foreach ( array( 'requires_action', 'pending' ) as $scenario ) {
			$expired = $stub->query( self::query( 'stub-pi-' . $scenario . '-' . self::INTENT, true ) );

			$this->assertNotNull( $expired, $scenario );
			$this->assertSame(
				array( Operation::Authorize, Outcome::Declined, StubGateway::EXPIRED, 'stub-ex-' . self::INTENT, 'stub-pi-' . $scenario . '-' . self::INTENT, 3080 ),
				array( $expired->operation, $expired->outcome, $expired->errorCode, $expired->providerObjectId, $expired->providerIntentId, $expired->amount->minorUnits() ),
				$scenario
			);
		}

		$this->assertSame( Outcome::Approved, $stub->query( self::query( 'stub-pi-requires_action-' . self::INTENT, false ) )?->outcome, 'Before its expiry, the customer confirmed.' );
		$this->assertNull( $stub->query( self::query( 'stub-pi-pending-' . self::INTENT, false ) ), 'Before its expiry, still pending.' );
		$this->assertSame( Outcome::Approved, $stub->query( self::query( 'stub-pi-approve-' . self::INTENT, true ) )?->outcome, 'An approval is not taken back by an expiry.' );
		$this->assertSame( StubGateway::NOT_FOUND, $stub->query( self::query( null, true ) )?->errorCode, 'An intent never heard of is not found, expired or not.' );
	}

	/**
	 * Tests that a capture takes the amount asked, or one minor unit more for an intent authorized to capture wrongly, under its own object.
	 *
	 * @since 0.1.0
	 */
	public function test_a_capture_takes_the_amount_asked(): void {
		$stub  = new StubGateway();
		$right = $stub->capture( new CaptureRequest( self::INTENT, 'stub-pi-approve-' . self::INTENT, self::amount(), Mode::Test ) );
		$wrong = $stub->capture( new CaptureRequest( self::INTENT, 'stub-pi-capture_wrong_amount-' . self::INTENT, self::amount(), Mode::Test ) );

		$this->assertSame( array( Operation::Capture, Outcome::Approved, 3080, 'stub-cap-' . self::INTENT ), array( $right->operation, $right->outcome, $right->amount->minorUnits(), $right->providerObjectId ) );
		$this->assertSame( 3081, $wrong->amount->minorUnits() );
	}

	/**
	 * Tests that a refund gives back the amount asked under an object named by its idempotency key, and is declined for an intent authorized so its refunds are.
	 *
	 * @since 0.1.0
	 */
	public function test_a_refund_gives_back_the_amount_asked_or_is_declined(): void {
		$stub     = new StubGateway();
		$key      = '01928c3e-0000-7000-8000-0000000000e1';
		$approved = $stub->refund( new GatewayRefund( self::INTENT, 'stub-pi-approve-' . self::INTENT, Money::of( 1234, Currency::of( 'EUR' ) ), $key, Mode::Test ) );
		$declined = $stub->refund( new GatewayRefund( self::INTENT, 'stub-pi-refund_decline-' . self::INTENT, Money::of( 1234, Currency::of( 'EUR' ) ), $key, Mode::Test ) );

		$this->assertSame( array( Operation::Refund, Outcome::Approved, 1234, 'EUR', 'stub-re-' . $key, null ), array( $approved->operation, $approved->outcome, $approved->amount->minorUnits(), $approved->amount->currency()->code(), $approved->providerObjectId, $approved->errorCode ) );
		$this->assertSame( array( Outcome::Declined, StubGateway::REFUND_DECLINED, 'stub-re-' . $key ), array( $declined->outcome, $declined->errorCode, $declined->providerObjectId ) );
		$this->assertEquals( $approved, $stub->refund( new GatewayRefund( self::INTENT, 'stub-pi-approve-' . self::INTENT, Money::of( 1234, Currency::of( 'EUR' ) ), $key, Mode::Test ) ), 'The same refund asked again is the same result.' );
		$this->assertSame( Outcome::Approved, $stub->authorize( self::authorization( StubGateway::REFUND_DECLINE ) )->outcome, 'An intent whose refunds are declined is authorized.' );
	}

	/**
	 * Tests that the stub can be told to be unavailable.
	 *
	 * @since 0.1.0
	 */
	public function test_it_can_be_unavailable(): void {
		$this->expectException( GatewayUnavailable::class );

		( new StubGateway() )->authorize( self::authorization( StubGateway::THROW ) );
	}

	/**
	 * Tests the stub's declaration: test mode only, no settings, USD, GBP and EUR for an account of any country, every operation but capturing in parts and charging without the customer, and a provider searchable at once.
	 *
	 * The unit suite loads no WordPress, so describe() calling any WordPress function would fail here.
	 *
	 * @since 0.2.0
	 */
	public function test_it_describes_itself_as_a_test_mode_stand_in(): void {
		$descriptor = ( new StubGateway() )->describe();
		$expected   = array_values( array_diff( Operations::ALL, array( Operations::MULTI_CAPTURE, Operations::OFF_SESSION ) ) );

		$this->assertSame( array( StubGateway::ID, GatewayDescriptor::TYPE_PAYMENTS, PaymentGateway::CONTRACT_VERSION ), array( $descriptor->id, $descriptor->type, $descriptor->contract ) );
		$this->assertSame( array( Mode::Test ), $descriptor->modes );
		$this->assertSame( array(), $descriptor->settings, 'The stub has no settings, so it has no settings document either.' );
		$this->assertSame( array(), $descriptor->hosts, 'The stub answers from its script and calls no external service.' );
		$this->assertSame( array( 'USD', 'GBP', 'EUR' ), $descriptor->matrix->currencies() );

		foreach ( $descriptor->matrix->rows as $row ) {
			$this->assertSame( array( MatrixRow::ANY_COUNTRY, $expected ), array( $row->accountCountry, $row->operations ), $row->currency->code() );
		}

		$this->assertFalse( $descriptor->matrix->allows( Operations::AUTHORIZE, Currency::of( 'JPY' ), null ), 'A currency the stub does not take.' );
		$this->assertEquals( new IdempotencyProfile( null, true, 0 ), $descriptor->idempotency );
	}

	/**
	 * Tests that a void is approved under an object named by the intent, the same every time, and that a webhook delivery with no signature is rejected.
	 *
	 * @since 0.2.0
	 */
	public function test_a_void_is_approved_and_a_webhook_rejected(): void {
		$stub    = new StubGateway();
		$request = new VoidRequest( self::INTENT, 'stub-pi-approve-' . self::INTENT, self::amount(), Mode::Test, 'requested' );
		$voided  = $stub->void( $request );

		$this->assertSame( array( Operation::Void, Outcome::Approved, 3080, 'stub-void-' . self::INTENT ), array( $voided->operation, $voided->outcome, $voided->amount->minorUnits(), $voided->providerObjectId ) );
		$this->assertEquals( $voided, $stub->void( $request ), 'The same void asked again is the same result.' );

		$late = $stub->void( new VoidRequest( self::INTENT, 'stub-pi-requires_action_completed-' . self::INTENT, self::amount(), Mode::Test, 'action_window_ended' ) );

		$this->assertSame( array( Operation::Authorize, Outcome::Approved, 'stub-ch-' . self::INTENT ), array( $late->operation, $late->outcome, $late->providerObjectId ), 'A shopper who finished before the void reached the stub: the void is answered with the authorization.' );

		$reading = $stub->readWebhook( new WebhookEnvelope( StubGateway::ID, Mode::Test, array(), '{"id":"evt_1"}', new \DateTimeImmutable( '2026-10-03 12:00:00', new \DateTimeZone( 'UTC' ) ) ) );

		$this->assertSame( array( WebhookReadingKind::Rejected, WebhookReading::BAD_SIGNATURE, null ), array( $reading->kind, $reading->reason, $reading->eventId ) );
	}

	/**
	 * Returns the amount every request is for.
	 *
	 * @since 0.1.0
	 *
	 * @return Money 3080 EUR.
	 */
	private static function amount(): Money {
		return Money::of( 3080, Currency::of( 'EUR' ) );
	}

	/**
	 * Builds an authorization request for the intent, with a token.
	 *
	 * @since 0.2.0
	 *
	 * @param string $token The token.
	 * @return PaymentRequest The request.
	 */
	private static function authorization( string $token ): PaymentRequest {
		return new PaymentRequest( self::INTENT, self::amount(), $token, Mode::Test, '01928c3e-0000-7000-8000-0000000000a1', '1001' );
	}

	/**
	 * Builds a status query.
	 *
	 * @since 0.1.0
	 *
	 * @param string|null $reference The reference the stub gave the intent, or null.
	 * @param bool        $waitEnded Optional. Whether the intent's wait has run out. Default false.
	 * @return PaymentQuery The query.
	 */
	private static function query( ?string $reference, bool $waitEnded = false ): PaymentQuery {
		return new PaymentQuery( self::INTENT, $reference, self::amount(), Mode::Test, new \DateTimeImmutable( '2026-10-01 12:15:00', new \DateTimeZone( 'UTC' ) ), $waitEnded );
	}
}
