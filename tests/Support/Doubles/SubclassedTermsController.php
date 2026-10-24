<?php
/**
 * SubclassedTermsController: a terms controller of another class than core's
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support\Doubles;

/**
 * Core's terms controller under another name, as a plugin that replaces a taxonomy's REST controller would register it.
 *
 * Owns one fact: that the route walk tells a replaced terms controller from core's own, by its
 * class. It changes nothing of core's.
 *
 * @since 0.2.0
 */
final class SubclassedTermsController extends \WP_REST_Terms_Controller {
}
