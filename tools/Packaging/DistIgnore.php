<?php
/**
 * DistIgnore: the one owner of the .distignore pattern dialect
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tools\Packaging;

use InvalidArgumentException;

/**
 * Parses .distignore and answers whether a plugin-relative path is left out of the release zip.
 *
 * The dialect is described in the header comment of .distignore and implemented here and
 * nowhere else, so the zip is cut the same way on every platform:
 *
 * - one pattern per line; blank lines are skipped; a line whose first character is `#`
 *   is a comment (there are no trailing comments, because `#` is legal in a file name);
 * - a leading `/` anchors the pattern to the plugin root, and only an anchored pattern
 *   may name a path of several segments, such as `/bin/dev`;
 * - a pattern without a leading `/` is one name, and matches a file or directory of
 *   that name at any depth;
 * - `*` matches any run of characters inside one path segment, a leading dot included,
 *   and never crosses a `/`;
 * - a pattern that matches a directory excludes everything beneath it;
 * - matching is case-sensitive.
 *
 * Anything else that .gitignore would accept — `!` negation, `**`, `?`, `[...]`, a
 * trailing `/`, a backslash, white space — is rejected when the file is read. A pattern
 * that quietly matched nothing would let development material into a release. So is
 * `bin/dev` without a leading `/`: .gitignore anchors a pattern with a `/` inside it and
 * the header of .distignore does not say, so the file has to say which it means.
 *
 * @since 0.1.0
 */
final class DistIgnore {

	/**
	 * Compiled rules: one regular expression per pattern.
	 *
	 * @since 0.1.0
	 *
	 * @var string[]
	 */
	private array $rules;

	/**
	 * Stores the compiled rules.
	 *
	 * @since 0.1.0
	 *
	 * @param string[] $rules One regular expression per pattern.
	 */
	private function __construct( array $rules ) {
		$this->rules = $rules;
	}

	/**
	 * Reads the patterns from a .distignore file.
	 *
	 * @since 0.1.0
	 *
	 * @param string $file Path to the file.
	 * @return self The matcher for that file.
	 *
	 * @throws InvalidArgumentException When the file cannot be read or a pattern is outside the dialect.
	 */
	public static function fromFile( string $file ): self {
		if ( ! is_file( $file ) || ! is_readable( $file ) ) {
			throw new InvalidArgumentException( "{$file} does not exist or cannot be read." );
		}

		return self::fromString( (string) file_get_contents( $file ) );
	}

	/**
	 * Reads the patterns from the contents of a .distignore file.
	 *
	 * @since 0.1.0
	 *
	 * @param string $contents The file contents.
	 * @return self The matcher for those patterns.
	 *
	 * @throws InvalidArgumentException When a pattern is outside the dialect.
	 */
	public static function fromString( string $contents ): self {
		$rules = array();

		foreach ( explode( "\n", $contents ) as $index => $line ) {
			$pattern = trim( $line );

			if ( '' === $pattern || '#' === $pattern[0] ) {
				continue;
			}

			$problem = self::unsupportedSyntax( $pattern );

			if ( null !== $problem ) {
				throw new InvalidArgumentException(
					sprintf( '.distignore line %d, "%s": %s', $index + 1, $pattern, $problem )
				);
			}

			$rules[] = self::compile( $pattern );
		}

		return new self( $rules );
	}

	/**
	 * Determines whether a path is excluded from the release zip.
	 *
	 * @since 0.1.0
	 *
	 * @param string $path Path relative to the plugin root, with forward slashes. A file or a directory.
	 * @return bool True when a pattern matches the path or one of the directories above it.
	 */
	public function excludes( string $path ): bool {
		$path = trim( $path, '/' );

		foreach ( $this->rules as $expression ) {
			if ( 1 === preg_match( $expression, $path ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Explains why a pattern is outside the dialect.
	 *
	 * @since 0.1.0
	 *
	 * @param string $pattern A trimmed, non-empty, non-comment line.
	 * @return string|null The reason, or null when the pattern is valid.
	 */
	private static function unsupportedSyntax( string $pattern ): ?string {
		if ( '!' === $pattern[0] ) {
			return 'negation with `!` is not part of the dialect. Remove the broader pattern instead.';
		}

		if ( str_contains( $pattern, '**' ) ) {
			return '`**` is not part of the dialect. A pattern without a leading `/` already matches at any depth.';
		}

		if ( str_ends_with( $pattern, '/' ) ) {
			return 'a trailing `/` is not part of the dialect. Name the directory without it; everything beneath a matched directory is excluded.';
		}

		if ( 1 === preg_match( '/[\s\\\\?\[\]]/', $pattern ) ) {
			return 'white space, `\`, `?` and `[...]` are not part of the dialect. A comment goes on a line of its own.';
		}

		if ( str_contains( $pattern, '//' ) ) {
			return 'the pattern has an empty path segment.';
		}

		if ( '/' !== $pattern[0] && str_contains( $pattern, '/' ) ) {
			return 'a pattern with a `/` inside it is ambiguous without a leading `/`: .gitignore would anchor it to the root and a bare name matches at any depth. Start it with `/`.';
		}

		return null;
	}

	/**
	 * Compiles a valid pattern to a regular expression over plugin-relative paths.
	 *
	 * The expression matches the path itself and every path beneath it: it ends at a
	 * segment boundary, never in the middle of a name.
	 *
	 * @since 0.1.0
	 *
	 * @param string $pattern A pattern that passed the syntax check.
	 * @return string The regular expression, delimiters included.
	 */
	private static function compile( string $pattern ): string {
		$segments = array();

		foreach ( explode( '/', ltrim( $pattern, '/' ) ) as $segment ) {
			$literals = array();

			foreach ( explode( '*', $segment ) as $literal ) {
				$literals[] = preg_quote( $literal, '~' );
			}

			$segments[] = implode( '[^/]*', $literals );
		}

		$start = '/' === $pattern[0] ? '^' : '(?:^|/)';

		return '~' . $start . implode( '/', $segments ) . '(?:/|$)~D';
	}
}
