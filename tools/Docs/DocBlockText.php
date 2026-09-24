<?php
/**
 * DocBlockText: reads the sentences the hooks reference documents itself from
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tools\Docs;

/**
 * Parses the three things a doc comment holds that HooksReference needs: a summary sentence,
 * an `@since` tag, and one `@param` entry's type and description.
 *
 * This is not a general doc-comment parser: it reads exactly the shapes this codebase's own
 * doc comments use (a summary paragraph, then blank-line-separated `@tag` lines, an `@param`
 * entry's description continuing on following lines until the next `@tag` or a blank line), and
 * nothing else.
 *
 * @since 0.1.0
 */
final class DocBlockText {

	/**
	 * Returns a doc comment's first sentence: its summary paragraph, up to the first period
	 * followed by a space, or the whole paragraph when none is found.
	 *
	 * @since 0.1.0
	 *
	 * @param string $docComment The doc comment, exactly as ReflectionClass::getDocComment() returns it.
	 * @return string The sentence, or '' when the comment has no summary.
	 */
	public static function firstSentence( string $docComment ): string {
		$summary = self::summary( $docComment );
		$period  = strpos( $summary, '. ' );

		return false === $period ? $summary : substr( $summary, 0, $period + 1 );
	}

	/**
	 * Returns a doc comment's `@since` value.
	 *
	 * @since 0.1.0
	 *
	 * @param string $docComment The doc comment.
	 * @return string|null The value, or null when there is no `@since` tag.
	 */
	public static function since( string $docComment ): ?string {
		if ( 1 !== preg_match( '/@since\s+(\S+)/', $docComment, $matches ) ) {
			return null;
		}

		return $matches[1];
	}

	/**
	 * Returns one `@param` entry's type and description.
	 *
	 * @since 0.1.0
	 *
	 * @param string $docComment The doc comment holding the `@param` tags, for example a
	 *                           constructor's.
	 * @param string $name       The parameter's name, without the leading `$`.
	 * @return array{type: string, description: string}|null The entry, or null when the doc
	 *                                                        comment has no `@param $name` tag, or
	 *                                                        that tag has no description sentence.
	 */
	public static function paramSentence( string $docComment, string $name ): ?array {
		$lines = self::lines( $docComment );
		$count = count( $lines );

		for ( $index = 0; $index < $count; $index++ ) {
			if ( 1 !== preg_match( '/^@param\s+(\S+)\s+\$(\w+)\s*(.*)$/', $lines[ $index ], $matches ) || $matches[2] !== $name ) {
				continue;
			}

			$type        = $matches[1];
			$description = trim( $matches[3] );
			$next        = $index + 1;

			while ( $next < $count ) {
				if ( '' === $lines[ $next ] || 1 === preg_match( '/^@\w+/', $lines[ $next ] ) ) {
					break;
				}

				$description = trim( $description . ' ' . $lines[ $next ] );
				++$next;
			}

			// A tag with no description sentence documents nothing; the caller refuses it rather
			// than rendering a blank.
			return '' === $description ? null : array(
				'type'        => $type,
				'description' => $description,
			);
		}

		return null;
	}

	/**
	 * Returns a doc comment's summary paragraph: everything before the first blank line or `@tag`.
	 *
	 * @since 0.1.0
	 *
	 * @param string $docComment The doc comment.
	 * @return string The paragraph, its lines joined with a single space.
	 */
	private static function summary( string $docComment ): string {
		$paragraph = array();

		foreach ( self::lines( $docComment ) as $line ) {
			if ( '' === $line || 1 === preg_match( '/^@\w+/', $line ) ) {
				break;
			}

			$paragraph[] = $line;
		}

		return trim( implode( ' ', $paragraph ) );
	}

	/**
	 * Splits a doc comment into its lines, with the comment delimiters and each leading `*` removed.
	 *
	 * @since 0.1.0
	 *
	 * @param string $docComment The doc comment.
	 * @return list<string> The lines, trimmed.
	 */
	private static function lines( string $docComment ): array {
		$body  = trim( (string) preg_replace( '#^/\*\*|\*/$#', '', trim( $docComment ) ) );
		$lines = array();

		foreach ( explode( "\n", $body ) as $line ) {
			$lines[] = trim( (string) preg_replace( '/^\s*\*\s?/', '', trim( $line ) ) );
		}

		return $lines;
	}
}
