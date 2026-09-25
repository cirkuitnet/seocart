<?php
/**
 * Fixture: a foreign plugin's listener registered as a `Class::method` string
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SeocartTestFixture\ForeignPlugin;

/**
 * Stands in for a foreign plugin's listener registered as a `Class::method` string.
 *
 * @since 0.1.0
 */
final class Listener {

	/**
	 * Does nothing; stands in for a foreign plugin's `save_post` listener.
	 *
	 * @param mixed $postId Unused.
	 * @param mixed $post   Unused.
	 * @param mixed $update Unused.
	 */
	public static function onSavePost( $postId, $post, $update ): void {
		unset( $postId, $post, $update );
	}
}
