<?php
/**
 * Fixture: the plugin's main file is shipped code, so the DRY rules apply to it
 *
 * This file deliberately has no plugin header: WordPress must never list it as a plugin.
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

// The real main file's admin notice, verbatim. It must not be reported.
wp_admin_notice(
	$message,
	array(
		'type' => 'error',
	)
);

// Violations.

$schema = array( 'type' => 'string' ); // Expect: SEOCart.DRY.SchemaLiteral.Type

update_option( 'seocart_version', '0.1.0' ); // Expect: SEOCart.DRY.RawOptionWrite.Found

// No directory named Interfaces here, so this is not the Money rule's business.
$total = $subtotal->add( $shipping );
