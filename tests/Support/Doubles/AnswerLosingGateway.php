<?php
/**
 * AnswerLosingGateway: a gateway that makes the first refund and loses its answer
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
use SEOCart\Contracts\Payment\GatewayUnavailable;
use SEOCart\Contracts\Payment\PaymentGateway;
use SEOCart\Contracts\Payment\PaymentQuery;
use SEOCart\Contracts\Payment\PaymentRequest;

/**
 * Wraps a gateway, has it make the first refund, and loses that refund's answer; every later call is answered.
 *
 * Owns one fact, for the test of a refund asked again: a refund the gateway made whose answer
 * never reached the code that records it, as when the connection drops on the way back or the
 * process dies between the gateway's call and the transaction. To the database the two are the
 * same: nothing was recorded.
 *
 * @since 0.1.0
 */
final class AnswerLosingGateway implements PaymentGateway {

	use DecoratesGateway;

	/**
	 * Whether the first refund's answer was lost yet.
	 *
	 * @since 0.1.0
	 *
	 * @var bool
	 */
	private bool $lost = false;

	/**
	 * Wraps a gateway.
	 *
	 * @since 0.1.0
	 *
	 * @param PaymentGateway $inner The gateway that makes the refunds.
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
	 * Has the wrapped gateway make the refund, then loses the first answer.
	 *
	 * @since 0.1.0
	 *
	 * @throws GatewayUnavailable The first time, after the wrapped gateway made the refund.
	 *
	 * @param GatewayRefund $request The request.
	 * @return GatewayResult The wrapped gateway's answer, from the second refund on.
	 */
	public function refund( GatewayRefund $request ): GatewayResult {
		$result = $this->inner->refund( $request );

		if ( ! $this->lost ) {
			$this->lost = true;

			throw new GatewayUnavailable( 'The gateway made the refund; its answer was lost on the way back.' );
		}

		return $result;
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

	/**
	 * Asks the wrapped gateway what became of a refund; that answer arrives.
	 *
	 * @since 0.1.0
	 *
	 * @param GatewayRefund $request The refund, as it was asked.
	 * @return GatewayResult|null Its answer.
	 */
	public function queryRefund( GatewayRefund $request ): ?GatewayResult {
		return $this->inner->queryRefund( $request );
	}
}
