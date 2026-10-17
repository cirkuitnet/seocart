<?php
/**
 * CaptureReportedAsAuthorization: a gateway that reports its capture as an authorization
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Conformance\Payment\Plants;

use SEOCart\Contracts\Payment\CaptureRequest;
use SEOCart\Contracts\Payment\GatewayResult;
use SEOCart\Contracts\Payment\Operation;
use SEOCart\Tests\Conformance\Payment\Reference\ReferenceGateway;

/**
 * Captures as the reference gateway does, and reports the capture as an authorization.
 *
 * The plant of rule 1: the conformance suite must fail on it with that rule's message.
 *
 * @since 0.2.0
 */
final class CaptureReportedAsAuthorization extends ReferenceGateway {

	/**
	 * What the suite's failure on this plant shows, beside its rule's message: the reason the plant plants.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const FAILS_WITH = 'after the capture, the payment ledger is not the one an authorization and a capture of the whole amount make';

	/**
	 * Captures, and answers with the wrong operation.
	 *
	 * @since 0.2.0
	 *
	 * @param CaptureRequest $request The capture.
	 * @return GatewayResult The capture's answer, as an authorization.
	 */
	public function capture( CaptureRequest $request ): GatewayResult {
		$captured = parent::capture( $request );

		return new GatewayResult( $captured->provider, Operation::Authorize, $captured->outcome, $captured->intentUuid, $captured->amount, $captured->providerObjectId, $captured->providerIntentId, $captured->errorCode );
	}
}
