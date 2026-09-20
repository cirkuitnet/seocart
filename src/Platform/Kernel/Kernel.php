<?php
/**
 * Kernel: the single entry point WordPress calls to start SEOCart
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Kernel;

defined( 'ABSPATH' ) || exit;

/**
 * Boots the plugin on `plugins_loaded`.
 *
 * The kernel owns one fact: what happens, and in which order, when SEOCart starts.
 * An idle request — one that touches no commerce — must pay for nothing beyond this
 * call, so everything registered from here is lazy: factories and cheap closures,
 * never a constructed service graph.
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
	 * Boots SEOCart. Hooked to `plugins_loaded` by the plugin's main file.
	 *
	 * Booting twice in one request is a no-op, so a second `plugins_loaded` pass — which
	 * some test harnesses and must-use loaders trigger — cannot double-register anything.
	 *
	 * The repository bootstrap registers nothing here. The boot option, the schema gate,
	 * Safe Mode, the container and the module providers arrive with the platform foundation.
	 *
	 * @since 0.1.0
	 */
	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}

		self::$booted = true;
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
}
