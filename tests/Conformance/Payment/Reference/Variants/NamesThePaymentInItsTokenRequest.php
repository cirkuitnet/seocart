<?php
/**
 * NamesThePaymentInItsTokenRequest: a gateway that sends a request about the payment on its declared token path, wrong on purpose
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Conformance\Payment\Reference\Variants;

use SEOCart\Contracts\Payment\CaptureRequest;
use SEOCart\Contracts\Payment\GatewayResult;

/**
 * Sends, before each capture, a request without a key on its token path that names the provider's intent and the amount, then captures as the reference gateway does.
 *
 * Wrong on purpose: a request on a path the test declares as a credential exchange that names the
 * payment is no credential exchange, and could move money the suite never asks a key of. Its test
 * declares the token request, and rules 6 and 12 must refuse the declaration.
 *
 * @since 0.2.0
 */
final class NamesThePaymentInItsTokenRequest extends ExchangesCredentialsFirst {

	/**
	 * Sends the request that names the payment on the token path, then captures.
	 *
	 * @since 0.2.0
	 *
	 * @param CaptureRequest $request The capture.
	 * @return GatewayResult The provider's answer.
	 */
	public function capture( CaptureRequest $request ): GatewayResult {
		$this->send(
			$request->mode,
			self::TOKEN_METHOD,
			self::TOKEN_PATH,
			array(
				'grant_type' => 'client_credentials',
				'intent'     => $this->providerIntent( $request->providerIntentId ),
				'amount'     => $request->amount->minorUnits(),
			),
			null
		);

		return parent::capture( $request );
	}
}
