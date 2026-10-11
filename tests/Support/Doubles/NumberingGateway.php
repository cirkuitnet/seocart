<?php
/**
 * NumberingGateway: a gateway that names every capture and refund it makes with one number
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

/**
 * Wraps a gateway, and answers each capture and each refund with the wrapped gateway's answer named by one number, as a provider such as Authorize.Net numbers its transactions.
 *
 * Owns one fact: a provider reference of digits alone, which may pass for a card number. Everything
 * else is the wrapped gateway's.
 *
 * @since 0.2.0
 */
final class NumberingGateway implements PaymentGateway {

	use DecoratesGateway;

	/**
	 * Creates the gateway.
	 *
	 * @since 0.2.0
	 *
	 * @param PaymentGateway $inner  The gateway that answers.
	 * @param string         $number The number every capture and refund is named by.
	 */
	public function __construct( private PaymentGateway $inner, private string $number ) {
	}

	/**
	 * Names every capture and refund from now on by another reference.
	 *
	 * @since 0.2.0
	 *
	 * @param string $number The reference, which need not be an identifier.
	 */
	public function nameBy( string $number ): void {
		$this->number = $number;
	}

	/**
	 * Authorizes through the wrapped gateway.
	 *
	 * @since 0.2.0
	 *
	 * @param PaymentRequest $request The request.
	 * @return GatewayResult The result.
	 */
	public function authorize( PaymentRequest $request ): GatewayResult {
		return $this->inner->authorize( $request );
	}

	/**
	 * Captures through the wrapped gateway, and names the capture by the number.
	 *
	 * @since 0.2.0
	 *
	 * @param CaptureRequest $request The request.
	 * @return GatewayResult The result, named by the number.
	 */
	public function capture( CaptureRequest $request ): GatewayResult {
		return $this->numbered( $this->inner->capture( $request ) );
	}

	/**
	 * Refunds through the wrapped gateway, and names the refund by the number.
	 *
	 * @since 0.2.0
	 *
	 * @param GatewayRefund $request The request.
	 * @return GatewayResult The result, named by the number.
	 */
	public function refund( GatewayRefund $request ): GatewayResult {
		return $this->numbered( $this->inner->refund( $request ) );
	}

	/**
	 * Asks the wrapped gateway what became of a refund, and names it by the number.
	 *
	 * @since 0.2.0
	 *
	 * @param GatewayRefund $request The refund, as it was asked.
	 * @return GatewayResult|null The answer, named by the number, or null.
	 */
	public function queryRefund( GatewayRefund $request ): ?GatewayResult {
		$answer = $this->inner->queryRefund( $request );

		return null === $answer ? null : $this->numbered( $answer );
	}

	/**
	 * Queries the wrapped gateway.
	 *
	 * @since 0.2.0
	 *
	 * @param PaymentQuery $query The intent.
	 * @return GatewayResult|null The answer.
	 */
	public function query( PaymentQuery $query ): ?GatewayResult {
		return $this->inner->query( $query );
	}

	/**
	 * Returns a result named by the number.
	 *
	 * @since 0.2.0
	 *
	 * @param GatewayResult $result The result.
	 * @return GatewayResult The same result, its provider object the number.
	 */
	private function numbered( GatewayResult $result ): GatewayResult {
		return new GatewayResult( $result->provider, $result->operation, $result->outcome, $result->intentUuid, $result->amount, $this->number, $result->providerIntentId, $result->errorCode, $result->settlement, $result->nextAction );
	}
}
