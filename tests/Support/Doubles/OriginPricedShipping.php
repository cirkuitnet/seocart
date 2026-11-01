<?php
/**
 * OriginPricedShipping: a shipping quoter whose rate depends on the location the order ships from
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support\Doubles;

use SEOCart\Pricing\Application\ShippingQuoteRequest;
use SEOCart\Pricing\Application\ShippingRateQuoter;
use SEOCart\Pricing\Domain\Quote\ShippingRateQuote;

defined( 'ABSPATH' ) || exit;

/**
 * Answers with one quoter for orders shipped from one location, and with another for every other origin.
 *
 * The plugin's own quoters ignore the origin until a provider uses it; this one lets a test see a
 * plan that ships from elsewhere move the total.
 *
 * @since 0.2.0
 */
final class OriginPricedShipping implements ShippingRateQuoter {

	/**
	 * Holds the two quoters.
	 *
	 * @since 0.2.0
	 *
	 * @param ShippingRateQuoter $near        What every origin but the far one is quoted by.
	 * @param ShippingRateQuoter $far         What the far origin is quoted by.
	 * @param int                $farLocation The far origin.
	 */
	public function __construct( private ShippingRateQuoter $near, private ShippingRateQuoter $far, private int $farLocation ) {
	}

	/**
	 * Quotes by the request's origin.
	 *
	 * @since 0.2.0
	 *
	 * @param ShippingQuoteRequest $request What to quote for.
	 * @return list<ShippingRateQuote> The rates.
	 */
	public function quote( ShippingQuoteRequest $request ): array {
		return ( $this->farLocation === $request->originLocationId ? $this->far : $this->near )->quote( $request );
	}
}
