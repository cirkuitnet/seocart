<?php
/**
 * Fixture: raw option writes in shipped code, outside the settings module
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

// Violations.

add_option( 'seocart_currency', 'USD' ); // Expect: SEOCart.DRY.RawOptionWrite.Found
update_option( 'seocart_currency', 'EUR', false ); // Expect: SEOCart.DRY.RawOptionWrite.Found
delete_option( 'seocart_currency' ); // Expect: SEOCart.DRY.RawOptionWrite.Found
add_site_option( 'seocart_network', 1 ); // Expect: SEOCart.DRY.RawOptionWrite.Found
update_site_option( 'seocart_network', 2 ); // Expect: SEOCart.DRY.RawOptionWrite.Found
delete_site_option( 'seocart_network' ); // Expect: SEOCart.DRY.RawOptionWrite.Found
add_network_option( null, 'seocart_network', 1 ); // Expect: SEOCart.DRY.RawOptionWrite.Found
update_network_option( null, 'seocart_network', 2 ); // Expect: SEOCart.DRY.RawOptionWrite.Found
delete_network_option( null, 'seocart_network' ); // Expect: SEOCart.DRY.RawOptionWrite.Found
add_blog_option( 2, 'seocart_currency', 'USD' ); // Expect: SEOCart.DRY.RawOptionWrite.Found
update_blog_option( 2, 'seocart_currency', 'EUR' ); // Expect: SEOCart.DRY.RawOptionWrite.Found
delete_blog_option( 2, 'seocart_currency' ); // Expect: SEOCart.DRY.RawOptionWrite.Found

// The autoload flag is part of a setting's declaration, so rewriting it is a write too.
wp_set_option_autoload( 'seocart_currency', false ); // Expect: SEOCart.DRY.RawOptionWrite.Found
wp_set_options_autoload( array( 'seocart_currency' ), false ); // Expect: SEOCart.DRY.RawOptionWrite.Found
wp_set_option_autoload_values( array( 'seocart_currency' => false ) ); // Expect: SEOCart.DRY.RawOptionWrite.Found

\update_option( 'seocart_currency', 'GBP' ); // Expect: SEOCart.DRY.RawOptionWrite.Found
Update_Option( 'seocart_currency', 'GBP' ); // Expect: SEOCart.DRY.RawOptionWrite.Found
update_option /* Spacing does not hide a call. */ ( 'seocart_currency', 'GBP' ); // Expect: SEOCart.DRY.RawOptionWrite.Found
$first_class_callable = update_option( ... ); // Expect: SEOCart.DRY.RawOptionWrite.Found

// Look-alikes: reads, other APIs and other people's functions. None of these may be reported.

$currency = get_option( 'seocart_currency', 'USD' );
$network  = get_site_option( 'seocart_network' );

set_transient( 'seocart_rates', $rates, HOUR_IN_SECONDS );
update_user_meta( $user_id, 'seocart_dismissed', 1 );
update_post_meta( $post_id, 'seocart_weight', 12 );

$settings->update_option( 'currency', 'USD' );
$settings?->delete_option( 'currency' );
SettingsStore::update_option( 'currency', 'USD' );
Vendor\Library\update_option( 'currency', 'USD' );
namespace\update_option( 'currency', 'USD' );

$label    = 'update_option';
$constant = UPDATE_OPTION;
$index    = $map['update_option'];

$store = new class() {
	/**
	 * A method declaration that shares the name is not a call to the WordPress function.
	 *
	 * @param string $name  Setting name.
	 * @param mixed  $value Setting value.
	 */
	public function update_option( $name, $value ) {
		return 'update_option( ' . $name . ' ) is only text here: ' . $value;
	}
};
