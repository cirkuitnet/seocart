<?php
/**
 * GenerationState: the generation marker of a product's commerce row
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Catalog\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Whether a product's commerce row is being written, was left half-built, or is whole.
 *
 * Owns one fact: the three values of the marker a product carries in `products.generation_state`,
 * and how a stored value this code does not know is read. Saving a product is not atomic across
 * WordPress: its post is written by core, inside a window where other plugins' listeners run. So
 * a product is marked `updating` before its post is written and is taken out of `updating` by one
 * conditional statement: to the state Product::settle() returns once every commerce row is
 * written, or back to the marker it had before when the write was rolled back. Whatever else
 * happens in between, a crash or a lost connection, leaves `updating` behind.
 *
 * What a reader may do with a product in each state is Sellability's to say, not this enum's.
 *
 * @since 0.1.0
 */
enum GenerationState: string {

	/**
	 * Not every commerce row a sale needs exists: a post the plugin did not write, or a save without a price.
	 *
	 * @since 0.1.0
	 */
	case Incomplete = 'incomplete';

	/**
	 * A write is in progress, or one ended without taking the product out of this state.
	 *
	 * @since 0.1.0
	 */
	case Updating = 'updating';

	/**
	 * Every commerce row a sale needs was written by one successful save.
	 *
	 * @since 0.1.0
	 */
	case Complete = 'complete';

	/**
	 * Reads a stored marker. A value this code does not know is read as Incomplete, never as Complete.
	 *
	 * @since 0.1.0
	 *
	 * @param string $stored The value of `products.generation_state`.
	 * @return self The state.
	 */
	public static function fromStored( string $stored ): self {
		return self::tryFrom( $stored ) ?? self::Incomplete;
	}
}
