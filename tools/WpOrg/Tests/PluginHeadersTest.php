<?php
/**
 * Tests the plugin header reader
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tools\WpOrg\Tests;

use PHPUnit\Framework\TestCase;
use SEOCart\Tools\WpOrg\PluginHeaders;

/**
 * Covers reading the header comment of a plugin's main file as text.
 *
 * @since 0.1.0
 */
final class PluginHeadersTest extends TestCase {

	/**
	 * Tests that the fields are read from a header comment.
	 *
	 * @since 0.1.0
	 */
	public function test_reads_the_header_fields(): void {
		$headers = PluginHeaders::read( (string) file_get_contents( __DIR__ . '/Fixtures/plugin-header.txt' ) );

		$this->assertSame(
			array(
				'Plugin Name'       => 'SEOCart',
				'Version'           => '0.1.0',
				'Requires at least' => '7.1',
				'Requires PHP'      => '8.3',
			),
			$headers
		);
	}

	/**
	 * Tests that an absent field is reported as an empty string.
	 *
	 * @since 0.1.0
	 */
	public function test_absent_field_is_empty(): void {
		$headers = PluginHeaders::read( "<?php\n/**\n * Plugin Name: Example\n */\n" );

		$this->assertSame( 'Example', $headers['Plugin Name'] );
		$this->assertSame( '', $headers['Version'] );
	}

	/**
	 * Tests that the real main file states every field the readme must agree with.
	 *
	 * @since 0.1.0
	 */
	public function test_the_real_main_file_states_every_field(): void {
		$headers = PluginHeaders::read( (string) file_get_contents( dirname( __DIR__, 3 ) . '/seocart.php' ) );

		$this->assertSame( PluginHeaders::FIELDS, array_keys( $headers ) );
		$this->assertNotContains( '', $headers );
	}
}
