<?php
/**
 * Fixture: uninstall.php is shipped code too
 *
 * Removing the plugin's options on uninstall is the settings registry's job, because only
 * the registry knows every option name.
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

delete_option( 'seocart_currency' ); // Expect: SEOCart.DRY.RawOptionWrite.Found
delete_site_option( 'seocart_network' ); // Expect: SEOCart.DRY.RawOptionWrite.Found
