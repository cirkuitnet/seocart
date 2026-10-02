<?php
/**
 * Refuses to let a seed run on a site that is not marked disposable
 *
 * Test tooling, never shipped and never loaded by the plugin. A script that writes test data into
 * a site with `wp eval-file` requires this file before it writes anything. The site must be marked
 * disposable twice: its environment type (wp_get_environment_type()) is `local` or `development`,
 * and SEOCART_SEED_DISPOSABLE is 1 for the run. The sites bin/dev/provision-site.sh builds set
 * both, and wp-env sets the environment type `local`, so only its run needs the variable given.
 * A site that lacks either is refused with exit code 1, before anything is written.
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

defined( 'ABSPATH' ) || exit;

( static function (): void {
	$environment = wp_get_environment_type();
	$missing     = array();

	if ( ! in_array( $environment, array( 'local', 'development' ), true ) ) {
		$missing[] = 'an environment type of local or development (this site\'s is ' . $environment . ')';
	}

	if ( '1' !== getenv( 'SEOCART_SEED_DISPOSABLE' ) ) {
		$missing[] = 'SEOCART_SEED_DISPOSABLE=1';
	}

	if ( array() !== $missing ) {
		fwrite( STDERR, 'Error: refused, and nothing was written. The seed runs only on a site marked disposable; this run lacks ' . implode( ' and ', $missing ) . ".\n" );
		exit( 1 );
	}
} )();
