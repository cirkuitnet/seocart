<?php
/**
 * ProductPrice: the markup of a product's price
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Catalog\Interfaces\Storefront;

defined( 'ABSPATH' ) || exit;

/**
 * Writes the price of the variant a product's page selects, and its compare-at price struck through when it has one.
 *
 * Owns one fact: the price's markup, written once for every caller. It reads only its arguments.
 * A variant with no price in the page's currency shows no figure, never zero, and a product that
 * sells no variant shows nothing. The texts are the
 * server's; when the shopper picks another variant, the store `seocart/product` puts that
 * variant's texts in their place, and the compare-at line hides when the variant has none.
 *
 *     <div class="seocart-price">
 *         <span class="seocart-price__amount seocart-money">$18.00</span>
 *         <s class="seocart-price__compare-at">
 *             <span class="seocart-visually-hidden">Original price</span>
 *             <span class="seocart-money">$24.00</span>
 *         </s>
 *     </div>
 *
 * @since 0.2.0
 *
 * @phpstan-import-type State from ProductState
 */
final class ProductPrice {

	/**
	 * Returns the markup.
	 *
	 * @since 0.2.0
	 *
	 * @param int                   $postId  The product's post, which the store keys the product by.
	 * @param array                 $product The product's state.
	 * @param array<string, string> $strings The storefront's words, by name.
	 * @return string The markup, escaped; empty for a product that sells no variant.
	 *
	 * @phpstan-param State $product
	 */
	public static function markup( int $postId, array $product, array $strings ): string {
		if ( array() === $product['variants'] ) {
			return '';
		}

		$selected = ProductState::selected( $product );
		$compared = array() !== array_filter( $product['variants'], static fn( array $variant ): bool => null !== $variant['compareAt'] );
		$compare  = $selected['compareAt']['text'] ?? '';
		$markup   = '<div class="seocart-price" data-wp-interactive="seocart/product" ' . wp_interactivity_data_wp_context( array( 'postId' => $postId ) ) . '>'
			. '<span class="seocart-price__amount seocart-money" data-wp-text="state.priceText">' . esc_html( $selected['price']['text'] ?? '' ) . '</span>';

		if ( $compared ) {
			$markup .= ' <s class="seocart-price__compare-at" data-wp-bind--hidden="!state.compareAtText"' . ( '' === $compare ? ' hidden' : '' ) . '>'
				. '<span class="seocart-visually-hidden">' . esc_html( $strings['original_price'] ) . ' </span>'
				. '<span class="seocart-money" data-wp-text="state.compareAtText">' . esc_html( $compare ) . '</span>'
				. '</s>';
		}

		return $markup . '</div>';
	}
}
