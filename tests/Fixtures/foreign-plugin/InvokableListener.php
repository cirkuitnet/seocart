<?php
/**
 * Fixture: a foreign plugin's listener registered as an invokable object
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SeocartTestFixture\ForeignPlugin;

/**
 * Stands in for a foreign plugin's listener registered as an invokable object.
 *
 * Keeps the one instance register() adds, as a class constant would not, so deregister() can
 * remove that same instance: WordPress compares objects by identity.
 *
 * @since 0.1.0
 */
final class InvokableListener {

	/**
	 * The instance register() added, or null once deregister() has removed it.
	 *
	 * @since 0.1.0
	 *
	 * @var self|null
	 */
	public static ?self $registered = null;

	/**
	 * Does nothing; stands in for a foreign plugin's `wp_insert_post` listener.
	 *
	 * @param mixed $postId Unused.
	 * @param mixed $post   Unused.
	 * @param mixed $update Unused.
	 */
	public function __invoke( $postId, $post, $update ): void {
		unset( $postId, $post, $update );
	}
}
