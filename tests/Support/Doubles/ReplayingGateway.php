<?php
/**
 * ReplayingGateway: a gateway that answers every refund with the first refund it made
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

/**
 * Wraps a gateway and answers every refund after the first with the first refund's result, as a provider does when it is asked again for a refund it already made.
 *
 * Owns one fact, for the test of a duplicate delivery: the same refund delivered again. Every
 * other call is the wrapped gateway's.
 *
 * @since 0.1.0
 */
final class ReplayingGateway implements PaymentGateway {

	use DecoratesGateway;

	/**
	 * The first refund's result, once there was one.
	 *
	 * @since 0.1.0
	 *
	 * @var GatewayResult|null
	 */
	private ?GatewayResult $first = null;

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
	 * Refunds through the wrapped gateway the first time, and answers with that refund ever after.
	 *
	 * @since 0.1.0
	 *
	 * @param GatewayRefund $request The request.
	 * @return GatewayResult The first refund's result.
	 */
	public function refund( GatewayRefund $request ): GatewayResult {
		$this->first ??= $this->inner->refund( $request );

		return $this->first;
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
	 * Answers what became of a refund as refund() does: with the first refund it made, once it made one.
	 *
	 * @since 0.1.0
	 *
	 * @param GatewayRefund $request The refund, as it was asked.
	 * @return GatewayResult|null The first refund's result, or the wrapped gateway's answer before any.
	 */
	public function queryRefund( GatewayRefund $request ): ?GatewayResult {
		return $this->first ?? $this->inner->queryRefund( $request );
	}
}
