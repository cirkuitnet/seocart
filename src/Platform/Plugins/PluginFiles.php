<?php
/**
 * PluginFiles: the plugin a file was loaded from
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Plugins;

defined( 'ABSPATH' ) || exit;

/**
 * Names the plugin a file was loaded from.
 *
 * Owns one fact: how SEOCart tells which plugin a class or a throwable came from, so a refused
 * registration, a payment gateway's or a checkout field's, can name it. WordPress's
 * plugin_basename() decides, so a plugin directory linked into the plugins directory from
 * elsewhere is known by the name it has there. A file outside the plugins directories belongs to
 * no plugin.
 *
 * @since 0.2.0
 */
final class PluginFiles {

	/**
	 * Holds nothing: the class only names.
	 *
	 * @since 0.2.0
	 */
	private function __construct() {
	}

	/**
	 * Returns the slug of the plugin a file was loaded from: its directory under the plugins directory.
	 *
	 * @since 0.2.0
	 *
	 * @param string $file The file's path.
	 * @return string|null The slug, such as `seocart-gateway-for-stripe`; null for no plugin.
	 */
	public static function slugOf( string $file ): ?string {
		$path     = wp_normalize_path( $file );
		$relative = plugin_basename( $path );

		if ( '' === $path || trim( $path, '/' ) === $relative ) {
			return null;
		}

		$slug = strstr( $relative, '/', true );

		return false === $slug ? basename( $relative, '.php' ) : $slug;
	}
}
