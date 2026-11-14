<?php
/**
 * DeclineLeavesNoPayment: the reference gateway of a provider that creates nothing when it declines a payment
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Conformance\Payment\Reference\Variants;

use SEOCart\Contracts\Payment\GatewayResult;
use SEOCart\Contracts\Payment\Operation;
use SEOCart\Contracts\Payment\Outcome;
use SEOCart\Contracts\Payment\PaymentQuery;
use SEOCart\Tests\Conformance\Payment\Reference\ReferenceGateway;

/**
 * Answers a declined authorization as a provider does that refuses to create a declined payment: the decline names no provider payment, and a query about a payment it names none of asks nothing.
 *
 * A correct gateway of a provider shaped otherwise than the reference's: the suite passes it when
 * its test declares declineLeavesNoPayment(), and rule 8 fails it when the test does not, since it
 * answers nothing about a decline.
 *
 * @since 0.2.0
 */
class DeclineLeavesNoPayment extends ReferenceGateway {

	/**
	 * Maps an intent the provider answered; a declined one names no provider payment.
	 *
	 * @since 0.2.0
	 *
	 * @param string       $intentUuid The store's uuid.
	 * @param array<mixed> $intent     The provider's intent.
	 * @return GatewayResult The authorization's answer.
	 */
	protected function authorizationOf( string $intentUuid, array $intent ): GatewayResult {
		$result = parent::authorizationOf( $intentUuid, $intent );

		if ( 'failed' !== $intent['status'] ) {
			return $result;
		}

		return new GatewayResult( self::ID, Operation::Authorize, Outcome::Declined, $intentUuid, $result->amount, null, null, $result->errorCode );
	}

	/**
	 * Asks where an intent stands by the provider's id; one it gave no id for has nothing to ask about.
	 *
	 * @since 0.2.0
	 *
	 * @param PaymentQuery $query The intent.
	 * @return GatewayResult|null The answer; null when no provider id is known.
	 */
	public function query( PaymentQuery $query ): ?GatewayResult {
		return null === $query->providerIntentId ? null : parent::query( $query );
	}
}
