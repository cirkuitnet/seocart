<?php
/**
 * AmountInAnotherCurrency: a gateway that reports a capture in another currency
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Conformance\Payment\Plants;

use SEOCart\Contracts\Payment\CaptureRequest;
use SEOCart\Contracts\Payment\GatewayResult;
use SEOCart\Support\Currency;
use SEOCart\Support\Money;
use SEOCart\Tests\Conformance\Payment\Reference\ReferenceGateway;

/**
 * Captures as the reference gateway does, and reports the amount in another currency.
 *
 * The plant of rule 5: the conformance suite must fail on it with that rule's message.
 *
 * @since 0.2.0
 */
final class AmountInAnotherCurrency extends ReferenceGateway {

	/**
	 * What the suite's failure on this plant shows, beside its rule's message: the reason the plant plants.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const FAILS_WITH = 'an answer reported another amount or currency than it was asked for';

	/**
	 * Captures, and answers in the wrong currency.
	 *
	 * @since 0.2.0
	 *
	 * @param CaptureRequest $request The capture.
	 * @return GatewayResult The capture's answer, in EUR, or in USD when the capture is in EUR.
	 */
	public function capture( CaptureRequest $request ): GatewayResult {
		$captured = parent::capture( $request );
		$other    = Currency::of( 'EUR' === $captured->amount->currency()->code() ? 'USD' : 'EUR' );

		return new GatewayResult( $captured->provider, $captured->operation, $captured->outcome, $captured->intentUuid, Money::of( $captured->amount->minorUnits(), $other ), $captured->providerObjectId, $captured->providerIntentId, $captured->errorCode );
	}
}
