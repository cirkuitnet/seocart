<?php
/**
 * PluginPackage: the shape of the release zip, stated once
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tools\Packaging;

/**
 * Owns the facts the zip builder and the zip checker must agree on.
 *
 * What the archive is called, which folder it unpacks to, what may sit at the top of
 * that folder, and how the version is read from the main plugin file. The builder
 * writes to this shape and the checker verifies it, so neither restates it.
 *
 * @since 0.1.0
 */
final class PluginPackage {

	/**
	 * The plugin slug: the single top-level folder inside the zip and the prefix of its file name.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const SLUG = 'seocart';

	/**
	 * The main plugin file, relative to the plugin root. It carries the `Version` header.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const MAIN_FILE = 'seocart.php';

	/**
	 * The allow-list: every entry that may sit at the top of the plugin folder in the zip.
	 *
	 * A trailing `/` marks a directory. The value says whether the entry is required.
	 * `CHANGELOG.md` is optional because `readme.txt` carries the changelog the directory
	 * reads; `templates/`, `languages/` and `build/` are optional because a directory with
	 * nothing to ship in it has no entry in a zip.
	 *
	 * The header comment of .distignore states the same list in prose for its readers;
	 * tests/Unit/Packaging/IgnoreListsTest.php fails when the two differ.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, bool>
	 */
	public const TOP_LEVEL = array(
		self::MAIN_FILE  => true,
		'uninstall.php'  => true,
		'readme.txt'     => true,
		'LICENSE'        => true,
		'composer.json'  => true,
		'CHANGELOG.md'   => false,
		'src/'           => true,
		'templates/'     => false,
		'languages/'     => false,
		'build/'         => false,
		'vendor-scoped/' => true,
	);

	/**
	 * Returns the file name of the release zip for a version.
	 *
	 * @since 0.1.0
	 *
	 * @param string $version The plugin version.
	 * @return string For example `seocart-1.2.3.zip`.
	 */
	public static function zipFileName( string $version ): string {
		return self::SLUG . '-' . $version . '.zip';
	}

	/**
	 * Reads the version a release zip claims in its file name.
	 *
	 * The format of the version itself is not judged here: the claim only has to equal
	 * the `Version` header, and tests/Unit/Packaging/VersionAgreementTest.php owns the format.
	 *
	 * @since 0.1.0
	 *
	 * @param string $file_name File name without a directory.
	 * @return string|null The version, or null when the name is not `seocart-<version>.zip`.
	 */
	public static function versionFromZipFileName( string $file_name ): ?string {
		$prefix = self::SLUG . '-';
		$suffix = '.zip';

		if ( ! str_starts_with( $file_name, $prefix ) || ! str_ends_with( $file_name, $suffix ) ) {
			return null;
		}

		$version = substr( $file_name, strlen( $prefix ), -strlen( $suffix ) );

		return '' === $version ? null : $version;
	}

	/**
	 * Reads one header field, such as `Version`, from the source of the main plugin file.
	 *
	 * This is the one reader of the plugin header: the zip builder, the zip checker, the
	 * readme validator and the version-agreement test all ask it, so they cannot disagree.
	 * It follows WordPress's own get_file_data() — the field name, a colon and the rest of
	 * the line, inside the first 8 KiB of the file — so it reads exactly what the Plugins
	 * screen will show. The file is never executed.
	 *
	 * @since 0.1.0
	 *
	 * @param string $main_file_source Contents of the main plugin file.
	 * @param string $field            Header field name, for example 'Version'.
	 * @return string|null The value, or null when the header is absent or empty.
	 */
	public static function header( string $main_file_source, string $field ): ?string {
		$head = str_replace( "\r", "\n", substr( $main_file_source, 0, 8192 ) );

		if ( 1 !== preg_match( '/^[ \t\/*#@]*' . preg_quote( $field, '/' ) . ':(.*)$/mi', $head, $matches ) ) {
			return null;
		}

		$value = trim( (string) preg_replace( '/\s*(?:\*\/|\?>).*/', '', $matches[1] ) );

		return '' === $value ? null : $value;
	}
}
