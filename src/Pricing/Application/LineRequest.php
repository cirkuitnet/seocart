<?php
/**
 * LineRequest: a line a cart or an order asks to have priced
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Pricing\Application;

defined( 'ABSPATH' ) || exit;

/**
 * A line as the caller knows it: its identity, the variant and how many, and no money at all.
 *
 * Owns one fact: what a caller hands in for a line. The price is never the caller's to give; the
 * calculation looks it up. The key is the caller's identity for the line, returned with every
 * figure of it.
 *
 * @since 0.1.0
 */
final readonly class LineRequest {

	/**
	 * Checks and holds the line.
	 *
	 * @since 0.1.0
	 *
	 * @throws \InvalidArgumentException When the key is empty, the variant id is not positive, or the quantity is below one.
	 *
	 * @param string $key       The caller's identity for the line: a cart line's identity, an order line's uuid.
	 * @param int    $variantId The variant.
	 * @param int    $quantity  How many, one or more.
	 */
	public function __construct(
		public string $key,
		public int $variantId,
		public int $quantity
	) {
		if ( '' === $key || $variantId < 1 || $quantity < 1 ) {
			throw new \InvalidArgumentException( 'A line to price has a key, a variant and one or more units.' );
		}
	}
}
