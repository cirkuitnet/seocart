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

use SEOCart\Contracts\Payment\CaptureRequest;
use SEOCart\Contracts\Payment\GatewayRefund;
use SEOCart\Contracts\Payment\GatewayResult;
use SEOCart\Contracts\Payment\PaymentGateway;
use SEOCart\Contracts\Payment\PaymentQuery;
use SEOCart\Contracts\Payment\PaymentRequest;

/**
 * Wraps a gateway, and hands each authorization's result to a webhook before returning it, and each refund's too when given a webhook for refunds.
 *
 * Owns one fact: the race a real provider allows, where its webhook reaches the store while the
 * authorization or refund call is still on its way back. The webhook runs where the call ran:
 * outside any transaction. Everything else is the wrapped gateway's.
 *
 * @since 0.1.0
 */
final class WebhookFirstGateway implements PaymentGateway {

	use DecoratesGateway;

	/**
	 * Creates the gateway.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 The webhook for refunds.
	 *
	 * @param PaymentGateway $inner         The gateway that answers.
	 * @param \Closure       $webhook       Receives each authorization's result before the call returns it.
	 * @param \Closure|null  $refundWebhook Optional. Receives each refund's request and result before the call returns
	 *                                      the result. Default null, none.
	 *
	 * @phpstan-param \Closure(GatewayResult): mixed $webhook
	 * @phpstan-param (\Closure(GatewayRefund, GatewayResult): mixed)|null $refundWebhook
	 */
	public function __construct( private PaymentGateway $inner, private \Closure $webhook, private ?\Closure $refundWebhook = null ) {
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
	 * Refunds through the wrapped gateway, and delivers the request and result to the webhook for refunds first, when there is one.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 The webhook for refunds.
	 *
	 * @param GatewayRefund $request The request.
	 * @return GatewayResult The result.
	 */
	public function refund( GatewayRefund $request ): GatewayResult {
		$result = $this->inner->refund( $request );

		if ( null !== $this->refundWebhook ) {
			( $this->refundWebhook )( $request, $result );
		}

		return $result;
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
