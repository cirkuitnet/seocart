<?php
/**
 * TotalsOrders: the order document a test places from a calculation's totals
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support\Pricing;

use SEOCart\Catalog\Domain\GenerationState;
use SEOCart\Catalog\Domain\SellabilityFacts;
use SEOCart\Checkout\Application\OrderDocument;
use SEOCart\Checkout\Domain\CheckoutDetails;
use SEOCart\Order\Domain\NewOrder;
use SEOCart\Pricing\Domain\Totals\Totals;
use SEOCart\Support\Address;
use SEOCart\Support\Locale;

/**
 * Builds the order a placement would write from a calculation's totals, for tests that place what was calculated without a cart.
 *
 * Owns one fact: what a test supplies that a placement reads elsewhere. The mapping itself is the
 * placement's own, OrderDocument; the test gives it a checkout, the fixture contact's addresses,
 * and, for each line's variant, the catalog's facts of a product of the same id, with a SKU and a
 * title named after the variant. Nothing is held.
 *
 * @since 0.1.0
 */
final class TotalsOrders {

	/**
	 * Builds the document.
	 *
	 * @since 0.1.0
	 *
	 * @param Totals $totals The totals.
	 * @return NewOrder The document, at the totals' conversion context.
	 */
	public static function document( Totals $totals ): NewOrder {
		$sold = array();

		foreach ( $totals->lines as $line ) {
			$variant = $line->line->variantId;

			$sold[ $variant ] = new SellabilityFacts( $variant, $variant, GenerationState::Complete, 1, 1, true, $variant, $variant, 'publish', true, true, 'SKU-' . $variant, 'Variant ' . $variant );
		}

		$details = new CheckoutDetails(
			new Address( 'GB', first_name: 'Jane', last_name: 'Doe', line1: '1 High Street', city: 'London', postcode: 'SW1A 1AA', email: 'jane.doe@example.com' ),
			new Address( 'GB', first_name: 'Jane', last_name: 'Doe', line1: '2 Low Road', city: 'Leeds', postcode: 'LS1 1AA' ),
			null,
			null
		);

		return OrderDocument::of( $totals, Locale::of( 'en_GB' ), $details, $sold, null, null );
	}
}
