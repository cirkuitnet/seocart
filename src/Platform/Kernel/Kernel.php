<?php
/**
 * Kernel: the entry points WordPress calls to start, activate and deactivate SEOCart
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Kernel;

defined( 'ABSPATH' ) || exit;

/**
 * Boots the plugin on `plugins_loaded`, and runs its activation and deactivation.
 *
 * The kernel owns one fact: what happens, and in which order, when WordPress hands control to
 * SEOCart. Booting builds the container, binds every module's factories and adds every module's
 * hooks; it builds no service, reads no option and sends no query. An idle request, one that
 * touches no commerce, pays for this file, the container, the module wiring and the main file.
 *
 * WordPress calls three entry points that cannot be given an object, so the container is held
 * statically here, and only these entry points reach it:
 *
 * - boot(), on `plugins_loaded`;
 * - activate() and deactivate(), on the activation hooks, which run in a request where the plugin
 *   file was included after `plugins_loaded` had already fired. They build the container
 *   themselves and never depend on boot() having run.
 *
 * Nothing else in the plugin may call container(); a test pins that.
 *
 * @since 0.1.0
 */
final class Kernel {

	/**
	 * Whether the kernel has already booted during this request.
	 *
	 * @since 0.1.0
	 *
	 * @var bool
	 */
	private static bool $booted = false;

	/**
	 * The container, once built.
	 *
	 * @since 0.1.0
	 *
	 * @var Container|null
	 */
	private static ?Container $container = null;

	/**
	 * Boots SEOCart. Hooked to `plugins_loaded` by the plugin's main file.
	 *
	 * Booting twice in one request is a no-op, so a second `plugins_loaded` pass — which
	 * some test harnesses and must-use loaders trigger — cannot double-register anything.
	 *
	 * @since 0.1.0
	 */
	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}

		self::$booted = true;

		Modules::subscribe( self::container() );
	}

	/**
	 * Reports whether the kernel has booted during this request.
	 *
	 * @since 0.1.0
	 *
	 * @return bool True once boot() has run.
	 */
	public static function hasBooted(): bool {
		return self::$booted;
	}

	/**
	 * Returns the container, building it and binding every module's factories on the first call.
	 *
	 * Building it adds no hook: that is boot()'s part.
	 *
	 * @since 0.1.0
	 *
	 * @return Container The container.
	 */
	public static function container(): Container {
		if ( null === self::$container ) {
			$container = new Container();

			Modules::register( $container );

			self::$container = $container;
		}

		return self::$container;
	}

	/**
	 * Installs SEOCart on the current site. Hooked to the plugin's activation by the main file.
	 *
	 * WordPress passes whether the activation is network-wide; it changes nothing here, because a
	 * network activation installs the current site only and every other site installs itself on
	 * its next admin, command-line or cron request.
	 *
	 * @since 0.1.0
	 */
	public static function activate(): void {
		self::container()->get( Lifecycle::class )->activate();
	}

	/**
	 * Runs SEOCart's deactivation, which removes nothing. Hooked to the plugin's deactivation by the main file.
	 *
	 * @since 0.1.0
	 */
	public static function deactivate(): void {
		self::container()->get( Lifecycle::class )->deactivate();
	}
}
