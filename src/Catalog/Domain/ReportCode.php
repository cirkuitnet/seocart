<?php
/**
 * ReportCode: the codes the catalog logs without throwing
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Catalog\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Machine codes that reach the reporter and the log, never a client.
 *
 * This enum owns one fact: the vocabulary of what the catalog reports. None of these is ever
 * thrown, none has a row in the error table, and none may spell a code the error table or
 * another module's reporter uses; a test composes them and checks it.
 *
 * @since 0.1.0
 */
enum ReportCode: string {

	/**
	 * Putting back the marker a failed save's mark replaced failed; the product stays `updating` until a later save or doctor settles it.
	 *
	 * @since 0.1.0
	 */
	case RestoreFailed = 'catalog.restore_failed';

	/**
	 * Under WP_DEBUG, another plugin or the theme hooked a callback to a hook core fires inside a product write, where it runs in the save's transaction.
	 *
	 * @since 0.1.0
	 */
	case ForeignSaveListener = 'catalog.foreign_save_post_listener';

	/**
	 * Under WP_DEBUG, a product post that is bound to a product was written by a path other than the plugin's save, such as Quick Edit or `wp post update`.
	 *
	 * @since 0.1.0
	 */
	case ForeignPostWrite = 'catalog.foreign_post_write';

	/**
	 * A product post the plugin did not write could not be given its `incomplete` product; the post stays unbound until doctor finds it.
	 *
	 * @since 0.1.0
	 */
	case ReconcileFailed = 'catalog.reconcile_failed';

	/**
	 * A product post went to the trash, and its variants' holds could not be released; they are reclaimed when they expire.
	 *
	 * @since 0.1.0
	 */
	case ReleaseFailed = 'catalog.release_failed';

	/**
	 * Deleting a product post was refused because its product could not be deleted with it; the post and every row stay as they were.
	 *
	 * @since 0.1.0
	 */
	case DeleteRefused = 'catalog.delete_refused';
}
