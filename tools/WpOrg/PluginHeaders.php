<?php
/**
 * PluginHeaders: reads the header comment of the plugin's main file
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tools\WpOrg;

/**
 * Extracts the header fields that readme.txt must agree with.
 *
 * The main file calls WordPress functions at file scope, so it is read as text and never
 * included. The match follows WordPress's own get_file_data(): the field name, a colon,
 * and the rest of the line, inside the first 8 KiB of the file.
 *
 * @since 0.1.0
 */
final class PluginHeaders {

	/**
	 * The header fields the directory checks compare with readme.txt.
	 *
	 * @since 0.1.0
	 *
	 * @var list<string>
	 */
	public const FIELDS = array( 'Plugin Name', 'Version', 'Requires at least', 'Requires PHP' );

	/**
	 * Reads the header fields from the source of a plugin's main file.
	 *
	 * @since 0.1.0
	 *
	 * @param string $source The PHP source of the main file.
	 * @return array<string, string> Trimmed values keyed by self::FIELDS; '' for a field that is absent.
	 */
	public static function read( string $source ): array {
		$head    = str_replace( "\r", "\n", substr( $source, 0, 8192 ) );
		$headers = array();

		foreach ( self::FIELDS as $field ) {
			$found = preg_match( '/^[ \t\/*#@]*' . preg_quote( $field, '/' ) . ':(.*)$/mi', $head, $matches );

			$headers[ $field ] = 1 === $found ? trim( (string) preg_replace( '/\s*(?:\*\/|\?>).*/', '', $matches[1] ) ) : '';
		}

		return $headers;
	}
}
