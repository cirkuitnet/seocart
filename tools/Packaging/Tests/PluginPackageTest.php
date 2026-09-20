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

use PHPUnit\Framework\TestCase;
use SEOCart\Tools\Packaging\PluginPackage;

/**
 * Pins the file name of the zip and the reading of the plugin header.
 *
 * @since 0.1.0
 */
final class PluginPackageTest extends TestCase {

	/**
	 * Tests that the file name written for a version is read back as that version.
	 *
	 * @since 0.1.0
	 */
	public function test_zip_file_name_round_trips(): void {
		$this->assertSame( 'seocart-1.2.3.zip', PluginPackage::zipFileName( '1.2.3' ) );
		$this->assertSame( '1.2.3', PluginPackage::versionFromZipFileName( 'seocart-1.2.3.zip' ) );
		$this->assertSame( '0.2.0-beta.1', PluginPackage::versionFromZipFileName( PluginPackage::zipFileName( '0.2.0-beta.1' ) ) );
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
		$this->assertNull( PluginPackage::versionFromZipFileName( $file_name ) );
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
}
