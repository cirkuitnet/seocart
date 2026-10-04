<?php
/**
 * Tests for the facts the zip builder and the zip checker share
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tools\Packaging\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SEOCart\Tools\Packaging\PluginPackage;

/**
 * Pins the file name of the zip and the reading of the plugin header.
 *
 * @since 0.1.0
 */
final class PluginPackageTest extends TestCase {

	use TemporaryDirectory;

	/**
	 * Tests that the file name written for a version is read back as that version.
	 *
	 * @since 0.1.0
	 */
	public function test_zip_file_name_round_trips(): void {
		$this->assertSame( 'seocart-1.2.3.zip', PluginPackage::core()->zipFileName( '1.2.3' ) );
		$this->assertSame( '1.2.3', PluginPackage::core()->versionFromZipFileName( 'seocart-1.2.3.zip' ) );
		$this->assertSame( '0.2.0-beta.1', PluginPackage::core()->versionFromZipFileName( PluginPackage::core()->zipFileName( '0.2.0-beta.1' ) ) );
	}

	/**
	 * Tests that a file name of another shape states no version.
	 *
	 * @since 0.1.0
	 *
	 * @dataProvider otherFileNames
	 *
	 * @param string $file_name A file name that is not `seocart-<version>.zip`.
	 */
	public function test_other_file_names_state_no_version( string $file_name ): void {
		$this->assertNull( PluginPackage::core()->versionFromZipFileName( $file_name ) );
	}

	/**
	 * Provides file names that state no version.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{string}>
	 */
	public function otherFileNames(): array {
		return array(
			'no version'        => array( 'seocart-.zip' ),
			'no separator'      => array( 'seocart.zip' ),
			'another plugin'    => array( 'seocart-stripe-1.2.3.tar.gz' ),
			'another prefix'    => array( 'release-1.2.3.zip' ),
			'another extension' => array( 'seocart-1.2.3.tar' ),
		);
	}

	/**
	 * Tests that a header field is read the way WordPress reads it, and only when it is there.
	 *
	 * @since 0.1.0
	 */
	public function test_header_field_is_read_from_the_plugin_header(): void {
		$source = "<?php\n/**\n * Plugin Name:       Fixture\n * Version:           1.2.3  \n * Requires PHP:      8.3\n * Empty:\n */\n";

		$this->assertSame( '1.2.3', PluginPackage::header( $source, 'Version' ) );
		$this->assertSame( '8.3', PluginPackage::header( $source, 'Requires PHP' ) );
		$this->assertNull( PluginPackage::header( $source, 'Empty' ) );
		$this->assertNull( PluginPackage::header( $source, 'Requires at least' ) );
	}

	/**
	 * Tests that the repository's main plugin file is where MAIN_FILE says and states a version.
	 *
	 * @since 0.1.0
	 */
	public function test_main_file_of_the_repository_states_a_version(): void {
		$main_file = dirname( __DIR__, 3 ) . '/' . PluginPackage::MAIN_FILE;

		$this->assertFileExists( $main_file );
		$this->assertNotNull( PluginPackage::header( (string) file_get_contents( $main_file ), 'Version' ) );
	}

	/**
	 * Tests that the repository's own root is SEOCart's package, found the way WordPress finds a main file.
	 *
	 * @since 0.2.0
	 */
	public function test_the_repository_root_is_seocart(): void {
		$package = PluginPackage::at( dirname( __DIR__, 3 ) );

		$this->assertTrue( $package->isCore );
		$this->assertSame( PluginPackage::SLUG, $package->slug );
		$this->assertSame( PluginPackage::TOP_LEVEL, $package->topLevel );
	}

	/**
	 * Tests that an extension's root is an extension's package: its slug from its main file, its
	 * main file required, no scoped libraries on its allow-list.
	 *
	 * @since 0.2.0
	 */
	public function test_an_extension_root_is_an_extension(): void {
		$this->writeFile( 'seocart-example.php', "<?php\n/**\n * Plugin Name: SEOCart Example\n */\n" );
		$this->writeFile( 'uninstall.php', "<?php\n" );

		$package = PluginPackage::at( $this->directory );

		$this->assertFalse( $package->isCore );
		$this->assertSame( 'seocart-example', $package->slug );
		$this->assertSame( 'seocart-example.php', $package->mainFile );
		$this->assertSame( 'seocart-example-0.1.0.zip', $package->zipFileName( '0.1.0' ) );
		$this->assertSame( '0.1.0', $package->versionFromZipFileName( 'seocart-example-0.1.0.zip' ) );
		$this->assertTrue( $package->topLevel['seocart-example.php'] );
		$this->assertArrayNotHasKey( 'vendor-scoped/', $package->topLevel );
	}

	/**
	 * Tests that a root without a main file, or with two, is refused.
	 *
	 * @since 0.2.0
	 *
	 * @dataProvider rootsWithoutOneMainFile
	 *
	 * @param array<string, string> $files File name => contents.
	 * @param string                $found What the refusal says was found.
	 */
	public function test_a_root_without_exactly_one_main_file_is_refused( array $files, string $found ): void {
		foreach ( $files as $name => $contents ) {
			$this->writeFile( $name, $contents );
		}

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( $found );

		PluginPackage::at( $this->directory );
	}

	/**
	 * Provides plugin roots without exactly one main file.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, array{array<string, string>, string}>
	 */
	public function rootsWithoutOneMainFile(): array {
		return array(
			'none' => array( array( 'helper.php' => "<?php\n" ), 'it holds 0.' ),
			'two'  => array(
				array(
					'one.php' => "<?php\n/* Plugin Name: One */\n",
					'two.php' => "<?php\n/* Plugin Name: Two */\n",
				),
				'it holds 2: one.php, two.php.',
			),
		);
	}
}
