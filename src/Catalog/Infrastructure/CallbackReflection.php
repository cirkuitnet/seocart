<?php
/**
 * CallbackReflection: reflects a WordPress hook callback, whatever shape it was registered in
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Catalog\Infrastructure;

defined( 'ABSPATH' ) || exit;

/**
 * Reflects a callback as `$wp_filter` stores it — a Closure, a plain function name, a
 * `Class::method` string, a `[class-or-object, method]` array, or an invokable object — and
 * resolves a file's real path.
 *
 * Owns one fact: how to turn any of the five shapes WordPress accepts for a callback into its
 * defining file and a name worth printing. WordPressPostGateway's debug-log listing and
 * ForeignHooksCheck's doctor report both classify a callback as foreign or not by its file, each
 * against its own notion of "own" and its own formatting; both reach that file through this class,
 * so the reflection is written once.
 *
 * @since 0.1.0
 */
final class CallbackReflection {

	/**
	 * Reflects a callback, whichever of the five shapes it was registered in.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $callback The callback, as WordPress stores it.
	 * @return array{name: string, reflection: \ReflectionFunctionAbstract}|null The name to print
	 *         it with, and its reflection; null when it is not a callback reflection can reach.
	 */
	public static function of( mixed $callback ): ?array {
		try {
			if ( $callback instanceof \Closure || ( is_string( $callback ) && ! str_contains( $callback, '::' ) ) ) {
				$reflection = new \ReflectionFunction( $callback );
				$name       = $callback instanceof \Closure ? 'closure' : $callback;
			} elseif ( is_array( $callback ) && isset( $callback[0], $callback[1] ) ) {
				$reflection = new \ReflectionMethod( $callback[0], (string) $callback[1] );
				$name       = ( is_object( $callback[0] ) ? get_class( $callback[0] ) . '->' : $callback[0] . '::' ) . $callback[1];
			} elseif ( is_string( $callback ) ) {
				list( $class, $method ) = explode( '::', $callback, 2 );
				$reflection             = new \ReflectionMethod( $class, $method );
				$name                   = $callback;
			} elseif ( is_object( $callback ) ) {
				$reflection = new \ReflectionMethod( $callback, '__invoke' );
				$name       = get_class( $callback ) . '->__invoke';
			} else {
				return null;
			}
		} catch ( \ReflectionException $unknown ) {
			return null;
		}

		return array(
			'name'       => $name,
			'reflection' => $reflection,
		);
	}

	/**
	 * Returns a path with every symbolic link resolved, normalized; the path itself, normalized,
	 * when it cannot be resolved (it does not exist, such as a fixture path in a test).
	 *
	 * A directory keeps its trailing slash, so a comparison by prefix stops at its name.
	 *
	 * @since 0.1.0
	 *
	 * @param string $path A file, or a directory ending in a slash.
	 * @return string The path.
	 */
	public static function realPath( string $path ): string {
		$real       = realpath( $path );
		$normalized = wp_normalize_path( false === $real ? $path : $real );

		return str_ends_with( $path, '/' ) ? rtrim( $normalized, '/' ) . '/' : $normalized;
	}
}
