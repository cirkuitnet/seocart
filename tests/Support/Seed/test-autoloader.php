<?php
/**
 * Loads the test classes into a running WordPress site
 *
 * Test tooling, never shipped and never loaded by the plugin. The plugin's autoloader reads
 * src/ only, so a script that runs inside a site with `wp eval-file` and needs a class of tests/
 * requires this file first. It loads the classes under tests/ and nothing else.
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

defined( 'ABSPATH' ) || exit;

spl_autoload_register(
	static function ( string $class_name ): void {
		$namespace = 'SEOCart\\Tests\\';

		if ( 0 === strncmp( $class_name, $namespace, strlen( $namespace ) ) && 1 === preg_match( '/^[A-Za-z0-9_\\\\]+$/D', $class_name ) ) {
			$file = dirname( __DIR__, 2 ) . '/' . str_replace( '\\', '/', substr( $class_name, strlen( $namespace ) ) ) . '.php';

			if ( is_file( $file ) ) {
				require $file;
			}
		}
	}
);
