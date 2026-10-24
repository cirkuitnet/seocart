<?php
/**
 * Tests that the canonical gateway offers nothing beyond the gateway contract, and passes on an answer only as an operation the call allows
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Payment;

use PHPUnit\Framework\TestCase;
use SEOCart\Contracts\Payment\CaptureRequest;
use SEOCart\Contracts\Payment\GatewayRefund;
use SEOCart\Contracts\Payment\GatewayResult;
use SEOCart\Contracts\Payment\GatewayUnavailable;
use SEOCart\Contracts\Payment\Mode;
use SEOCart\Contracts\Payment\Operation;
use SEOCart\Contracts\Payment\Operations;
use SEOCart\Contracts\Payment\PaymentGateway;
use SEOCart\Contracts\Payment\PaymentQuery;
use SEOCart\Contracts\Payment\PaymentRequest;
use SEOCart\Contracts\Payment\VoidRequest;
use SEOCart\Payment\Application\CanonicalGateway;
use SEOCart\Payment\Infrastructure\Gateway\StubGateway;
use SEOCart\Support\Currency;
use SEOCart\Support\Money;
use SEOCart\Tests\Support\Doubles\MisaddressingGateway;
use SEOCart\Tests\Support\Doubles\RememberingGateway;

/**
 * The wrapper the registry serves every gateway in is the gateway contract and nothing more: no way to the gateway behind it, so no caller, an extension included, can hand a gateway's raw answer to the money path.
 *
 * It also passes on an answer only as an operation the call allows: the call's own, or for a void
 * the authorization a provider says still stands; a status query's answer names whatever operation
 * the provider's state maps to. Any other is refused as the gateway's error, as a call it did not
 * answer is. Each call is asked of the stand-in, whose answers name the operation the test sets.
 *
 * Planted violations, each shown red and removed:
 * - give CanonicalGateway a public `wrapped(): PaymentGateway` returning its gateway: the surface
 *   then holds a method the contract does not;
 * - in CanonicalGateway::answerTo(), drop the comparison of the answer's operation with the
 *   operations the call allows: every refused pair is then passed on.
 *
 * @since 0.2.0
 */
final class CanonicalGatewayTest extends TestCase {

	/**
	 * The intent every call is about.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const INTENT = '01928c3e-0000-7000-8000-000000000015';

	/**
	 * The calls of the contract that answer with a gateway result, by their names in Operations::ALL.
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	private const CALLS = array( Operations::AUTHORIZE, Operations::CAPTURE, Operations::VOID, Operations::REFUND, Operations::QUERY_REFUND, Operations::QUERY );

	/**
	 * Tests that the wrapper's public methods are the contract's, and its constructor.
	 *
	 * @since 0.2.0
	 */
	public function test_the_wrapper_offers_the_contract_alone(): void {
		$public = static fn( string $type ): array => array_map( static fn( \ReflectionMethod $method ): string => $method->getName(), ( new \ReflectionClass( $type ) )->getMethods( \ReflectionMethod::IS_PUBLIC ) );
		$offers = $public( CanonicalGateway::class );

		sort( $offers );

		$contract = array_merge( $public( PaymentGateway::class ), array( '__construct' ) );

		sort( $contract );

		$this->assertSame( $contract, $offers );
	}

	/**
	 * Tests that an answer naming an operation the call does not allow is refused as the gateway's error, the message naming the gateway and the call and nothing of the answer.
	 *
	 * @dataProvider refusedAnswers
	 *
	 * @since 0.2.0
	 *
	 * @param string    $call     The call: a name of Operations::ALL.
	 * @param Operation $answered The operation the answer names.
	 */
	public function test_an_answer_of_an_operation_the_call_does_not_allow_is_refused( string $call, Operation $answered ): void {
		$refused = null;

		try {
			self::ask( $call, $answered );
		} catch ( GatewayUnavailable $unanswered ) {
			$refused = $unanswered;
		}

		$this->assertInstanceOf( GatewayUnavailable::class, $refused, "A call to {$call} answered as {$answered->value} was passed on." );
		$this->assertSame( 'The gateway ' . StubGateway::ID . " answered a call to {$call} with the result of another operation: nothing of the answer was applied.", $refused->getMessage() );
	}

	/**
	 * Tests that an answer naming an operation the call allows is passed on as the operation it names.
	 *
	 * @dataProvider acceptedAnswers
	 *
	 * @since 0.2.0
	 *
	 * @param string    $call     The call: a name of Operations::ALL.
	 * @param Operation $answered The operation the answer names.
	 */
	public function test_an_answer_of_an_operation_the_call_allows_is_passed_on( string $call, Operation $answered ): void {
		$answer = self::ask( $call, $answered );

		$this->assertSame( array( StubGateway::ID, self::INTENT, $answered ), array( $answer->provider, $answer->intentUuid, $answer->operation ) );
	}

	/**
	 * Tests that the refused and the accepted answers are, together, every operation an answer can name for every call, each once.
	 *
	 * @since 0.2.0
	 */
	public function test_the_cases_are_every_call_answered_as_every_operation(): void {
		$cases = array_map( static fn( array $pair ): string => $pair[0] . ' as ' . $pair[1]->value, array_values( array_merge( self::refusedAnswers(), self::acceptedAnswers() ) ) );
		$every = array();

		foreach ( self::CALLS as $call ) {
			foreach ( Operation::cases() as $operation ) {
				$every[] = $call . ' as ' . $operation->value;
			}
		}

		sort( $cases );
		sort( $every );

		$this->assertSame( $every, $cases );
	}

	/**
	 * Returns the answers no call allows: each call's answer named as another operation, a void's as neither a void nor the authorization.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, array{0: string, 1: Operation}> The call and the operation its answer names.
	 */
	public static function refusedAnswers(): array {
		return array(
			'an authorization answered as a capture' => array( Operations::AUTHORIZE, Operation::Capture ),
			'an authorization answered as a void'    => array( Operations::AUTHORIZE, Operation::Void ),
			'an authorization answered as a refund'  => array( Operations::AUTHORIZE, Operation::Refund ),
			'a capture answered as an authorization' => array( Operations::CAPTURE, Operation::Authorize ),
			'a capture answered as a void'           => array( Operations::CAPTURE, Operation::Void ),
			'a capture answered as a refund'         => array( Operations::CAPTURE, Operation::Refund ),
			'a void answered as a capture'           => array( Operations::VOID, Operation::Capture ),
			'a void answered as a refund'            => array( Operations::VOID, Operation::Refund ),
			'a refund answered as an authorization'  => array( Operations::REFUND, Operation::Authorize ),
			'a refund answered as a capture'         => array( Operations::REFUND, Operation::Capture ),
			'a refund answered as a void'            => array( Operations::REFUND, Operation::Void ),
			"a refund's query answered as an authorization" => array( Operations::QUERY_REFUND, Operation::Authorize ),
			"a refund's query answered as a capture" => array( Operations::QUERY_REFUND, Operation::Capture ),
			"a refund's query answered as a void"    => array( Operations::QUERY_REFUND, Operation::Void ),
		);
	}

	/**
	 * Returns the answers the calls allow: each call's own operation, the authorization that still stands for a void, and any operation for a status query.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, array{0: string, 1: Operation}> The call and the operation its answer names.
	 */
	public static function acceptedAnswers(): array {
		return array(
			'an authorization answered as one'            => array( Operations::AUTHORIZE, Operation::Authorize ),
			'a capture answered as one'                   => array( Operations::CAPTURE, Operation::Capture ),
			'a void answered as one'                      => array( Operations::VOID, Operation::Void ),
			'a void answered as the authorization that still stands' => array( Operations::VOID, Operation::Authorize ),
			'a refund answered as one'                    => array( Operations::REFUND, Operation::Refund ),
			"a refund's query answered as a refund"       => array( Operations::QUERY_REFUND, Operation::Refund ),
			'a status query answered as an authorization' => array( Operations::QUERY, Operation::Authorize ),
			'a status query answered as a capture'        => array( Operations::QUERY, Operation::Capture ),
			'a status query answered as a void'           => array( Operations::QUERY, Operation::Void ),
			'a status query answered as a refund'         => array( Operations::QUERY, Operation::Refund ),
		);
	}

	/**
	 * Asks a call of the stand-in through the wrapper, every answer naming the operation given.
	 *
	 * The stand-in is the one that remembers its refunds, so a refund's query has an answer.
	 *
	 * @since 0.2.0
	 *
	 * @throws GatewayUnavailable When the wrapper refuses the answer.
	 *
	 * @param string    $call     The call: one of CALLS.
	 * @param Operation $answered The operation the answer names.
	 * @return GatewayResult The answer the wrapper passed on.
	 */
	private static function ask( string $call, Operation $answered ): GatewayResult {
		$stub      = new MisaddressingGateway( new RememberingGateway( new StubGateway() ) );
		$gateway   = new CanonicalGateway( $stub );
		$reference = 'stub-pi-approve-' . self::INTENT;
		$amount    = Money::of( 3080, Currency::of( 'EUR' ) );
		$refund    = new GatewayRefund( self::INTENT, $reference, $amount, '01928c3e-0000-7000-8000-0000000000f1', Mode::Test );

		$stub->answerWith( $answered );

		$answer = match ( $call ) {
			Operations::AUTHORIZE    => $gateway->authorize( new PaymentRequest( self::INTENT, $amount, StubGateway::APPROVE, Mode::Test, '01928c3e-0000-7000-8000-0000000000a1', '1001' ) ),
			Operations::CAPTURE      => $gateway->capture( new CaptureRequest( self::INTENT, $reference, $amount, Mode::Test ) ),
			Operations::VOID         => $gateway->void( new VoidRequest( self::INTENT, $reference, $amount, Mode::Test, 'requested' ) ),
			Operations::REFUND       => $gateway->refund( $refund ),
			Operations::QUERY_REFUND => $gateway->queryRefund( $refund ),
			Operations::QUERY        => $gateway->query( new PaymentQuery( self::INTENT, $reference, $amount, Mode::Test ) ),
			default                  => self::fail( "{$call} is not a call that answers with a gateway result." ),
		};

		if ( null === $answer ) {
			self::fail( "The stand-in gave no answer to a call to {$call}." );
		}

		return $answer;
	}
}
