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

use SEOCart\Contracts\Payment\CaptureRequest;
use SEOCart\Contracts\Payment\GatewayRefund;
use SEOCart\Contracts\Payment\GatewayResult;
use SEOCart\Contracts\Payment\PaymentGateway;
use SEOCart\Contracts\Payment\PaymentQuery;
use SEOCart\Contracts\Payment\PaymentRequest;
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

	use DecoratesGateway;

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
		return self::overgiving( $this->inner->refund( $request ) );
	}

	/**
	 * Asks the wrapped gateway what became of a refund, and reports one minor unit more than it was asked, as refund() does.
	 *
	 * @since 0.1.0
	 *
	 * @param GatewayRefund $request The refund, as it was asked.
	 * @return GatewayResult|null The answer, overgiving; null when the wrapped gateway cannot say.
	 */
	public function queryRefund( GatewayRefund $request ): ?GatewayResult {
		$answer = $this->inner->queryRefund( $request );

		return null === $answer ? null : self::overgiving( $answer );
	}

	/**
	 * Returns an answer for one minor unit more than it reports.
	 *
	 * @since 0.1.0
	 *
	 * @param GatewayResult $answer The answer.
	 * @return GatewayResult The same answer, overgiving.
	 */
	private static function overgiving( GatewayResult $answer ): GatewayResult {
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
