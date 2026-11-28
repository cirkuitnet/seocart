<?php
/**
 * AddToCartForm: the markup of a product's add-to-cart form
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Catalog\Interfaces\Storefront;

defined( 'ABSPATH' ) || exit;

/**
 * Writes the quantity and the button that add the variant a product's page selects, and the two regions that say what came of it.
 *
 * Owns one fact: the form's markup, written once for every caller. It reads only its arguments.
 * The quantity is a labelled number of at least one, with a labelled button on each side; the
 * button reads "Add to cart", or "Sold out" or "Unavailable" when the selected variant cannot be
 * added, and is then marked `aria-disabled`, so it keeps its place in the tab order and keeps the
 * focus through an add. A `role="status"` region says what an add did; a `role="alert"` region
 * says why one failed. The form posts nowhere: the store `seocart/product` sends the add, and
 * without a script the price and the options still read, and a sentence says adding needs one.
 * A product that sells no variant gets the sentence that it is unavailable, and no form.
 *
 *     <form class="seocart-add-to-cart">
 *         <div class="seocart-add-to-cart__quantity">
 *             <label class="seocart-add-to-cart__label" for="…">Quantity</label>
 *             <div class="seocart-qty">
 *                 <button class="seocart-qty__step" type="button" aria-label="Decrease quantity">−</button>
 *                 <input class="seocart-qty__input" id="…" type="number" min="1" value="1">
 *                 <button class="seocart-qty__step" type="button" aria-label="Increase quantity">+</button>
 *             </div>
 *         </div>
 *         <button class="seocart-button seocart-button--block seocart-button--large" type="submit">Add to cart</button>
 *         <p class="seocart-add-to-cart__status" role="status"></p>
 *         <p class="seocart-notice seocart-notice--error" role="alert"></p>
 *     </form>
 *
 * @since 0.2.0
 *
 * @phpstan-import-type State from ProductState
 */
final class AddToCartForm {

	/**
	 * Returns the markup.
	 *
	 * @since 0.2.0
	 *
	 * @param int                   $postId  The product's post, which the store keys the product by.
	 * @param array                 $product The product's state.
	 * @param array<string, string> $strings The storefront's words, by name.
	 * @return string The markup, escaped.
	 *
	 * @phpstan-param State $product
	 */
	public static function markup( int $postId, array $product, array $strings ): string {
		if ( array() === $product['variants'] ) {
			return '<p class="seocart-notice">' . esc_html( $strings['product_unavailable'] ) . '</p>';
		}

		$selected = ProductState::selected( $product );
		$input    = 'seocart-quantity-' . $postId;

		return '<form class="seocart-add-to-cart" data-wp-interactive="seocart/product" ' . wp_interactivity_data_wp_context( array( 'postId' => $postId ) ) . ' data-wp-on--submit="actions.add" data-wp-init="callbacks.viewItem" novalidate>'
			. '<div class="seocart-add-to-cart__quantity">'
			. '<label class="seocart-add-to-cart__label" for="' . esc_attr( $input ) . '">' . esc_html( $strings['quantity'] ) . '</label>'
			. '<div class="seocart-qty">'
			. '<button class="seocart-qty__step" type="button" aria-label="' . esc_attr( $strings['decrease'] ) . '" data-wp-on--click="actions.decrease"><span aria-hidden="true">&minus;</span></button>'
			. '<input class="seocart-qty__input" id="' . esc_attr( $input ) . '" type="number" inputmode="numeric" min="1" step="1" value="' . esc_attr( (string) $product['quantity'] ) . '" data-wp-bind--value="state.quantity" data-wp-bind--max="state.maxQuantity" data-wp-on--change="actions.setQuantity">'
			. '<button class="seocart-qty__step" type="button" aria-label="' . esc_attr( $strings['increase'] ) . '" data-wp-on--click="actions.increase"><span aria-hidden="true">+</span></button>'
			. '</div>'
			. '</div>'
			. '<button class="seocart-button seocart-button--block seocart-button--large" type="submit" aria-disabled="' . ( ProductState::canAdd( $selected ) ? 'false' : 'true' ) . '" data-wp-bind--aria-disabled="state.cannotAdd" data-wp-bind--aria-busy="state.adding" data-wp-text="state.buttonLabel">' . esc_html( $strings[ ProductState::buttonLabel( $selected ) ] ) . '</button>'
			. '<p class="seocart-add-to-cart__status" role="status" data-wp-text="state.statusText"></p>'
			. '<p class="seocart-notice seocart-notice--error" role="alert" data-wp-text="state.alertText"></p>'
			. '<noscript><p class="seocart-notice">' . esc_html( $strings['needs_script'] ) . '</p></noscript>'
			. '</form>';
	}
}
