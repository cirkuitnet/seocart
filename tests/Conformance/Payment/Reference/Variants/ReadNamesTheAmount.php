<?php
/**
 * ReadNamesTheAmount: a gateway that reads by POST and names the refund's amount and currency in the read it sends before refunding
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Conformance\Payment\Reference\Variants;

use SEOCart\Contracts\Payment\GatewayRefund;

/**
 * Reads the intent before it refunds, as ReadsByPost does, with the refund's amount and its currency in the read's fields.
 *
 * No read needs the amount of a refund: a request that names it beside its currency, sent as a
 * declared read, could be the refund itself. Rules 6 and 12 must refuse the declaration of its
 * reads.
 *
 * @since 0.2.0
 */
final class ReadNamesTheAmount extends ReadsByPost {

	/**
	 * Reads the intent, naming the refund's amount and currency.
	 *
	 * @since 0.2.0
	 *
	 * @param GatewayRefund $request The refund.
	 */
	protected function readBeforeRefunding( GatewayRefund $request ): void {
		$this->send(
			$request->mode,
			'POST',
			self::PATH,
			array(
				self::RETRIEVE => array(
					'id'       => $this->providerIntent( $request->providerIntentId ),
					'amount'   => $request->amount->minorUnits(),
					'currency' => strtolower( $request->amount->currency()->code() ),
				),
			),
			null
		);
	}
}
