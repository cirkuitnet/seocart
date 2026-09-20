<?php
/**
 * Traversal canary for the main plugin autoloader
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

/**
 * Marks that an autoloader traversed from `src/` into the test support directory.
 *
 * @since 0.1.0
 */
define( 'SEOCART_TESTS_TRAVERSAL_CANARY_LOADED', true );
