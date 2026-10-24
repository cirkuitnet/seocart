<?php
/**
 * CanonicalGateway: a registered gateway, its answers checked against the call they answer and kept as the store keeps a provider's values
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Payment\Application;

use SEOCart\Contracts\Payment\AvailabilityContext;
use SEOCart\Contracts\Payment\CaptureRequest;
use SEOCart\Contracts\Payment\GatewayDescriptor;
use SEOCart\Contracts\Payment\GatewayRefund;
use SEOCart\Contracts\Payment\GatewayResult;
use SEOCart\Contracts\Payment\GatewayUnavailable;
use SEOCart\Contracts\Payment\Operation;
use SEOCart\Contracts\Payment\Operations;
use SEOCart\Contracts\Payment\PaymentGateway;
use SEOCart\Contracts\Payment\PaymentQuery;
use SEOCart\Contracts\Payment\PaymentRequest;
use SEOCart\Contracts\Payment\VoidRequest;
use SEOCart\Contracts\Payment\WebhookEnvelope;
use SEOCart\Contracts\Payment\WebhookReading;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- These exceptions report a gateway's error to the developer; they are never HTML, and they name the gateway and the operation, never a value of the answer.

/**
 * Wraps a registered gateway so every answer to a call of the store's is checked against the call, and passes through DeliveredValues::canonical() once: the same canonicalisation a delivery of that answer passes through in the webhook receiver.
 *
 * Owns one fact: that a gateway's answer reaches the money path only as the answer to the call it
 * answers, its provider's references kept the same way whether it arrives in the request or by
 * webhook, so the two meet in the ledger. The answer must name the intent the call was about, the
 * gateway called as its provider, and an operation the call allows (ANSWERS): one that names
 * another is refused before anything reads it (answerTo()). A delivery is read through as the
 * gateway reads it, and checked and canonicalised once, by the receiver. It offers the gateway
 * contract and nothing more, no way to the gateway behind it, so no caller can hand a raw answer
 * to the money path.
 *
 * @since 0.2.0
 */
final class CanonicalGateway implements PaymentGateway {

	/**
	 * The operations an answer may name, by the call it answers: the call's own, and for a void the authorization a provider says still stands.
	 *
	 * The money path applies an answer as the operation it names, so an answer naming another
	 * would carry out what nobody asked for: a void answered as a capture would record money as
	 * taken. A status query's answer is where the provider says the payment stands, whichever
	 * operation that is, so it is not checked (null).
	 *
	 * @since 0.2.0
	 *
	 * @var array<string, list<Operation>|null>
	 */
	private const ANSWERS = array(
		Operations::AUTHORIZE    => array( Operation::Authorize ),
		Operations::CAPTURE      => array( Operation::Capture ),
		Operations::VOID         => array( Operation::Void, Operation::Authorize ),
		Operations::REFUND       => array( Operation::Refund ),
		Operations::QUERY_REFUND => array( Operation::Refund ),
		Operations::QUERY        => null,
	);

	/**
	 * Wraps a gateway.
	 *
	 * @since 0.2.0
	 *
	 * @param PaymentGateway $inner The registered gateway.
	 */
	public function __construct( private PaymentGateway $inner ) {
	}

	/**
	 * Describes the gateway.
	 *
	 * @since 0.2.0
	 *
	 * @return GatewayDescriptor Its descriptor.
	 */
	public function describe(): GatewayDescriptor {
		return $this->inner->describe();
	}

	/**
	 * Tells whether the gateway takes a payment in a context.
	 *
	 * @since 0.2.0
	 *
	 * @param AvailabilityContext $context The context.
	 * @return bool Its answer.
	 */
	public function isAvailable( AvailabilityContext $context ): bool {
		return $this->inner->isAvailable( $context );
	}

	/**
	 * Authorizes, and keeps the answer canonical once it is found to answer the request.
	 *
	 * @since 0.2.0
	 *
	 * @throws GatewayUnavailable When the gateway has no answer, or answers for another intent, as another provider, or with another operation than an authorization.
	 *
	 * @param PaymentRequest $request The request.
	 * @return GatewayResult The answer.
	 */
	public function authorize( PaymentRequest $request ): GatewayResult {
		return $this->answerTo( Operations::AUTHORIZE, $request->intentUuid, $this->inner->authorize( $request ) );
	}

	/**
	 * Captures, and keeps the answer canonical once it is found to answer the request.
	 *
	 * @since 0.2.0
	 *
	 * @throws GatewayUnavailable When the gateway has no answer, or answers for another intent, as another provider, or with another operation than a capture.
	 *
	 * @param CaptureRequest $request The request.
	 * @return GatewayResult The answer.
	 */
	public function capture( CaptureRequest $request ): GatewayResult {
		return $this->answerTo( Operations::CAPTURE, $request->intentUuid, $this->inner->capture( $request ) );
	}

	/**
	 * Voids, and keeps the answer canonical once it is found to answer the request.
	 *
	 * @since 0.2.0
	 *
	 * @throws GatewayUnavailable When the gateway has no answer, or answers for another intent, as another provider, or with another operation than a void or the authorization that still stands.
	 *
	 * @param VoidRequest $request The request.
	 * @return GatewayResult The answer.
	 */
	public function void( VoidRequest $request ): GatewayResult {
		return $this->answerTo( Operations::VOID, $request->intentUuid, $this->inner->void( $request ) );
	}

	/**
	 * Refunds, and keeps the answer canonical once it is found to answer the request.
	 *
	 * @since 0.2.0
	 *
	 * @throws GatewayUnavailable When the gateway has no answer, or answers for another intent, as another provider, or with another operation than a refund.
	 *
	 * @param GatewayRefund $request The request.
	 * @return GatewayResult The answer.
	 */
	public function refund( GatewayRefund $request ): GatewayResult {
		return $this->answerTo( Operations::REFUND, $request->intentUuid, $this->inner->refund( $request ) );
	}

	/**
	 * Asks what became of a refund, and keeps an answer canonical once it is found to answer the request.
	 *
	 * @since 0.2.0
	 *
	 * @throws GatewayUnavailable When the gateway has no answer, or answers for another intent, as another provider, or with another operation than a refund.
	 *
	 * @param GatewayRefund $request The refund, as it was asked.
	 * @return GatewayResult|null The answer, or null.
	 */
	public function queryRefund( GatewayRefund $request ): ?GatewayResult {
		$answer = $this->inner->queryRefund( $request );

		return null === $answer ? null : $this->answerTo( Operations::QUERY_REFUND, $request->intentUuid, $answer );
	}

	/**
	 * Asks where an intent stands, and keeps an answer canonical once it is found to answer the query.
	 *
	 * @since 0.2.0
	 *
	 * @throws GatewayUnavailable When the gateway has no answer, or answers for another intent or as another provider.
	 *
	 * @param PaymentQuery $query The query.
	 * @return GatewayResult|null The answer, or null.
	 */
	public function query( PaymentQuery $query ): ?GatewayResult {
		$answer = $this->inner->query( $query );

		return null === $answer ? null : $this->answerTo( Operations::QUERY, $query->intentUuid, $answer );
	}

	/**
	 * Reads a delivery as the gateway reads it: the receiver checks it and canonicalises it once.
	 *
	 * @since 0.2.0
	 *
	 * @param WebhookEnvelope $envelope The delivery.
	 * @return WebhookReading What the gateway read.
	 */
	public function readWebhook( WebhookEnvelope $envelope ): WebhookReading {
		return $this->inner->readWebhook( $envelope );
	}

	/**
	 * Returns a gateway's answer canonical, once it is found to answer the call: about the intent the call was about, from the gateway called, as an operation the call allows.
	 *
	 * An answer about another intent, or given as another provider, is the gateway's error, and
	 * applied it would move another payment's money, perhaps one of another gateway. So is one
	 * naming an operation the call does not allow (ANSWERS), which applied would do what nobody
	 * asked for, past the checks the store makes before it asks: a void answered as a capture would
	 * record a capture that the person's capabilities or the gateway's matrix may not allow. Each
	 * is refused as a call the gateway did not answer is, so every caller does what it does then,
	 * and nothing of the answer is applied: a placement waits for the reconciliation run, a capture
	 * or a void asked again sends the same key, and a refund stays claimed. The checks read the call
	 * and the answer alone, and the message names the gateway and the operation called, never a
	 * value of the answer.
	 *
	 * @since 0.2.0
	 *
	 * @throws GatewayUnavailable When the answer names another intent than the call, another provider than the gateway,
	 *                            or an operation the call does not allow.
	 *
	 * @param string        $operation  The operation called: a key of ANSWERS.
	 * @param string        $intentUuid The intent the call was about.
	 * @param GatewayResult $answer     The gateway's answer.
	 * @return GatewayResult The answer the store applies.
	 */
	private function answerTo( string $operation, string $intentUuid, GatewayResult $answer ): GatewayResult {
		$gatewayId = $this->inner->describe()->id;
		$allowed   = self::ANSWERS[ $operation ];

		if ( $answer->intentUuid !== $intentUuid ) {
			throw new GatewayUnavailable( sprintf( 'The gateway %1$s answered a call to %2$s for another intent than the one the call was about: nothing of the answer was applied.', $gatewayId, $operation ) );
		}

		if ( $answer->provider !== $gatewayId ) {
			throw new GatewayUnavailable( sprintf( 'The gateway %1$s answered a call to %2$s as another provider: nothing of the answer was applied.', $gatewayId, $operation ) );
		}

		if ( null !== $allowed && ! in_array( $answer->operation, $allowed, true ) ) {
			throw new GatewayUnavailable( sprintf( 'The gateway %1$s answered a call to %2$s with the result of another operation: nothing of the answer was applied.', $gatewayId, $operation ) );
		}

		return DeliveredValues::canonical( $answer );
	}
}
