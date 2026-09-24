<?php
/**
 * SubclassedAutosavesController: an autosave controller of another class than core's
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support\Doubles;

/**
 * Core's autosave controller under another name, as a plugin that replaces a post type's autosave controller would register it.
 *
 * Owns one fact: that the route walk tells a replaced autosave controller from core's own, by its
 * class. It changes nothing of core's.
 *
 * @since 0.1.0
 */
final class SubclassedAutosavesController extends \WP_REST_Autosaves_Controller {
}
