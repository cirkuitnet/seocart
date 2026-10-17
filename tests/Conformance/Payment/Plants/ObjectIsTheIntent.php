<?php
/**
 * ObjectIsTheIntent: a gateway that names the provider's intent as the object of every outcome
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Conformance\Payment\Plants;

use SEOCart\Contracts\Payment\CaptureRequest;
use SEOCart\Contracts\Payment\GatewayRefund;
use SEOCart\Contracts\Payment\GatewayResult;
use SEOCart\Contracts\Payment\PaymentRequest;
use SEOCart\Tests\Conformance\Payment\Reference\ReferenceGateway;

/**
 * Authorizes, captures and refunds as the reference gateway does, and names the provider's intent as the object of each.
 *
 * The object of an outcome is its charge, capture or refund, never the long-lived intent: two
 * refunds named by one intent would key the ledger as one. The plant of rule 1: the conformance
 * suite must fail on it with that rule's message.
 *
 * @since 0.2.0
 */
final class ObjectIsTheIntent extends ReferenceGateway {

	/**
	 * What the suite's failure on this plant shows, beside its rule's message: the reason the plant plants.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const FAILS_WITH = 'named the provider\'s intent';

	/**
	 * Authorizes, and names the intent as the charge.
	 *
	 * @since 0.2.0
	 *
	 * @param PaymentRequest $request The payment.
	 * @return GatewayResult The answer, its object the intent.
	 */
	public function authorize( PaymentRequest $request ): GatewayResult {
		return self::intentAsObject( parent::authorize( $request ) );
	}

	/**
	 * Captures, and names the intent as the capture.
	 *
	 * @since 0.2.0
	 *
	 * @param CaptureRequest $request The capture.
	 * @return GatewayResult The answer, its object the intent.
	 */
	public function capture( CaptureRequest $request ): GatewayResult {
		return self::intentAsObject( parent::capture( $request ) );
	}

	/**
	 * Refunds, and names the intent as the refund.
	 *
	 * @since 0.2.0
	 *
	 * @param GatewayRefund $request The refund.
	 * @return GatewayResult The answer, its object the intent.
	 */
	public function refund( GatewayRefund $request ): GatewayResult {
		return self::intentAsObject( parent::refund( $request ) );
	}

	/**
	 * Returns an answer whose object is its intent.
	 *
	 * @since 0.2.0
	 *
	 * @param GatewayResult $answer The reference gateway's answer.
	 * @return GatewayResult The plant's.
	 */
	private static function intentAsObject( GatewayResult $answer ): GatewayResult {
		if ( null === $answer->providerIntentId ) {
			return $answer;
		}

		return new GatewayResult( $answer->provider, $answer->operation, $answer->outcome, $answer->intentUuid, $answer->amount, $answer->providerIntentId, $answer->providerIntentId, $answer->errorCode, $answer->settlement, $answer->nextAction );
	}
}
