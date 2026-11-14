<?php
/**
 * NamesTheAmountInItsTokenRequest: a gateway that sends the refund's amount and currency on its declared token path, wrong on purpose
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Conformance\Payment\Reference\Variants;

use SEOCart\Contracts\Payment\GatewayRefund;
use SEOCart\Contracts\Payment\GatewayResult;

/**
 * Sends, before each refund, a request without a key on its token path that names the refund's amount and currency, and nothing else of the payment, then refunds as the reference gateway does.
 *
 * Wrong on purpose: a request on a path the test declares as a credential exchange that names an
 * operation's amount with its currency is no credential exchange. Its test declares the token
 * request, and rules 6 and 12 must refuse the declaration.
 *
 * @since 0.2.0
 */
final class NamesTheAmountInItsTokenRequest extends ExchangesCredentialsFirst {

	/**
	 * Sends the request that names the amount on the token path, then refunds.
	 *
	 * @since 0.2.0
	 *
	 * @param GatewayRefund $request The refund.
	 * @return GatewayResult The provider's answer.
	 */
	public function refund( GatewayRefund $request ): GatewayResult {
		$this->send(
			$request->mode,
			self::TOKEN_METHOD,
			self::TOKEN_PATH,
			array(
				'grant_type' => 'client_credentials',
				'amount'     => $request->amount->minorUnits(),
				'currency'   => strtolower( $request->amount->currency()->code() ),
			),
			null
		);

		return parent::refund( $request );
	}
}
