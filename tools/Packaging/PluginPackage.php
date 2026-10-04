<?php
/**
 * PluginPackage: the shape of a plugin's release zip, stated once
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tools\Packaging;

use InvalidArgumentException;

/**
 * Owns the facts the zip builder and the zip checker must agree on.
 *
 * What the archive is called, which folder it unpacks to, what may sit at the top of
 * that folder, and how the version is read from the main plugin file. The builder
 * writes to this shape and the checker verifies it, so neither restates it.
 *
 * There are two shapes. SEOCart itself ships compiled assets and scoped libraries, Action
 * Scheduler among them. A SEOCart extension, a plugin of its own in a repository of its own,
 * ships its main file and its source and bundles no library: an extension that needs one is a
 * reviewed change to this class, not something its zip can simply contain.
 *
 * @since 0.1.0
 */
final class PluginPackage {

	/**
	 * SEOCart's slug: the single top-level folder inside the zip and the prefix of its file name.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const SLUG = 'seocart';

	/**
	 * SEOCart's main plugin file, relative to the plugin root. It carries the `Version` header.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const MAIN_FILE = 'seocart.php';

	/**
	 * SEOCart's allow-list: every entry that may sit at the top of the plugin folder in the zip.
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
	 * An extension's allow-list, after its main file, which is always required.
	 *
	 * No `vendor-scoped/`: an extension bundles no library.
	 *
	 * @since 0.2.0
	 *
	 * @var array<string, bool>
	 */
	public const EXTENSION_TOP_LEVEL = array(
		'uninstall.php' => false,
		'readme.txt'    => true,
		'LICENSE'       => true,
		'composer.json' => false,
		'CHANGELOG.md'  => false,
		'src/'          => true,
		'templates/'    => false,
		'languages/'    => false,
		'build/'        => false,
	);

	/**
	 * The slug: the single top-level folder inside the zip and the prefix of its file name.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public readonly string $slug;

	/**
	 * The main plugin file, relative to the plugin root: the slug with `.php`.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public readonly string $mainFile;

	/**
	 * Every entry that may sit at the top of the plugin folder, mapped to whether it is required.
	 *
	 * @since 0.2.0
	 *
	 * @var array<string, bool>
	 */
	public readonly array $topLevel;

	/**
	 * Whether this is SEOCart itself, which ships built assets and scoped libraries.
	 *
	 * @since 0.2.0
	 *
	 * @var bool
	 */
	public readonly bool $isCore;

	/**
	 * Stores the shape.
	 *
	 * @since 0.2.0
	 *
	 * @param string              $main_file Main plugin file, relative to the plugin root.
	 * @param array<string, bool> $top_level The allow-list.
	 * @param bool                $is_core   Whether this is SEOCart itself.
	 */
	private function __construct( string $main_file, array $top_level, bool $is_core ) {
		$this->mainFile = $main_file;
		$this->slug     = substr( $main_file, 0, -strlen( '.php' ) );
		$this->topLevel = $top_level;
		$this->isCore   = $is_core;
	}

	/**
	 * Returns SEOCart's own package.
	 *
	 * @since 0.2.0
	 *
	 * @return self The package.
	 */
	public static function core(): self {
		return new self( self::MAIN_FILE, self::TOP_LEVEL, true );
	}

	/**
	 * Returns the package of the plugin whose root this is.
	 *
	 * The main file is found the way WordPress finds it: the one PHP file at the top of the
	 * plugin folder whose header names the plugin. Its name without `.php` is the slug, which
	 * is how every SEOCart plugin is laid out. A root whose main file is SEOCart's is SEOCart;
	 * any other is an extension.
	 *
	 * @since 0.2.0
	 *
	 * @param string $root Path of the plugin root.
	 * @return self The package.
	 *
	 * @throws InvalidArgumentException When the root is not a directory, or holds no main file or more than one.
	 */
	public static function at( string $root ): self {
		if ( ! is_dir( $root ) ) {
			throw new InvalidArgumentException( "{$root} is not a directory." );
		}

		$main_files = array();
		$php_files  = glob( rtrim( $root, '/' ) . '/*.php' );

		foreach ( false === $php_files ? array() : $php_files as $file ) {
			$head = (string) file_get_contents( $file, false, null, 0, 8192 );

			if ( null !== self::header( $head, 'Plugin Name' ) ) {
				$main_files[] = basename( $file );
			}
		}

		if ( 1 !== count( $main_files ) ) {
			throw new InvalidArgumentException(
				sprintf(
					'%s must hold exactly one PHP file whose header names the plugin ("Plugin Name:"), as WordPress expects; it holds %d%s.',
					$root,
					count( $main_files ),
					array() === $main_files ? '' : ': ' . implode( ', ', $main_files )
				)
			);
		}

		if ( self::MAIN_FILE === $main_files[0] ) {
			return self::core();
		}

		return new self( $main_files[0], array( $main_files[0] => true ) + self::EXTENSION_TOP_LEVEL, false );
	}

	/**
	 * Returns the file name of the release zip for a version.
	 *
	 * @since 0.1.0
	 *
	 * @param string $version The plugin version.
	 * @return string For example `seocart-1.2.3.zip`.
	 */
	public function zipFileName( string $version ): string {
		return $this->slug . '-' . $version . '.zip';
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
	 * @return string|null The version, or null when the name is not `<slug>-<version>.zip`.
	 */
	public function versionFromZipFileName( string $file_name ): ?string {
		$prefix = $this->slug . '-';
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
