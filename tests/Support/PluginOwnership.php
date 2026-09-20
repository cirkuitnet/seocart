<?php
/**
 * PluginOwnership: decides whether a file, a callback or a call stack belongs to SEOCart
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support;

/**
 * Answers one question for every idle-budget probe: is this SEOCart's?
 *
 * A budget is only meaningful if it counts what the plugin ships and nothing else. Three
 * facts identify shipped code: it lives under the plugin directory, its classes are under
 * the `SEOCart\` namespace, and its functions start with `seocart_`. The test harness lives
 * under the same directory and the same root namespace, so it is subtracted: `vendor/`, plus
 * every namespace and directory that composer.json declares under `autoload-dev`. Reading
 * that declaration, instead of repeating it here, keeps the two from drifting apart.
 *
 * Nothing here calls WordPress, so every rule is unit-tested by passing data in.
 *
 * @since 0.1.0
 */
final class PluginOwnership {

	/**
	 * The root namespace of every SEOCart class.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const NAMESPACE_PREFIX = 'SEOCart\\';

	/**
	 * The prefix of every SEOCart function in the global namespace.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const FUNCTION_PREFIX = 'seocart_';

	/**
	 * Composer's dependency directory: development tooling, never shipped.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const COMPOSER_VENDOR_DIRECTORY = 'vendor/';

	/**
	 * The call-stack frames that WordPress prints with a file path as their argument.
	 *
	 * @since 0.1.0
	 *
	 * @var list<string>
	 */
	private const INCLUDE_FRAMES = array( 'include', 'include_once', 'require', 'require_once' );

	/**
	 * Absolute plugin directory, with forward slashes and a trailing slash.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private string $pluginDirectory;

	/**
	 * Namespace prefixes that hold development code, each with a trailing namespace separator.
	 *
	 * @since 0.1.0
	 *
	 * @var list<string>
	 */
	private array $developmentNamespaces;

	/**
	 * Directories that hold development code, relative to the plugin directory, each with a trailing slash.
	 *
	 * @since 0.1.0
	 *
	 * @var list<string>
	 */
	private array $developmentDirectories;

	/**
	 * Describes a plugin checkout.
	 *
	 * @since 0.1.0
	 *
	 * @param string                $pluginDirectory Absolute path of the plugin directory.
	 * @param array<string, string> $developmentCode Namespace prefix => directory relative to the plugin
	 *                                               directory, in the shape of Composer's `autoload-dev.psr-4`.
	 */
	public function __construct( string $pluginDirectory, array $developmentCode ) {
		$this->pluginDirectory        = rtrim( self::resolvePath( $pluginDirectory ), '/' ) . '/';
		$this->developmentNamespaces  = array();
		$this->developmentDirectories = array( self::COMPOSER_VENDOR_DIRECTORY );

		foreach ( $developmentCode as $namespace => $directory ) {
			$this->developmentNamespaces[]  = trim( $namespace, '\\' ) . '\\';
			$this->developmentDirectories[] = trim( self::normalizePath( $directory ), '/' ) . '/';
		}
	}

	/**
	 * Describes the plugin checkout whose composer.json declares the development code.
	 *
	 * @since 0.1.0
	 *
	 * @param string $pluginDirectory Absolute path of the plugin directory.
	 * @return self The ownership rules for that checkout.
	 */
	public static function fromComposerManifest( string $pluginDirectory ): self {
		$manifest = json_decode( (string) file_get_contents( $pluginDirectory . '/composer.json' ), true, 512, JSON_THROW_ON_ERROR );

		return new self( $pluginDirectory, $manifest['autoload-dev']['psr-4'] ?? array() );
	}

	/**
	 * Shortens an absolute path to one relative to the plugin directory, for a readable report.
	 *
	 * @since 0.1.0
	 *
	 * @param string $file Absolute path of a file.
	 * @return string The path relative to the plugin directory when the file is inside it, otherwise
	 *                the path unchanged. Forward slashes either way.
	 */
	public function relativePath( string $file ): string {
		$file = self::resolvePath( $file );

		return str_starts_with( $file, $this->pluginDirectory ) ? substr( $file, strlen( $this->pluginDirectory ) ) : $file;
	}

	/**
	 * Tells whether a file is shipped plugin code.
	 *
	 * @since 0.1.0
	 *
	 * @param string $file Absolute path of the file.
	 * @return bool True when the file is under the plugin directory and outside every development directory.
	 */
	public function ownsFile( string $file ): bool {
		$file = self::resolvePath( $file );

		if ( ! str_starts_with( $file, $this->pluginDirectory ) ) {
			return false;
		}

		$relative = substr( $file, strlen( $this->pluginDirectory ) );

		foreach ( $this->developmentDirectories as $directory ) {
			if ( str_starts_with( $relative, $directory ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Tells whether a class or function name is shipped plugin code.
	 *
	 * @since 0.1.0
	 *
	 * @param string $symbol A class name, a function name, or `Class::method`.
	 * @return bool True for a name under the plugin namespace, development namespaces excepted,
	 *              and for a function that carries the plugin prefix.
	 */
	public function ownsSymbol( string $symbol ): bool {
		// Class and function names are case-insensitive in PHP.
		return 0 === stripos( ltrim( $symbol, '\\' ), self::FUNCTION_PREFIX ) || $this->ownsClass( $symbol );
	}

	/**
	 * Tells whether a class name is shipped plugin code.
	 *
	 * @since 0.1.0
	 *
	 * @param string $symbol A class name, or `Class::method`.
	 * @return bool True for a name under the plugin namespace, development namespaces excepted.
	 */
	private function ownsClass( string $symbol ): bool {
		$symbol = ltrim( $symbol, '\\' );

		if ( 0 !== stripos( $symbol, self::NAMESPACE_PREFIX ) ) {
			return false;
		}

		foreach ( $this->developmentNamespaces as $namespace ) {
			if ( 0 === stripos( $symbol, $namespace ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Tells whether a hook callback is shipped plugin code.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $callback The callback as WordPress stores it: a function name, a `Class::method`
	 *                        string, an array of object or class name and method, a closure, or an
	 *                        invokable object.
	 * @return bool True for a method of a plugin class, a plugin function, and a closure written in a plugin file.
	 */
	public function ownsCallback( $callback ): bool {
		if ( is_string( $callback ) ) {
			return $this->ownsSymbol( $callback );
		}

		if ( is_array( $callback ) && isset( $callback[0] ) ) {
			return $this->ownsSymbol( is_object( $callback[0] ) ? get_class( $callback[0] ) : (string) $callback[0] );
		}

		if ( $callback instanceof \Closure ) {
			// A closure belongs to the file it is written in, whatever it is bound to.
			return $this->ownsFile( (string) ( new \ReflectionFunction( $callback ) )->getFileName() );
		}

		if ( is_object( $callback ) ) {
			return $this->ownsSymbol( get_class( $callback ) );
		}

		return false;
	}

	/**
	 * Tells whether shipped plugin code appears anywhere in a call stack.
	 *
	 * The stack is the one-line summary WordPress records with every query when SAVEQUERIES is
	 * on: frames separated by a comma and a space, outermost first. A query is the plugin's when
	 * any frame is, because the plugin is then what caused WordPress to run it.
	 *
	 * One stack cannot be attributed: a query issued at file scope by a plugin file that WordPress
	 * loaded from inside its own content directory, because WordPress shortens that path.
	 *
	 * @since 0.1.0
	 *
	 * @param string $caller The call-stack summary, as produced by wp_debug_backtrace_summary().
	 * @return bool True when at least one frame is shipped plugin code.
	 */
	public function ownsCaller( string $caller ): bool {
		foreach ( explode( ', ', $caller ) as $frame ) {
			if ( $this->ownsFrame( $frame ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Tells whether one frame of a call-stack summary is shipped plugin code.
	 *
	 * @since 0.1.0
	 *
	 * @param string $frame One frame: `function`, `Class->method`, `Class::method`, `do_action('hook')`
	 *                      or `require_once('path')`.
	 * @return bool True when the frame is shipped plugin code.
	 */
	private function ownsFrame( string $frame ): bool {
		$parts    = explode( "('", $frame, 2 );
		$name     = $parts[0];
		$argument = isset( $parts[1] ) ? rtrim( $parts[1], "')" ) : '';

		if ( in_array( $name, self::INCLUDE_FRAMES, true ) ) {
			return $this->ownsFile( $argument );
		}

		/*
		 * The argument of any other frame is a hook name. It is ignored on purpose: a third-party
		 * callback that queries on a `seocart_` hook is not the plugin querying.
		 *
		 * PHP 8.4 names a closure after the place it was written: `{closure:Class::method():12}`
		 * inside a function or method, `{closure:/path/to/file.php:12}` at file scope. That place
		 * is judged on its own, as a name or as a file, and then removed: a closure belongs to
		 * where it was written, whatever it is bound to, and a directory called
		 * `seocart_something` must not pass for a function. Earlier PHP versions name a closure
		 * `{closure}` or `Name\Space\{closure}`, which only the name test can attribute.
		 */
		if ( 1 === preg_match( '/\{closure:([^{}]+):\d+\}/', $name, $matches ) ) {
			$written_in = $matches[1];

			if ( str_ends_with( $written_in, '()' ) ? $this->ownsName( substr( $written_in, 0, -2 ) ) : $this->ownsFile( $written_in ) ) {
				return true;
			}

			$name = str_replace( $matches[0], '', $name );
		}

		return $this->ownsName( $name );
	}

	/**
	 * Tells whether the name in a frame, a function or a method with its class, is shipped plugin code.
	 *
	 * A method belongs to its class, whatever it is called: `Acme\Bridge->seocart_sync` is
	 * third-party code. The function prefix therefore counts only in a name without a class.
	 *
	 * @since 0.1.0
	 *
	 * @param string $name `function`, `Class->method`, `Class::method` or `Name\Space\{closure}`.
	 * @return bool True when the name is shipped plugin code.
	 */
	private function ownsName( string $name ): bool {
		$is_method = str_contains( $name, '->' ) || str_contains( $name, '::' );
		$symbols   = preg_split( '/[^A-Za-z0-9_\\\\]+/', $name, -1, PREG_SPLIT_NO_EMPTY );

		foreach ( false === $symbols ? array() : $symbols as $symbol ) {
			if ( $is_method ? $this->ownsClass( $symbol ) : $this->ownsSymbol( $symbol ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Rewrites a path the way the plugin directory is written, so that the two can be compared.
	 *
	 * The plugin directory is resolved through realpath(). The path in a call stack is whatever
	 * was handed to `require`: Composer includes `<plugin>/vendor/composer/../../src/Foo.php`,
	 * and a checkout reached through a symbolic link is named by the link. A file that exists
	 * is resolved the same way; for one that does not, the `.` and `..` segments are folded.
	 *
	 * A relative path is left as it is. WordPress shortens the paths under its own directories
	 * in a call stack, and resolving one of those against the working directory, which is the
	 * plugin directory during a test run, could attribute it to the plugin by accident.
	 *
	 * @since 0.1.0
	 *
	 * @param string $path A file system path.
	 * @return string The resolved path, with forward slashes.
	 */
	private static function resolvePath( string $path ): string {
		$path = self::normalizePath( $path );

		if ( 1 !== preg_match( '#^(?:/|[A-Za-z]:/)#', $path ) ) {
			return $path;
		}

		$resolved = realpath( $path );

		if ( false !== $resolved ) {
			return self::normalizePath( $resolved );
		}

		$folded = array();

		foreach ( explode( '/', $path ) as $segment ) {
			if ( '..' === $segment && count( $folded ) > 1 ) {
				array_pop( $folded );
			} elseif ( '.' !== $segment && '..' !== $segment ) {
				$folded[] = $segment;
			}
		}

		return implode( '/', $folded );
	}

	/**
	 * Rewrites a path with forward slashes, so that paths compare equal on every platform.
	 *
	 * @since 0.1.0
	 *
	 * @param string $path A file system path.
	 * @return string The same path with forward slashes only.
	 */
	private static function normalizePath( string $path ): string {
		return str_replace( '\\', '/', $path );
	}
}
