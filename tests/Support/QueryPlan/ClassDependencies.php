<?php
/**
 * ClassDependencies: the parent classes and traits the classes of a PHP source take code from
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support\QueryPlan;

/**
 * Finds what each class, trait, interface and enum a source declares extends or uses.
 *
 * Owns one fact: which classes a source takes code from. A read can live in a parent class or a
 * trait as well as in the class that sends it, so the inventory's reading of a module is only
 * whole when everything its classes take is inside the module. The source is read as tokens, so
 * a declaration is found wherever it stands, in a block or after other code, and a name is
 * resolved against the namespace and the `use` imports in force where it is written.
 *
 * Not read: group imports (`use A\{B, C}`), which this code base does not write; a name that is
 * not resolved is reported as it would resolve in the namespace, and the class that cannot be
 * loaded shows up by name.
 *
 * @since 0.2.0
 */
final class ClassDependencies {

	/**
	 * Finds the dependencies of the classes a source declares.
	 *
	 * @since 0.2.0
	 *
	 * @param string $source The PHP source.
	 * @return array<string, list<string>> The full name of each class, trait, interface and enum, and the full names of what it extends and the traits it uses.
	 */
	public static function of( string $source ): array {
		$tokens    = token_get_all( $source );
		$count     = count( $tokens );
		$namespace = '';
		$imports   = array();
		$found     = array();
		$bodies    = array();
		$pending   = null;
		$depth     = 0;

		for ( $at = 0; $at < $count; $at++ ) {
			$token = $tokens[ $at ];

			if ( self::opens( $token ) ) {
				++$depth;

				if ( null !== $pending ) {
					$bodies[] = array( $pending, $depth );
					$pending  = null;
				}
			} elseif ( '}' === $token ) {
				if ( array() !== $bodies && $depth === $bodies[ count( $bodies ) - 1 ][1] ) {
					array_pop( $bodies );
				}

				--$depth;
			} elseif ( is_array( $token ) && T_NAMESPACE === $token[0] ) {
				$name = self::nameAfter( $tokens, $at );

				if ( null !== $name ) {
					$namespace = $name[0];
					$imports   = array();
				}
			} elseif ( is_array( $token ) && T_USE === $token[0] ) {
				$inside = array() !== $bodies && $depth === $bodies[ count( $bodies ) - 1 ][1];

				if ( $inside ) {
					foreach ( self::namesUntil( $tokens, $at ) as $used ) {
						$found[ $bodies[ count( $bodies ) - 1 ][0] ][] = self::resolve( $used, $namespace, $imports );
					}
				} elseif ( array() === $bodies && null === $pending ) {
					$imports = array_merge( $imports, self::import( $tokens, $at ) );
				}
			} elseif ( is_array( $token ) && in_array( $token[0], array( T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM ), true ) ) {
				$name      = $tokens[ self::next( $tokens, $at ) ] ?? '';
				$before    = $tokens[ self::before( $tokens, $at ) ] ?? '';
				$anonymous = is_array( $before ) && T_NEW === $before[0];

				// `Name::class` is no declaration; `new class` is an anonymous one, which has no name.
				if ( ( is_array( $before ) && T_DOUBLE_COLON === $before[0] ) || ( ! $anonymous && ( ! is_array( $name ) || T_STRING !== $name[0] ) ) ) {
					continue;
				}

				$class           = $anonymous ? 'class@anonymous#' . $at : ( '' === $namespace ? '' : $namespace . '\\' ) . $name[1];
				$found[ $class ] = array();

				foreach ( self::parentsOf( $tokens, $at ) as $parent ) {
					$found[ $class ][] = self::resolve( $parent, $namespace, $imports );
				}

				$pending = $class;
			}
		}

		return array_map( static fn( array $names ): array => array_values( array_unique( $names ) ), $found );
	}

	/**
	 * Tells whether a token opens a block.
	 *
	 * @since 0.2.0
	 *
	 * @param array|string $token The token.
	 * @return bool True when it does.
	 */
	private static function opens( $token ): bool {
		return '{' === $token || ( is_array( $token ) && in_array( $token[0], array( T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ), true ) );
	}

	/**
	 * Names what a declaration extends: the names after `extends`, up to `implements` or the body.
	 *
	 * @since 0.2.0
	 *
	 * @param array $tokens The tokens.
	 * @param int   $at     Where the declaration's keyword is.
	 * @return list<string> The names, as written.
	 */
	private static function parentsOf( array $tokens, int $at ): array {
		$names = array();
		$count = count( $tokens );

		for ( $cursor = $at + 1; $cursor < $count && '{' !== $tokens[ $cursor ]; $cursor++ ) {
			if ( is_array( $tokens[ $cursor ] ) && T_IMPLEMENTS === $tokens[ $cursor ][0] ) {
				break;
			}

			if ( is_array( $tokens[ $cursor ] ) && T_EXTENDS === $tokens[ $cursor ][0] ) {
				$names = array_merge( $names, self::namesUntil( $tokens, $cursor, array( T_IMPLEMENTS ) ) );
			}
		}

		return $names;
	}

	/**
	 * Reads the comma-separated names after a keyword.
	 *
	 * @since 0.2.0
	 *
	 * @param array $tokens The tokens.
	 * @param int   $at     Where the keyword is.
	 * @param int[] $stops  Tokens that end the list besides `;` and `{`.
	 * @return list<string> The names, as written.
	 */
	private static function namesUntil( array $tokens, int $at, array $stops = array() ): array {
		$names = array();
		$count = count( $tokens );

		for ( $cursor = $at + 1; $cursor < $count; $cursor++ ) {
			$token = $tokens[ $cursor ];

			if ( ';' === $token || '{' === $token || ( is_array( $token ) && in_array( $token[0], $stops, true ) ) ) {
				break;
			}

			if ( is_array( $token ) && in_array( $token[0], array( T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED ), true ) ) {
				$names[] = $token[1];
			}
		}

		return $names;
	}

	/**
	 * Reads a top-level `use` import: `use A\B;`, `use A\B as C;`, or several of those.
	 *
	 * @since 0.2.0
	 *
	 * @param array $tokens The tokens.
	 * @param int   $at     Where `use` is.
	 * @return array<string, string> The full name each alias stands for, by lower-case alias.
	 */
	private static function import( array $tokens, int $at ): array {
		$next = $tokens[ self::next( $tokens, $at ) ] ?? '';

		if ( ! is_array( $next ) || ! in_array( $next[0], array( T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED ), true ) ) {
			return array();
		}

		$imports = array();
		$name    = null;
		$count   = count( $tokens );

		for ( $cursor = $at + 1; $cursor <= $count; $cursor++ ) {
			$token = $tokens[ $cursor ] ?? ';';

			if ( is_array( $token ) && in_array( $token[0], array( T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED ), true ) ) {
				if ( null !== $name && is_array( $tokens[ self::before( $tokens, $cursor ) ] ?? null ) && T_AS === $tokens[ self::before( $tokens, $cursor ) ][0] ) {
					$imports[ strtolower( $token[1] ) ] = ltrim( $name, '\\' );
					$name                               = null;
				} else {
					$name = $token[1];
				}
			} elseif ( ',' === $token || ';' === $token ) {
				if ( null !== $name ) {
					$imports[ strtolower( substr( strrchr( '\\' . $name, '\\' ), 1 ) ) ] = ltrim( $name, '\\' );
				}

				$name = null;

				if ( ';' === $token ) {
					break;
				}
			}
		}

		return $imports;
	}

	/**
	 * Resolves a name against a namespace and the imports in force.
	 *
	 * @since 0.2.0
	 *
	 * @param string                $name      The name as written.
	 * @param string                $space     The namespace.
	 * @param array<string, string> $imports   The imports.
	 * @return string The full name, without a leading backslash.
	 */
	private static function resolve( string $name, string $space, array $imports ): string {
		if ( '\\' === $name[0] ) {
			return ltrim( $name, '\\' );
		}

		$parts = explode( '\\', $name, 2 );
		$alias = strtolower( $parts[0] );

		if ( isset( $imports[ $alias ] ) ) {
			return $imports[ $alias ] . ( isset( $parts[1] ) ? '\\' . $parts[1] : '' );
		}

		return ( '' === $space ? '' : $space . '\\' ) . $name;
	}

	/**
	 * Reads the name after a keyword.
	 *
	 * @since 0.2.0
	 *
	 * @param array $tokens The tokens.
	 * @param int   $at     Where the keyword is.
	 * @return array{0: string}|null The name, or null when none follows.
	 */
	private static function nameAfter( array $tokens, int $at ): ?array {
		$next = $tokens[ self::next( $tokens, $at ) ] ?? '';

		return is_array( $next ) && in_array( $next[0], array( T_STRING, T_NAME_QUALIFIED ), true ) ? array( $next[1] ) : null;
	}

	/**
	 * Finds the next token that is neither white space nor a comment.
	 *
	 * @since 0.2.0
	 *
	 * @param array $tokens The tokens.
	 * @param int   $at     Where to start looking, after this one.
	 * @return int Its position.
	 */
	private static function next( array $tokens, int $at ): int {
		do {
			++$at;
		} while ( is_array( $tokens[ $at ] ?? null ) && in_array( $tokens[ $at ][0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) );

		return $at;
	}

	/**
	 * Finds the nearest token before a position that is neither white space nor a comment.
	 *
	 * @since 0.2.0
	 *
	 * @param array $tokens The tokens.
	 * @param int   $at     Where to start looking, before this one.
	 * @return int Its position; minus one when there is none.
	 */
	private static function before( array $tokens, int $at ): int {
		do {
			--$at;
		} while ( $at >= 0 && is_array( $tokens[ $at ] ) && in_array( $tokens[ $at ][0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) );

		return $at;
	}
}
