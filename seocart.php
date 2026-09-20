<?php
/**
 * SEOCart
 *
 * @package           SEOCart
 * @license           GPL-3.0-or-later
 *
 * @wordpress-plugin
 * Plugin Name:       SEOCart
 * Plugin URI:        https://github.com/cirkuitnet/seocart
 * Description:       A free, open-source store for WordPress: products, cart, checkout, orders and payments, with nothing locked away.
 * Version:           0.1.0
 * Requires at least: 7.1
 * Requires PHP:      8.3
 * Author:            Cirkuit
 * Author URI:        https://github.com/cirkuitnet
 * License:           GPLv3 or later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       seocart
 * Domain Path:       /languages
 */

/*
 * This file does three things and nothing else: it checks the PHP and WordPress
 * versions, registers the class autoloader, and hooks the kernel to `plugins_loaded`.
 * It must stay parsable by PHP versions older than the supported floor, so that an
 * unsupported site sees a notice instead of a parse error. No translation call, no
 * database access and no object construction may happen at file scope.
 */

defined( 'ABSPATH' ) || exit;

/**
 * The plugin version. Must match the `Version` header above.
 *
 * @since 0.1.0
 */
define( 'SEOCART_VERSION', '0.1.0' );

/**
 * Absolute path to this file, the plugin's main file.
 *
 * @since 0.1.0
 */
define( 'SEOCART_PLUGIN_FILE', __FILE__ );

/**
 * The lowest PHP version the plugin runs on. Must match the `Requires PHP` header.
 *
 * @since 0.1.0
 */
define( 'SEOCART_MINIMUM_PHP_VERSION', '8.3' );

/**
 * The lowest WordPress version the plugin runs on. Must match the `Requires at least` header.
 *
 * @since 0.1.0
 */
define( 'SEOCART_MINIMUM_WP_VERSION', '7.1' );

/**
 * Determines which platform requirement, if any, the site does not meet.
 *
 * WordPress checks the `Requires PHP` and `Requires at least` headers when a plugin
 * is activated, but not when PHP or WordPress is downgraded underneath an active
 * plugin. This check covers that case.
 *
 * @since 0.1.0
 *
 * @return string Either 'php', 'wp', or an empty string when every requirement is met.
 */
function seocart_unmet_requirement() {
	// @phpstan-ignore if.alwaysFalse (PHPStan assumes the supported floor; this guard exists for sites below it.)
	if ( version_compare( PHP_VERSION, SEOCART_MINIMUM_PHP_VERSION, '<' ) ) {
		return 'php';
	}

	// Compare the release number only, so that 7.1-RC1 and 7.1-alpha builds satisfy 7.1.
	$wp_release = preg_replace( '/[^0-9.].*$/', '', get_bloginfo( 'version' ) );

	if ( version_compare( $wp_release, SEOCART_MINIMUM_WP_VERSION, '<' ) ) {
		return 'wp';
	}

	return '';
}

/**
 * Displays an admin notice explaining why SEOCart did not load.
 *
 * Runs on `admin_notices`, long after `init`, so translating here is safe.
 *
 * @since 0.1.0
 */
function seocart_render_requirements_notice() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	if ( 'php' === seocart_unmet_requirement() ) {
		$message = sprintf(
			/* translators: 1: Required PHP version number, 2: Current PHP version number. */
			__( 'SEOCart requires PHP %1$s or newer and is not running. This site runs PHP %2$s.', 'seocart' ),
			SEOCART_MINIMUM_PHP_VERSION,
			PHP_VERSION
		);
	} else {
		$message = sprintf(
			/* translators: 1: Required WordPress version number, 2: Current WordPress version number. */
			__( 'SEOCart requires WordPress %1$s or newer and is not running. This site runs WordPress %2$s.', 'seocart' ),
			SEOCART_MINIMUM_WP_VERSION,
			get_bloginfo( 'version' )
		);
	}

	wp_admin_notice(
		$message,
		array(
			'type' => 'error',
		)
	);
}

if ( '' !== seocart_unmet_requirement() ) {
	add_action( 'admin_notices', 'seocart_render_requirements_notice' );
	return;
}

/*
 * A first-party PSR-4 autoloader for the `SEOCart\` namespace. Composer's autoloader
 * is a development tool here: the release zip ships without `vendor/`, and loading
 * classes the same way in development and in production keeps the two from diverging.
 */
spl_autoload_register(
	static function ( $class_name ) {
		if ( 0 !== strncmp( $class_name, 'SEOCart\\', 8 ) ) {
			return;
		}

		$file = __DIR__ . '/src/' . str_replace( '\\', '/', substr( $class_name, 8 ) ) . '.php';

		if ( is_file( $file ) ) {
			require $file;
		}
	}
);

add_action( 'plugins_loaded', array( 'SEOCart\\Platform\\Kernel\\Kernel', 'boot' ) );
