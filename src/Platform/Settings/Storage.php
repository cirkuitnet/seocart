<?php
/**
 * Storage: how a setting is kept in the options table
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The two ways a setting can be stored.
 *
 * This enum owns one fact: which storage shapes a setting may be declared with. Every setting of
 * a group is stored the same way, so a group is either a set of independent options or one
 * document.
 *
 * @since 0.1.0
 */
enum Storage {

	/**
	 * An independent value in an option of its own, `seocart_{group}_{name}`. Two writers of two
	 * such settings touch two rows, so neither can overwrite the other.
	 *
	 * @since 0.1.0
	 */
	case Scalar;

	/**
	 * A value in the group's versioned JSON document, `seocart_{group}`, with the other settings
	 * it must be valid together with. The document is written whole, by compare-and-swap on its
	 * version, so it is never half-saved and a writer that read an older version loses.
	 *
	 * @since 0.1.0
	 */
	case Document;
}
