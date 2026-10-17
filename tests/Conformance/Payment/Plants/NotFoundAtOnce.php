<?php
/**
 * NotFoundAtOnce: a gateway that answers not found about every intent
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
use SEOCart\Contracts\Payment\PaymentGateway;
use SEOCart\Contracts\Payment\PaymentQuery;
use SEOCart\Tests\Conformance\Payment\Reference\ReferenceGateway;

/**
 * Answers every query that the provider has no record of the intent, even of one it authorized.
 *
 * The plant of rule 8: the conformance suite must fail on it with that rule's message.
 *
 * @since 0.2.0
 */
final class NotFoundAtOnce extends ReferenceGateway {

	/**
	 * What the suite's failure on this plant shows, beside its rule's message: the reason the plant plants.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const FAILS_WITH = 'query() answered not_found for an intent the provider authorized';

	/**
	 * Answers not found.
	 *
	 * @since 0.2.0
	 *
	 * @param PaymentQuery $query The intent.
	 * @return GatewayResult A declined authorization `not_found`.
	 */
	public function query( PaymentQuery $query ): GatewayResult {
		return new GatewayResult( self::ID, Operation::Authorize, Outcome::Declined, $query->intentUuid, $query->amount, null, null, PaymentGateway::NOT_FOUND );
	}
}
