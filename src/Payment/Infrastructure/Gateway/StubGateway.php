<?php
/**
 * StubGateway: a payment gateway that answers from a script, without the network
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Payment\Infrastructure\Gateway;

use SEOCart\Payment\Domain\Gateway\CaptureRequest;
use SEOCart\Payment\Domain\Gateway\GatewayResult;
use SEOCart\Payment\Domain\Gateway\GatewayUnavailable;
use SEOCart\Payment\Domain\Gateway\PaymentGateway;
use SEOCart\Payment\Domain\Gateway\PaymentQuery;
use SEOCart\Payment\Domain\Gateway\PaymentRequest;
use SEOCart\Payment\Domain\Gateway\Settlement;
use SEOCart\Payment\Domain\Operation;
use SEOCart\Payment\Domain\Outcome;
use SEOCart\Support\Currency;
use SEOCart\Support\Decimal;
use SEOCart\Support\Money;

defined( 'ABSPATH' ) || exit;

/**
 * The gateway the plugin ships with until a real one is installed: it answers as the payment token says.
 *
 * Owns one fact: the script each token stands for. It calls no WordPress function and sends no
 * request. The token names the outcome: `stub:approve`, `stub:approve_settled` (with a
 * settlement in another currency), `stub:decline`, `stub:requires_action`, `stub:pending`,
 * `stub:wrong_amount` and `stub:wrong_currency` (approvals that do not match their intent),
 * `stub:capture_wrong_amount` (an approval whose capture does not match), and `stub:throw` (the
 * gateway is unavailable). Any other token is declined.
 *
 * Like a provider, it remembers what it did with an intent by the reference it gives it, which
 * the plugin records on the intent: `stub-pi-{scenario}-{intent uuid}`. So a capture and a status
 * query, in any later request, answer from the scenario the intent was authorized with. Every
 * object it names is deterministic per intent and operation, so the same outcome delivered again
 * is the same result: `stub-ch-{uuid}` for an authorization's charge, `stub-cap-{uuid}` for a
 * capture.
 *
 * @since 0.1.0
 */
final class StubGateway implements PaymentGateway {

	/**
	 * The gateway's id.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const ID = 'stub';

	/**
	 * Approves the authorization.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const APPROVE = 'stub:approve';

	/**
	 * Approves the authorization and reports a settlement in another currency.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const APPROVE_SETTLED = 'stub:approve_settled';

	/**
	 * Declines the authorization.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const DECLINE = 'stub:decline';

	/**
	 * Asks the customer to act; a status query then finds the authorization approved.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const REQUIRES_ACTION = 'stub:requires_action';

	/**
	 * Keeps deciding; a status query finds it still pending.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const PENDING = 'stub:pending';

	/**
	 * Approves one minor unit more than was asked.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const WRONG_AMOUNT = 'stub:wrong_amount';

	/**
	 * Approves the amount in another currency.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const WRONG_CURRENCY = 'stub:wrong_currency';

	/**
	 * Approves the authorization; its capture then takes one minor unit more than was asked.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const CAPTURE_WRONG_AMOUNT = 'stub:capture_wrong_amount';

	/**
	 * Throws GatewayUnavailable, as a network failure would.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const THROW = 'stub:throw';

	/**
	 * The machine code of a decline.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const CARD_DECLINED = 'card_declined';

	/**
	 * The machine code of a decline for a token the script does not know.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const INVALID_TOKEN = 'invalid_payment_token';

	/**
	 * What every token the script knows begins with.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const TOKEN_PREFIX = 'stub:';

	/**
	 * What every intent reference the stub gives begins with.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const INTENT_PREFIX = 'stub-pi-';

	/**
	 * What an authorization's charge is named with, before the intent's uuid.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const CHARGE_PREFIX = 'stub-ch-';

	/**
	 * What a capture is named with, before the intent's uuid.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const CAPTURE_PREFIX = 'stub-cap-';

	/**
	 * The length of a uuid, which ends an intent reference.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private const UUID_LENGTH = 36;

	/**
	 * Returns the gateway's id.
	 *
	 * @since 0.1.0
	 *
	 * @return string `stub`.
	 */
	public function id(): string {
		return self::ID;
	}

	/**
	 * Tells whether the stub has a capability: it can ask the customer to act, and capture only in full.
	 *
	 * @since 0.1.0
	 *
	 * @param string $capability One of PaymentGateway's capability constants.
	 * @return bool True for PaymentGateway::SCA.
	 */
	public function supports( string $capability ): bool {
		return PaymentGateway::SCA === $capability;
	}

	/**
	 * Answers an authorization as its token says.
	 *
	 * @since 0.1.0
	 *
	 * @throws GatewayUnavailable For `stub:throw`.
	 *
	 * @param PaymentRequest $request The intent and the token.
	 * @return GatewayResult The answer.
	 */
	public function authorize( PaymentRequest $request ): GatewayResult {
		if ( self::THROW === $request->paymentToken ) {
			throw new GatewayUnavailable( 'The stub gateway was asked to be unavailable.' );
		}

		$known = in_array( $request->paymentToken, self::scripted(), true );

		if ( ! $known ) {
			return self::authorization( $request->intentUuid, Outcome::Declined, $request->amount, null, self::INVALID_TOKEN );
		}

		return self::firstAnswer( $request->intentUuid, $request->amount, self::scenario( $request->paymentToken ) );
	}

	/**
	 * Captures an intent in full, or one minor unit more when it was authorized with `stub:capture_wrong_amount`.
	 *
	 * @since 0.1.0
	 *
	 * @param CaptureRequest $request The intent and the amount.
	 * @return GatewayResult The approval.
	 */
	public function capture( CaptureRequest $request ): GatewayResult {
		$amount = self::scenario( self::CAPTURE_WRONG_AMOUNT ) === self::scenarioOf( $request->providerIntentId )
			? $request->amount->add( Money::of( 1, $request->amount->currency() ) )
			: $request->amount;

		return new GatewayResult( self::ID, Operation::Capture, Outcome::Approved, $request->intentUuid, $amount, self::CAPTURE_PREFIX . $request->intentUuid, $request->providerIntentId );
	}

	/**
	 * Says where an intent stands: the final answer of the scenario it was authorized with.
	 *
	 * An intent that waited for the customer is found approved, as if they had confirmed; one
	 * that is pending is still pending; any other scenario answers as it answered first. An
	 * intent the stub never gave a reference to is unknown to it.
	 *
	 * @since 0.1.0
	 *
	 * @param PaymentQuery $query The intent.
	 * @return GatewayResult|null The answer, or null while pending or unknown.
	 */
	public function query( PaymentQuery $query ): ?GatewayResult {
		$scenario = self::scenarioOf( $query->providerIntentId );

		return null === $scenario ? null : self::laterAnswer( $query->intentUuid, $query->amount, $scenario );
	}

	/**
	 * Returns the tokens the script knows, `stub:throw` aside.
	 *
	 * @since 0.1.0
	 *
	 * @return list<string> The tokens.
	 */
	public static function scripted(): array {
		return array( self::APPROVE, self::APPROVE_SETTLED, self::DECLINE, self::REQUIRES_ACTION, self::PENDING, self::WRONG_AMOUNT, self::WRONG_CURRENCY, self::CAPTURE_WRONG_AMOUNT );
	}

	/**
	 * Answers an authorization for a scenario, as the gateway first answers it.
	 *
	 * @since 0.1.0
	 *
	 * @param string $intentUuid The intent.
	 * @param Money  $amount     The amount asked.
	 * @param string $scenario   The token without its prefix.
	 * @return GatewayResult The answer.
	 */
	private static function firstAnswer( string $intentUuid, Money $amount, string $scenario ): GatewayResult {
		$reference = self::reference( $scenario, $intentUuid );
		$oneMore   = Money::of( 1, $amount->currency() );
		$other     = Currency::of( 'EUR' === $amount->currency()->code() ? 'USD' : 'EUR' );

		return match ( $scenario ) {
			self::scenario( self::DECLINE )         => self::authorization( $intentUuid, Outcome::Declined, $amount, $reference, self::CARD_DECLINED ),
			self::scenario( self::REQUIRES_ACTION ) => new GatewayResult( self::ID, Operation::Authorize, Outcome::RequiresAction, $intentUuid, $amount, null, $reference ),
			self::scenario( self::PENDING )         => new GatewayResult( self::ID, Operation::Authorize, Outcome::Pending, $intentUuid, $amount, null, $reference ),
			self::scenario( self::WRONG_AMOUNT )    => self::authorization( $intentUuid, Outcome::Approved, $amount->add( $oneMore ), $reference ),
			self::scenario( self::WRONG_CURRENCY )  => self::authorization( $intentUuid, Outcome::Approved, Money::of( $amount->minorUnits(), $other ), $reference ),
			self::scenario( self::APPROVE_SETTLED ) => self::authorization( $intentUuid, Outcome::Approved, $amount, $reference, null, new Settlement( Money::of( 1234, Currency::of( 'CHF' ) ), Decimal::of( '0.912345678901' ), Money::of( 56, Currency::of( 'CHF' ) ), self::ID ) ),
			default                                 => self::authorization( $intentUuid, Outcome::Approved, $amount, $reference ),
		};
	}

	/**
	 * Answers a status query for a scenario: the gateway's answer once the customer or the provider finished.
	 *
	 * The customer asked to act confirmed, so the authorization is approved; a pending one is
	 * still pending; every other scenario answers as it first did.
	 *
	 * @since 0.1.0
	 *
	 * @param string $intentUuid The intent.
	 * @param Money  $amount     The amount asked.
	 * @param string $scenario   The token without its prefix.
	 * @return GatewayResult|null The answer, or null while still pending.
	 */
	private static function laterAnswer( string $intentUuid, Money $amount, string $scenario ): ?GatewayResult {
		return match ( $scenario ) {
			self::scenario( self::REQUIRES_ACTION ) => self::authorization( $intentUuid, Outcome::Approved, $amount, self::reference( $scenario, $intentUuid ) ),
			self::scenario( self::PENDING )         => null,
			default                                 => self::firstAnswer( $intentUuid, $amount, $scenario ),
		};
	}

	/**
	 * Returns the reference the stub gives an intent: its scenario and its uuid, so a later call reads what it was authorized with.
	 *
	 * @since 0.1.0
	 *
	 * @param string $scenario   The token without its prefix.
	 * @param string $intentUuid The intent.
	 * @return string For example `stub-pi-approve-{uuid}`.
	 */
	private static function reference( string $scenario, string $intentUuid ): string {
		return self::INTENT_PREFIX . $scenario . '-' . $intentUuid;
	}

	/**
	 * Builds an authorization's answer, named by the intent's charge.
	 *
	 * @since 0.1.0
	 *
	 * @param string          $intentUuid The intent.
	 * @param Outcome         $outcome    Approved or declined.
	 * @param Money           $amount     The amount reported.
	 * @param string|null     $reference  The stub's reference to the intent.
	 * @param string|null     $errorCode  Optional. The decline's machine code. Default null.
	 * @param Settlement|null $settlement Optional. The settlement reported. Default null.
	 * @return GatewayResult The answer.
	 */
	private static function authorization( string $intentUuid, Outcome $outcome, Money $amount, ?string $reference, ?string $errorCode = null, ?Settlement $settlement = null ): GatewayResult {
		return new GatewayResult( self::ID, Operation::Authorize, $outcome, $intentUuid, $amount, self::CHARGE_PREFIX . $intentUuid, $reference, $errorCode, $settlement );
	}

	/**
	 * Returns a token's scenario: the token without its prefix.
	 *
	 * @since 0.1.0
	 *
	 * @param string $token A token of the script.
	 * @return string The scenario, for example `approve`.
	 */
	private static function scenario( string $token ): string {
		return substr( $token, strlen( self::TOKEN_PREFIX ) );
	}

	/**
	 * Reads the scenario an intent was authorized with from the reference the stub gave it.
	 *
	 * @since 0.1.0
	 *
	 * @param string|null $reference The intent's provider reference.
	 * @return string|null The scenario, or null when the reference is not the stub's.
	 */
	private static function scenarioOf( ?string $reference ): ?string {
		if ( null === $reference || ! str_starts_with( $reference, self::INTENT_PREFIX ) || strlen( $reference ) <= strlen( self::INTENT_PREFIX ) + self::UUID_LENGTH + 1 ) {
			return null;
		}

		$scenario = substr( $reference, strlen( self::INTENT_PREFIX ), -( self::UUID_LENGTH + 1 ) );

		return in_array( self::TOKEN_PREFIX . $scenario, self::scripted(), true ) ? $scenario : null;
	}
}
