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

use SEOCart\Contracts\Payment\GatewayRefund;
use SEOCart\Contracts\Payment\GatewayResult;
use SEOCart\Tests\Conformance\Payment\Reference\Variants\OneObjectPerPayment;

/**
 * Authorizes, captures and refunds as the reference gateway does, and names the provider's intent as the object of each.
 *
 * The object of an outcome is its charge, capture or refund, never the long-lived intent: two
 * refunds named by one intent would key the ledger as one. The plant of rule 1: the conformance
 * suite must fail on it with that rule's message, and, where the test declares that the provider
 * keeps one object for a payment, because its two refunds name one object.
 *
 * @since 0.2.0
 */
final class ObjectIsTheIntent extends OneObjectPerPayment {

	/**
	 * What the suite's failure on this plant shows, beside its rule's message: the reason the plant plants.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const FAILS_WITH = 'named the provider\'s intent';

	/**
	 * Refunds, and names the intent as the refund.
	 *
	 * @since 0.2.0
	 *
	 * @param GatewayRefund $request The refund.
	 * @return GatewayResult The answer, its object the intent.
	 */
	public function refund( GatewayRefund $request ): GatewayResult {
		return self::namedByTheIntent( parent::refund( $request ) );
	}
}
