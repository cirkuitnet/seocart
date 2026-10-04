<?php
/**
 * CrashingRefundGateway: a gateway whose process dies once it has given a refund's money back
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

// phpcs:disable WordPress.WP.AlternativeFunctions -- The call is logged to the file the test reads after the process died.

/**
 * Wraps a gateway, has it make each refund, logs the refund to a file, then runs the crash it was given before the answer is returned.
 *
 * Owns one fact, for the test of a refund killed between the gateway's approval and the
 * transaction that records it: the refund probe gives a crash that kills its own process, so
 * nothing after the gateway's answer runs, not even PHP's shutdown. The log, one line per refund
 * with its key and the provider's refund object, is written before the crash and is how the test
 * counts what the gateway did in a process that never reported.
 *
 * @since 0.1.0
 */
final class CrashingRefundGateway implements PaymentGateway {

	use DecoratesGateway;

	/**
	 * The gateway that answers.
	 *
	 * @since 0.1.0
	 *
	 * @var PaymentGateway
	 */
	private PaymentGateway $inner;

	/**
	 * The file each refund is logged to.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private string $log;

	/**
	 * What runs once a refund is made and logged: the crash.
	 *
	 * @since 0.1.0
	 *
	 * @var \Closure(): void
	 */
	private \Closure $crash;

	/**
	 * Wraps a gateway.
	 *
	 * @since 0.1.0
	 *
	 * @param PaymentGateway $inner The gateway that answers.
	 * @param string         $log   The file each refund is logged to.
	 * @param \Closure       $crash What runs once a refund is made and logged.
	 *
	 * @phpstan-param \Closure(): void $crash
	 */
	public function __construct( PaymentGateway $inner, string $log, \Closure $crash ) {
		$this->inner = $inner;
		$this->log   = $log;
		$this->crash = $crash;
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
	 * Has the wrapped gateway make the refund, logs it, then crashes before the answer is returned.
	 *
	 * @since 0.1.0
	 *
	 * @param GatewayRefund $request The request.
	 * @return GatewayResult The wrapped gateway's answer, when the crash returns.
	 */
	public function refund( GatewayRefund $request ): GatewayResult {
		$answer = $this->inner->refund( $request );

		file_put_contents( $this->log, $request->refundUuid . ' ' . (string) $answer->providerObjectId . "\n", FILE_APPEND );

		( $this->crash )();

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
	 * Asks the wrapped gateway what became of a refund.
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
