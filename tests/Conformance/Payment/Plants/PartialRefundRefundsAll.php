<?php
/**
 * PartialRefundRefundsAll: a gateway that declares partial refunds and refunds everything
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Conformance\Payment\Plants;

use SEOCart\Contracts\Payment\GatewayRefund;
use SEOCart\Support\Money;
use SEOCart\Tests\Conformance\Payment\Reference\ReferenceGateway;

/**
 * Declares partial refunds, as the reference gateway does, and asks the provider for everything still captured whatever the refund asks.
 *
 * The plant of rule 2: the conformance suite must fail on it with that rule's message.
 *
 * @since 0.2.0
 */
final class PartialRefundRefundsAll extends ReferenceGateway {

	/**
	 * What the suite's failure on this plant shows, beside its rule's message: the reason the plant plants.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const FAILS_WITH = 'one unit, then the rest, was refused';

	/**
	 * Returns what is still captured, instead of the refund's amount.
	 *
	 * @since 0.2.0
	 *
	 * @param GatewayRefund $request The refund.
	 * @return Money The intent's captured amount less what was refunded.
	 */
	protected function amountToRefund( GatewayRefund $request ): Money {
		$intent = self::intentIn( $this->send( $request->mode, 'GET', '/v1/intents/' . $this->providerIntent( $request->providerIntentId ), array(), null ) );

		return self::money( (int) $intent['amount_captured'] - (int) $intent['amount_refunded'], (string) $intent['currency'] );
	}
}
