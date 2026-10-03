<?php
/**
 * WebhookFirstGateway: a gateway whose webhook delivers an authorization's result before the authorization call returns it
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

/**
 * Wraps a gateway, and hands each authorization's result to a webhook before returning it.
 *
 * Owns one fact: the race a real provider allows, where its webhook reaches the store while the
 * authorization call is still on its way back. The webhook runs where the call ran: outside any
 * transaction. Everything else is the wrapped gateway's.
 *
 * @since 0.1.0
 */
final class WebhookFirstGateway implements PaymentGateway {

	/**
	 * Creates the gateway.
	 *
	 * @since 0.1.0
	 *
	 * @param PaymentGateway $inner   The gateway that answers.
	 * @param \Closure       $webhook Receives each authorization's result before the call returns it.
	 *
	 * @phpstan-param \Closure(GatewayResult): mixed $webhook
	 */
	public function __construct( private PaymentGateway $inner, private \Closure $webhook ) {
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
	 * Tells whether the wrapped gateway supports a capability.
	 *
	 * @since 0.1.0
	 *
	 * @param string $capability The capability.
	 * @return bool The wrapped gateway's answer.
	 */
	public function supports( string $capability ): bool {
		return $this->inner->supports( $capability );
	}

	/**
	 * Authorizes through the wrapped gateway, and delivers the result to the webhook first.
	 *
	 * @since 0.1.0
	 *
	 * @param PaymentRequest $request The request.
	 * @return GatewayResult The result, delivered already.
	 */
	public function authorize( PaymentRequest $request ): GatewayResult {
		$result = $this->inner->authorize( $request );

		( $this->webhook )( $result );

		return $result;
	}

	/**
	 * Captures through the wrapped gateway.
	 *
	 * @since 0.1.0
	 *
	 * @param CaptureRequest $request The request.
	 * @return GatewayResult The result.
	 */
	public function capture( CaptureRequest $request ): GatewayResult {
		return $this->inner->capture( $request );
	}

	/**
	 * Refunds through the wrapped gateway.
	 *
	 * @since 0.1.0
	 *
	 * @param GatewayRefund $request The request.
	 * @return GatewayResult The result.
	 */
	public function refund( GatewayRefund $request ): GatewayResult {
		return $this->inner->refund( $request );
	}

	/**
	 * Queries the wrapped gateway.
	 *
	 * @since 0.1.0
	 *
	 * @param PaymentQuery $query The intent.
	 * @return GatewayResult|null The answer.
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
	 * @return GatewayResult|null The answer.
	 */
	public function queryRefund( GatewayRefund $request ): ?GatewayResult {
		return $this->inner->queryRefund( $request );
	}
}
