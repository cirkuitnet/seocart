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

use SEOCart\Tools\Packaging\PluginPackage;

/**
 * Extracts the header fields that readme.txt must agree with.
 *
 * This class owns one fact: WHICH header fields the directory checks compare. How a field
 * is read from the file is owned by PluginPackage::header(), the one reader of the header.
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
		$headers = array();

		foreach ( self::FIELDS as $field ) {
			$headers[ $field ] = PluginPackage::header( $source, $field ) ?? '';
		}

		return $headers;
	}
}
