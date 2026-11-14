<?php
/**
 * ReadsBeforeRefunding: the reference gateway of a provider whose refund needs what only a read of the payment tells
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Conformance\Payment\Reference\Variants;

use SEOCart\Contracts\Payment\GatewayRefund;
use SEOCart\Contracts\Payment\GatewayResult;
use SEOCart\Tests\Conformance\Payment\Reference\ReferenceGateway;

/**
 * Reads the provider's intent before it refunds, as a gateway does that must find the capture to refund on the payment first.
 *
 * A correct gateway of a provider shaped otherwise than the reference's: the answer rule 2 loses
 * is the refund's, the first request of the refund that can move money, not the read's.
 *
 * @since 0.2.0
 */
final class ReadsBeforeRefunding extends ReferenceGateway {

	/**
	 * Reads the intent, then refunds.
	 *
	 * @since 0.2.0
	 *
	 * @param GatewayRefund $request The refund.
	 * @return GatewayResult The provider's answer.
	 */
	public function refund( GatewayRefund $request ): GatewayResult {
		$this->send( $request->mode, 'GET', '/v1/intents/' . $this->providerIntent( $request->providerIntentId ), array(), null );

		return parent::refund( $request );
	}
}
