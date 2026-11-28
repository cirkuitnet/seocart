<?php
/**
 * Tests how the storefront's script modules and stylesheet are loaded
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Storefront;

use SEOCart\Platform\Storefront\ReportCode;
use SEOCart\Platform\Storefront\StorefrontAssets;
use WP_UnitTestCase;

/**
 * A module is registered from its .asset.json file with its dependencies and its version, once a
 * request, after the storefront module it imports, and enqueued; the stylesheet the same way; and
 * a missing or unreadable list is reported, loads nothing and throws nothing.
 *
 * Each test builds the loader over a plugin directory of its own, in the system's temporary
 * directory, holding the build's files it needs.
 *
 * @since 0.2.0
 */
final class StorefrontAssetsTest extends WP_UnitTestCase {

	/**
	 * The test's plugin directory.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private string $directory = '';

	/**
	 * The reports the loader made: each its code and its context.
	 *
	 * @since 0.2.0
	 *
	 * @var list<array{0: string, 1: array<string, mixed>}>
	 */
	private array $reports = array();

	/**
	 * Creates the plugin directory, with an empty build.
	 *
	 * @since 0.2.0
	 */
	public function set_up(): void {
		parent::set_up();

		$this->directory = (string) tempnam( sys_get_temp_dir(), 'seocart-assets-' );

		unlink( $this->directory );
		mkdir( $this->directory . '/build/blocks', 0700, true );
		touch( $this->directory . '/seocart.php' );
	}

	/**
	 * Removes what a test registered, and the plugin directory.
	 *
	 * @since 0.2.0
	 */
	public function tear_down(): void {
		foreach ( array_keys( StorefrontAssets::MODULES ) as $id ) {
			wp_script_modules()->dequeue( $id );
			wp_script_modules()->deregister( $id );
		}

		wp_dequeue_style( StorefrontAssets::STYLE );
		wp_deregister_style( StorefrontAssets::STYLE );

		foreach ( (array) glob( $this->directory . '/build/blocks/*' ) as $file ) {
			unlink( (string) $file );
		}

		rmdir( $this->directory . '/build/blocks' );
		rmdir( $this->directory . '/build' );
		unlink( $this->directory . '/seocart.php' );

		parent::tear_down();
	}

	/**
	 * Tests that a module that imports the store registers the store first, with each one's dependencies and version, and enqueues the one asked for.
	 *
	 * @since 0.2.0
	 */
	public function test_a_module_is_registered_after_the_module_it_imports_and_enqueued(): void {
		$this->build( 'blocks/store', array( '@wordpress/interactivity' ), 'store-version' );
		$this->build( 'blocks/product', array( '@seocart/store', '@wordpress/interactivity' ), 'product-version' );

		$this->loader()->module( '@seocart/product' );

		$store   = wp_script_modules()->get_registered( '@seocart/store' );
		$product = wp_script_modules()->get_registered( '@seocart/product' );

		$this->assertNotNull( $store, 'The module the product imports is not registered, so the import map would not name it.' );
		$this->assertNotNull( $product );
		$this->assertSame( 'store-version', $store['version'] );
		$this->assertSame( 'product-version', $product['version'] );
		$this->assertStringEndsWith( '/build/blocks/product.js', $product['src'] );
		$this->assertSame(
			array(
				array(
					'id'     => '@seocart/store',
					'import' => 'static',
				),
				array(
					'id'     => '@wordpress/interactivity',
					'import' => 'static',
				),
			),
			$product['dependencies']
		);
		$this->assertContains( '@seocart/product', wp_script_modules()->get_queue() );
		$this->assertSame( array(), $this->reports );
	}

	/**
	 * Tests that a module asked for twice in a request is read once.
	 *
	 * @since 0.2.0
	 */
	public function test_a_module_asked_for_twice_is_read_once(): void {
		$this->build( 'blocks/store', array( '@wordpress/interactivity' ), 'first' );

		$loader = $this->loader();

		$loader->module( '@seocart/store' );
		$this->build( 'blocks/store', array( '@wordpress/interactivity' ), 'second' );
		$loader->module( '@seocart/store' );

		$this->assertSame( 'first', wp_script_modules()->get_registered( '@seocart/store' )['version'] ?? null );
	}

	/**
	 * Tests that the stylesheet is registered with the version of its list, and enqueued.
	 *
	 * @since 0.2.0
	 */
	public function test_the_stylesheet_is_registered_with_its_version_and_enqueued(): void {
		$this->build( StorefrontAssets::STYLESHEET, array(), 'style-version' );

		$this->loader()->style();

		$this->assertTrue( wp_style_is( StorefrontAssets::STYLE, 'enqueued' ) );
		$this->assertSame( 'style-version', wp_styles()->registered[ StorefrontAssets::STYLE ]->ver ?? null );
		$this->assertStringEndsWith( '/build/blocks/storefront.css', (string) ( wp_styles()->registered[ StorefrontAssets::STYLE ]->src ?? '' ) );
	}

	/**
	 * Tests that a missing or unreadable list is reported, and loads nothing.
	 *
	 * @since 0.2.0
	 */
	public function test_a_missing_or_unreadable_list_is_reported_and_loads_nothing(): void {
		file_put_contents( $this->directory . '/build/blocks/product.asset.json', '{ not json' );

		$loader = $this->loader();

		$loader->module( '@seocart/store' );
		$loader->module( '@seocart/product' );
		$loader->style();

		$this->assertNull( wp_script_modules()->get_registered( '@seocart/store' ) );
		$this->assertNull( wp_script_modules()->get_registered( '@seocart/product' ) );
		$this->assertFalse( wp_style_is( StorefrontAssets::STYLE, 'registered' ) );
		$this->assertSame(
			array(
				array( ReportCode::AssetUnreadable->value, array( 'file' => 'build/blocks/store.asset.json' ) ),
				array( ReportCode::AssetUnreadable->value, array( 'file' => 'build/blocks/product.asset.json' ) ),
				array( ReportCode::AssetUnreadable->value, array( 'file' => 'build/blocks/storefront.asset.json' ) ),
			),
			$this->reports
		);
	}

	/**
	 * Returns a loader over the test's plugin directory, whose reports the test keeps.
	 *
	 * @since 0.2.0
	 *
	 * @return StorefrontAssets The loader.
	 */
	private function loader(): StorefrontAssets {
		return new StorefrontAssets(
			$this->directory . '/seocart.php',
			function ( string $code, array $context ): void {
				$this->reports[] = array( $code, $context );
			}
		);
	}

	/**
	 * Writes a file's list of dependencies and version, as the build does.
	 *
	 * @since 0.2.0
	 *
	 * @param string   $file         The file under build/, without its extension.
	 * @param string[] $dependencies Its dependencies.
	 * @param string   $version      Its version.
	 *
	 * @phpstan-param list<string> $dependencies
	 */
	private function build( string $file, array $dependencies, string $version ): void {
		file_put_contents(
			$this->directory . '/build/' . $file . '.asset.json',
			(string) wp_json_encode(
				array(
					'dependencies' => $dependencies,
					'version'      => $version,
					'type'         => 'module',
				)
			)
		);
	}
}
