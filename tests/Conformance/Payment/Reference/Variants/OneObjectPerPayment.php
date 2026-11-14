<?php
/**
 * OneObjectPerPayment: the reference gateway of a provider that keeps one object for a payment's authorization and capture
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Conformance\Payment\Reference\Variants;

use SEOCart\Contracts\Payment\CaptureRequest;
use SEOCart\Contracts\Payment\GatewayResult;
use SEOCart\Contracts\Payment\PaymentRequest;
use SEOCart\Tests\Conformance\Payment\Reference\ReferenceGateway;

/**
 * Names the provider's intent as the object of the authorization and of the capture, as a gateway does whose provider answers both with the payment's one id, and each refund by its own.
 *
 * A correct gateway of a provider shaped otherwise than the reference's: the suite passes it when
 * its test declares paymentIsOneObject(), and rule 1 fails it when the test does not.
 *
 * @since 0.2.0
 */
class OneObjectPerPayment extends ReferenceGateway {

	/**
	 * Authorizes, and names the intent as the authorization's object.
	 *
	 * @since 0.2.0
	 *
	 * @param PaymentRequest $request The payment.
	 * @return GatewayResult The answer, its object the intent.
	 */
	public function authorize( PaymentRequest $request ): GatewayResult {
		return self::namedByTheIntent( parent::authorize( $request ) );
	}

	/**
	 * Captures, and names the intent as the capture's object.
	 *
	 * @since 0.2.0
	 *
	 * @param CaptureRequest $request The capture.
	 * @return GatewayResult The answer, its object the intent.
	 */
	public function capture( CaptureRequest $request ): GatewayResult {
		return self::namedByTheIntent( parent::capture( $request ) );
	}

	/**
	 * Returns an answer whose object is its provider's intent; an answer that names no intent as it was.
	 *
	 * @since 0.2.0
	 *
	 * @param GatewayResult $answer The answer.
	 * @return GatewayResult The answer, its object the intent.
	 */
	public static function namedByTheIntent( GatewayResult $answer ): GatewayResult {
		if ( null === $answer->providerIntentId ) {
			return $answer;
		}

		return new GatewayResult( $answer->provider, $answer->operation, $answer->outcome, $answer->intentUuid, $answer->amount, $answer->providerIntentId, $answer->providerIntentId, $answer->errorCode, $answer->settlement, $answer->nextAction );
	}
}
