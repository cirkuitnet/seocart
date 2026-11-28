<?php
/**
 * StorefrontStrings: every word the storefront blocks show a shopper
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Storefront;

defined( 'ABSPATH' ) || exit;

/**
 * The sentences and labels of the storefront, translated, each under a name.
 *
 * Owns one fact: what the storefront says. The components print these in their markup, and the
 * store's configuration carries all of them to the browser, so a script module shows a sentence
 * by its name and holds none of its own, and every visitor of a locale reads the same words. A
 * placeholder is a name in braces, such as `{name}`, which format() fills.
 *
 * @since 0.2.0
 */
final class StorefrontStrings {

	/**
	 * Returns every sentence and label, translated, by name.
	 *
	 * Called while a block renders, after `init`, so the words are translated here.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, string> The words, by name.
	 */
	public static function all(): array {
		return array(
			'add'                 => __( 'Add to cart', 'seocart' ),
			'adding'              => __( 'Adding…', 'seocart' ),
			'sold_out'            => __( 'Sold out', 'seocart' ),
			'unavailable'         => __( 'Unavailable', 'seocart' ),
			'quantity'            => __( 'Quantity', 'seocart' ),
			'decrease'            => __( 'Decrease quantity', 'seocart' ),
			'increase'            => __( 'Increase quantity', 'seocart' ),
			'needs_script'        => __( 'Adding to the cart needs JavaScript, which is turned off in this browser.', 'seocart' ),
			'product_unavailable' => __( 'This product is unavailable right now.', 'seocart' ),
			'original_price'      => __( 'Original price', 'seocart' ),
			'value_unavailable'   => __( '(unavailable)', 'seocart' ),
			/* translators: {block}: The name of a block, such as "Product price". */
			'placeholder'         => __( '{block}: shown on the page of a product.', 'seocart' ),
			/* translators: {name}: The name of a product. */
			'added'               => __( 'Added to cart: {name}', 'seocart' ),
			/* translators: 1: {name}: The name of a product. 2: {label}: The variant's label, such as "250 g / Whole bean". */
			'added_variant'       => __( 'Added to cart: {name} · {label}', 'seocart' ),
			/* translators: 1: {for_sale}: How many units are for sale, a whole number. 2: {quantity}: How many the cart holds, a whole number. */
			'insufficient'        => __( 'Only {for_sale} available. Your cart holds {quantity}.', 'seocart' ),
			'out_of_stock'        => __( 'This item is out of stock. It is in your cart and cannot be ordered until it is back.', 'seocart' ),
			'unpriced'            => __( 'This item cannot be bought in your currency.', 'seocart' ),
			'cart_changed'        => __( 'Your cart changed. Check it and try again.', 'seocart' ),
			'unconfirmed'         => __( 'We could not confirm that. Your cart is shown as it is now.', 'seocart' ),
			'failed'              => __( 'Something went wrong. Reload the page.', 'seocart' ),
			'session_expired'     => __( 'Your session has expired. Reload the page.', 'seocart' ),
			'rate_limited'        => __( 'Too many requests. Wait a moment and try again.', 'seocart' ),
			'placing'             => __( 'Your order is being placed. Finish it at checkout.', 'seocart' ),
		);
	}

	/**
	 * Fills a sentence's named placeholders.
	 *
	 * @since 0.2.0
	 *
	 * @param string                $sentence The sentence, such as `{block}: shown on the page of a product.`.
	 * @param array<string, string> $values   Each placeholder's value, by its name without braces.
	 * @return string The sentence, filled.
	 */
	public static function format( string $sentence, array $values ): string {
		$pairs = array();

		foreach ( $values as $name => $value ) {
			$pairs[ '{' . $name . '}' ] = $value;
		}

		return strtr( $sentence, $pairs );
	}
}
