<?php
/**
 * CapturesAnObjectOfItsOwn: a gateway that names the payment as the object of a capture its provider answers as an object of its own
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Conformance\Payment\Reference\Variants;

use SEOCart\Contracts\Payment\CaptureRequest;
use SEOCart\Contracts\Payment\GatewayResult;
use SEOCart\Contracts\Payment\GatewayUnavailable;
use SEOCart\Contracts\Payment\Operation;
use SEOCart\Contracts\Payment\Outcome;

/**
 * Captures with a request whose answer is a capture object of its own, `{"id": …, "intent": …}`, and names the provider's intent, which that answer names in another field, as the capture's object.
 *
 * Where the test declares that the provider keeps one object for a payment, an outcome names the
 * object its answer is about: rule 1 must fail this gateway, though its answer holds the intent's id.
 *
 * @since 0.2.0
 */
final class CapturesAnObjectOfItsOwn extends OneObjectPerPayment {

	/**
	 * Captures, and names the intent as the capture's object.
	 *
	 * @since 0.2.0
	 *
	 * @throws GatewayUnavailable When the provider answers no capture.
	 *
	 * @param CaptureRequest $request The capture.
	 * @return GatewayResult The answer, its object the intent.
	 */
	public function capture( CaptureRequest $request ): GatewayResult {
		$answer  = $this->send(
			$request->mode,
			'POST',
			'/v1/captures',
			array(
				'intent' => $this->providerIntent( $request->providerIntentId ),
				'amount' => $request->amount->minorUnits(),
			),
			$request->idempotencyKey()
		);
		$capture = $answer['body'];

		if ( 200 !== $answer['status'] || ! isset( $capture['id'], $capture['intent'], $capture['amount'], $capture['currency'] ) ) {
			throw new GatewayUnavailable( 'The reference provider answered no capture.' );
		}

		return new GatewayResult( self::ID, Operation::Capture, Outcome::Approved, $request->intentUuid, self::money( (int) $capture['amount'], (string) $capture['currency'] ), (string) $capture['intent'], (string) $capture['intent'] );
	}
}
