<?php
/**
 * BlockPlaceholder: the markup a product block shows in the editor where there is no product
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Catalog\Interfaces\Storefront;

defined( 'ABSPATH' ) || exit;

/**
 * Writes the one line the editor's preview shows for a product block placed where no product is, such as in a template being edited.
 *
 * Owns one fact: the placeholder's markup. A page shows nothing there; only the editor, which
 * previews a block through WordPress's server render, shows where the block sits.
 *
 *     <p class="seocart-placeholder">Product price: shown on the page of a product.</p>
 *
 * @since 0.2.0
 */
final class BlockPlaceholder {

	/**
	 * Returns the markup.
	 *
	 * @since 0.2.0
	 *
	 * @param string $sentence The sentence, translated.
	 * @return string The markup, escaped.
	 */
	public static function markup( string $sentence ): string {
		return '<p class="seocart-placeholder">' . esc_html( $sentence ) . '</p>';
	}
}
