<?php
/**
 * ReferenceGateway: a correct payment gateway of a fictional provider, the worked example the conformance suite is proved on
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Conformance\Payment\Reference;

use SEOCart\Contracts\ExtensionContext;
use SEOCart\Contracts\OutboundHost;
use SEOCart\Contracts\OutboundRequest;
use SEOCart\Contracts\Payment\AvailabilityContext;
use SEOCart\Contracts\Payment\CapabilityMatrix;
use SEOCart\Contracts\Payment\CaptureRequest;
use SEOCart\Contracts\Payment\GatewayDescriptor;
use SEOCart\Contracts\Payment\GatewayRefund;
use SEOCart\Contracts\Payment\GatewayResult;
use SEOCart\Contracts\Payment\GatewayUnavailable;
use SEOCart\Contracts\Payment\IdempotencyProfile;
use SEOCart\Contracts\Payment\MatrixRow;
use SEOCart\Contracts\Payment\Mode;
use SEOCart\Contracts\Payment\NextAction;
use SEOCart\Contracts\Payment\Operation;
use SEOCart\Contracts\Payment\Operations;
use SEOCart\Contracts\Payment\Outcome;
use SEOCart\Contracts\Payment\PaymentGateway;
use SEOCart\Contracts\Payment\PaymentQuery;
use SEOCart\Contracts\Payment\PaymentRequest;
use SEOCart\Contracts\Payment\VoidRequest;
use SEOCart\Contracts\Payment\WebhookEnvelope;
use SEOCart\Contracts\Payment\WebhookReading;
use SEOCart\Contracts\TransportFailure;
use SEOCart\Support\Currency;
use SEOCart\Support\Money;
use SEOCart\Support\Schema\FieldSpec;
use SEOCart\Support\Schema\FieldType;
use SEOCart\Support\Schema\Privacy;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- These exceptions report a provider's failure to the plugin; they are never HTML.

/**
 * A payment gateway that does everything the contract asks, of a fictional provider (ReferenceProvider), in the way a real adapter does it.
 *
 * Owns one fact: what a correct adapter looks like to the conformance suite. It sends every call
 * through the client its context gives it, with the credential of the request's mode, the key the
 * request derived and the store's uuid where the provider can be searched by it; maps the
 * provider's answers, including "already captured" and "already succeeded", to results; asks by id
 * and searches by uuid; and verifies a delivery's signature over its raw body before it reads it.
 * The suite's planted gateways extend it, each breaking one rule through one of its protected
 * methods; it is also the example the guide for gateway plugins points to.
 *
 * @since 0.2.0
 */
class ReferenceGateway implements PaymentGateway {

	/**
	 * The gateway's id.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const ID = 'reference';

	/**
	 * The id of the provider's declared host.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const HOST = 'reference-api';

	/**
	 * Where the provider's API is.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const ENDPOINT = 'https://' . ReferenceProvider::HOST;

	/**
	 * The header that carries a delivery's signature.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const SIGNATURE_HEADER = 'reference-signature';

	/**
	 * How far a delivery's signed time may be from now, in seconds.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	public const TOLERANCE_SECONDS = 300;

	/**
	 * The smallest payment the provider takes, in minor units: the gateway's own narrowing of the matrix.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	public const MINIMUM_MINOR = 50;

	/**
	 * The secret setting that holds the API key.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const SECRET_KEY = 'secret_key';

	/**
	 * What the plugin gives this gateway.
	 *
	 * @since 0.2.0
	 *
	 * @var ExtensionContext
	 */
	protected ExtensionContext $context;

	/**
	 * Builds the gateway with its context.
	 *
	 * @since 0.2.0
	 *
	 * @param ExtensionContext $context What the plugin gives it.
	 */
	public function __construct( ExtensionContext $context ) {
		$this->context = $context;
	}

	/**
	 * Returns the gateway's one declaration.
	 *
	 * @since 0.2.0
	 *
	 * @return GatewayDescriptor The descriptor, built without I/O.
	 */
	public function describe(): GatewayDescriptor {
		$operations = array_values( array_diff( Operations::ALL, array( Operations::MULTI_CAPTURE, Operations::OFF_SESSION ) ) );

		return new GatewayDescriptor(
			self::ID,
			static fn(): string => __( 'Reference gateway', 'seocart' ),
			GatewayDescriptor::TYPE_PAYMENTS,
			PaymentGateway::CONTRACT_VERSION,
			array( Mode::Test, Mode::Live ),
			array(
				self::secretSetting( self::SECRET_KEY, 'The API key of the provider account.', 'API key' ),
				self::secretSetting( GatewayDescriptor::WEBHOOK_SECRET, 'The secret the provider signs its webhook deliveries with.', 'Webhook signing secret' ),
				new FieldSpec( name: GatewayDescriptor::ACCOUNT_COUNTRY, type: FieldType::String, description: 'The country of the provider account.', label: static fn(): string => 'Account country', example: 'US', max_length: 2 ),
			),
			new CapabilityMatrix( array_map( static fn( string $code ): MatrixRow => new MatrixRow( Currency::of( $code ), MatrixRow::ANY_COUNTRY, $operations ), array( 'EUR', 'GBP', 'USD' ) ) ),
			$this->idempotency(),
			array(
				new OutboundHost( self::HOST, 'Reference payments', 'Authorizes, captures, voids and refunds the store\'s payments.', self::ENDPOINT, 'The payment\'s amount and currency, the order\'s number and the payment\'s identifier.', 'When an order is placed, and when its payment is captured, voided or refunded.', 'https://reference.example/terms', 'https://reference.example/privacy' ),
			)
		);
	}

	/**
	 * Tells whether the provider takes a payment: not below its smallest amount.
	 *
	 * @since 0.2.0
	 *
	 * @param AvailabilityContext $context The payment.
	 * @return bool True when the amount is at least MINIMUM_MINOR.
	 */
	public function isAvailable( AvailabilityContext $context ): bool {
		return $context->amount->minorUnits() >= self::MINIMUM_MINOR;
	}

	/**
	 * Creates and confirms the provider's intent for the payment.
	 *
	 * @since 0.2.0
	 *
	 * @param PaymentRequest $request The payment.
	 * @return GatewayResult The provider's answer.
	 */
	public function authorize( PaymentRequest $request ): GatewayResult {
		$fields = array(
			'amount'       => $request->amount->minorUnits(),
			'currency'     => strtolower( $request->amount->currency()->code() ),
			'token'        => (string) $request->paymentToken,
			'intent_uuid'  => $request->intentUuid,
			'order_number' => $request->orderNumber,
			'capture'      => 'manual',
		);

		if ( null !== $request->returnUrl ) {
			$fields['return_url'] = $request->returnUrl;
		}

		$answer = $this->send( $request->mode, 'POST', '/v1/intents', $fields, $request->idempotencyKey() );

		return $this->authorizationOf( $request->intentUuid, self::intentIn( $answer ) );
	}

	/**
	 * Captures the intent; one already captured answers that capture.
	 *
	 * @since 0.2.0
	 *
	 * @param CaptureRequest $request The capture.
	 * @return GatewayResult The provider's answer.
	 */
	public function capture( CaptureRequest $request ): GatewayResult {
		$answer = $this->send( $request->mode, 'POST', '/v1/intents/' . $this->providerIntent( $request->providerIntentId ) . '/capture', array( 'amount' => $request->amount->minorUnits() ), $request->idempotencyKey() );
		$intent = self::intentIn( $answer );

		if ( 'captured' !== $intent['status'] ) {
			return new GatewayResult( self::ID, Operation::Capture, Outcome::Declined, $request->intentUuid, $request->amount, null, (string) $intent['id'], self::errorCodeOf( $answer ) );
		}

		return new GatewayResult( self::ID, Operation::Capture, Outcome::Approved, $request->intentUuid, self::money( (int) $intent['amount_captured'], (string) $intent['currency'] ), (string) $intent['capture'], (string) $intent['id'] );
	}

	/**
	 * Cancels the intent; one whose customer already acted answers its authorization's approval.
	 *
	 * @since 0.2.0
	 *
	 * @param VoidRequest $request The void.
	 * @return GatewayResult The provider's answer.
	 */
	public function void( VoidRequest $request ): GatewayResult {
		$answer = $this->send( $request->mode, 'POST', '/v1/intents/' . $this->providerIntent( $request->providerIntentId ) . '/cancel', array( 'reason' => $request->reason ), $request->idempotencyKey() );
		$intent = self::intentIn( $answer );

		if ( 'already_succeeded' === self::errorCodeOf( $answer ) ) {
			return $this->authorizationOf( $request->intentUuid, $intent );
		}

		if ( 'canceled' !== $intent['status'] ) {
			return new GatewayResult( self::ID, Operation::Void, Outcome::Declined, $request->intentUuid, $request->amount, null, (string) $intent['id'], self::errorCodeOf( $answer ) );
		}

		return new GatewayResult( self::ID, Operation::Void, Outcome::Approved, $request->intentUuid, self::money( (int) $intent['amount'], (string) $intent['currency'] ), (string) $intent['id'], (string) $intent['id'] );
	}

	/**
	 * Refunds part or all of the capture, by the refund's uuid as key and in a searchable field.
	 *
	 * @since 0.2.0
	 *
	 * @param GatewayRefund $request The refund.
	 * @return GatewayResult The provider's answer.
	 */
	public function refund( GatewayRefund $request ): GatewayResult {
		$amount = $this->amountToRefund( $request );
		$answer = $this->send(
			$request->mode,
			'POST',
			'/v1/refunds',
			array(
				'intent'      => $this->providerIntent( $request->providerIntentId ),
				'amount'      => $amount->minorUnits(),
				'refund_uuid' => $request->refundUuid,
			),
			$request->idempotencyKey()
		);

		if ( 200 !== $answer['status'] ) {
			return new GatewayResult( self::ID, Operation::Refund, Outcome::Declined, $request->intentUuid, $request->amount, null, $request->providerIntentId, self::errorCodeOf( $answer ) );
		}

		return $this->refundOf( $request->intentUuid, $answer['body'] );
	}

	/**
	 * Finds a refund by its uuid in the list of the intent's refunds: a list the provider answers at once, so a refund absent from it was not made.
	 *
	 * @since 0.2.0
	 *
	 * @param GatewayRefund $request The refund as it was asked.
	 * @return GatewayResult|null The refund; a declined refund `not_found` when the list has none of that uuid; null when no provider intent is known.
	 */
	public function queryRefund( GatewayRefund $request ): ?GatewayResult {
		if ( null === $request->providerIntentId ) {
			return null;
		}

		$answer = $this->send( $request->mode, 'GET', '/v1/refunds?' . http_build_query( array( 'intent' => $request->providerIntentId ) ), array(), null );

		foreach ( (array) ( $answer['body']['data'] ?? array() ) as $refund ) {
			if ( is_array( $refund ) && ( $refund['refund_uuid'] ?? null ) === $request->refundUuid ) {
				return $this->refundOf( $request->intentUuid, $refund );
			}
		}

		return new GatewayResult( self::ID, Operation::Refund, Outcome::Declined, $request->intentUuid, $request->amount, null, $request->providerIntentId, PaymentGateway::NOT_FOUND );
	}

	/**
	 * Asks where an intent stands: by the provider's id when known, else by a search for the store's uuid.
	 *
	 * @since 0.2.0
	 *
	 * @param PaymentQuery $query The intent.
	 * @return GatewayResult|null The authorization, a decline (`expired` for a cancelled intent, `not_found` when the search finds none), or null while it waits.
	 */
	public function query( PaymentQuery $query ): ?GatewayResult {
		if ( null !== $query->providerIntentId ) {
			return $this->answerAbout( $query, self::intentIn( $this->send( $query->mode, 'GET', '/v1/intents/' . $query->providerIntentId, array(), null ) ) );
		}

		$found = (array) ( $this->send( $query->mode, 'GET', '/v1/intents?' . http_build_query( array( 'intent_uuid' => $query->intentUuid ) ), array(), null )['body']['data'] ?? array() );

		if ( array() === $found || ! is_array( $found[0] ?? null ) ) {
			return new GatewayResult( self::ID, Operation::Authorize, Outcome::Declined, $query->intentUuid, $query->amount, null, null, PaymentGateway::NOT_FOUND );
		}

		return $this->answerAbout( $query, $found[0] );
	}

	/**
	 * Reads a delivery: its signature over the raw body and its signed time first, then its content.
	 *
	 * @since 0.2.0
	 *
	 * @param WebhookEnvelope $envelope The delivery.
	 * @return WebhookReading What it says, or why it was rejected.
	 */
	public function readWebhook( WebhookEnvelope $envelope ): WebhookReading {
		$signature = self::signatureIn( $envelope->headers );

		if ( null === $signature ) {
			return WebhookReading::rejected( WebhookReading::BAD_SIGNATURE );
		}

		$expected = self::hmac( $signature['t'], $envelope->rawBody, $this->context->settings( $envelope->mode )->secret( GatewayDescriptor::WEBHOOK_SECRET ) );

		if ( ! hash_equals( $expected, $signature['v1'] ) ) {
			return WebhookReading::rejected( WebhookReading::BAD_SIGNATURE );
		}

		if ( abs( $this->context->clock()->now()->getTimestamp() - $signature['t'] ) > self::TOLERANCE_SECONDS ) {
			return WebhookReading::rejected( WebhookReading::STALE );
		}

		return $this->readEvent( $this->decoded( $envelope->rawBody ) );
	}

	/**
	 * Signs a delivery as the provider does: `t=<unix time>,v1=<HMAC-SHA256 of "t.body">`.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, string> $headers The delivery's other headers.
	 * @param string                $body    The raw body.
	 * @param string                $secret  The signing secret.
	 * @param \DateTimeImmutable    $at      When it is signed.
	 * @return array<string, string> The headers, with the signature.
	 */
	public static function signed( array $headers, string $body, #[\SensitiveParameter] string $secret, \DateTimeImmutable $at ): array {
		$headers[ self::SIGNATURE_HEADER ] = sprintf( 't=%1$d,v1=%2$s', $at->getTimestamp(), self::hmac( $at->getTimestamp(), $body, $secret ) );

		return $headers;
	}

	/**
	 * Sends one request through the context's client and decodes its answer.
	 *
	 * @since 0.2.0
	 *
	 * @throws GatewayUnavailable When the provider could not be reached or did not answer in JSON.
	 *
	 * @param Mode                 $mode   The mode of the payment the request is about.
	 * @param string               $method The method.
	 * @param string               $path   The path, with its query.
	 * @param array<string, mixed> $fields The form fields of a POST.
	 * @param string|null          $key    The idempotency key of a money call.
	 * @return array{status: int, body: array<mixed>} The answer.
	 */
	protected function send( Mode $mode, string $method, string $path, array $fields, ?string $key ): array {
		$headers = array( 'Authorization' => 'Bearer ' . $this->credential( $mode ) );

		if ( null !== $key ) {
			$headers['Idempotency-Key'] = $this->keyFor( $key );
		}

		if ( 'POST' === $method ) {
			$headers['Content-Type'] = 'application/x-www-form-urlencoded';
		}

		try {
			$response = $this->context->http()->send( new OutboundRequest( self::HOST, $method, $this->url( $path, $mode ), $headers, 'POST' === $method ? http_build_query( $fields ) : null ) );
		} catch ( TransportFailure $failure ) {
			throw new GatewayUnavailable( 'The reference provider could not be reached.', 0, $failure );
		}

		$answer = array(
			'status' => $response->status,
			'body'   => $this->decoded( $response->body ),
		);

		$this->answered( $answer );

		return $answer;
	}

	/**
	 * Returns the URL of a path.
	 *
	 * @since 0.2.0
	 *
	 * @param string $path The path, with its query.
	 * @param Mode   $mode The mode of the payment.
	 * @return string The URL.
	 */
	protected function url( string $path, Mode $mode ): string {
		unset( $mode );

		return self::ENDPOINT . $path;
	}

	/**
	 * Returns the API key of a mode, read when it is needed.
	 *
	 * @since 0.2.0
	 *
	 * @param Mode $mode The mode of the payment.
	 * @return string The key.
	 */
	protected function credential( Mode $mode ): string {
		return $this->context->settings( $mode )->secret( self::SECRET_KEY );
	}

	/**
	 * Returns how the provider keeps idempotency keys: for a day, and searchable by a payment's uuid a minute on.
	 *
	 * @since 0.2.0
	 *
	 * @return IdempotencyProfile The profile.
	 */
	protected function idempotency(): IdempotencyProfile {
		return new IdempotencyProfile( 86400, true, 60 );
	}

	/**
	 * Returns the idempotency key to send: the one the request derived.
	 *
	 * @since 0.2.0
	 *
	 * @param string $key The derived key.
	 * @return string The key sent.
	 */
	protected function keyFor( string $key ): string {
		return $key;
	}

	/**
	 * Returns the amount a refund asks the provider for: the request's.
	 *
	 * @since 0.2.0
	 *
	 * @param GatewayRefund $request The refund.
	 * @return Money The amount.
	 */
	protected function amountToRefund( GatewayRefund $request ): Money {
		return $request->amount;
	}

	/**
	 * Called with every decoded answer; a correct adapter keeps none of it.
	 *
	 * @since 0.2.0
	 *
	 * @param array{status: int, body: array<mixed>} $answer The answer.
	 */
	protected function answered( array $answer ): void {
		unset( $answer );
	}

	/**
	 * Decodes a JSON body; an answer that is not a JSON object decodes to nothing.
	 *
	 * @since 0.2.0
	 *
	 * @param string $body The body.
	 * @return array<mixed> The object; empty when the body is not one.
	 */
	protected function decoded( string $body ): array {
		$decoded = json_decode( $body, true );

		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Maps an intent the provider answered to the answer about an authorization.
	 *
	 * @since 0.2.0
	 *
	 * @param string       $intentUuid The store's uuid.
	 * @param array<mixed> $intent     The provider's intent.
	 * @return GatewayResult The authorization's answer.
	 */
	protected function authorizationOf( string $intentUuid, array $intent ): GatewayResult {
		$amount = self::money( (int) $intent['amount'], (string) $intent['currency'] );
		$id     = (string) $intent['id'];

		return match ( (string) $intent['status'] ) {
			'requires_capture', 'captured' => new GatewayResult( self::ID, Operation::Authorize, Outcome::Approved, $intentUuid, $amount, (string) $intent['charge'], $id ),
			'requires_action'              => new GatewayResult( self::ID, Operation::Authorize, Outcome::RequiresAction, $intentUuid, $amount, null, $id, null, null, NextAction::redirect( (string) $intent['next_action']['url'] ) ),
			'processing'                   => new GatewayResult( self::ID, Operation::Authorize, Outcome::Pending, $intentUuid, $amount, null, $id ),
			'canceled'                     => new GatewayResult( self::ID, Operation::Authorize, Outcome::Declined, $intentUuid, $amount, $id, $id, 'expired' ),
			default                        => new GatewayResult( self::ID, Operation::Authorize, Outcome::Declined, $intentUuid, $amount, (string) $intent['charge'], $id, (string) ( $intent['decline_code'] ?? 'declined' ) ),
		};
	}

	/**
	 * Maps an intent found by a query: null while it waits, the authorization's answer otherwise.
	 *
	 * @since 0.2.0
	 *
	 * @param PaymentQuery $query  The query.
	 * @param array<mixed> $intent The provider's intent.
	 * @return GatewayResult|null The answer; null while the provider or the customer has not decided.
	 */
	protected function answerAbout( PaymentQuery $query, array $intent ): ?GatewayResult {
		if ( in_array( $intent['status'] ?? null, array( 'requires_action', 'processing' ), true ) ) {
			return null;
		}

		return $this->authorizationOf( $query->intentUuid, $intent );
	}

	/**
	 * Maps a refund the provider answered.
	 *
	 * @since 0.2.0
	 *
	 * @param string       $intentUuid The store's uuid of the intent.
	 * @param array<mixed> $refund     The provider's refund.
	 * @return GatewayResult The refund's answer.
	 */
	protected function refundOf( string $intentUuid, array $refund ): GatewayResult {
		return new GatewayResult( self::ID, Operation::Refund, Outcome::Approved, $intentUuid, self::money( (int) $refund['amount'], (string) $refund['currency'] ), (string) $refund['id'], (string) $refund['intent'] );
	}

	/**
	 * Reads a verified delivery's event.
	 *
	 * @since 0.2.0
	 *
	 * @param array<mixed> $event The decoded body.
	 * @return WebhookReading What it says.
	 */
	protected function readEvent( array $event ): WebhookReading {
		$id     = (string) ( $event['id'] ?? '' );
		$type   = (string) ( $event['type'] ?? '' );
		$object = $event['data']['object'] ?? null;

		if ( '' === $id || '' === $type || ! is_array( $object ) ) {
			return WebhookReading::rejected( WebhookReading::MALFORMED );
		}

		$at = ( new \DateTimeImmutable( '@' . (int) ( $event['created'] ?? 0 ) ) )->setTimezone( new \DateTimeZone( 'UTC' ) );

		return match ( $type ) {
			'intent.authorized' => WebhookReading::result( $id, $type, $at, $this->authorizationOf( (string) $object['intent_uuid'], $object ) ),
			'refund.succeeded'  => WebhookReading::result( $id, $type, $at, $this->refundOf( (string) $object['intent_uuid'], $object ), (string) $object['refund_uuid'] ),
			default             => WebhookReading::ignored( $id, $type, 'not_a_payment_event', $at ),
		};
	}

	/**
	 * Returns the provider's id of an intent the plugin knows, or refuses a call about one it does not.
	 *
	 * @since 0.2.0
	 *
	 * @throws GatewayUnavailable When the plugin has no provider id for the intent.
	 *
	 * @param string|null $providerIntentId The provider's id.
	 * @return string The id.
	 */
	protected function providerIntent( ?string $providerIntentId ): string {
		return $providerIntentId ?? throw new GatewayUnavailable( 'The reference provider is asked about an intent it gave no id for.' );
	}

	/**
	 * Returns the intent an answer is about: its body, or the intent an error names.
	 *
	 * @since 0.2.0
	 *
	 * @throws GatewayUnavailable When the answer names no intent.
	 *
	 * @param array{status: int, body: array<mixed>} $answer The answer.
	 * @return array<mixed> The intent.
	 */
	protected static function intentIn( array $answer ): array {
		$intent = isset( $answer['body']['error'] ) ? ( $answer['body']['intent'] ?? null ) : $answer['body'];

		if ( ! is_array( $intent ) || ! isset( $intent['id'], $intent['status'], $intent['amount'], $intent['currency'] ) ) {
			throw new GatewayUnavailable( sprintf( 'The reference provider answered %d without an intent.', $answer['status'] ) );
		}

		return $intent;
	}

	/**
	 * Returns an error answer's code.
	 *
	 * @since 0.2.0
	 *
	 * @param array{status: int, body: array<mixed>} $answer The answer.
	 * @return string|null The code; null for an answer that is no error.
	 */
	protected static function errorCodeOf( array $answer ): ?string {
		$code = $answer['body']['error']['code'] ?? null;

		return is_string( $code ) ? $code : null;
	}

	/**
	 * Builds an amount from the provider's minor units and lower-case currency.
	 *
	 * @since 0.2.0
	 *
	 * @param int    $minor    The minor units.
	 * @param string $currency The currency, in lower case.
	 * @return Money The amount.
	 */
	protected static function money( int $minor, string $currency ): Money {
		return Money::of( $minor, Currency::of( strtoupper( $currency ) ) );
	}

	/**
	 * Declares a secret setting.
	 *
	 * @since 0.2.0
	 *
	 * @param string $name        The setting's name.
	 * @param string $description What it is.
	 * @param string $label       Its label.
	 * @return FieldSpec The declaration.
	 */
	private static function secretSetting( string $name, string $description, string $label ): FieldSpec {
		return new FieldSpec( name: $name, type: FieldType::String, description: $description, label: static fn(): string => $label, example: 'ref_test_example', max_length: 255, privacy: Privacy::Secret );
	}

	/**
	 * Reads a delivery's signature header.
	 *
	 * @since 0.2.0
	 *
	 * @param array<mixed, mixed> $headers The delivery's headers.
	 * @return array{t: int, v1: string}|null The signed time and signature; null when the header is absent or malformed.
	 */
	private static function signatureIn( array $headers ): ?array {
		$headers = array_change_key_case( $headers );
		$header  = $headers[ self::SIGNATURE_HEADER ] ?? null;

		if ( ! is_string( $header ) || 1 !== preg_match( '/^t=(\d{1,12}),v1=([0-9a-f]{64})$/D', $header, $match ) ) {
			return null;
		}

		return array(
			't'  => (int) $match[1],
			'v1' => $match[2],
		);
	}

	/**
	 * Computes a delivery's signature.
	 *
	 * @since 0.2.0
	 *
	 * @param int    $time   The signed time.
	 * @param string $body   The raw body.
	 * @param string $secret The signing secret.
	 * @return string The HMAC-SHA256 of "time.body", in hexadecimal.
	 */
	private static function hmac( int $time, string $body, #[\SensitiveParameter] string $secret ): string {
		return hash_hmac( 'sha256', $time . '.' . $body, $secret );
	}
}
