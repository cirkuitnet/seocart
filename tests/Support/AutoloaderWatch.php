<?php
/**
 * AutoloaderWatch: makes the plugin's own autoloader load the plugin's classes, and records the ones it cannot
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support;

use Composer\Autoload\ClassLoader;

/**
 * Keeps Composer from covering for the autoloader that ships.
 *
 * The release zip has no `vendor/`, so the autoloader in seocart.php is the only one a released
 * site has. In a test process Composer's class loader is registered first, and composer.json
 * maps `SEOCart\` to `src/` as well, so Composer would answer for every plugin class and the
 * plugin's autoloader would never run: a release that cannot find its own classes would pass
 * every test.
 *
 * start() therefore moves Composer's loaders behind the autoloaders registered so far. That
 * alone is not enough, because a plugin autoloader that finds nothing simply lets the request
 * fall through to Composer. So a watch is registered between the two: a plugin class that gets
 * as far as the watch, and that Composer is about to find, is a class the release would not
 * have loaded. It is recorded, and PluginLoadsTest fails on anything recorded.
 *
 * A class that nobody can find is not recorded: asking whether an optional class exists is
 * legitimate. Neither is test or tooling code, which only Composer is meant to load.
 *
 * The state is static for the same reason as in ErrorRecorder: the bootstrap starts the watch
 * and a test reads it, with no object that both could be handed.
 *
 * @since 0.1.0
 */
final class AutoloaderWatch {

	/**
	 * The plugin classes that reached the watch, in the order they were asked for.
	 *
	 * @since 0.1.0
	 *
	 * @var list<string>
	 */
	private static array $missed = array();

	/**
	 * The registered watch, or null while nothing is being watched.
	 *
	 * @since 0.1.0
	 *
	 * @var (\Closure(string): void)|null
	 */
	private static ?\Closure $watch = null;

	/**
	 * Moves Composer's class loaders behind every autoloader registered so far, with the watch in front of them.
	 *
	 * Call it directly after the main plugin file has registered its autoloader.
	 *
	 * @since 0.1.0
	 *
	 * @param array<array-key, ClassLoader> $loaders Composer's class loaders, as ClassLoader::getRegisteredLoaders() returns them.
	 * @param PluginOwnership               $owner   The rules that decide which classes are shipped plugin code.
	 */
	public static function start( array $loaders, PluginOwnership $owner ): void {
		self::stop();

		self::$missed = array();

		self::$watch = static function ( string $class_name ) use ( $loaders, $owner ): void {
			if ( ! $owner->ownsSymbol( $class_name ) ) {
				return;
			}

			foreach ( $loaders as $loader ) {
				if ( false !== $loader->findFile( $class_name ) ) {
					self::$missed[] = $class_name;

					return;
				}
			}
		};

		foreach ( $loaders as $loader ) {
			$loader->unregister();
		}

		spl_autoload_register( self::$watch );

		foreach ( $loaders as $loader ) {
			// False appends the loader instead of putting it back in front.
			$loader->register( false );
		}
	}

	/**
	 * Removes the watch. Composer's loaders stay where start() put them.
	 *
	 * @since 0.1.0
	 */
	public static function stop(): void {
		if ( null !== self::$watch ) {
			spl_autoload_unregister( self::$watch );

			self::$watch = null;
		}
	}

	/**
	 * Tells whether the watch is registered. An empty record proves nothing while it is not.
	 *
	 * @since 0.1.0
	 *
	 * @return bool True between start() and stop().
	 */
	public static function isWatching(): bool {
		return null !== self::$watch;
	}

	/**
	 * Returns the plugin classes that the plugin's own autoloader did not load and Composer did.
	 *
	 * @since 0.1.0
	 *
	 * @return list<string> Class names, in the order they were asked for.
	 */
	public static function missed(): array {
		return self::$missed;
	}
}
