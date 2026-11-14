<?php
/**
 * AsksAboutItsDeclinePastTheClient: a gateway whose decline leaves no payment, and that asks the provider about it past its client, wrong on purpose
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Conformance\Payment\Reference\Variants;

use SEOCart\Contracts\Payment\GatewayResult;
use SEOCart\Contracts\Payment\PaymentQuery;

/**
 * Asks the provider about a payment it has no id of with WordPress's HTTP API directly, not through the client its context gives it, and answers nothing.
 *
 * Wrong on purpose: under the declaration that a decline leaves no payment, query() of the
 * declined payment sends no request at all, through the client or any other way, and rule 8 must
 * see the request the gateway's client never carried.
 *
 * @since 0.2.0
 */
final class AsksAboutItsDeclinePastTheClient extends DeclineLeavesNoPayment {

	/**
	 * Asks the provider past the client about a payment it has no id of, and answers nothing.
	 *
	 * @since 0.2.0
	 *
	 * @param PaymentQuery $query The intent.
	 * @return GatewayResult|null The answer; null when no provider id is known.
	 */
	public function query( PaymentQuery $query ): ?GatewayResult {
		if ( null === $query->providerIntentId ) {
			wp_remote_get( self::ENDPOINT . '/v1/intents?' . http_build_query( array( 'intent_uuid' => $query->intentUuid ) ) );
		}

		return parent::query( $query );
	}
}
