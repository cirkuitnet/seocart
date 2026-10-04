<?php
/**
 * RecordingGateway: a gateway that records each call it receives and the transaction depth it was made at
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
use SEOCart\Platform\Database\TransactionManager;

/**
 * Wraps a gateway, answers as it does, and records every call with the depth of the transaction open when it came.
 *
 * Owns one fact, for the tests that hold the payment service to calling a gateway only outside
 * any transaction: which calls were made, and at what depth. A test can also give it a call to
 * make inside each call, as a real adapter's HTTP request would be made.
 *
 * @since 0.1.0
 */
final class RecordingGateway implements PaymentGateway {

	use DecoratesGateway;

	/**
	 * Every call so far, in order: the method and the depth it was made at.
	 *
	 * @since 0.1.0
	 *
	 * @var list<array{method: string, depth: int}>
	 */
	public array $calls = array();

	/**
	 * Every refund the wrapped gateway answered, in order: the key it was asked with, and the refund object it answered with.
	 *
	 * @since 0.1.0
	 *
	 * @var list<array{key: string, object: string|null}>
	 */
	public array $refunds = array();

	/**
	 * The gateway that answers.
	 *
	 * @since 0.1.0
	 *
	 * @var PaymentGateway
	 */
	private PaymentGateway $inner;

	/**
	 * The unit of work whose depth is recorded.
	 *
	 * @since 0.1.0
	 *
	 * @var TransactionManager
	 */
	private TransactionManager $tx;

	/**
	 * What each call does before it answers, as a real adapter's request would; null for nothing.
	 *
	 * @since 0.1.0
	 *
	 * @var (\Closure(): void)|null
	 */
	private ?\Closure $during = null;

	/**
	 * Wraps a gateway.
	 *
	 * @since 0.1.0
	 *
	 * @param PaymentGateway     $inner The gateway that answers.
	 * @param TransactionManager $tx    The unit of work whose depth is recorded.
	 */
	public function __construct( PaymentGateway $inner, TransactionManager $tx ) {
		$this->inner = $inner;
		$this->tx    = $tx;
	}

	/**
	 * Gives every later call something to do before it answers.
	 *
	 * @since 0.1.0
	 *
	 * @param \Closure $during What each call does, such as an outbound request.
	 *
	 * @phpstan-param \Closure(): void $during
	 */
	public function during( \Closure $during ): void {
		$this->during = $during;
	}

	/**
	 * Records the call, then authorizes through the wrapped gateway.
	 *
	 * @since 0.1.0
	 *
	 * @param PaymentRequest $request The request.
	 * @return GatewayResult Its answer.
	 */
	public function authorize( PaymentRequest $request ): GatewayResult {
		$this->record( __FUNCTION__ );

		return $this->inner->authorize( $request );
	}

	/**
	 * Records the call, then captures through the wrapped gateway.
	 *
	 * @since 0.1.0
	 *
	 * @param CaptureRequest $request The request.
	 * @return GatewayResult Its answer.
	 */
	public function capture( CaptureRequest $request ): GatewayResult {
		$this->record( __FUNCTION__ );

		return $this->inner->capture( $request );
	}

	/**
	 * Records the call, then refunds through the wrapped gateway, and records the key and the refund object.
	 *
	 * @since 0.1.0
	 *
	 * @param GatewayRefund $request The request.
	 * @return GatewayResult Its answer.
	 */
	public function refund( GatewayRefund $request ): GatewayResult {
		$this->record( __FUNCTION__ );

		$result          = $this->inner->refund( $request );
		$this->refunds[] = array(
			'key'    => $request->refundUuid,
			'object' => $result->providerObjectId,
		);

		return $result;
	}

	/**
	 * Records the call, then asks the wrapped gateway.
	 *
	 * @since 0.1.0
	 *
	 * @param PaymentQuery $query The query.
	 * @return GatewayResult|null Its answer.
	 */
	public function query( PaymentQuery $query ): ?GatewayResult {
		$this->record( __FUNCTION__ );

		return $this->inner->query( $query );
	}

	/**
	 * Records the call, then asks the wrapped gateway what became of a refund.
	 *
	 * @since 0.1.0
	 *
	 * @param GatewayRefund $request The refund, as it was asked.
	 * @return GatewayResult|null Its answer.
	 */
	public function queryRefund( GatewayRefund $request ): ?GatewayResult {
		$this->record( __FUNCTION__ );

		return $this->inner->queryRefund( $request );
	}

	/**
	 * Records one call at the current depth, and does what every call does.
	 *
	 * @since 0.1.0
	 *
	 * @param string $method The method called.
	 */
	private function record( string $method ): void {
		$this->calls[] = array(
			'method' => $method,
			'depth'  => $this->tx->depth(),
		);

		if ( null !== $this->during ) {
			( $this->during )();
		}
	}
}
