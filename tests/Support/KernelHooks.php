<?php
/**
 * KernelHooks: takes the kernel's own callbacks off hooks a test wires itself
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support;

/**
 * Finds the callbacks the kernel added to a hook, and removes them from the hooks a test is about to wire with its own services.
 *
 * Owns one fact: which callbacks are the kernel's. The integration suite loads the plugin, so the
 * kernel has hooked the production wiring into the test process: the operations' REST routes and
 * abilities, and the job runner. A test that builds its own adapters or runner over fixtures, and
 * hooks them to the same hooks, would otherwise get both: the production routes beside its own,
 * or two runners for one job. The kernel's callbacks are the closures written in the module
 * wiring; nothing else is removed, core's and the test's own callbacks stay. The test framework
 * puts every hook back after the test.
 *
 * @since 0.1.0
 */
final class KernelHooks {

	/**
	 * The file the kernel's callbacks are written in, relative to the plugin directory.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const MODULES = 'src/Platform/Kernel/Modules.php';

	/**
	 * Removes the kernel's callbacks from hooks.
	 *
	 * @since 0.1.0
	 *
	 * @param string ...$hooks The hooks.
	 * @return int How many callbacks were removed.
	 */
	public static function detach( string ...$hooks ): int {
		global $wp_filter;

		$removed = 0;

		foreach ( $hooks as $hook ) {
			if ( ! isset( $wp_filter[ $hook ] ) ) {
				continue;
			}

			foreach ( $wp_filter[ $hook ]->callbacks as $priority => $callbacks ) {
				foreach ( $callbacks as $callback ) {
					if ( self::isKernels( $callback['function'] ) ) {
						remove_filter( $hook, $callback['function'], $priority );
						++$removed;
					}
				}
			}
		}

		return $removed;
	}

	/**
	 * Returns the kernel's callbacks on a hook, in the order WordPress runs them, so a test can run
	 * the kernel's own work on a hook without firing everything else hooked there.
	 *
	 * @since 0.1.0
	 *
	 * @param string $hook The hook.
	 * @return list<callable> The callbacks.
	 */
	public static function callbacks( string $hook ): array {
		global $wp_filter;

		$found = array();

		if ( ! isset( $wp_filter[ $hook ] ) ) {
			return $found;
		}

		$priorities = $wp_filter[ $hook ]->callbacks;

		ksort( $priorities );

		foreach ( $priorities as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				if ( self::isKernels( $callback['function'] ) ) {
					$found[] = $callback['function'];
				}
			}
		}

		return $found;
	}

	/**
	 * Tells whether a callback is one the kernel added: a closure written in the module wiring.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $callback The callback.
	 * @return bool True for the kernel's.
	 */
	public static function isKernels( $callback ): bool {
		if ( ! $callback instanceof \Closure ) {
			return false;
		}

		$file = str_replace( '\\', '/', (string) ( new \ReflectionFunction( $callback ) )->getFileName() );

		return str_ends_with( $file, '/' . self::MODULES );
	}
}
