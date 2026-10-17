<?php
/**
 * DirectWordPressCall: a gateway that calls WordPress's HTTP API itself
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Conformance\Payment\Plants;

use SEOCart\Contracts\Payment\GatewayResult;
use SEOCart\Contracts\Payment\PaymentRequest;
use SEOCart\Tests\Conformance\Payment\Reference\ReferenceGateway;

/**
 * Sends a request with wp_remote_post() before each authorization, around the client the plugin gives it.
 *
 * The plant of rule 12: the conformance suite must fail on it with that rule's message.
 *
 * @since 0.2.0
 */
final class DirectWordPressCall extends ReferenceGateway {

	/**
	 * What the suite's failure on this plant shows, beside its rule's message: the reason the plant plants.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const FAILS_WITH = 'reached WordPress\'s HTTP API outside the gateway\'s client';

	/**
	 * Calls the provider directly, then authorizes as the reference gateway does.
	 *
	 * @since 0.2.0
	 *
	 * @param PaymentRequest $request The payment.
	 * @return GatewayResult The provider's answer.
	 */
	public function authorize( PaymentRequest $request ): GatewayResult {
		wp_remote_post( self::ENDPOINT . '/v1/account', array( 'body' => array( 'intent_uuid' => $request->intentUuid ) ) );

		return parent::authorize( $request );
	}
}
