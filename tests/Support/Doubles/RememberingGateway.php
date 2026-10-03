<?php
/**
 * RememberingGateway: a gateway that remembers each refund it made by its key, and says what became of one
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support\Doubles;

use SEOCart\Payment\Domain\Gateway\CaptureRequest;
use SEOCart\Payment\Domain\Gateway\GatewayRefund;
use SEOCart\Payment\Domain\Gateway\GatewayResult;
use SEOCart\Payment\Domain\Gateway\GatewayUnavailable;
use SEOCart\Payment\Domain\Gateway\PaymentGateway;
use SEOCart\Payment\Domain\Gateway\PaymentQuery;
use SEOCart\Payment\Domain\Gateway\PaymentRequest;
use SEOCart\Payment\Domain\Operation;
use SEOCart\Payment\Domain\Outcome;

/**
 * Wraps a gateway and plays a provider that keeps every refund it made, by the key it was asked with, so it can say what became of one.
 *
 * Owns one fact, for the tests of a refund's claim: what a real provider knows about the refunds it
 * was asked for. Three switches make it the provider a test needs:
 *
 * - `$ignoresKey`: it makes a new refund every time it is asked, as a provider with no idempotency
 *   key does, each named by the wrapped gateway's refund with `-{n}` after it;
 * - `$reachable`: when false, a refund never reaches it: it throws GatewayUnavailable and makes
 *   nothing, as a request that died on its way;
 * - `$knows`: when false, it cannot say what became of a refund, and answers null.
 *
 * Asked what became of a refund it knows, it answers the first refund it made under the key, or a
 * declined refund `not_found` when it made none. Everything else is the wrapped gateway's.
 *
 * @since 0.1.0
 */
final class RememberingGateway implements PaymentGateway {

	/**
	 * Whether every refund it is asked for is a new one, whatever its key.
	 *
	 * @since 0.1.0
	 *
	 * @var bool
	 */
	public bool $ignoresKey = false;

	/**
	 * Whether a refund reaches it.
	 *
	 * @since 0.1.0
	 *
	 * @var bool
	 */
	public bool $reachable = true;

	/**
	 * Whether it can say what became of a refund.
	 *
	 * @since 0.1.0
	 *
	 * @var bool
	 */
	public bool $knows = true;

	/**
	 * Whether a decline it answers names no provider object, as a real gateway's may.
	 *
	 * @since 0.1.0
	 *
	 * @var bool
	 */
	public bool $declinesUnnamed = false;

	/**
	 * Every refund it made, by the key it was asked with, in order.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, list<GatewayResult>>
	 */
	public array $made = array();

	/**
	 * The gateway that answers.
	 *
	 * @since 0.1.0
	 *
	 * @var PaymentGateway
	 */
	private PaymentGateway $inner;

	/**
	 * Wraps a gateway.
	 *
	 * @since 0.1.0
	 *
	 * @param PaymentGateway $inner The gateway that answers.
	 */
	public function __construct( PaymentGateway $inner ) {
		$this->inner = $inner;
	}

	/**
	 * Counts the refunds it made, under every key.
	 *
	 * @since 0.1.0
	 *
	 * @return int The count.
	 */
	public function refundsMade(): int {
		return array_sum( array_map( 'count', $this->made ) );
	}

	/**
	 * Returns the wrapped gateway's id.
	 *
	 * @since 0.1.0
	 *
	 * @return string The id.
	 */
	public function id(): string {
		return $this->inner->id();
	}

	/**
	 * Tells whether the wrapped gateway has a capability.
	 *
	 * @since 0.1.0
	 *
	 * @param string $capability The capability.
	 * @return bool Its answer.
	 */
	public function supports( string $capability ): bool {
		return $this->inner->supports( $capability );
	}

	/**
	 * Authorizes through the wrapped gateway.
	 *
	 * @since 0.1.0
	 *
	 * @param PaymentRequest $request The request.
	 * @return GatewayResult Its answer.
	 */
	public function authorize( PaymentRequest $request ): GatewayResult {
		return $this->inner->authorize( $request );
	}

	/**
	 * Captures through the wrapped gateway.
	 *
	 * @since 0.1.0
	 *
	 * @param CaptureRequest $request The request.
	 * @return GatewayResult Its answer.
	 */
	public function capture( CaptureRequest $request ): GatewayResult {
		return $this->inner->capture( $request );
	}

	/**
	 * Makes the refund through the wrapped gateway and keeps it under its key; a new one each time when it ignores the key; a decline naming no provider object when it declines unnamed.
	 *
	 * @since 0.1.0
	 *
	 * @throws GatewayUnavailable When the refund does not reach it; nothing was made.
	 *
	 * @param GatewayRefund $request The request.
	 * @return GatewayResult The refund it made.
	 */
	public function refund( GatewayRefund $request ): GatewayResult {
		if ( ! $this->reachable ) {
			throw new GatewayUnavailable( 'The refund never reached the gateway.' );
		}

		$made = $this->made[ $request->refundUuid ] ?? array();

		if ( array() !== $made && ! $this->ignoresKey ) {
			return $made[0];
		}

		$answer = $this->inner->refund( $request );

		if ( $this->ignoresKey ) {
			$answer = new GatewayResult( $answer->provider, $answer->operation, $answer->outcome, $answer->intentUuid, $answer->amount, $answer->providerObjectId . '-' . ( count( $made ) + 1 ), $answer->providerIntentId, $answer->errorCode );
		}

		if ( $this->declinesUnnamed && Outcome::Declined === $answer->outcome ) {
			$answer = new GatewayResult( $answer->provider, $answer->operation, $answer->outcome, $answer->intentUuid, $answer->amount, null, $answer->providerIntentId, $answer->errorCode );
		}

		$this->made[ $request->refundUuid ][] = $answer;

		return $answer;
	}

	/**
	 * Asks the wrapped gateway where an intent stands.
	 *
	 * @since 0.1.0
	 *
	 * @param PaymentQuery $query The query.
	 * @return GatewayResult|null Its answer.
	 */
	public function query( PaymentQuery $query ): ?GatewayResult {
		return $this->inner->query( $query );
	}

	/**
	 * Says what became of a refund: the first one it made under the key, or that it made none.
	 *
	 * @since 0.1.0
	 *
	 * @param GatewayRefund $request The refund, as it was asked.
	 * @return GatewayResult|null The refund it made; a declined refund `not_found` when it made none; null when it cannot say.
	 */
	public function queryRefund( GatewayRefund $request ): ?GatewayResult {
		if ( ! $this->knows ) {
			return null;
		}

		return $this->made[ $request->refundUuid ][0]
			?? new GatewayResult( $this->inner->id(), Operation::Refund, Outcome::Declined, $request->intentUuid, $request->amount, null, $request->providerIntentId, PaymentGateway::NOT_FOUND );
	}
}
