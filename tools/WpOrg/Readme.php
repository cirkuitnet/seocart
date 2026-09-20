<?php
/**
 * Readme: a parsed WordPress.org readme.txt
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tools\WpOrg;

/**
 * Reads the parts of a readme.txt that the directory rules are about.
 *
 * It follows the layout the WordPress.org directory parses: a `=== Name ===` line, a
 * block of `Field: value` headers, a short description, then `== Section ==` blocks.
 * It interprets nothing; ReadmeValidator owns the rules.
 *
 * @since 0.1.0
 */
final class Readme {

	/**
	 * The plugin name from the `=== Name ===` line, or '' when the line is missing.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private string $name = '';

	/**
	 * Header values keyed by lower-case field name. The first occurrence of a field wins.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, string>
	 */
	private array $headers = array();

	/**
	 * Lower-case names of header fields that occur more than once.
	 *
	 * @since 0.1.0
	 *
	 * @var list<string>
	 */
	private array $duplicateHeaders = array();

	/**
	 * The text between the header block and the first section, as one line.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private string $shortDescription = '';

	/**
	 * Section bodies keyed by lower-case section title.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, string>
	 */
	private array $sections = array();

	/**
	 * Parses the text of a readme.txt.
	 *
	 * @since 0.1.0
	 *
	 * @param string $text The file's content.
	 * @return self The parsed readme.
	 */
	public static function parse( string $text ): self {
		$readme = new self();
		$lines  = explode( "\n", str_replace( array( "\r\n", "\r" ), "\n", $text ) );
		$index  = 0;
		$total  = count( $lines );

		while ( $index < $total && '' === trim( $lines[ $index ] ) ) {
			++$index;
		}

		if ( $index < $total && 1 === preg_match( '/^===\s*(.+?)\s*===\s*$/', $lines[ $index ], $matches ) ) {
			$readme->name = $matches[1];
			++$index;
		}

		// The header block: consecutive `Field: value` lines.
		while ( $index < $total && 1 === preg_match( '/^([A-Za-z][A-Za-z ]*?)\s*:\s*(.*?)\s*$/', $lines[ $index ], $matches ) ) {
			$field = strtolower( $matches[1] );

			if ( isset( $readme->headers[ $field ] ) ) {
				$readme->duplicateHeaders[] = $field;
			} else {
				$readme->headers[ $field ] = $matches[2];
			}

			++$index;
		}

		// Everything up to the first section heading is the short description.
		$short = array();

		while ( $index < $total && 1 !== preg_match( '/^==[^=]/', $lines[ $index ] ) ) {
			$short[] = trim( $lines[ $index ] );
			++$index;
		}

		$readme->shortDescription = trim( (string) preg_replace( '/\s+/', ' ', implode( ' ', $short ) ) );

		$title = null;
		$body  = array();

		for ( ; $index <= $total; ++$index ) {
			$isEnd = $index === $total;

			if ( $isEnd || 1 === preg_match( '/^==\s*([^=].*?)\s*==\s*$/', $lines[ $index ], $matches ) ) {
				if ( null !== $title && ! isset( $readme->sections[ $title ] ) ) {
					$readme->sections[ $title ] = trim( implode( "\n", $body ) );
				}

				$title = $isEnd ? null : strtolower( $matches[1] );
				$body  = array();
				continue;
			}

			$body[] = $lines[ $index ];
		}

		return $readme;
	}

	/**
	 * Returns the plugin name from the first line.
	 *
	 * @since 0.1.0
	 *
	 * @return string The name, or '' when the `=== Name ===` line is missing.
	 */
	public function name(): string {
		return $this->name;
	}

	/**
	 * Returns the value of a header field.
	 *
	 * @since 0.1.0
	 *
	 * @param string $field Field name in any letter case, for example 'Stable tag'.
	 * @return string|null The trimmed value, or null when the field is absent.
	 */
	public function header( string $field ): ?string {
		return $this->headers[ strtolower( $field ) ] ?? null;
	}

	/**
	 * Returns the header fields that occur more than once.
	 *
	 * @since 0.1.0
	 *
	 * @return list<string> Lower-case field names.
	 */
	public function duplicateHeaders(): array {
		return $this->duplicateHeaders;
	}

	/**
	 * Returns the tags of the `Tags` header.
	 *
	 * @since 0.1.0
	 *
	 * @return list<string> The trimmed, non-empty tags, in order.
	 */
	public function tags(): array {
		$tags = array_map( 'trim', explode( ',', $this->header( 'Tags' ) ?? '' ) );

		return array_values( array_filter( $tags, static fn ( string $tag ): bool => '' !== $tag ) );
	}

	/**
	 * Returns the short description.
	 *
	 * @since 0.1.0
	 *
	 * @return string The short description as a single line, or '' when there is none.
	 */
	public function shortDescription(): string {
		return $this->shortDescription;
	}

	/**
	 * Returns the body of a section.
	 *
	 * @since 0.1.0
	 *
	 * @param string $title Section title in any letter case, for example 'Changelog'.
	 * @return string|null The trimmed body, or null when the section is absent.
	 */
	public function section( string $title ): ?string {
		return $this->sections[ strtolower( $title ) ] ?? null;
	}

	/**
	 * Returns every section.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, string> The trimmed bodies keyed by lower-case section title, in order.
	 */
	public function sections(): array {
		return $this->sections;
	}
}
