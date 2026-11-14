<?php
/**
 * DeclinesThePartItSent: a gateway that asks the provider for a part of a payment's refund, and answers it declined
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Conformance\Payment\Reference\Variants;

use SEOCart\Contracts\Payment\GatewayRefund;
use SEOCart\Contracts\Payment\GatewayResult;
use SEOCart\Contracts\Payment\Operation;
use SEOCart\Contracts\Payment\Outcome;

/**
 * Sends the refund of a part to the provider, as the reference gateway does, and answers it declined whatever the provider did.
 *
 * The provider may refund the part it was asked for: a gateway that declines a part before
 * settlement asks it for nothing. Under the test's declaration that partial refunds wait for the
 * provider's settlement, rule 2 must fail it, as the part's refusal sent a request that moves money.
 *
 * @since 0.2.0
 */
final class DeclinesThePartItSent extends RefusesAPartBeforeSettlement {

	/**
	 * Refunds, and answers a part declined.
	 *
	 * @since 0.2.0
	 *
	 * @param GatewayRefund $request The refund.
	 * @return GatewayResult The answer.
	 */
	public function refund( GatewayRefund $request ): GatewayResult {
		$answer = parent::refund( $request );

		if ( Outcome::Declined !== $answer->outcome ) {
			return $answer;
		}

		$this->send(
			$request->mode,
			'POST',
			'/v1/refunds',
			array(
				'intent'      => $this->providerIntent( $request->providerIntentId ),
				'amount'      => $request->amount->minorUnits(),
				'refund_uuid' => $request->refundUuid,
			),
			$request->idempotencyKey()
		);

		return new GatewayResult( self::ID, Operation::Refund, Outcome::Declined, $request->intentUuid, $request->amount, null, $request->providerIntentId, self::NOT_SETTLED );
	}
}
