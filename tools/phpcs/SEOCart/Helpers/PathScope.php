<?php
/**
 * Path matching shared by the SEOCart sniffs
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tools\phpcs\SEOCart\Helpers;

use PHP_CodeSniffer\Files\File;

/**
 * Owns one fact: how a path named in a sniff property is matched against a scanned file.
 *
 * Every path is relative to a root and written with forward slashes: `src`, `seocart.php`,
 * `src/Support/Schema`. The root is PHP_CodeSniffer's base path when one is set, which
 * phpcs.xml.dist does, and otherwise the repository this standard lies in, which is how
 * `composer cs:dry` runs. Matching from the root, instead of anywhere in the absolute path,
 * keeps a checkout below a directory that happens to be named `src` from changing what is
 * reported. A file outside the root is inside nothing. The self-tests set the base path to
 * Tests/Fixtures/, whose tree mirrors the plugin's layout.
 *
 * PHP_CodeSniffer's result cache hashes only the classes that are loaded before the first
 * file is scanned, and this class is loaded later, by the first sniff that needs it. After
 * editing this file, delete `.phpcs-cache`.
 *
 * @since 0.1.0
 */
final class PathScope {

	/**
	 * Determines whether a file is one of the given paths or lies under one of them.
	 *
	 * @since 0.1.0
	 *
	 * @param File     $phpcsFile The file being scanned.
	 * @param string[] $paths     Files and directories, relative to the root.
	 * @return bool True when at least one of the paths holds the file.
	 */
	public static function isUnder( File $phpcsFile, array $paths ): bool {
		$file = self::relativePath( $phpcsFile );

		if ( null === $file ) {
			return false;
		}

		foreach ( $paths as $path ) {
			$path = trim( str_replace( '\\', '/', (string) $path ), '/' );

			if ( '' !== $path && ( $file === $path || str_starts_with( $file, $path . '/' ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Determines whether a directory with the given name lies between the root and a file.
	 *
	 * @since 0.1.0
	 *
	 * @param File   $phpcsFile The file being scanned.
	 * @param string $name      A single directory name.
	 * @return bool True when the file is inside such a directory, at any depth.
	 */
	public static function hasDirectory( File $phpcsFile, string $name ): bool {
		$file = self::relativePath( $phpcsFile );

		return null !== $file && str_contains( '/' . $file, '/' . $name . '/' );
	}

	/**
	 * Formats a list of paths for an error message.
	 *
	 * @since 0.1.0
	 *
	 * @param string[] $paths Paths, as given to isUnder().
	 * @return string The paths, or a statement that there are none.
	 */
	public static function describe( array $paths ): string {
		return array() === $paths ? '(nowhere)' : implode( ', ', $paths );
	}

	/**
	 * Returns a scanned file's path relative to the root.
	 *
	 * @since 0.1.0
	 *
	 * @param File $phpcsFile The file being scanned.
	 * @return string|null The path, with forward slashes, or null when the file is outside the root.
	 */
	private static function relativePath( File $phpcsFile ): ?string {
		$path = str_replace( '\\', '/', $phpcsFile->getFilename() );

		// Only a --stdin-path that names no existing file arrives relative. PHP_CodeSniffer's reports take it as it is; so does this.
		if ( 1 !== preg_match( '~^(/|[A-Za-z]:/)~', $path ) ) {
			return $path;
		}

		$root = rtrim( str_replace( '\\', '/', $phpcsFile->config->basepath ?? dirname( __DIR__, 4 ) ), '/' ) . '/';

		return str_starts_with( $path, $root ) ? substr( $path, strlen( $root ) ) : null;
	}
}
