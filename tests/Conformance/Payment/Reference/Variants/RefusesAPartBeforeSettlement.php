<?php
/**
 * RefusesAPartBeforeSettlement: the reference gateway of a provider that refunds part of a payment only once it has settled
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
use SEOCart\Tests\Conformance\Payment\Reference\ReferenceGateway;

/**
 * Reads the provider's intent before it refunds, and declines a refund of less than what is left of the capture without asking the provider for it, as a gateway does whose provider refunds no part before the day's settlement.
 *
 * A correct gateway of a provider shaped otherwise than the reference's: the fake provider never
 * settles, so every part is declined, and the payment is refunded whole. The suite passes it when
 * its test declares partialRefundsNeedSettlement(), and rules 1 and 2 fail it when the test does
 * not, as their first refund is of one unit.
 *
 * @since 0.2.0
 */
class RefusesAPartBeforeSettlement extends ReferenceGateway {

	/**
	 * The gateway's word for a part asked before settlement.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const NOT_SETTLED = 'refund_not_settled';

	/**
	 * Reads the intent, declines a part, and refunds the rest of the capture whole.
	 *
	 * @since 0.2.0
	 *
	 * @param GatewayRefund $request The refund.
	 * @return GatewayResult The provider's answer, or the gateway's decline.
	 */
	public function refund( GatewayRefund $request ): GatewayResult {
		$intent = self::intentIn( $this->send( $request->mode, 'GET', '/v1/intents/' . $this->providerIntent( $request->providerIntentId ), array(), null ) );

		if ( $request->amount->minorUnits() < (int) $intent['amount_captured'] - (int) $intent['amount_refunded'] ) {
			return new GatewayResult( self::ID, Operation::Refund, Outcome::Declined, $request->intentUuid, $request->amount, null, $request->providerIntentId, self::NOT_SETTLED );
		}

		return parent::refund( $request );
	}
}
