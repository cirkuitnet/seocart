<?php
/**
 * AlreadyCapturedThrows: a gateway that fails a capture asked again
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Conformance\Payment\Plants;

use SEOCart\Contracts\Payment\CaptureRequest;
use SEOCart\Contracts\Payment\GatewayResult;
use SEOCart\Contracts\Payment\GatewayUnavailable;
use SEOCart\Tests\Conformance\Payment\Reference\ReferenceGateway;

/**
 * Captures once, and throws when it is asked to capture the same intent again, instead of answering the capture already made.
 *
 * The plant of rule 7: the conformance suite must fail on it with that rule's message.
 *
 * @since 0.2.0
 */
final class AlreadyCapturedThrows extends ReferenceGateway {

	/**
	 * What the suite's failure on this plant shows, beside its rule's message: the reason the plant plants.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const FAILS_WITH = 'The provider says the intent is already captured';

	/**
	 * The provider intents asked to capture so far.
	 *
	 * @since 0.2.0
	 *
	 * @var array<string, true>
	 */
	private static array $captured = array();

	/**
	 * Captures once; throws the second time.
	 *
	 * @since 0.2.0
	 *
	 * @throws GatewayUnavailable When the intent was asked to capture before.
	 *
	 * @param CaptureRequest $request The capture.
	 * @return GatewayResult The first capture's answer.
	 */
	public function capture( CaptureRequest $request ): GatewayResult {
		$intent = (string) $request->providerIntentId;

		if ( isset( self::$captured[ $intent ] ) ) {
			throw new GatewayUnavailable( 'The provider says the intent is already captured.' );
		}

		self::$captured[ $intent ] = true;

		return parent::capture( $request );
	}
}
