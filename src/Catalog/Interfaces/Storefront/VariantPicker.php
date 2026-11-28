<?php
/**
 * VariantPicker: the markup of a product's options
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Catalog\Interfaces\Storefront;

defined( 'ABSPATH' ) || exit;

/**
 * Writes one group of radio buttons per option axis, in the product's order, the default selection checked.
 *
 * Owns one fact: the picker's markup, written once for every caller. It reads only its arguments.
 * Each axis is a fieldset whose legend names the axis and the value selected on it; each value is
 * a native radio button in a pill, with its swatch or its image when it has one, so the keyboard
 * moves through an axis as it does through any radio group. A product without options has no
 * axis, and the picker writes nothing; nor does it for a product that sells no variant.
 *
 * When the shopper changes a value, the store `seocart/product` selects the variant of the new
 * combination; a value that makes no variant that can be bought with the other axes' values gets
 * `is-unavailable` and "(unavailable)" in its accessible name, and stays selectable, so the
 * shopper can move through the combinations. A combination the product does not sell, or that the
 * sellability rule refuses, has no variant, so it reads as unavailable too.
 *
 *     <div class="seocart-variant-picker">
 *         <fieldset class="seocart-options">
 *             <legend class="seocart-options__legend">Size <span class="seocart-options__current">250 g</span></legend>
 *             <div class="seocart-options__values">
 *                 <label class="seocart-pill">
 *                     <input class="seocart-pill__input" type="radio" name="…" value="250g" checked>
 *                     <span class="seocart-pill__label">250 g</span>
 *                     <span class="seocart-visually-hidden"></span>
 *                 </label>
 *             </div>
 *         </fieldset>
 *     </div>
 *
 * @since 0.2.0
 *
 * @phpstan-import-type State from ProductState
 * @phpstan-import-type StateValue from ProductState
 */
final class VariantPicker {

	/**
	 * Returns the markup.
	 *
	 * @since 0.2.0
	 *
	 * @param int                $postId  The product's post, which the store keys the product by.
	 * @param array              $product The product's state.
	 * @param array<int, string> $images  The markup of each value's image, by attachment id, escaped and decorative; a value whose image is missing shows none.
	 * @return string The markup, escaped; empty for a product without options or that sells no variant.
	 *
	 * @phpstan-param State $product
	 */
	public static function markup( int $postId, array $product, array $images ): string {
		if ( array() === $product['axes'] || array() === $product['variants'] ) {
			return '';
		}

		$markup = '<div class="seocart-variant-picker" data-wp-interactive="seocart/product" ' . wp_interactivity_data_wp_context( array( 'postId' => $postId ) ) . '>';

		foreach ( $product['axes'] as $axis ) {
			$markup .= '<fieldset class="seocart-options" ' . wp_interactivity_data_wp_context( array( 'axis' => $axis['key'] ) ) . '>'
				. '<legend class="seocart-options__legend">' . esc_html( $axis['label'] ) . ' <span class="seocart-options__current" data-wp-text="state.axisValueLabel">' . esc_html( ProductState::valueLabel( $product, $axis['key'] ) ) . '</span></legend>'
				. '<div class="seocart-options__values">';

			foreach ( $axis['values'] as $value ) {
				$markup .= self::pill( $postId, $axis['key'], $value, ( $product['selection'][ $axis['key'] ] ?? null ) === $value['key'], $images );
			}

			$markup .= '</div></fieldset>';
		}

		return $markup . '</div>';
	}

	/**
	 * Returns one value's pill: its radio button, its swatch or image, its label, and room for "(unavailable)".
	 *
	 * @since 0.2.0
	 *
	 * @param int                $postId   The product's post.
	 * @param string             $axisKey  The axis.
	 * @param array              $value    The value.
	 * @param bool               $selected Whether the value is selected.
	 * @param array<int, string> $images   The markup of each value's image, by attachment id.
	 * @return string The markup.
	 *
	 * @phpstan-param StateValue $value
	 */
	private static function pill( int $postId, string $axisKey, array $value, bool $selected, array $images ): string {
		$picture = '';

		if ( null !== $value['image'] && isset( $images[ $value['image'] ] ) ) {
			$picture = $images[ $value['image'] ];
		} elseif ( null !== $value['swatch'] ) {
			$picture = '<span class="seocart-pill__swatch" aria-hidden="true" style="background-color:' . esc_attr( $value['swatch'] ) . '"></span>';
		}

		return '<label class="seocart-pill" ' . wp_interactivity_data_wp_context( array( 'value' => $value['key'] ) ) . ' data-wp-class--is-unavailable="state.valueUnavailable">'
			. '<input class="seocart-pill__input" type="radio" name="' . esc_attr( 'seocart-' . $postId . '-' . $axisKey ) . '" value="' . esc_attr( $value['key'] ) . '"' . ( $selected ? ' checked' : '' ) . ' data-wp-on--change="actions.select">'
			. $picture
			. '<span class="seocart-pill__label">' . esc_html( $value['label'] ) . '</span>'
			. '<span class="seocart-visually-hidden" data-wp-text="state.valueUnavailableText"></span>'
			. '</label>';
	}
}
