<?php
/**
 * PricingError: the error catalog of the pricing module
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Pricing\Domain;

use SEOCart\Support\Error\ErrorCode;
use SEOCart\Support\Error\ErrorDefinition;

defined( 'ABSPATH' ) || exit;

/**
 * The errors a calculation can end in.
 *
 * Owns one fact: how a calculation that cannot produce totals is reported. The engine raises
 * one of them itself, so the catalog is domain code, like the catalog module's. None of them is ever
 * answered with a total that is wrong instead: no silent zero for tax a provider did not quote,
 * no invented shipping price. A line with no price in the cart's currency is not an error: it is
 * reported beside the totals. A caller's programming error, such as a quantity of zero or a
 * calculation started inside a transaction, is an \InvalidArgumentException or a
 * \LogicException, never a row here.
 *
 * @since 0.1.0
 */
enum PricingError: string implements ErrorCode {

	/**
	 * A shipping or tax provider could not quote, so no total can be given.
	 *
	 * Internal: the provider it names is the store's own business, so a customer gets the status
	 * and a generic message, and the message with the provider goes to the site's log.
	 *
	 * @since 0.1.0
	 */
	case QuoteUnavailable = 'pricing.quote_unavailable';

	/**
	 * The cart has a destination, and no shipping method can deliver to it.
	 *
	 * @since 0.1.0
	 */
	case NoShippingRate = 'pricing.no_shipping_rate';

	/**
	 * Prices are not offered in the cart's currency.
	 *
	 * @since 0.1.0
	 */
	case CurrencyNotEnabled = 'pricing.currency_not_enabled';

	/**
	 * Returns the catalog's rows.
	 *
	 * @since 0.1.0
	 *
	 * @return list<ErrorDefinition> One row per case.
	 */
	public static function definitions(): array {
		return array(
			new ErrorDefinition(
				self::QuoteUnavailable,
				503,
				static fn(): string =>
					/* translators: %1$s: The name of the shipping or tax provider that could not answer. */
					__( 'A total could not be worked out, because the provider %1$s did not answer or left out a tax class the cart needs.', 'seocart' ),
				array( 'provider' ),
				internal: true
			),
			new ErrorDefinition(
				self::NoShippingRate,
				409,
				static fn(): string => __( 'No shipping method delivers to this address. Check the address, or contact the store.', 'seocart' )
			),
			new ErrorDefinition(
				self::CurrencyNotEnabled,
				409,
				static fn(): string =>
					/* translators: %1$s: An ISO 4217 currency code, such as EUR. */
					__( 'Prices are not offered in %1$s. Choose another currency.', 'seocart' ),
				array( 'currency' )
			),
		);
	}
}
