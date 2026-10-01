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
use SEOCart\Payment\Domain\Gateway\CaptureRequest;
use SEOCart\Payment\Domain\Gateway\GatewayRefund;
use SEOCart\Payment\Domain\Gateway\GatewayUnavailable;
use SEOCart\Payment\Domain\Gateway\PaymentGateway;
use SEOCart\Payment\Domain\Gateway\PaymentQuery;
use SEOCart\Payment\Domain\Gateway\PaymentRequest;
use SEOCart\Payment\Domain\Operation;
use SEOCart\Payment\Domain\Outcome;
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
			'approve'              => array( StubGateway::APPROVE, array( Outcome::Approved, 3080, 'EUR', $charge, 'stub-pi-approve-' . self::INTENT, null ) ),
			'approve, settled'     => array( StubGateway::APPROVE_SETTLED, array( Outcome::Approved, 3080, 'EUR', $charge, 'stub-pi-approve_settled-' . self::INTENT, null ) ),
			'decline'              => array( StubGateway::DECLINE, array( Outcome::Declined, 3080, 'EUR', $charge, 'stub-pi-decline-' . self::INTENT, 'card_declined' ) ),
			'requires action'      => array( StubGateway::REQUIRES_ACTION, array( Outcome::RequiresAction, 3080, 'EUR', null, 'stub-pi-requires_action-' . self::INTENT, null ) ),
			'pending'              => array( StubGateway::PENDING, array( Outcome::Pending, 3080, 'EUR', null, 'stub-pi-pending-' . self::INTENT, null ) ),
			'wrong amount'         => array( StubGateway::WRONG_AMOUNT, array( Outcome::Approved, 3081, 'EUR', $charge, 'stub-pi-wrong_amount-' . self::INTENT, null ) ),
			'wrong currency'       => array( StubGateway::WRONG_CURRENCY, array( Outcome::Approved, 3080, 'USD', $charge, 'stub-pi-wrong_currency-' . self::INTENT, null ) ),
			'capture wrong amount' => array( StubGateway::CAPTURE_WRONG_AMOUNT, array( Outcome::Approved, 3080, 'EUR', $charge, 'stub-pi-capture_wrong_amount-' . self::INTENT, null ) ),
			'refund decline'       => array( StubGateway::REFUND_DECLINE, array( Outcome::Approved, 3080, 'EUR', $charge, 'stub-pi-refund_decline-' . self::INTENT, null ) ),
			'a token not scripted' => array( 'tok_visa', array( Outcome::Declined, 3080, 'EUR', $charge, null, StubGateway::INVALID_TOKEN ) ),
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
		$result = ( new StubGateway() )->authorize( new PaymentRequest( self::INTENT, self::amount(), $token ) );

		$this->assertSame( array( StubGateway::ID, Operation::Authorize, self::INTENT ), array( $result->provider, $result->operation, $result->intentUuid ) );
		$this->assertSame( $expected, array( $result->outcome, $result->amount->minorUnits(), $result->amount->currency()->code(), $result->providerObjectId, $result->providerIntentId, $result->errorCode ) );
		$this->assertSame( StubGateway::APPROVE_SETTLED === $token, null !== $result->settlement, 'Only the settled approval reports a settlement.' );
	}

	/**
	 * Tests the later answers: the customer confirmed, the provider still decides, and the rest answer as they first did; an unknown intent is unknown.
	 *
	 * @since 0.1.0
	 */
	public function test_a_query_answers_what_the_intent_came_to(): void {
		$stub = new StubGateway();

		$confirmed = $stub->query( self::query( 'stub-pi-requires_action-' . self::INTENT ) );

		$this->assertNotNull( $confirmed );
		$this->assertSame( array( Outcome::Approved, 'stub-ch-' . self::INTENT ), array( $confirmed->outcome, $confirmed->providerObjectId ) );
		$this->assertNull( $stub->query( self::query( 'stub-pi-pending-' . self::INTENT ) ), 'Still pending.' );
		$this->assertEquals( $stub->authorize( new PaymentRequest( self::INTENT, self::amount(), StubGateway::DECLINE ) ), $stub->query( self::query( 'stub-pi-decline-' . self::INTENT ) ) );
		$this->assertNull( $stub->query( self::query( null ) ), 'An intent the stub gave no reference to.' );
		$this->assertNull( $stub->query( self::query( 'pi_3OtherProvider' ) ), 'Another provider\'s reference.' );
		$this->assertNull( $stub->query( self::query( 'stub-pi-throw-' . self::INTENT ) ), 'A scenario the script does not know.' );
	}

	/**
	 * Tests that a capture takes the amount asked, or one minor unit more for an intent authorized to capture wrongly, under its own object.
	 *
	 * @since 0.1.0
	 */
	public function test_a_capture_takes_the_amount_asked(): void {
		$stub  = new StubGateway();
		$right = $stub->capture( new CaptureRequest( self::INTENT, 'stub-pi-approve-' . self::INTENT, self::amount() ) );
		$wrong = $stub->capture( new CaptureRequest( self::INTENT, 'stub-pi-capture_wrong_amount-' . self::INTENT, self::amount() ) );

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
		$approved = $stub->refund( new GatewayRefund( self::INTENT, 'stub-pi-approve-' . self::INTENT, Money::of( 1234, Currency::of( 'EUR' ) ), $key ) );
		$declined = $stub->refund( new GatewayRefund( self::INTENT, 'stub-pi-refund_decline-' . self::INTENT, Money::of( 1234, Currency::of( 'EUR' ) ), $key ) );

		$this->assertSame( array( Operation::Refund, Outcome::Approved, 1234, 'EUR', 'stub-re-' . $key, null ), array( $approved->operation, $approved->outcome, $approved->amount->minorUnits(), $approved->amount->currency()->code(), $approved->providerObjectId, $approved->errorCode ) );
		$this->assertSame( array( Outcome::Declined, StubGateway::REFUND_DECLINED, 'stub-re-' . $key ), array( $declined->outcome, $declined->errorCode, $declined->providerObjectId ) );
		$this->assertEquals( $approved, $stub->refund( new GatewayRefund( self::INTENT, 'stub-pi-approve-' . self::INTENT, Money::of( 1234, Currency::of( 'EUR' ) ), $key ) ), 'The same refund asked again is the same result.' );
		$this->assertSame( Outcome::Approved, $stub->authorize( new PaymentRequest( self::INTENT, self::amount(), StubGateway::REFUND_DECLINE ) )->outcome, 'An intent whose refunds are declined is authorized.' );
	}

	/**
	 * Tests that the stub can be told to be unavailable, and declares only the confirmation capability.
	 *
	 * @since 0.1.0
	 */
	public function test_it_can_be_unavailable_and_supports_only_confirmation(): void {
		$stub = new StubGateway();

		$this->assertTrue( $stub->supports( PaymentGateway::SCA ) );
		$this->assertFalse( $stub->supports( PaymentGateway::PARTIAL_CAPTURE ) );

		$this->expectException( GatewayUnavailable::class );

		$stub->authorize( new PaymentRequest( self::INTENT, self::amount(), StubGateway::THROW ) );
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
	 * Builds a status query.
	 *
	 * @since 0.1.0
	 *
	 * @param string|null $reference The reference the stub gave the intent, or null.
	 * @return PaymentQuery The query.
	 */
	private static function query( ?string $reference ): PaymentQuery {
		return new PaymentQuery( self::INTENT, $reference, self::amount() );
	}
}
