<?php
/**
 * BootstrapProbes: what SEOCart loaded and hooked during a request
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support;

/**
 * The files-and-hooks half of the measurement harness (docs/architecture/performance.md, section 2.1).
 *
 * An eager service graph shows up here long before it shows up as a slow page: as PHP files
 * that an idle request had no reason to parse, and as hook registrations it had no reason to
 * make. The two probes list exactly what belongs to the plugin, so a budget counts the list
 * and a failure prints it.
 *
 * The filtering, the attribution and the report take their data as arguments and call no
 * WordPress function; they are unit-tested. Only the two `loaded…` and `registered…` readers
 * at the end of the class look at the running process.
 *
 * @since 0.1.0
 */
final class BootstrapProbes {

	/**
	 * The rules that decide what is plugin code.
	 *
	 * @since 0.1.0
	 *
	 * @var PluginOwnership
	 */
	private PluginOwnership $owner;

	/**
	 * Creates the probes for a plugin checkout.
	 *
	 * @since 0.1.0
	 *
	 * @param PluginOwnership $owner The rules that decide what is plugin code.
	 */
	public function __construct( PluginOwnership $owner ) {
		$this->owner = $owner;
	}

	/**
	 * Picks the shipped plugin files out of a list of included files.
	 *
	 * @since 0.1.0
	 *
	 * @param string[] $includedFiles Absolute paths, as get_included_files() returns them.
	 * @return array<string, int> Size in bytes of each plugin file, keyed by its path relative to the plugin directory.
	 */
	public function pluginFiles( array $includedFiles ): array {
		$files = array();

		foreach ( $includedFiles as $file ) {
			if ( $this->owner->ownsFile( $file ) ) {
				$files[ $this->owner->relativePath( $file ) ] = is_file( $file ) ? (int) filesize( $file ) : 0;
			}
		}

		ksort( $files );

		return $files;
	}

	/**
	 * Picks the plugin's registrations out of the hook table.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $filters The hook table, shaped like the `$wp_filter` global: hook name =>
	 *                                      WP_Hook, or hook name => priority => callbacks, where each
	 *                                      callback is an array with a 'function' entry.
	 * @return list<array{hook: string, priority: int, callback: string}> One entry per registration that
	 *                                      belongs to the plugin, in hook table order.
	 */
	public function pluginHooks( array $filters ): array {
		$hooks = array();

		foreach ( $filters as $hook => $registrations ) {
			// WordPress keeps each hook in a WP_Hook object, whose public `callbacks` property is the plain array.
			$priorities = is_object( $registrations ) && isset( $registrations->callbacks ) ? $registrations->callbacks : $registrations;

			foreach ( (array) $priorities as $priority => $callbacks ) {
				foreach ( (array) $callbacks as $callback ) {
					if ( isset( $callback['function'] ) && $this->owner->ownsCallback( $callback['function'] ) ) {
						$hooks[] = array(
							'hook'     => (string) $hook,
							'priority' => (int) $priority,
							'callback' => $this->describeCallback( $callback['function'] ),
						);
					}
				}
			}
		}

		return $hooks;
	}

	/**
	 * Names a callback the way a developer would look for it.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $callback The callback as WordPress stores it.
	 * @return string For example `seocart_render_notice`, `SEOCart\Foo::bar`, `SEOCart\Foo->bar`,
	 *                `closure at src/Foo.php:12` or `SEOCart\Foo (invokable)`.
	 */
	public function describeCallback( $callback ): string {
		if ( is_string( $callback ) ) {
			return $callback;
		}

		if ( is_array( $callback ) && isset( $callback[0], $callback[1] ) ) {
			return is_object( $callback[0] )
				? get_class( $callback[0] ) . '->' . $callback[1]
				: $callback[0] . '::' . $callback[1];
		}

		if ( $callback instanceof \Closure ) {
			$reflection = new \ReflectionFunction( $callback );

			return sprintf( 'closure at %s:%d', $this->owner->relativePath( (string) $reflection->getFileName() ), (int) $reflection->getStartLine() );
		}

		if ( is_object( $callback ) ) {
			return get_class( $callback ) . ' (invokable)';
		}

		return '(unrecognized callback)';
	}

	/**
	 * Prints a list of plugin files with their sizes, for a failure message.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, int> $files Size in bytes of each file, keyed by path, as pluginFiles() returns them.
	 * @return string One line per file, then the total.
	 */
	public static function describeFiles( array $files ): string {
		$lines = array();

		foreach ( $files as $path => $bytes ) {
			$lines[] = sprintf( '  %8d bytes  %s', $bytes, $path );
		}

		$lines[] = sprintf( '  %8d bytes  in %d files', array_sum( $files ), count( $files ) );

		return implode( "\n", $lines );
	}

	/**
	 * Prints a list of hook registrations, for a failure message.
	 *
	 * @since 0.1.0
	 *
	 * @param list<array{hook: string, priority: int, callback: string}> $hooks The registrations, as pluginHooks() returns them.
	 * @return string One line per registration, or a note that there are none.
	 */
	public static function describeHooks( array $hooks ): string {
		if ( array() === $hooks ) {
			return '  (no registrations)';
		}

		$lines = array();

		foreach ( $hooks as $registration ) {
			$lines[] = sprintf( '  %s @%d  %s', $registration['hook'], $registration['priority'], $registration['callback'] );
		}

		return implode( "\n", $lines );
	}

	/**
	 * Lists the shipped plugin files this PHP process has included so far.
	 *
	 * The list only ever grows, so a budget on it means something only in a process that served
	 * nothing but the request being measured.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, int> Size in bytes of each plugin file, keyed by its path relative to the plugin directory.
	 */
	public function loadedPluginFiles(): array {
		return $this->pluginFiles( get_included_files() );
	}

	/**
	 * Lists the plugin's registrations in the live WordPress hook table.
	 *
	 * @since 0.1.0
	 *
	 * @return list<array{hook: string, priority: int, callback: string}> One entry per registration that belongs to the plugin.
	 */
	public function registeredPluginHooks(): array {
		global $wp_filter;

		return $this->pluginHooks( (array) $wp_filter );
	}
}
