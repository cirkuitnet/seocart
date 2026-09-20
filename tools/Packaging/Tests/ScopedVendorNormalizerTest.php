<?php
/**
 * Tests for ScopedVendorNormalizer
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tools\Packaging\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use SEOCart\Tools\Packaging\ScopedVendorNormalizer;

/**
 * Proves that two installs with different machine state end up with the same bytes.
 *
 * @since 0.1.0
 */
final class ScopedVendorNormalizerTest extends TestCase {

	use TemporaryDirectory;

	/**
	 * A content hash as composer.lock states it.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const CONTENT_HASH = '311fe560ad4997be34b99c0abc18cc39';

	/**
	 * The reference of a dependency, which must survive.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const DEPENDENCY_REFERENCE = '9e2c6b02e89652bade4445ea2ca5fb12e0fa7242';

	/**
	 * Writes a miniature of what Strauss generates, with the given machine state.
	 *
	 * @since 0.1.0
	 *
	 * @param string $name      Directory name beneath the scratch directory.
	 * @param string $suffix    The random loader class suffix.
	 * @param string $version   The detected root version, for example 'dev-main'.
	 * @param string $reference The detected root commit as a PHP literal.
	 * @return string Absolute path of the directory.
	 */
	private function install( string $name, string $suffix, string $version, string $reference ): string {
		$this->writeFile( $name . '/autoload.php', "<?php\nrequire_once __DIR__ . '/composer/autoload_real.php';\nreturn ComposerAutoloaderInit{$suffix}::getLoader();\n" );
		$this->writeFile( $name . '/composer/autoload_real.php', "<?php\nclass ComposerAutoloaderInit{$suffix}\n{\n\t// Uses \\Composer\\Autoload\\ComposerStaticInit{$suffix}.\n}\n" );
		$this->writeFile( $name . '/composer/autoload_static.php', "<?php\nnamespace Composer\\Autoload;\nclass ComposerStaticInit{$suffix}\n{\n}\n" );
		$this->writeFile(
			$name . '/composer/installed.php',
			"<?php return array (\n  'root' => \n  array (\n    'name' => 'cirkuitnet/seocart',\n    'pretty_version' => '{$version}',\n    'version' => '{$version}',\n    'reference' => {$reference},\n    'dev' => true,\n  ),\n"
			. "  'versions' => \n  array (\n    'woocommerce/action-scheduler' => \n    array (\n      'pretty_version' => '4.2.0',\n      'version' => '4.2.0.0',\n      'reference' => '" . self::DEPENDENCY_REFERENCE . "',\n    ),\n  ),\n);\n"
		);

		return $this->directory . '/' . $name;
	}

	/**
	 * Reads every generated file of an install into one string.
	 *
	 * @since 0.1.0
	 *
	 * @param string $directory Absolute path of the install.
	 * @return string The files, concatenated in a fixed order.
	 */
	private function bytes( string $directory ): string {
		$bytes = '';

		foreach ( array( 'autoload.php', 'composer/autoload_real.php', 'composer/autoload_static.php', 'composer/installed.php' ) as $file ) {
			$bytes .= $file . "\n" . file_get_contents( $directory . '/' . $file );
		}

		return $bytes;
	}

	/**
	 * Tests that a branch checkout and a tag checkout normalize to identical files.
	 *
	 * @since 0.1.0
	 */
	public function test_two_installs_with_different_machine_state_become_identical(): void {
		$first  = $this->install( 'first', str_repeat( 'a1', 16 ), 'dev-main', "'50c15bbc1b11b22f7d97750b9cc1cf870a24743b'" );
		$second = $this->install( 'second', str_repeat( 'b2', 16 ), '0.1.0', 'NULL' );

		$this->assertNotSame( $this->bytes( $first ), $this->bytes( $second ), 'The fixtures must differ before they are normalized.' );

		( new ScopedVendorNormalizer() )->normalize( $first, self::CONTENT_HASH );
		( new ScopedVendorNormalizer() )->normalize( $second, self::CONTENT_HASH );

		$this->assertSame( $this->bytes( $first ), $this->bytes( $second ) );
	}

	/**
	 * Tests that the suffix follows the lock file and the root package carries no machine state.
	 *
	 * @since 0.1.0
	 */
	public function test_suffix_follows_the_lock_file_and_the_root_package_is_neutral(): void {
		$directory = $this->install( 'install', str_repeat( 'a1', 16 ), 'dev-main', "'50c15bbc1b11b22f7d97750b9cc1cf870a24743b'" );

		( new ScopedVendorNormalizer() )->normalize( $directory, self::CONTENT_HASH );

		$bytes  = $this->bytes( $directory );
		$suffix = ScopedVendorNormalizer::suffixFor( self::CONTENT_HASH );

		$this->assertMatchesRegularExpression( '/^[0-9a-f]{32}$/D', $suffix );
		$this->assertNotSame( self::CONTENT_HASH, $suffix, 'vendor/autoload.php uses the content hash itself; the two loaders share a process in the test suites.' );
		$this->assertNotSame( $suffix, ScopedVendorNormalizer::suffixFor( str_repeat( '0', 32 ) ) );
		$this->assertSame( 2, substr_count( $bytes, 'ComposerAutoloaderInit' . $suffix ) );
		$this->assertSame( 2, substr_count( $bytes, 'ComposerStaticInit' . $suffix ) );
		$this->assertStringNotContainsString( str_repeat( 'a1', 16 ), $bytes );
		$this->assertStringNotContainsString( 'dev-main', $bytes );
		$this->assertStringNotContainsString( '50c15bbc', $bytes );
		$this->assertStringContainsString( "'pretty_version' => '1.0.0+no-version-set'", $bytes );
		$this->assertStringContainsString( "'reference' => NULL", $bytes );
		$this->assertStringContainsString( "'reference' => '" . self::DEPENDENCY_REFERENCE . "'", $bytes, 'The reference of a dependency comes from composer.lock and must stay.' );
		$this->assertStringContainsString( "'pretty_version' => '4.2.0'", $bytes );
	}

	/**
	 * Tests that a second run changes nothing.
	 *
	 * @since 0.1.0
	 */
	public function test_second_run_changes_nothing(): void {
		$directory = $this->install( 'install', str_repeat( 'a1', 16 ), 'dev-main', 'NULL' );

		( new ScopedVendorNormalizer() )->normalize( $directory, self::CONTENT_HASH );
		$once = $this->bytes( $directory );
		( new ScopedVendorNormalizer() )->normalize( $directory, self::CONTENT_HASH );

		$this->assertSame( $once, $this->bytes( $directory ) );
	}

	/**
	 * Tests that an autoloader of an unknown shape fails instead of shipping as it is.
	 *
	 * @since 0.1.0
	 */
	public function test_unknown_loader_shape_fails(): void {
		$directory = $this->install( 'install', str_repeat( 'a1', 16 ), 'dev-main', 'NULL' );
		$this->writeFile( 'install/autoload.php', "<?php\nreturn SomeOtherLoader::getLoader();\n" );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'does not name a loader class' );

		( new ScopedVendorNormalizer() )->normalize( $directory, self::CONTENT_HASH );
	}

	/**
	 * Tests that a missing generated file fails.
	 *
	 * @since 0.1.0
	 */
	public function test_missing_file_fails(): void {
		$directory = $this->install( 'install', str_repeat( 'a1', 16 ), 'dev-main', 'NULL' );
		unlink( $directory . '/composer/installed.php' );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'is missing' );

		( new ScopedVendorNormalizer() )->normalize( $directory, self::CONTENT_HASH );
	}

	/**
	 * Tests that a root block without a field fails instead of being skipped.
	 *
	 * @since 0.1.0
	 */
	public function test_root_block_without_a_field_fails(): void {
		$directory = $this->install( 'install', str_repeat( 'a1', 16 ), 'dev-main', 'NULL' );
		$installed = (string) file_get_contents( $directory . '/composer/installed.php' );
		$this->writeFile( 'install/composer/installed.php', str_replace( "    'reference' => NULL,\n", '', $installed ) );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( "does not state 'reference' exactly once" );

		( new ScopedVendorNormalizer() )->normalize( $directory, self::CONTENT_HASH );
	}

	/**
	 * Tests that a content hash of the wrong shape is refused.
	 *
	 * @since 0.1.0
	 */
	public function test_malformed_content_hash_fails(): void {
		$directory = $this->install( 'install', str_repeat( 'a1', 16 ), 'dev-main', 'NULL' );

		$this->expectException( RuntimeException::class );

		( new ScopedVendorNormalizer() )->normalize( $directory, 'not-a-hash' );
	}
}
