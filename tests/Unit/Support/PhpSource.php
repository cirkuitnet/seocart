<?php
/**
 * PhpSource: reads PHP files as tokens and resolves the names in them
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Support;

use PHPUnit\Framework\Assert;

/**
 * The source reading the Support gates share: which files, which tokens, which names.
 *
 * A name is resolved the way PHP resolves a class name: a fully qualified name as written; a
 * name whose first segment was imported with `use` through that import; `namespace\X` and
 * any other name relative to the file's namespace. Only class imports count; `use function`
 * and `use const` do not name classes.
 *
 * @since 0.1.0
 */
final class PhpSource {

	/**
	 * Returns the repository root.
	 *
	 * @since 0.1.0
	 *
	 * @return string Absolute path without a trailing slash.
	 */
	public static function root(): string {
		return dirname( __DIR__, 3 );
	}

	/**
	 * Reads every PHP file under a directory of the repository.
	 *
	 * @since 0.1.0
	 *
	 * @param string   $directory A directory relative to the repository root, such as 'src/Support'.
	 * @param string[] $skip      Optional. Subdirectories, relative to the root, to leave out. Default none.
	 * @return array<string, string> Source text, keyed by path relative to the root, in path order.
	 */
	public static function files( string $directory, array $skip = array() ): array {
		$root    = self::root();
		$sources = array();
		$files   = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root . '/' . $directory, \FilesystemIterator::SKIP_DOTS ) );

		foreach ( $files as $file ) {
			if ( ! $file instanceof \SplFileInfo || 'php' !== $file->getExtension() ) {
				continue;
			}

			$relative = str_replace( '\\', '/', substr( $file->getPathname(), strlen( $root ) + 1 ) );

			foreach ( $skip as $skipped ) {
				if ( str_starts_with( $relative, rtrim( $skipped, '/' ) . '/' ) ) {
					continue 2;
				}
			}

			$sources[ $relative ] = (string) file_get_contents( $file->getPathname() );
		}

		ksort( $sources );

		Assert::assertNotSame( array(), $sources, 'No PHP file was found under ' . $directory . ', so every check over it would pass.' );

		return $sources;
	}

	/**
	 * Returns the tokens that carry meaning: no white space, no comments, no open or close tags.
	 *
	 * @since 0.1.0
	 *
	 * @param string $source The PHP source.
	 * @return list<\PhpToken> The tokens.
	 */
	public static function tokens( string $source ): array {
		$ignored = array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG, T_CLOSE_TAG, T_INLINE_HTML );

		return array_values( array_filter( \PhpToken::tokenize( $source ), static fn( \PhpToken $token ): bool => ! $token->is( $ignored ) ) );
	}

	/**
	 * Returns the fully qualified names of the classes, interfaces, traits and enums a file declares.
	 *
	 * @since 0.1.0
	 *
	 * @param string $source The PHP source.
	 * @return list<string> The names, without a leading backslash.
	 */
	public static function declarations( string $source ): array {
		$tokens    = self::tokens( $source );
		$namespace = self::namespaceOf( $tokens );
		$declared  = array();

		foreach ( $tokens as $index => $token ) {
			if ( ! $token->is( array( T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM ) ) ) {
				continue;
			}

			$previous = $tokens[ $index - 1 ] ?? null;
			$name     = $tokens[ $index + 1 ] ?? null;

			// Foo::class is a constant, and `new class` is anonymous.
			if ( null === $name || ! $name->is( T_STRING ) || ( null !== $previous && $previous->is( array( T_DOUBLE_COLON, T_NEW ) ) ) ) {
				continue;
			}

			$declared[] = ltrim( $namespace . '\\' . $name->text, '\\' );
		}

		return $declared;
	}

	/**
	 * Returns every `Name::CONSTANT` or `Name::Case` reference in a file, with the class resolved.
	 *
	 * Method calls (`Name::method(`) and `Name::class` are not constants and are left out, and so
	 * are `self::`, `static::` and `parent::`, which only a class can say about itself.
	 *
	 * @since 0.1.0
	 *
	 * @param string $source The PHP source.
	 * @return list<array{class: string, constant: string, line: int}> The references.
	 */
	public static function constantReferences( string $source ): array {
		$tokens     = self::tokens( $source );
		$namespace  = self::namespaceOf( $tokens );
		$imports    = self::importsOf( $tokens );
		$references = array();

		foreach ( $tokens as $index => $token ) {
			if ( ! $token->is( T_DOUBLE_COLON ) ) {
				continue;
			}

			$class    = $tokens[ $index - 1 ] ?? null;
			$constant = $tokens[ $index + 1 ] ?? null;
			$after    = $tokens[ $index + 2 ] ?? null;

			if ( null === $class || null === $constant || ! $class->is( array( T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE ) ) ) {
				continue;
			}

			// The tokenizer reports self and parent as names; static is its own token.
			if ( in_array( strtolower( $class->text ), array( 'self', 'parent' ), true ) ) {
				continue;
			}

			if ( ! $constant->is( T_STRING ) || ( null !== $after && '(' === $after->text ) ) {
				continue;
			}

			$references[] = array(
				'class'    => self::resolve( $class->text, $namespace, $imports ),
				'constant' => $constant->text,
				'line'     => $constant->line,
			);
		}

		return $references;
	}

	/**
	 * Returns every fully qualified class name a file names, in `use` imports and in its code.
	 *
	 * @since 0.1.0
	 *
	 * @param string $source The PHP source.
	 * @return list<array{name: string, line: int}> The names, without a leading backslash.
	 */
	public static function classNames( string $source ): array {
		$tokens    = self::tokens( $source );
		$namespace = self::namespaceOf( $tokens );
		$imports   = self::importsOf( $tokens );
		$names     = array();

		foreach ( $imports as $imported ) {
			$names[] = array(
				'name' => $imported,
				'line' => 0,
			);
		}

		foreach ( $tokens as $token ) {
			if ( $token->is( array( T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED, T_NAME_RELATIVE ) ) ) {
				$names[] = array(
					'name' => self::resolve( $token->text, $namespace, $imports ),
					'line' => $token->line,
				);
			}
		}

		return $names;
	}

	/**
	 * Resolves a class name as PHP would inside a file.
	 *
	 * @since 0.1.0
	 *
	 * @param string                $name         The name as written.
	 * @param string                $in_namespace The file's namespace, without a leading backslash.
	 * @param array<string, string> $imports      The file's class imports: alias, lower-cased, => full name.
	 * @return string The fully qualified name, without a leading backslash.
	 */
	public static function resolve( string $name, string $in_namespace, array $imports ): string {
		if ( str_starts_with( $name, '\\' ) ) {
			return substr( $name, 1 );
		}

		if ( 0 === stripos( $name, 'namespace\\' ) ) {
			return ltrim( $in_namespace . '\\' . substr( $name, 10 ), '\\' );
		}

		$parts = explode( '\\', $name, 2 );
		$first = strtolower( $parts[0] );

		if ( isset( $imports[ $first ] ) ) {
			return $imports[ $first ] . ( isset( $parts[1] ) ? '\\' . $parts[1] : '' );
		}

		return ltrim( $in_namespace . '\\' . $name, '\\' );
	}

	/**
	 * Returns a file's namespace.
	 *
	 * @since 0.1.0
	 *
	 * @param \PhpToken[] $tokens The file's tokens.
	 * @return string The namespace, without a leading backslash; empty for the global namespace.
	 */
	public static function namespaceOf( array $tokens ): string {
		foreach ( $tokens as $index => $token ) {
			$name = $tokens[ $index + 1 ] ?? null;

			if ( $token->is( T_NAMESPACE ) && null !== $name && $name->is( array( T_STRING, T_NAME_QUALIFIED ) ) ) {
				return $name->text;
			}
		}

		return '';
	}

	/**
	 * Returns a file's class imports.
	 *
	 * Handles `use A\B;`, `use A\B as C;`, lists separated by commas and group imports
	 * (`use A\{B, C as D};`). Imports inside a class body (traits) and in closures are not imports.
	 *
	 * @since 0.1.0
	 *
	 * @param \PhpToken[] $tokens The file's tokens.
	 * @return array<string, string> The full names, keyed by their lower-cased alias.
	 */
	public static function importsOf( array $tokens ): array {
		$imports = array();
		$depth   = 0;
		$count   = count( $tokens );
		$index   = -1;

		while ( ++$index < $count ) {
			$token = $tokens[ $index ];

			if ( '{' === $token->text || $token->is( array( T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ) ) ) {
				++$depth;
			} elseif ( '}' === $token->text ) {
				--$depth;
			}

			if ( 0 !== $depth || ! $token->is( T_USE ) ) {
				continue;
			}

			$next = $tokens[ $index + 1 ] ?? null;

			if ( null === $next || $next->is( array( T_FUNCTION, T_CONST ) ) ) {
				continue;
			}

			$prefix = '';
			$name   = '';
			$alias  = null;

			while ( ++$index < $count && ';' !== $tokens[ $index ]->text ) {
				$part = $tokens[ $index ];

				if ( '{' === $part->text ) {
					$prefix = trim( $name, '\\' ) . '\\';
					$name   = '';
				} elseif ( ',' === $part->text || '}' === $part->text ) {
					self::addImport( $imports, $prefix, $name, $alias );
					$name  = '';
					$alias = null;
				} elseif ( $part->is( T_AS ) ) {
					$alias = ( $tokens[ $index + 1 ] ?? null )?->text;
					++$index;
				} else {
					$name .= $part->text;
				}
			}

			self::addImport( $imports, $prefix, $name, $alias );
		}

		return $imports;
	}

	/**
	 * Records one import, unless the name is empty (the end of a group import).
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, string> $imports The imports so far.
	 * @param string                $prefix  The group prefix, ending in a backslash, or empty.
	 * @param string                $name    The imported name, after the prefix.
	 * @param string|null           $alias   The alias, or null for the last segment.
	 */
	private static function addImport( array &$imports, string $prefix, string $name, ?string $alias ): void {
		$name = trim( $name, '\\' );

		if ( '' === $name ) {
			return;
		}

		$full     = ltrim( $prefix . $name, '\\' );
		$segments = explode( '\\', $full );

		$imports[ strtolower( $alias ?? $segments[ count( $segments ) - 1 ] ) ] = $full;
	}
}
