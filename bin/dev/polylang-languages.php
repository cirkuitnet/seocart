<?php
/**
 * Creates the three test languages of a disposable instance
 *
 * Development tooling, never shipped and never loaded by the plugin. `provision-site.sh
 * --with-polylang` runs it inside the disposable instance with `wp eval-file`, once
 * Polylang is active. Safe to repeat: a language that exists is left alone.
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

( static function (): void {
	if ( ! function_exists( 'PLL' ) || ! isset( PLL()->model->languages ) ) {
		WP_CLI::error( 'Polylang 3.7 or newer is required: PLL()->model->languages is missing.' );
	}

	$languages = PLL()->model->languages;
	$existing  = wp_list_pluck( $languages->get_list(), 'locale' );

	// The first language added becomes the default. en_US and en_GB share Polylang's
	// predefined code "en", and codes must be unique, so en_GB gets one of its own.
	$wanted = array(
		array(
			'locale'     => 'en_US',
			'slug'       => 'en',
			'name'       => 'English (US)',
			'flag'       => 'us',
			'rtl'        => false,
			'term_group' => 0,
		),
		array(
			'locale'     => 'en_GB',
			'slug'       => 'en-gb',
			'name'       => 'English (UK)',
			'flag'       => 'gb',
			'rtl'        => false,
			'term_group' => 1,
		),
		array(
			'locale'     => 'de_DE',
			'slug'       => 'de',
			'name'       => 'Deutsch',
			'flag'       => 'de',
			'rtl'        => false,
			'term_group' => 2,
		),
	);

	foreach ( $wanted as $language ) {
		if ( in_array( $language['locale'], $existing, true ) ) {
			WP_CLI::log( 'Language exists: ' . $language['locale'] );
			continue;
		}

		$result = $languages->add( $language );
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $language['locale'] . ': ' . implode( ' ', $result->get_error_messages() ) );
		}

		WP_CLI::log( 'Language added: ' . $language['locale'] );
	}
} )();
