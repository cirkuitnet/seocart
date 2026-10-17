<?php
/**
 * VoidAfterApprovalFails: a gateway that declines a void the provider found already approved
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
use SEOCart\Tests\Conformance\Payment\Reference\ReferenceGateway;

/**
 * Answers a declined void when the provider says the authorization already succeeded, instead of its approval.
 *
 * The plant of rule 17: the conformance suite must fail on it with that rule's message.
 *
 * @since 0.2.0
 */
final class VoidAfterApprovalFails extends ReferenceGateway {

	/**
	 * What the suite's failure on this plant shows, beside its rule's message: the reason the plant plants.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const FAILS_WITH = 'not authorized by the approval it answers';

	/**
	 * Voids; an approval found instead is answered as a declined void.
	 *
	 * @since 0.2.0
	 *
	 * @param VoidRequest $request The void.
	 * @return GatewayResult The answer.
	 */
	public function void( VoidRequest $request ): GatewayResult {
		$answer = parent::void( $request );

		if ( Operation::Authorize !== $answer->operation ) {
			return $answer;
		}

		return new GatewayResult( self::ID, Operation::Void, Outcome::Declined, $request->intentUuid, $request->amount, null, $request->providerIntentId, 'already_succeeded' );
	}
}
