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
 * Description:       A free, open-source store plugin in early development. Version 0.1.0 is a development foundation and does not sell anything yet.
 * Version:           0.1.0
 * Requires at least: 7.1
 * Requires PHP:      8.3
 * Author:            Cirkuit
 * Author URI:        https://github.com/cirkuitnet
 * License:           GPLv3 or later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       seocart
 */

/*
 * This file does six things and nothing else: it checks the PHP and WordPress
 * versions, registers the class autoloader, loads the bundled Action Scheduler, hooks the
 * kernel to `plugins_loaded`, and registers the kernel's activation and deactivation hooks.
 * The last two must be registered here, at file scope: WordPress activates a plugin in a
 * request where it includes the plugin file after `plugins_loaded` has already fired.
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
 * Runs on `admin_notices` or `network_admin_notices`, long after `init`, so translating here is safe.
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
	// A network-activated plugin is managed from Network Admin, which fires only this hook.
	add_action( 'network_admin_notices', 'seocart_render_requirements_notice' );
	return;
}

/*
 * A first-party PSR-4 autoloader for the `SEOCart\` namespace. Composer's autoloader
 * is a development tool here: the release zip ships without `vendor/`, and loading
 * classes the same way in development and in production keeps the two from diverging.
 * Requiring plain class-name segments keeps a crafted name from traversing outside `src/`.
 */
spl_autoload_register(
	static function ( $class_name ) {
		if ( 0 !== strncmp( $class_name, 'SEOCart\\', 8 ) ) {
			return;
		}

		if ( 1 !== preg_match( '/^SEOCart(?:\\\\[A-Za-z_][A-Za-z0-9_]*)+$/D', $class_name ) ) {
			return;
		}

		$file = __DIR__ . '/src/' . str_replace( '\\', '/', substr( $class_name, 8 ) ) . '.php';

		if ( is_file( $file ) ) {
			require $file;
		}
	}
);

/*
 * Action Scheduler, the background-job library, bundled without a namespace prefix. Every
 * plugin that bundles a copy registers it at `plugins_loaded` priority 0, and the newest
 * registered copy is initialised at priority 1, so it must be required here, while this file
 * is included. Required from the kernel's `plugins_loaded` callback, which runs at priority
 * 10, it would register too late: this copy would take no part in choosing the newest, and
 * which copy ran would depend on the order plugins load. The file loads one class and adds
 * two callbacks; SEOCart does not call the library before `action_scheduler_init`.
 */
require_once __DIR__ . '/vendor-scoped/woocommerce/action-scheduler/action-scheduler.php';

add_action( 'plugins_loaded', array( 'SEOCart\\Platform\\Kernel\\Kernel', 'boot' ) );
register_activation_hook( __FILE__, array( 'SEOCart\\Platform\\Kernel\\Kernel', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'SEOCart\\Platform\\Kernel\\Kernel', 'deactivate' ) );
