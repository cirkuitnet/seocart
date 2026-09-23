<?php
/**
 * LibraryShare: which part of an idle request's cost is the bundled Action Scheduler's
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support;

/**
 * Splits what the plugin loads and hooks on an idle request into its own share and the bundled library's.
 *
 * Owns one fact: what counts as the bundled Action Scheduler's cost. SEOCart ships the library
 * unprefixed under DIRECTORY and requires it from its main file, so every request pays for it,
 * and the idle-request budgets state that share apart from the plugin's own:
 *
 * - files: those under DIRECTORY are the library's, the other shipped files the plugin's;
 * - hooks: the library's are every registration that loading the plugin added to the hook
 *   table (the with-plugin run's registrations minus the control run's) and that is not the
 *   plugin's own. A closure written in a library file is the library's, although it lives
 *   under the plugin directory.
 *
 * Nothing here calls WordPress except describeAll(), which reads the hook table it is given.
 *
 * @since 0.1.0
 */
final class LibraryShare {

	/**
	 * Where the bundled library lives, relative to the plugin directory.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const DIRECTORY = 'vendor-scoped/woocommerce/action-scheduler/';

	/**
	 * Splits shipped files into the plugin's and the library's.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, int> $files Size of each file, keyed by its path relative to the plugin directory.
	 * @return array{plugin: array<string, int>, library: array<string, int>} The two shares.
	 */
	public static function splitFiles( array $files ): array {
		$split = array(
			'plugin'  => array(),
			'library' => array(),
		);

		foreach ( $files as $path => $bytes ) {
			$split[ str_starts_with( (string) $path, self::DIRECTORY ) ? 'library' : 'plugin' ][ (string) $path ] = $bytes;
		}

		return $split;
	}

	/**
	 * Keeps the plugin's own registrations out of those the ownership rules give it: a closure written in a library file is the library's.
	 *
	 * @since 0.1.0
	 *
	 * @param list<array{hook: string, priority: int, callback: string}> $pluginHooks The registrations the ownership rules give the plugin.
	 * @return list<array{hook: string, priority: int, callback: string}> The plugin's own.
	 */
	public static function ownHooks( array $pluginHooks ): array {
		return array_values( array_filter( $pluginHooks, static fn( array $registration ): bool => ! str_starts_with( $registration['callback'], 'closure at ' . self::DIRECTORY ) ) );
	}

	/**
	 * Splits hook registrations into the plugin's own and the library's.
	 *
	 * @since 0.1.0
	 *
	 * @param list<array{hook: string, priority: int, callback: string}> $pluginHooks The registrations the ownership rules give the plugin.
	 * @param string[]                                                   $withPlugin  Every registration of the with-plugin run, as describeAll() writes them.
	 * @param string[]                                                   $control     Every registration of the control run.
	 * @return array{plugin: list<string>, library: list<string>} The two shares, one line per registration.
	 */
	public static function splitHooks( array $pluginHooks, array $withPlugin, array $control ): array {
		$own = array();

		foreach ( self::ownHooks( $pluginHooks ) as $registration ) {
			$own[] = self::line( $registration['hook'], $registration['priority'], $registration['callback'] );
		}

		$library = array();
		$left    = array_count_values( $control );

		foreach ( $withPlugin as $line ) {
			if ( ( $left[ $line ] ?? 0 ) > 0 ) {
				--$left[ $line ];

				continue;
			}

			if ( ! in_array( $line, $own, true ) ) {
				$library[] = $line;
			}
		}

		return array(
			'plugin'  => $own,
			'library' => $library,
		);
	}

	/**
	 * Describes every registration in a hook table, one line each.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $filters The hook table, shaped like the `$wp_filter` global.
	 * @param BootstrapProbes      $probes  Names the callbacks, closures relative to the plugin directory.
	 * @return list<string> `hook @priority  callback` per registration.
	 */
	public static function describeAll( array $filters, BootstrapProbes $probes ): array {
		$lines = array();

		foreach ( $filters as $hook => $registrations ) {
			$priorities = is_object( $registrations ) && isset( $registrations->callbacks ) ? $registrations->callbacks : $registrations;

			foreach ( (array) $priorities as $priority => $callbacks ) {
				foreach ( (array) $callbacks as $callback ) {
					if ( isset( $callback['function'] ) ) {
						$lines[] = self::line( (string) $hook, (int) $priority, $probes->describeCallback( $callback['function'] ) );
					}
				}
			}
		}

		return $lines;
	}

	/**
	 * Writes one registration as a line.
	 *
	 * @since 0.1.0
	 *
	 * @param string $hook     The hook.
	 * @param int    $priority The priority.
	 * @param string $callback The callback's description.
	 * @return string The line.
	 */
	private static function line( string $hook, int $priority, string $callback ): string {
		return sprintf( '%s @%d  %s', $hook, $priority, $callback );
	}
}
