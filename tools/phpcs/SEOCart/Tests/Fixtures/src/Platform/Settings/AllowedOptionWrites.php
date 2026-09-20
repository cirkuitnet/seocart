<?php
/**
 * Fixture: the settings module is the one place that may write options directly
 *
 * Nothing here may be reported by SEOCart.DRY.RawOptionWrite: this file lies under the
 * directory that ruleset.xml allow-lists. The other DRY rules still apply to it.
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

add_option( 'seocart_currency', 'USD', '', false );
update_option( 'seocart_currency', 'EUR', false );
delete_option( 'seocart_currency' );
update_site_option( 'seocart_network', 2 );
delete_network_option( null, 'seocart_network' );

$hand_written = array( 'type' => 'string' ); // Expect: SEOCart.DRY.SchemaLiteral.Type
