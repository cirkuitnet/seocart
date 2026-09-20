<?php
/**
 * Tests that every declaration of the plugin version and platform floors agrees
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Packaging;

use PHPUnit\Framework\TestCase;
use SEOCart\Tools\Packaging\PluginPackage;

/**
 * Guards the facts that WordPress and the toolchain force us to state more than once.
 *
 * The plugin header is read by WordPress, the constants by the running plugin, and the
 * manifests by Composer and npm. None of them can read another, so the same fact is
 * unavoidably declared in several files. This test owns one fact: those declarations
 * are equal. It is the set-equality companion that every hand-maintained parallel list
 * must have.
 *
 * @since 0.1.0
 */
final class VersionAgreementTest extends TestCase {

	/**
	 * Reads a header field from the main plugin file without executing it.
	 *
	 * The main file calls WordPress functions at file scope, so it cannot be included here.
	 * PluginPackage::header() is the one reader of the plugin header: the zip builder, the
	 * zip checker, the readme validator and this test all ask it, so they cannot disagree.
	 *
	 * @since 0.1.0
	 *
	 * @param string $field Header field name, for example 'Version'.
	 * @return string The trimmed header value.
	 */
	private function pluginHeader( string $field ): string {
		$value = PluginPackage::header( (string) file_get_contents( $this->root() . '/seocart.php' ), $field );

		$this->assertNotNull( $value, "The plugin header has no \"{$field}\" field." );

		return $value;
	}

	/**
	 * Reads the value of a `define()` call in the main plugin file without executing it.
	 *
	 * @since 0.1.0
	 *
	 * @param string $name Constant name.
	 * @return string The string literal the constant is defined as.
	 */
	private function pluginConstant( string $name ): string {
		$source = (string) file_get_contents( $this->root() . '/seocart.php' );

		$this->assertSame(
			1,
			preg_match( "/define\(\s*'" . preg_quote( $name, '/' ) . "',\s*'([^']*)'\s*\)/", $source, $matches ),
			"The main plugin file does not define {$name} as a string literal."
		);

		return $matches[1];
	}

	/**
	 * Decodes a JSON manifest from the repository root.
	 *
	 * @since 0.1.0
	 *
	 * @param string $file File name relative to the repository root.
	 * @return array<string, mixed> The decoded manifest.
	 */
	private function manifest( string $file ): array {
		return json_decode( (string) file_get_contents( $this->root() . '/' . $file ), true, 512, JSON_THROW_ON_ERROR );
	}

	/**
	 * Returns the repository root.
	 *
	 * @since 0.1.0
	 *
	 * @return string Absolute path without a trailing slash.
	 */
	private function root(): string {
		return dirname( __DIR__, 3 );
	}

	/**
	 * Tests that the header, the constant and package.json state the same version.
	 *
	 * @since 0.1.0
	 */
	public function test_plugin_version_is_declared_consistently(): void {
		$header = $this->pluginHeader( 'Version' );

		$this->assertMatchesRegularExpression( '/^\d+\.\d+\.\d+$/', $header, 'The plugin version must be MAJOR.MINOR.PATCH.' );
		$this->assertSame( $header, $this->pluginConstant( 'SEOCART_VERSION' ), 'SEOCART_VERSION must match the Version header.' );
		$this->assertSame( $header, $this->manifest( 'package.json' )['version'], 'package.json "version" must match the Version header.' );
	}

	/**
	 * Tests that the header, the constant and composer.json state the same PHP floor.
	 *
	 * @since 0.1.0
	 */
	public function test_minimum_php_version_is_declared_consistently(): void {
		$header = $this->pluginHeader( 'Requires PHP' );

		$this->assertSame( $header, $this->pluginConstant( 'SEOCART_MINIMUM_PHP_VERSION' ), 'SEOCART_MINIMUM_PHP_VERSION must match the Requires PHP header.' );
		$this->assertSame( '>=' . $header, $this->manifest( 'composer.json' )['require']['php'], 'composer.json "require.php" must match the Requires PHP header.' );
	}

	/**
	 * Tests that the header and the constant state the same WordPress floor.
	 *
	 * @since 0.1.0
	 */
	public function test_minimum_wordpress_version_is_declared_consistently(): void {
		$this->assertSame(
			$this->pluginHeader( 'Requires at least' ),
			$this->pluginConstant( 'SEOCART_MINIMUM_WP_VERSION' ),
			'SEOCART_MINIMUM_WP_VERSION must match the Requires at least header.'
		);
	}
}
