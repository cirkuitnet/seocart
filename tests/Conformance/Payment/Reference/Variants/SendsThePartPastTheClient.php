<?php
/**
 * SendsThePartPastTheClient: a gateway that declines a part of a refund before settlement, and sends that part to the provider past the client the plugin gives it
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Conformance\Payment\Reference\Variants;

use SEOCart\Contracts\Payment\GatewayRefund;
use SEOCart\Contracts\Payment\GatewayResult;
use SEOCart\Contracts\Payment\Outcome;

/**
 * Declines a part as RefusesAPartBeforeSettlement does, then asks the provider for it with wp_remote_post(), around the client the plugin gives it.
 *
 * The provider refunds what it was asked for, whoever asks: under the test's declaration that
 * partial refunds wait for the provider's settlement, rule 2 must fail this gateway, as its decline
 * of the part sent a request that moves money, though none through the client.
 *
 * @since 0.2.0
 */
final class SendsThePartPastTheClient extends RefusesAPartBeforeSettlement {

	/**
	 * Refunds, and sends a declined part to the provider past the client.
	 *
	 * @since 0.2.0
	 *
	 * @param GatewayRefund $request The refund.
	 * @return GatewayResult The answer: the part declined.
	 */
	public function refund( GatewayRefund $request ): GatewayResult {
		$answer = parent::refund( $request );

		if ( Outcome::Declined === $answer->outcome ) {
			wp_remote_post(
				self::ENDPOINT . '/v1/refunds',
				array(
					'headers' => array(
						'Authorization'   => 'Bearer ' . $this->credential( $request->mode ),
						'Idempotency-Key' => $request->idempotencyKey(),
					),
					'body'    => array(
						'intent'      => $this->providerIntent( $request->providerIntentId ),
						'amount'      => $request->amount->minorUnits(),
						'refund_uuid' => $request->refundUuid,
					),
				)
			);
		}

		return $answer;
	}
}
