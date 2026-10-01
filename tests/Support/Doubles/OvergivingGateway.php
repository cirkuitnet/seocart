<?php
/**
 * OvergivingGateway: a gateway that gives back one minor unit more than each refund asks
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
use SEOCart\Payment\Domain\Gateway\PaymentGateway;
use SEOCart\Payment\Domain\Gateway\PaymentQuery;
use SEOCart\Payment\Domain\Gateway\PaymentRequest;
use SEOCart\Support\Money;

/**
 * Wraps a gateway and approves every refund for one minor unit more than was asked, as a faulty provider would.
 *
 * Owns one fact, for the test of a refund whose money the plugin cannot record as asked. Every
 * other call is the wrapped gateway's.
 *
 * @since 0.1.0
 */
final class OvergivingGateway implements PaymentGateway {

	/**
	 * Wraps a gateway.
	 *
	 * @since 0.1.0
	 *
	 * @param PaymentGateway $inner The gateway that answers.
	 */
	public function __construct( private PaymentGateway $inner ) {
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
	 * Refunds through the wrapped gateway, and reports one minor unit more than it was asked.
	 *
	 * @since 0.1.0
	 *
	 * @param GatewayRefund $request The request.
	 * @return GatewayResult The answer, overgiving.
	 */
	public function refund( GatewayRefund $request ): GatewayResult {
		$answer = $this->inner->refund( $request );

		return new GatewayResult( $answer->provider, $answer->operation, $answer->outcome, $answer->intentUuid, Money::of( $answer->amount->minorUnits() + 1, $answer->amount->currency() ), $answer->providerObjectId, $answer->providerIntentId, $answer->errorCode );
	}

	/**
	 * Asks the wrapped gateway.
	 *
	 * @since 0.1.0
	 *
	 * @param PaymentQuery $query The query.
	 * @return GatewayResult|null Its answer.
	 */
	public function query( PaymentQuery $query ): ?GatewayResult {
		return $this->inner->query( $query );
	}
}
