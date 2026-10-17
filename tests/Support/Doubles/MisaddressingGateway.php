<?php
/**
 * MisaddressingGateway: answers the store's calls with the wrapped gateway's answers addressed to another intent, or given as another provider
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support\Doubles;

use SEOCart\Contracts\Payment\CaptureRequest;
use SEOCart\Contracts\Payment\GatewayRefund;
use SEOCart\Contracts\Payment\GatewayResult;
use SEOCart\Contracts\Payment\PaymentGateway;
use SEOCart\Contracts\Payment\PaymentQuery;
use SEOCart\Contracts\Payment\PaymentRequest;
use SEOCart\Contracts\Payment\VoidRequest;

/**
 * A gateway that makes what the store asks of it, as the gateway it wraps makes it, and answers for another intent than the one asked about, or as another provider: what a misbehaving or buggy adapter can hand the store.
 *
 * Owns one fact: the wrong address on an answer. Until answerFor() or answerAs() is called, every
 * answer is the wrapped gateway's; after, every answer to authorize, capture, void, refund,
 * queryRefund and query keeps all the wrapped gateway said, its amount and provider objects
 * included, and names the intent or the provider given instead. No answer stays no answer.
 *
 * @since 0.2.0
 */
final class MisaddressingGateway implements PaymentGateway {

	use DecoratesGateway;

	/**
	 * The intent every answer names instead of the one asked about; null to keep the intent the wrapped gateway named.
	 *
	 * @since 0.2.0
	 *
	 * @var string|null
	 */
	private ?string $intentUuid = null;

	/**
	 * The provider every answer is given as; null to keep the wrapped gateway's.
	 *
	 * @since 0.2.0
	 *
	 * @var string|null
	 */
	private ?string $provider = null;

	/**
	 * Wraps a gateway.
	 *
	 * @since 0.2.0
	 *
	 * @param PaymentGateway $inner The gateway that makes what is asked and answers it.
	 */
	public function __construct( private PaymentGateway $inner ) {
	}

	/**
	 * From now on, answers every call as if it were about another intent.
	 *
	 * @since 0.2.0
	 *
	 * @param string $intentUuid The intent the answers name.
	 */
	public function answerFor( string $intentUuid ): void {
		$this->intentUuid = $intentUuid;
	}

	/**
	 * From now on, answers every call as another provider.
	 *
	 * @since 0.2.0
	 *
	 * @param string $provider The provider the answers are given as: another gateway's id.
	 */
	public function answerAs( string $provider ): void {
		$this->provider = $provider;
	}

	/**
	 * Authorizes through the wrapped gateway, and addresses its answer as set.
	 *
	 * @since 0.2.0
	 *
	 * @param PaymentRequest $request The request.
	 * @return GatewayResult The answer.
	 */
	public function authorize( PaymentRequest $request ): GatewayResult {
		return $this->misaddressed( $this->inner->authorize( $request ) );
	}

	/**
	 * Captures through the wrapped gateway, and addresses its answer as set.
	 *
	 * @since 0.2.0
	 *
	 * @param CaptureRequest $request The request.
	 * @return GatewayResult The answer.
	 */
	public function capture( CaptureRequest $request ): GatewayResult {
		return $this->misaddressed( $this->inner->capture( $request ) );
	}

	/**
	 * Voids through the wrapped gateway, and addresses its answer as set.
	 *
	 * @since 0.2.0
	 *
	 * @param VoidRequest $request The request.
	 * @return GatewayResult The answer.
	 */
	public function void( VoidRequest $request ): GatewayResult {
		return $this->misaddressed( $this->inner->void( $request ) );
	}

	/**
	 * Refunds through the wrapped gateway, and addresses its answer as set.
	 *
	 * @since 0.2.0
	 *
	 * @param GatewayRefund $request The request.
	 * @return GatewayResult The answer.
	 */
	public function refund( GatewayRefund $request ): GatewayResult {
		return $this->misaddressed( $this->inner->refund( $request ) );
	}

	/**
	 * Asks the wrapped gateway what became of a refund, and addresses an answer as set.
	 *
	 * @since 0.2.0
	 *
	 * @param GatewayRefund $request The refund, as it was asked.
	 * @return GatewayResult|null The answer, or null when the wrapped gateway gave none.
	 */
	public function queryRefund( GatewayRefund $request ): ?GatewayResult {
		$answer = $this->inner->queryRefund( $request );

		return null === $answer ? null : $this->misaddressed( $answer );
	}

	/**
	 * Asks the wrapped gateway where an intent stands, and addresses an answer as set.
	 *
	 * @since 0.2.0
	 *
	 * @param PaymentQuery $query The query.
	 * @return GatewayResult|null The answer, or null when the wrapped gateway gave none.
	 */
	public function query( PaymentQuery $query ): ?GatewayResult {
		$answer = $this->inner->query( $query );

		return null === $answer ? null : $this->misaddressed( $answer );
	}

	/**
	 * Returns an answer of the wrapped gateway, naming the intent and the provider set, and keeping everything else.
	 *
	 * @since 0.2.0
	 *
	 * @param GatewayResult $answer The wrapped gateway's answer.
	 * @return GatewayResult The answer, as this gateway gives it.
	 */
	private function misaddressed( GatewayResult $answer ): GatewayResult {
		return new GatewayResult( $this->provider ?? $answer->provider, $answer->operation, $answer->outcome, $this->intentUuid ?? $answer->intentUuid, $answer->amount, $answer->providerObjectId, $answer->providerIntentId, $answer->errorCode, $answer->settlement, $answer->nextAction );
	}
}
