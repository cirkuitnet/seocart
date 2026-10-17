<?php
/**
 * VoidReportsZero: a gateway whose approved void reports zero
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Conformance\Payment\Plants;

use SEOCart\Contracts\Payment\GatewayResult;
use SEOCart\Contracts\Payment\Operation;
use SEOCart\Contracts\Payment\Outcome;
use SEOCart\Contracts\Payment\VoidRequest;
use SEOCart\Support\Money;
use SEOCart\Tests\Conformance\Payment\Reference\ReferenceGateway;

/**
 * Voids as the reference gateway does, and reports an approved void as having moved nothing.
 *
 * The plant of rule 19: the conformance suite must fail on it with that rule's message.
 *
 * @since 0.2.0
 */
final class VoidReportsZero extends ReferenceGateway {

	/**
	 * What the suite's failure on this plant shows, beside its rule's message: the reason the plant plants.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const FAILS_WITH = 'reported 0 USD (minor units)';

	/**
	 * Voids, and reports zero when the void is approved.
	 *
	 * @since 0.2.0
	 *
	 * @param VoidRequest $request The void.
	 * @return GatewayResult The void's answer, of zero when approved.
	 */
	public function void( VoidRequest $request ): GatewayResult {
		return self::zeroed( parent::void( $request ) );
	}

	/**
	 * Returns the answer this plant gives in place of another: an approved void of zero; any other answer as it is.
	 *
	 * @since 0.2.0
	 *
	 * @param GatewayResult $answer The reference gateway's answer.
	 * @return GatewayResult The plant's.
	 */
	public static function zeroed( GatewayResult $answer ): GatewayResult {
		if ( Operation::Void !== $answer->operation || Outcome::Approved !== $answer->outcome ) {
			return $answer;
		}

		return new GatewayResult( $answer->provider, $answer->operation, $answer->outcome, $answer->intentUuid, Money::of( 0, $answer->amount->currency() ), $answer->providerObjectId, $answer->providerIntentId, $answer->errorCode, $answer->settlement, $answer->nextAction );
	}
}
