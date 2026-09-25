<?php
/**
 * Fixture: a third-party "plugin" that hooks the catalog's own hooks, for doctor check 9
 *
 * Never loaded by the plugin; loaded by CatalogDoctorTest only, from its own directory, so that
 * reflection finds a file path outside the plugin's own to name. Registers a callback of each
 * shape WordPress accepts: a plain function here, a `Class::method` string (Listener) and an
 * invokable object (InvokableListener), each in its own file.
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SeocartTestFixture\ForeignPlugin;

require_once __DIR__ . '/Listener.php';
require_once __DIR__ . '/InvokableListener.php';

/**
 * Does nothing; stands in for a foreign plugin's `pre_delete_post` listener.
 *
 * @param mixed $check   Unused.
 * @param mixed $post    Unused.
 * @param mixed $forceDelete Unused.
 * @return mixed $check, unchanged.
 */
function on_pre_delete_post( $check, $post, $forceDelete ) {
	unset( $post, $forceDelete );

	return $check;
}

/**
 * Registers one callback of each shape: a plain function, a `Class::method` string, and an
 * invokable object.
 */
function register(): void {
	add_filter( 'pre_delete_post', __NAMESPACE__ . '\\on_pre_delete_post', 10, 3 );
	add_action( 'save_post', __NAMESPACE__ . '\\Listener::onSavePost', 10, 3 );

	InvokableListener::$registered = new InvokableListener();

	add_action( 'wp_insert_post', InvokableListener::$registered, 10, 3 );
}

/**
 * Removes every callback register() added.
 */
function deregister(): void {
	remove_action( 'pre_delete_post', __NAMESPACE__ . '\\on_pre_delete_post' );
	remove_action( 'save_post', __NAMESPACE__ . '\\Listener::onSavePost' );

	if ( InvokableListener::$registered instanceof InvokableListener ) {
		remove_action( 'wp_insert_post', InvokableListener::$registered );

		InvokableListener::$registered = null;
	}
}
