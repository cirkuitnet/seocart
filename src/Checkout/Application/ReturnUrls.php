<?php
/**
 * ReturnUrls: where a payment provider sends the shopper back after asking them to act
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Checkout\Application;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the address a provider sends the shopper back to, once they have acted for it, for example confirmed with their bank.
 *
 * Owns one fact: what that address carries. It is the site's own address with the payment's public
 * identifier, and nothing else: no access key, cart token or idempotency key, and nothing a
 * provider gives, so nothing secret reaches a URL, a browser's history or a server's access log by
 * the plugin's doing. The page there does not settle the payment: the shopper's client asks the
 * checkout to resume it (`checkout.resume_payment`), which asks the provider. A checkout page of
 * the store's own replaces the site's address once the storefront has one.
 *
 * @since 0.2.0
 */
final class ReturnUrls {

	/**
	 * The query argument the payment's identifier travels in.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const QUERY_ARG = 'seocart_payment';

	/**
	 * Returns the address a provider sends the shopper back to, for a payment.
	 *
	 * @since 0.2.0
	 *
	 * @param string $intentUuid The payment's public identifier.
	 * @return string The site's address, carrying the identifier alone.
	 */
	public static function for( string $intentUuid ): string {
		return add_query_arg( self::QUERY_ARG, rawurlencode( $intentUuid ), home_url( '/' ) );
	}
}
